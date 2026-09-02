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
	 * Reachability check for the settings screen.
	 *
	 * Deliberately hits the public plans endpoint, which needs no credentials:
	 * it separates "I cannot reach this host at all" from "the host is there
	 * but rejects my key". An administrator debugging a firewall wants those
	 * two answers apart.
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
			return ['ok' => $response->getStatusCode() === 200, 'reason' => 'reachable'];
		} catch (\Throwable $e) {
			$this->logger->info('SealDoc reachability check failed', ['exception' => $e]);
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
			'multipart' => [[
				'name' => 'file',
				'contents' => $content,
				'filename' => $filename,
			]],
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
		$response = $this->clientService->newClient()->get($this->getBaseUrl() . '/api/jobs/' . rawurlencode($jobId) . '/evidence-pack', [
			'timeout' => 120,
			'headers' => ['X-API-Key' => $this->getApiKey()],
		]);
		return (string)$response->getBody();
	}

	public function download(string $jobId): string {
		$response = $this->clientService->newClient()->get($this->getBaseUrl() . '/api/download/' . rawurlencode($jobId), [
			'timeout' => 120,
			'headers' => ['X-API-Key' => $this->getApiKey()],
		]);
		return (string)$response->getBody();
	}
}
