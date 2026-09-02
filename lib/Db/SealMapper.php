<?php

declare(strict_types=1);

namespace OCA\SealDoc\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Seal>
 */
class SealMapper extends QBMapper {
	public const TABLE_NAME = 'sealdoc_seals';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, Seal::class);
	}

	/**
	 * Any row for this source, successful or failed.
	 *
	 * Callers that mean "is this already sealed" must check the state; a failed
	 * attempt is a row too now, and treating it as a seal is how a document
	 * that could not be sealed becomes permanently unsealable.
	 */
	public function findBySourceFileId(int $fileId): ?Seal {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Every row that mentions this file, in any of its three roles.
	 *
	 * Used when a file is deleted. A seal whose output no longer exists is not
	 * a seal, and leaving the row behind made the panel keep asserting one, the
	 * shield keep drawing, the evidence link resolve to nothing, and the source
	 * permanently unsealable with no route back.
	 *
	 * @return list<Seal>
	 */
	public function findAllTouching(int $fileId): array {
		$qb = $this->db->getQueryBuilder();
		$p = $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT);
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->orX(
				$qb->expr()->eq('sealed_file_id', $p),
				$qb->expr()->eq('file_id', $p),
				$qb->expr()->eq('evidence_file_id', $p),
			));
		return $this->findEntities($qb);
	}

	/**
	 * Looks up a seal by any of the three files it involves.
	 *
	 * All three, and the third one was missing. A seal touches the original,
	 * the sealed output written next to it, and the evidence pack filed
	 * elsewhere. Matching only the first two meant that opening the pack, the
	 * one file whose entire reason for existing is to prove the seal, answered
	 * "this document has not been sealed".
	 *
	 * Ordered so the sealed output wins if a file id somehow matches more than
	 * one column, because that is the file a person is usually looking at.
	 */
	public function findByAnyFileId(int $fileId): ?Seal {
		$qb = $this->db->getQueryBuilder();
		$p = $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT);
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->andX(
				$qb->expr()->eq('state', $qb->createNamedParameter(Seal::STATE_SEALED)),
				$qb->expr()->orX(
					$qb->expr()->eq('sealed_file_id', $p),
					$qb->expr()->eq('file_id', $p),
					$qb->expr()->eq('evidence_file_id', $p),
				),
			))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
