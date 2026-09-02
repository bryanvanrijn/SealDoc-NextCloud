<?php

declare(strict_types=1);

namespace OCA\SealDoc\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A failed attempt has no job id, so the column may be null.
 *
 * The failure row could not be written at all before this. Two things stacked:
 * job_id was NOT NULL, and Nextcloud's Entity skips a setter whose argument
 * equals the property's current value, so setJobId('') on a property that is
 * already '' never marks the field as updated and QBMapper::insert leaves it
 * out entirely. The insert then hit the constraint with a literal null:
 *
 *   SQLSTATE[23502]: null value in column "job_id" violates not-null constraint
 *
 * Nullable rather than a sentinel string. When SealDoc rejects the upload there
 * IS no job, and writing "none" or "" would be inventing one.
 */
class Version000700Date20260902200000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$column = $schema->getTable('sealdoc_seals')->getColumn('job_id');

		if ($column->getNotnull()) {
			$column->setNotnull(false);
		}

		return $schema;
	}
}
