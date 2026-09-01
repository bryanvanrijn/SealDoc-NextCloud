<?php

declare(strict_types=1);

namespace OCA\SealDoc\BackgroundJob;

use OCA\SealDoc\Service\SealDocClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Does the slow half: upload, wait, write the sealed file back.
 *
 * Polling rather than webhooks, deliberately. A webhook would be faster and
 * cheaper, but it requires the Nextcloud instance to be reachable from the
 * internet. A large part of this app's audience runs Nextcloud behind their own
 * firewall precisely so that it is not. Polling works in both worlds; a webhook
 * would work in one and fail silently in the other.
 */
class SealJob extends QueuedJob {
	public const SEALED_SUFFIX = '-sealed.pdf';

	/** Cap the wait so a stuck job cannot occupy a cron slot forever. */
	private const MAX_ATTEMPTS = 40;
	private const POLL_SECONDS = 3;

	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private SealDocClient $client,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$fileId = (int)($argument['fileId'] ?? 0);
		$userId = (string)($argument['userId'] ?? '');
		if ($fileId === 0 || $userId === '') {
			return;
		}

		if (!$this->client->isConfigured()) {
			$this->logger->warning('SealDoc job skipped: app is not configured', ['fileId' => $fileId]);
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$nodes = $userFolder->getById($fileId);
			$node = $nodes[0] ?? null;
			if (!$node instanceof File) {
				// The file was moved or deleted between the write and this job.
				// That is normal, not an error.
				return;
			}

			$targetName = $this->sealedName($node->getName());
			$parent = $node->getParent();
			if ($parent->nodeExists($targetName)) {
				return;
			}

			$jobId = $this->client->createJob($node->getContent(), $node->getName());
			$sealed = $this->await($jobId);
			if ($sealed === null) {
				return;
			}

			$parent->newFile($targetName, $sealed);
			$this->logger->info('SealDoc sealed a document', [
				'fileId' => $fileId,
				'target' => $targetName,
			]);
		} catch (\Throwable $e) {
			// Logged and dropped. Retrying automatically would re-upload the
			// same document on every cron run, and a document that fails
			// conversion tends to keep failing; that turns one problem into a
			// recurring bill.
			$this->logger->error('SealDoc job failed', ['exception' => $e, 'fileId' => $fileId]);
		}
	}

	private function await(string $jobId): ?string {
		for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
			$job = $this->client->getJob($jobId);
			if ($job['status'] === 'completed') {
				return $this->client->download($jobId);
			}
			if ($job['status'] === 'failed') {
				$this->logger->error('SealDoc reported a failed conversion', [
					'jobId' => $jobId,
					'error' => $job['error'],
				]);
				return null;
			}
			sleep(self::POLL_SECONDS);
		}
		$this->logger->error('SealDoc job did not finish in time', ['jobId' => $jobId]);
		return null;
	}

	/** invoice.docx -> invoice-sealed.pdf */
	private function sealedName(string $original): string {
		$dot = strrpos($original, '.');
		$stem = $dot === false ? $original : substr($original, 0, $dot);
		return $stem . self::SEALED_SUFFIX;
	}
}
