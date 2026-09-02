<?php

declare(strict_types=1);

namespace OCA\SealDoc\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Gives a seal attempt somewhere to record that it failed.
 *
 * Until now a row appeared only on success. Every other outcome left nothing at
 * all: the job aborted, the panel said "This document has not been sealed", and
 * that is the same sentence a file nobody ever touched gets. A user who clicked
 * and got a green "queued" toast could not tell "it is coming" from "it was
 * tried and lost", and neither could the administrator they asked.
 *
 * The widest funnel is a wrong or revoked API key: it passed the click-time
 * check and the settings test button, then failed inside every job forever, one
 * log line each.
 *
 * 'sealed' is the default so every existing row keeps its meaning.
 */
class Version000600Date20260902180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('sealdoc_seals');

		if (!$table->hasColumn('state')) {
			$table->addColumn('state', 'string', [
				'notnull' => true,
				'length' => 16,
				'default' => 'sealed',
			]);
		}

		if (!$table->hasColumn('error')) {
			// The reason, in the words the user will read. Not an exception
			// dump: the panel shows this, and a stack trace in a sidebar helps
			// nobody and leaks paths.
			$table->addColumn('error', 'string', [
				'notnull' => false,
				'length' => 255,
			]);
		}

		if (!$table->hasIndex('sealdoc_state_idx')) {
			$table->addIndex(['state'], 'sealdoc_state_idx');
		}

		return $schema;
	}
}
