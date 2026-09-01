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

	public function download(string $jobId): string {
		$response = $this->clientService->newClient()->get($this->getBaseUrl() . '/api/download/' . rawurlencode($jobId), [
			'timeout' => 120,
			'headers' => ['X-API-Key' => $this->getApiKey()],
		]);
		return (string)$response->getBody();
	}
}
