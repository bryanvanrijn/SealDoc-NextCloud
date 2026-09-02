<?php

declare(strict_types=1);

namespace OCA\SealDoc\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Keeps the evidence ledger's own assurance verdict alongside the passport.
 *
 * The pack contains a ledger.json that states, in one sentence, how much the
 * evidence is worth. On the test instance every seal carried
 * "VALID-WITH-LOWER-ASSURANCE" and the note "No independent time anchor. The
 * chain is intact, but the time it happened rests on SealDoc's own clock only."
 *
 * That is the single most useful line in the whole pack and the app was showing
 * none of it. Stored verbatim rather than parsed into columns: it is SealDoc's
 * judgement, not ours, and paraphrasing somebody else's verdict is how a
 * compliance tool starts overclaiming.
 */
class Version000500Date20260902160000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('sealdoc_seals');

		if (!$table->hasColumn('assurance')) {
			$table->addColumn('assurance', 'text', ['notnull' => false]);
		}

		return $schema;
	}
}
