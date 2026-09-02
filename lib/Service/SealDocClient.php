<?php

declare(strict_types=1);

namespace OCA\SealDoc\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Everything that talks to SealDoc goes through here.
 *
 * Two decisions worth stating, because both are easy to get wrong later.
 *
 * The base URL is configurable and has no default that points at a hosted
 * service. An administrator who installs this app is expected to say where
 * their SealDoc lives, and for a large part of this audience that will be
 * their own machine. Hard-coding a vendor URL would quietly turn a
 * self-hosted deployment into one that phones home.
 *
 * The API key is stored through ICrypto, not in plain appconfig. It is a
 * credential that can create billable work and read back sealed output, so it
 * belongs in the same class of secret as a password.
 */
class SealDocClient {
	public const APP_ID = 'sealdoc';

	private const CONFIG_BASE_URL = 'base_url';
	private const CONFIG_API_KEY = 'api_key';
	private const CONFIG_EVIDENCE_FOLDER = 'evidence_folder';
	private const CONFIG_STORE_EVIDENCE = 'store_evidence';
	private const CONFIG_RETENTION_LABEL = 'retention_label';

	/** How often to re-ask for a pack that is not finished yet. */
	private const PACK_ATTEMPTS = 6;

	/** And the longest single wait between those attempts. */
	private const PACK_MAX_WAIT_SECONDS = 10;

