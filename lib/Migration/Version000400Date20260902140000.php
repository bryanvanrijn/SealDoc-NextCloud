<?php

declare(strict_types=1);

namespace OCA\SealDoc\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Indexes the evidence file, because the lookup now matches on it.
 *
 * findByAnyFileId used to match the source and the sealed output, both of which
 * had an index, and quietly not the evidence pack. Opening the pack therefore
 * answered "not sealed". Adding the third column to that query without adding
 * its index would have turned every file listing in the instance into a table
 * scan, since the WebDAV plugin asks once per row.
 */
class Version000400Date20260902140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('sealdoc_seals');

		if (!$table->hasIndex('sealdoc_evi_idx')) {
			$table->addIndex(['evidence_file_id'], 'sealdoc_evi_idx');
		}

		return $schema;
	}
}
