<?php

declare(strict_types=1);

namespace OCA\SealDoc\Migration;

use Closure;
use OCA\SealDoc\Db\SealMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000100Date20260901120000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(SealMapper::TABLE_NAME)) {
			return null;
		}

		$table = $schema->createTable(SealMapper::TABLE_NAME);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
		$table->addColumn('job_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('sealed_file_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
		$table->addColumn('evidence_file_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('sealed_at', Types::BIGINT, ['notnull' => true, 'length' => 20, 'default' => 0]);

		$table->setPrimaryKey(['id']);
		// The Files list asks "is this one sealed" for every row it renders, so
		// both lookup columns are indexed. Without this the shield turns a
		// folder listing into a table scan per file.
		$table->addIndex(['file_id'], 'sealdoc_src_idx');
		$table->addIndex(['sealed_file_id'], 'sealdoc_out_idx');

		return $schema;
	}
}
