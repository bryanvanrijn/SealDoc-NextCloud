<?php

declare(strict_types=1);

namespace OCA\SealDoc\Migration;

use Closure;
use OCA\SealDoc\Db\SealMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000300Date20260902090000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(SealMapper::TABLE_NAME)) {
			return null;
		}
		$table = $schema->getTable(SealMapper::TABLE_NAME);
		if ($table->hasColumn('passport')) {
			return null;
		}

		// The compliance passport is stored verbatim rather than as parsed
		// columns. The panel's job is to report what the evidence says, not a
		// summary of it that could drift from the pack, and a raw document
		// survives SealDoc adding fields without a migration here.
		$table->addColumn('passport', Types::TEXT, ['notnull' => false]);

		return $schema;
	}
}
