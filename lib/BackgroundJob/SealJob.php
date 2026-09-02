<?php

declare(strict_types=1);

namespace OCA\SealDoc\BackgroundJob;

use OCA\SealDoc\Db\Seal;
use OCA\SealDoc\Db\SealMapper;
use OCA\SealDoc\Service\SealDocClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Does the slow half: upload, wait, write the results back into Nextcloud.
 *
 * Polling rather than webhooks, deliberately. A webhook would be faster and
 * cheaper, but it requires the Nextcloud instance to be reachable from the
 * internet. A large part of this app's audience runs Nextcloud behind their own
 * firewall precisely so that it is not. Polling works in both worlds; a webhook
 * would work in one and fail silently in the other.
 */
class SealJob extends QueuedJob {
	public const SEALED_SUFFIX = '-sealed.pdf';
	public const EVIDENCE_SUFFIX = '-evidence.zip';

	/** Cap the wait so a stuck job cannot occupy a cron slot forever. */
	private const MAX_ATTEMPTS = 40;
	private const POLL_SECONDS = 3;

	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private SealDocClient $client,
		private SealMapper $mapper,
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

			if ($this->mapper->findBySourceFileId($fileId) !== null) {
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

			// The sealed document goes next to the original, so it inherits the
			// folder, the sharing and the versioning the user already set up.
			$sealedFile = $parent->newFile($targetName, $sealed);

			// The evidence pack goes somewhere else, because it is a different
			// kind of object: nobody reads it, it exists to be handed over. Put
			// it in the working folder and every invoice directory doubles in
			// size, which is the complaint an administrator raises long before
			// the compliance benefit is noticed.
			// The pack is fetched either way, because the compliance passport
			// inside it is the only place that says whether a timestamp was
			// actually attached. Writing the zip to disk is the part that is
			// optional; knowing what the seal contains is not, or the panel
			// would have to guess and a panel that guesses is worse than none.
			$pack = null;
			try {
				$pack = $this->client->downloadEvidencePack($jobId);
			} catch (\Throwable $e) {
				$this->logger->warning('SealDoc could not fetch the evidence pack', [
					'exception' => $e,
					'jobId' => $jobId,
				]);
			}

			$passport = $pack === null ? null : $this->extractPassport($pack);

			$evidenceFileId = 0;
			if ($pack !== null && $this->client->isStoringEvidence()) {
				$evidenceFileId = $this->storeEvidence($userFolder, $pack, $node->getName());
			}

			$seal = new Seal();
			$seal->setFileId($fileId);
			$seal->setJobId($jobId);
			$seal->setSealedFileId($sealedFile->getId());
			$seal->setEvidenceFileId($evidenceFileId);
			$seal->setUserId($userId);
			$seal->setPassport($passport);
			$seal->setSealedAt(time());
			$this->mapper->insert($seal);

			$this->logger->info('SealDoc sealed a document', [
				'fileId' => $fileId,
				'target' => $targetName,
				'evidenceStored' => $evidenceFileId !== 0,
			]);
		} catch (\Throwable $e) {
			// Logged and dropped. Retrying automatically would re-upload the
			// same document on every cron run, and a document that fails
			// conversion tends to keep failing; that turns one problem into a
			// recurring bill.
			$this->logger->error('SealDoc job failed', ['exception' => $e, 'fileId' => $fileId]);
		}
	}

	/**
	 * Writes the evidence pack into the configured folder, foldered by year.
	 *
	 * Year subfolders are not decoration. Retention runs in years, and a single
	 * directory holding a decade of packs is one nobody can navigate and that
	 * some filesystems handle badly.
	 *
	 * @return int the file id of the stored pack, or 0 when it could not be stored
	 */
	private function storeEvidence(Folder $userFolder, string $pack, string $originalName): int {
		try {
			$folder = $this->ensureFolder($userFolder, $this->client->getEvidenceFolder() . '/' . date('Y'));

			$name = $this->uniqueName($folder, $this->evidenceName($originalName));
			return $folder->newFile($name, $pack)->getId();
		} catch (\Throwable $e) {
			// A missing pack must not undo a successful seal. The sealed
			// document is already written and is the thing the user asked for.
			$this->logger->error('SealDoc could not store the evidence pack', ['exception' => $e]);
			return 0;
		}
	}

	private function ensureFolder(Folder $userFolder, string $path): Folder {
		$parts = array_values(array_filter(explode('/', $path), static fn ($p) => $p !== ''));
		$current = $userFolder;
		foreach ($parts as $part) {
			try {
				$next = $current->get($part);
			} catch (NotFoundException) {
				$next = $current->newFolder($part);
			}
			if (!$next instanceof Folder) {
				throw new \RuntimeException('Evidence path is blocked by a file: ' . $path);
			}
			$current = $next;
		}
		return $current;
	}

	/** Two documents with the same name in one year must not overwrite each other. */
	private function uniqueName(Folder $folder, string $name): string {
		if (!$folder->nodeExists($name)) {
			return $name;
		}
		$stem = substr($name, 0, -strlen(self::EVIDENCE_SUFFIX));
		for ($i = 2; $i < 1000; $i++) {
			$candidate = $stem . '-' . $i . self::EVIDENCE_SUFFIX;
			if (!$folder->nodeExists($candidate)) {
				return $candidate;
			}
		}
		return $stem . '-' . time() . self::EVIDENCE_SUFFIX;
	}

	/**
	 * Pulls compliance_passport.json out of the pack.
	 *
	 * Stored verbatim. The panel reports what the evidence says; summarising it
	 * here would put a second version of the truth in the database, and the two
	 * would eventually disagree.
	 */
	private function extractPassport(string $pack): ?string {
		$tmp = tempnam(sys_get_temp_dir(), 'sealdoc');
		if ($tmp === false) {
			return null;
		}
		try {
			file_put_contents($tmp, $pack);
			$zip = new \ZipArchive();
			if ($zip->open($tmp) !== true) {
				return null;
			}
			$json = $zip->getFromName('compliance_passport.json');
			$zip->close();
			return $json === false ? null : $json;
		} catch (\Throwable $e) {
			$this->logger->warning('SealDoc could not read the compliance passport', ['exception' => $e]);
			return null;
		} finally {
			@unlink($tmp);
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
		return $this->stem($original) . self::SEALED_SUFFIX;
	}

	/** invoice.docx -> invoice-evidence.zip */
	private function evidenceName(string $original): string {
		return $this->stem($original) . self::EVIDENCE_SUFFIX;
	}

	private function stem(string $original): string {
		$dot = strrpos($original, '.');
		return $dot === false ? $original : substr($original, 0, $dot);
	}
}
