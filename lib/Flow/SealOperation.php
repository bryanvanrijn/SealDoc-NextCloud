<?php

declare(strict_types=1);

namespace OCA\SealDoc\Flow;

use OCA\SealDoc\AppInfo\Application;
use OCA\SealDoc\BackgroundJob\SealJob;
use OCA\SealDoc\Service\SealDocClient;
use OCA\WorkflowEngine\Entity\File;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File as FileNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IRuleMatcher;
use OCP\WorkflowEngine\ISpecificOperation;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * The Flow action: "when a file matches these conditions, seal it".
 *
 * WHY THIS QUEUES INSTEAD OF SEALING RIGHT HERE. onEvent runs inside the
 * request that wrote the file. Converting a document, waiting for a timestamp
 * authority and downloading the result takes seconds at best and can take a
 * minute for a large scan. Doing that inline would hold the upload open for
 * that whole time, and a client that times out would look to the user like a
 * failed upload of a file that actually arrived. So this method does the cheap
 * part, deciding whether the rule applies, and hands the slow part to a
 * background job.
 *
 * The consequence is worth being honest about in the UI: sealing is not
 * instant, and the sealed file appears next to the original a little later.
 */
class SealOperation implements ISpecificOperation {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
		private IJobList $jobList,
		private SealDocClient $client,
		private LoggerInterface $logger,
	) {
	}

	public function getDisplayName(): string {
		return $this->l->t('Seal document with SealDoc');
	}

	public function getDescription(): string {
		return $this->l->t('Convert the file to PDF/A-3B and attach a hash chain, a chain-of-custody record and, where the SealDoc instance can reach a timestamping authority, an RFC 3161 timestamp. The sealed file is written next to the original, and the Seal panel reports per document what its seal actually contains.');
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}

	public function getEntityId(): string {
		return File::class;
	}

	public function isAvailableForScope(int $scope): bool {
		// Admin scope only, on purpose. The API key is a single instance-wide
		// credential that costs money to use; letting every user build their
		// own rules against it would spend someone else's quota.
		return $scope === IManager::SCOPE_ADMIN;
	}

	public function validateOperation(string $name, array $checks, string $operation): void {
		if (!$this->client->isConfigured()) {
			throw new UnexpectedValueException($this->l->t('Configure the SealDoc server URL and API key in the administration settings first.'));
		}
	}

	public function onEvent(string $eventName, Event $event, IRuleMatcher $ruleMatcher): void {
		if (!$event instanceof NodeCreatedEvent && !$event instanceof NodeWrittenEvent) {
			return;
		}

		$node = $event->getNode();
		if (!$node instanceof FileNode) {
			return;
		}

		// Never seal our own output. Without this the rule that seals
		// everything in a folder would seal the sealed file, and then seal
		// that, until the folder fills up.
		if (str_ends_with($node->getName(), SealJob::SEALED_SUFFIX)) {
			return;
		}

		if (count($ruleMatcher->getFlows(false)) === 0) {
			return;
		}

		try {
			$this->jobList->add(SealJob::class, [
				'fileId' => $node->getId(),
				'userId' => $node->getOwner()?->getUID(),
			]);
		} catch (\Throwable $e) {
			// A queue failure must not break the write that triggered it: the
			// user's file is already stored and their upload should succeed.
			$this->logger->error('Could not queue SealDoc job', ['exception' => $e]);
		}
	}
}