	/**
	 * Default off, and that is deliberate. Storing the pack doubles the number
	 * of files an administrator sees per document, and a folder that suddenly
	 * holds three times as much is the complaint you get before the compliance
	 * benefit is ever noticed. Turning it on should be a decision.
	 */
	public const DEFAULT_EVIDENCE_FOLDER = '/SealDoc evidence';

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private ICrypto $crypto,
		private LoggerInterface $logger,
	) {
	}

	public function getBaseUrl(): string {
		return rtrim((string)$this->config->getAppValue(self::APP_ID, self::CONFIG_BASE_URL, ''), '/');
	}

	public function setBaseUrl(string $url): void {
		$this->config->setAppValue(self::APP_ID, self::CONFIG_BASE_URL, rtrim(trim($url), '/'));
	}

	public function hasApiKey(): bool {
		return $this->config->getAppValue(self::APP_ID, self::CONFIG_API_KEY, '') !== '';
	}

	public function setApiKey(string $key): void {
		$key = trim($key);
		if ($key === '') {
			$this->config->deleteAppValue(self::APP_ID, self::CONFIG_API_KEY);
			return;
		}
		$this->config->setAppValue(self::APP_ID, self::CONFIG_API_KEY, $this->crypto->encrypt($key));
	}

	private function getApiKey(): string {
		$stored = (string)$this->config->getAppValue(self::APP_ID, self::CONFIG_API_KEY, '');
		if ($stored === '') {
			throw new RuntimeException('No SealDoc API key configured');
		}
		try {
			return $this->crypto->decrypt($stored);
		} catch (\Throwable $e) {
			// A key that cannot be decrypted is worse than a missing one,
			// because it fails at the moment somebody relies on it. Say so
			// plainly instead of returning an empty string that produces a
			// confusing 401 further down.
			throw new RuntimeException('Stored SealDoc API key could not be decrypted', 0, $e);
		}
	}

	public function isStoringEvidence(): bool {
		return $this->config->getAppValue(self::APP_ID, self::CONFIG_STORE_EVIDENCE, 'no') === 'yes';
	}

	public function setStoringEvidence(bool $on): void {
		$this->config->setAppValue(self::APP_ID, self::CONFIG_STORE_EVIDENCE, $on ? 'yes' : 'no');
	}

	public function getEvidenceFolder(): string {
		$v = trim((string)$this->config->getAppValue(self::APP_ID, self::CONFIG_EVIDENCE_FOLDER, self::DEFAULT_EVIDENCE_FOLDER));
		return $v === '' ? self::DEFAULT_EVIDENCE_FOLDER : '/' . trim($v, '/');
	}

	public function setEvidenceFolder(string $path): void {
		$this->config->setAppValue(self::APP_ID, self::CONFIG_EVIDENCE_FOLDER, '/' . trim(trim($path), '/'));
	}

	/**
	 * The organisation's own retention statement, shown as such.
	 *
	 * SealDoc does not know how long a document has to be kept. That depends on
	 * what the document IS, in which country, under which rule, and none of
	 * those are things a sealing service can see. Printing "statutory retention:
	 * 7 years" next to a file it knows nothing about would be the one kind of
	 * claim a compliance product must never make.
	 *
	 * So the text is whatever the administrator writes, and the panel labels it
	 * as the organisation's policy rather than as a fact. Empty by default,
	 * because an empty panel row is honest and a guessed one is not.
	 */
	public function getRetentionLabel(): string {
		return trim((string)$this->config->getAppValue(self::APP_ID, self::CONFIG_RETENTION_LABEL, ''));
	}

	public function setRetentionLabel(string $label): void {
		$this->config->setAppValue(self::APP_ID, self::CONFIG_RETENTION_LABEL, trim($label));
	}

	public function isConfigured(): bool {
		return $this->getBaseUrl() !== '' && $this->hasApiKey();
	}

	/**
	 * What the settings screen's "Test connection" button measures.
	 *
	 * TWO PROBES, BECAUSE ONE ANSWERED THE WRONG QUESTION. This used to hit the
	 * public plans endpoint only, while its own docblock claimed it separated
	 * "I cannot reach this host at all" from "the host is there but rejects my
	 * key". It could not: that endpoint needs no credentials and answers 200
	 * with no key and 200 with a wrong one. Measured against the live API:
	 *
	 *   /api/public/plans   no key   -> 200
	 *   /api/public/plans   bad key  -> 200
	 *   /api/jobs?limit=1   no key   -> 401
	 *   /api/jobs?limit=1   bad key  -> 401
	 *   /api/jobs?limit=1   real key -> 200
	 *
	 * So a truncated or revoked key produced a green "Server reachable", after
	 * which nothing ever sealed and nothing said why. That is the widest funnel
	 * into a silent failure this app has.
	 *
	 * The reachability probe stays first and stays credential-free, because a
	 * firewall and a bad key are genuinely different problems and an
	 * administrator wants them apart. The second probe then answers the
	 * question the button was always advertised as answering.
	 *
	 * @return array{ok: bool, reason: string}
	 */
	public function ping(): array {
		$base = $this->getBaseUrl();
		if ($base === '') {
			return ['ok' => false, 'reason' => 'no_url'];
		}

		try {
			$response = $this->clientService->newClient()->get($base . '/api/public/plans', [
				'timeout' => 10,
				'headers' => ['Accept' => 'application/json'],
			]);
			if ($response->getStatusCode() !== 200) {
				return ['ok' => false, 'reason' => 'unreachable'];
			}
		} catch (\Throwable $e) {
			$this->logger->info('SealDoc reachability check failed', ['exception' => $e]);
			return ['ok' => false, 'reason' => 'unreachable'];
		}

		if (!$this->hasApiKey()) {
			// Reachable and unusable. Reporting this as success is how an
			// administrator leaves the page believing setup is finished.
			return ['ok' => false, 'reason' => 'no_key'];
		}

		try {
			// http_errors off on purpose: a 401 is an answer here, not a
			// transport failure, and letting it throw would render it as
			// "server not reachable", identical to a DNS error.
			$response = $this->clientService->newClient()->get($base . '/api/jobs?limit=1', [
				'timeout' => 10,
				'headers' => [
					'X-API-Key' => $this->getApiKey(),
					'Accept' => 'application/json',
				],
				'http_errors' => false,
			]);
			$status = $response->getStatusCode();
			if ($status === 401 || $status === 403) {
				return ['ok' => false, 'reason' => 'key_rejected'];
			}
			if ($status === 200) {
				return ['ok' => true, 'reason' => 'ok'];
			}
			$this->logger->warning('SealDoc key check returned an unexpected status', ['status' => $status]);
			return ['ok' => false, 'reason' => 'unexpected'];
		} catch (\Throwable $e) {
			$this->logger->info('SealDoc key check failed', ['exception' => $e]);
			return ['ok' => false, 'reason' => 'unreachable'];
		}
	}

	/**
	 * Hands a document to SealDoc and returns the job id.
	 *
	 * @param string $content raw file bytes
	 * @param string $filename used only for the multipart part name; SealDoc
	 *                         determines the actual type from the content
	 */
	public function createJob(string $content, string $filename): string {
		$response = $this->clientService->newClient()->post($this->getBaseUrl() . '/api/jobs', [
			'timeout' => 120,
			'headers' => [
				'X-API-Key' => $this->getApiKey(),
				'Accept' => 'application/json',
			],
			'multipart' => [
				[
					'name' => 'file',
					'contents' => $content,
					'filename' => $filename,
				],
				// Asked for explicitly, because SealDoc will not do it otherwise.
				//
				// This field defaults to false on the API, and this client never
				// sent it. Four documents therefore came back sealed and without
				// the one thing the whole promise leans on, and the panel spent a
				// release honestly reporting a gap this app had created itself.
				//
				// It costs nothing to ask: there is no plan gate on timestamps,
				// and the path is fail-open, so a TSA that cannot be reached
				// still yields a completed job. The passport then says the
				// timestamp is missing and the panel shows it in red, which is
				// the outcome we want in that case.
				[
					'name' => 'timestampRfc3161',
					'contents' => 'true',
				],
			],
		]);

		$body = json_decode((string)$response->getBody(), true);
		$jobId = $body['jobId'] ?? $body['id'] ?? null;
		if (!is_string($jobId) || $jobId === '') {
			throw new RuntimeException('SealDoc did not return a job id');
		}
		return $jobId;
	}

	/**
	 * @return array{status: string, error: ?string}
	 */
	public function getJob(string $jobId): array {
		$response = $this->clientService->newClient()->get($this->getBaseUrl() . '/api/jobs/' . rawurlencode($jobId), [
			'timeout' => 30,
			'headers' => [
				'X-API-Key' => $this->getApiKey(),
				'Accept' => 'application/json',
			],
		]);
		$body = json_decode((string)$response->getBody(), true);
		return [
			'status' => strtolower((string)($body['status'] ?? 'unknown')),
			'error' => isset($body['errorMessage']) ? (string)$body['errorMessage'] : null,
		];
	}

	/**
	 * The evidence pack: the document, the audit trail and a manifest that
	 * seals both the individual files and the set.
	 *
	 * Kept separate from download() on purpose. The sealed PDF proves itself;
	 * the pack proves the chain around it, and for a retention obligation that
	 * is the artefact an auditor asks for. Which of the two an administrator
	 * wants stored is a choice, so it is a setting rather than an assumption.
	 */
	public function downloadEvidencePack(string $jobId): string {
		$url = $this->getBaseUrl() . '/api/jobs/' . rawurlencode($jobId) . '/evidence-pack';

		// SealDoc answers 409 while the evidence ledger is still being written.
		//
		// Vault ingest runs asynchronously there, so for a few seconds after a
		// job reports completed the pack would be missing ledger.json and
		// public-keys.jwk. It used to hand that over with a 200 and a manifest
		// that described the short pack perfectly; now it refuses, which is
		// right, and this client is the one that has to wait.
		//
		// Measured: one pack in seven arrived without a ledger because this app
		// polls until completed and downloads immediately. Waiting a few seconds
		// turns that into a complete pack rather than an honest warning about an
		// incomplete one.
		for ($attempt = 0; $attempt < self::PACK_ATTEMPTS; $attempt++) {
			$response = $this->clientService->newClient()->get($url, [
				'timeout' => 120,
				'headers' => ['X-API-Key' => $this->getApiKey()],
				'http_errors' => false,
			]);

			$status = $response->getStatusCode();
			if ($status === 200) {
				return (string)$response->getBody();
			}
			if ($status !== 409 && $status !== 425) {
				throw new RuntimeException('SealDoc returned HTTP ' . $status . ' for the evidence pack');
			}

			$wait = (int)($response->getHeader('Retry-After') ?: 0);
			sleep(max(1, min($wait, self::PACK_MAX_WAIT_SECONDS)));
		}

		throw new RuntimeException('SealDoc did not finish the evidence for job ' . $jobId . ' in time');
	}

	public function download(string $jobId): string {
		$response = $this->clientService->newClient()->get($this->getBaseUrl() . '/api/download/' . rawurlencode($jobId), [
			'timeout' => 120,
			'headers' => ['X-API-Key' => $this->getApiKey()],
		]);
		return (string)$response->getBody();
	}
}
