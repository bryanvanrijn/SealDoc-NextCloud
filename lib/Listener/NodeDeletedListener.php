<?php

declare(strict_types=1);

namespace OCA\SealDoc\Listener;

use OCA\SealDoc\Db\Seal;
use OCA\SealDoc\Db\SealMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\FileInfo;
use Psr\Log\LoggerInterface;

/**
 * Keeps the seal table honest when a file goes away.
 *
 * WHY. Nothing ever deleted a seal row. Remove the sealed PDF and the panel kept
 * saying "Sealed on <date>", kept ticking four guarantees and kept offering a
 * link to evidence, all about a file that no longer existed. Worse, the source
 * became permanently unsealable: the row still answered "already sealed" and
 * the file list still hid "Seal with SealDoc", with no route back through the
 * UI. The likeliest way to get there is the obvious one, deleting a bad output
 * in order to try again.
 *
 * Three files, three different consequences, because they are not
 * interchangeable:
 *
 *   the sealed output   the seal is gone. Drop the row, so the original can be
 *                       sealed again.
 *   the evidence pack   the seal stands, the pack does not. Clear the id so the
 *                       panel stops linking into nothing.
 *   the original        the seal stands. The output and the pack still describe
 *                       exactly the bytes they always did.
 *
 * @template-implements IEventListener<NodeDeletedEvent>
 */
class NodeDeletedListener implements IEventListener {
	public function __construct(
		private SealMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof NodeDeletedEvent) {
			return;
		}

		$node = $event->getNode();
		if ($node->getType() !== FileInfo::TYPE_FILE) {
			return;
		}

		try {
			$fileId = $node->getId();
			foreach ($this->mapper->findAllTouching($fileId) as $seal) {
				$this->react($seal, $fileId);
			}
		} catch (\Throwable $e) {
			// A deletion must never fail because of us. The table then holds a
			// row it should not, which the panel's own resolution check catches.
			$this->logger->warning('SealDoc could not update its seals after a deletion', ['exception' => $e]);
		}
	}

	private function react(Seal $seal, int $fileId): void {
		if ($seal->getSealedFileId() === $fileId) {
			$this->mapper->delete($seal);
			$this->logger->info('SealDoc removed a seal because its sealed document was deleted', [
				'sealId' => $seal->getId(),
				'sourceFileId' => $seal->getFileId(),
			]);
			return;
		}

		if ($seal->getEvidenceFileId() === $fileId) {
			$seal->setEvidenceFileId(0);
			$this->mapper->update($seal);
			$this->logger->info('SealDoc cleared an evidence pack reference because the pack was deleted', [
				'sealId' => $seal->getId(),
			]);
		}
	}
}
