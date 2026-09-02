<?php

declare(strict_types=1);

namespace OCA\SealDoc\Service;

use OCA\SealDoc\BackgroundJob\SealJob;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;

/**
 * Answers "will a queued seal actually happen, and when".
 *
 * WHY THIS EXISTS. Sealing is a background job, and on a default Docker
 * Nextcloud nothing runs background jobs on a clock: the mode is "ajax", which
 * advances at most one job per page load, competing with every other job in
 * the instance. Two seals queued during testing sat untouched for twenty-five
 * minutes while the browser was open on the file list the whole time.
 *
 * From the outside that is indistinguishable from a broken app. The click
 * reports success, the file never appears, the log says nothing because
 * nothing ran. The app cannot fix an administrator's cron, but it can refuse
 * to let that be a mystery, which is what this class is for.
 */
class QueueStatus {
	public function __construct(
		private IJobList $jobList,
		private IConfig $config,
	) {
	}

	/**
	 * 'cron', 'webcron' or 'ajax'. Nextcloud's own default is 'ajax'.
	 */
	public function mode(): string {
		return (string)$this->config->getAppValue('core', 'backgroundjobs_mode', 'ajax');
	}

	/**
	 * Only 'cron' runs on a clock. 'webcron' does too, but only if somebody
	 * outside actually calls the URL, which is not something this app can see;
	 * it is reported as its own state rather than lumped in with either.
	 */
	public function isReliable(): bool {
		return $this->mode() === 'cron';
	}

	/** How many documents are waiting for a run that may never come. */
	public function waiting(): int {
		$counts = $this->jobList->countByClass();
		foreach ($counts as $row) {
			if (($row['class'] ?? null) === SealJob::class) {
				return (int)($row['count'] ?? 0);
			}
		}
		return 0;
	}

	/** Is this specific file waiting? Used by the panel, per file. */
	public function isWaitingFor(int $fileId, string $userId): bool {
		return $this->jobList->has(SealJob::class, ['fileId' => $fileId, 'userId' => $userId]);
	}
}
