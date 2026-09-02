<?php

declare(strict_types=1);

namespace OCA\SealDoc\BackgroundJob;

use OCA\SealDoc\Db\Seal;
use OCA\SealDoc\Db\SealMapper;
use OCA\SealDoc\Service\PassportReader;
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

	/**
	 * Above this, refuse instead of reading the file into memory.
	 *
	 * getContent() loads the whole thing. PHP's memory_limit is typically a few
	 * hundred megabytes and chunked uploads are not bounded by
	 * upload_max_filesize, so files past it exist. Exhausting memory is a FATAL
	 * and not a Throwable: the catch at the end of run() never sees it, the
	 * cron worker dies mid-run, and the log blames core rather than this app.
	 *
	 * SealController already guards the identical hazard on the cheaper path
	 * with the same reasoning.
	 */
	private const MAX_SOURCE_BYTES = 64 * 1024 * 1024;
	private const POLL_SECONDS = 3;

	public function __construct(
		ITimeFactory $time,
		private IRootFolder $rootFolder,
		private SealDocClient $client,
		private SealMapper $mapper,
		private PassportReader $passports,
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
			$this->recordFailure($fileId, $userId, 'not_configured');
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

			$existing = $this->mapper->findBySourceFileId($fileId);
			if ($existing !== null && $existing->getState() === Seal::STATE_SEALED) {
				return;
			}
			if ($existing !== null) {
				// A previous attempt failed and left a row saying so. This run
				// supersedes it; leaving both would make "has it been tried"
				// unanswerable.
				$this->mapper->delete($existing);
			}

			if ($node->getSize() > self::MAX_SOURCE_BYTES) {
				$this->logger->error('SealDoc refused a file larger than it can read into memory', [
					'fileId' => $fileId,
					'bytes' => $node->getSize(),
					'limit' => self::MAX_SOURCE_BYTES,
				]);
				$this->recordFailure($fileId, $userId, 'too_large');
				return;
			}

			// uniqueName, exactly as the evidence pack has always done. Sealing
			// used to abort with a bare return here, no log and no row, so the
			// user got a green "queued" toast on every retry forever and the
			// administrator asked to investigate found an empty log. The
			// collision is easy to reach: stem() strips everything after the
			// last dot, so report.docx and report.pdf both target
			// report-sealed.pdf.
			$parent = $node->getParent();
			$targetName = $this->uniqueName($parent, $this->sealedName($node->getName()), self::SEALED_SUFFIX);

			$jobId = $this->client->createJob($node->getContent(), $node->getName());
			$sealed = $this->await($jobId);
			if ($sealed === null) {
				// Timed out or came back failed. Either way this document was
				// attempted and lost, which is not the same as never touched.
				$this->recordFailure($fileId, $userId, 'sealdoc_failed', $jobId);
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

			$passport = $pack === null ? null : $this->passports->fromBytes($pack);
			$assurance = $pack === null ? null : $this->passports->assuranceFromBytes($pack);

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
			$seal->setAssurance($assurance);
			$seal->setSealedAt(time());
			$this->mapper->insert($seal);

			$this->logger->info('SealDoc sealed a document', [
				'fileId' => $fileId,
				'target' => $targetName,
				'evidenceStored' => $evidenceFileId !== 0,
			]);
		} catch (\Throwable $e) {
			// Not retried automatically. Re-uploading the same document on every
			// cron run turns one problem into a recurring bill, and a document
			// that fails conversion tends to keep failing.
			//
			// It IS recorded now. Logging alone meant the only person who could
			// see the failure was somebody already reading the log, while the
			// user was told "this document has not been sealed", which is the
			// sentence a file nobody ever touched gets.
			$this->logger->error('SealDoc job failed', ['exception' => $e, 'fileId' => $fileId]);
			$this->recordFailure($fileId, $userId, 'error');
		}
	}

	/**
	 * Records that this document was attempted and lost.
	 *
	 * A row, not just a log line. "Attempted and failed" and "never touched"
	 * rendered identically before this, so a user whose click was swallowed had
	 * no way to tell the difference and neither did the administrator they
	 * asked. The reason is a short code the panel translates; an exception
	 * message in a sidebar helps nobody and leaks paths.
	 *
	 * Best-effort by design: a failure to record a failure must not itself
	 * throw out of the job.
	 */
	private function recordFailure(int $fileId, string $userId, string $reason, ?string $jobId = null): void {
		try {
			$existing = $this->mapper->findBySourceFileId($fileId);
			if ($existing !== null) {
				if ($existing->getState() === Seal::STATE_SEALED) {
					return;
				}
				$this->mapper->delete($existing);
			}

			$seal = new Seal();
			$seal->setFileId($fileId);
			$seal->setJobId($jobId);
			$seal->setSealedFileId(0);
			$seal->setEvidenceFileId(0);
			$seal->setUserId($userId);
			$seal->setSealedAt(time());
			$seal->setState(Seal::STATE_FAILED);
			$seal->setError($reason);
			$this->mapper->insert($seal);
		} catch (\Throwable $e) {
			$this->logger->warning('SealDoc could not record a failed seal', [
				'exception' => $e,
				'fileId' => $fileId,
			]);
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

	/**
	 * Two documents with the same target name must not overwrite each other.
	 *
	 * The suffix is a parameter because both outputs need this and only one of
	 * them had it. The sealed document aborted on a collision instead, silently
	 * and forever.
	 */
	private function uniqueName(Folder $folder, string $name, string $suffix = self::EVIDENCE_SUFFIX): string {
		if (!$folder->nodeExists($name)) {
			return $name;
		}
		$stem = substr($name, 0, -strlen($suffix));
		for ($i = 2; $i < 1000; $i++) {
			$candidate = $stem . '-' . $i . $suffix;
			if (!$folder->nodeExists($candidate)) {
				return $candidate;
			}
		}
		return $stem . '-' . time() . $suffix;
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
