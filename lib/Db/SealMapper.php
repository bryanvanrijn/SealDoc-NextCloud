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

	public function findBySourceFileId(int $fileId): ?Seal {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Looks up a seal by either of the two files it produced.
	 *
	 * The shield has to light up on the sealed document itself, which is the
	 * file a user actually looks at. Matching only the source would leave the
	 * one file that matters unmarked.
	 */
	public function findByAnyFileId(int $fileId): ?Seal {
		$qb = $this->db->getQueryBuilder();
		$p = $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT);
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->orX(
				$qb->expr()->eq('file_id', $p),
				$qb->expr()->eq('sealed_file_id', $p),
			))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
