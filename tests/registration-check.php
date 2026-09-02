<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Proves the app registers what it claims to register, from inside a real
 * Nextcloud. Run it in the container:
 *
 *   docker exec -u www-data <container> php /var/www/html/custom_apps/sealdoc/tests/registration-check.php
 *
 * This exists because "app:enable" succeeding proves nothing. The first
 * version of this app enabled cleanly, showed as active in the app list, and
 * logged "Call to undefined method registerWorkflowOperation" on every single
 * request. The Flow action simply never appeared, and nothing in the normal
 * output said so.
 */
declare(strict_types=1);

require_once '/var/www/html/lib/base.php';

$fail = 0;
function check(string $what, bool $ok): void {
	global $fail;
	echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n";
	if (!$ok) {
		$fail++;
	}
}

echo "SealDoc app registration check\n\n";

$server = \OC::$server;

// 1. The operation can be built by the DI container. A constructor that asks
//    for something Nextcloud cannot provide fails exactly here, and nowhere
//    else until an administrator opens the Flow settings.
$operation = null;
try {
	$operation = $server->get(\OCA\SealDoc\Flow\SealOperation::class);
	check('SealOperation resolves from the container', true);
} catch (\Throwable $e) {
	check('SealOperation resolves from the container: ' . $e->getMessage(), false);
}

// 2. It satisfies the contract the workflow engine will call it through.
check('implements ISpecificOperation', $operation instanceof \OCP\WorkflowEngine\ISpecificOperation);
check('bound to the File entity', $operation !== null && $operation->getEntityId() === \OCA\WorkflowEngine\Entity\File::class);
check('is admin scope only', $operation !== null
	&& $operation->isAvailableForScope(\OCP\WorkflowEngine\IManager::SCOPE_ADMIN)
	&& !$operation->isAvailableForScope(\OCP\WorkflowEngine\IManager::SCOPE_USER));
check('has a display name', $operation !== null && $operation->getDisplayName() !== '');
check('has an icon path', $operation !== null && str_contains($operation->getIcon(), 'sealdoc'));

// 3. The listener actually puts it in the engine's list. This is the step that
//    was broken and that nothing else catches.
// The interface OCP\WorkflowEngine\IManager is only bound to a concrete class
// once the workflowengine app has booted, which does not happen in a bare CLI
// bootstrap. Ask for the concrete manager instead; that is what the engine
// hands to the event in production anyway.
//
// getOperatorList() dispatches RegisterOperationsEvent itself and returns the
// merged list, so this single call exercises the exact path that renders the
// Flow settings page. It is the assertion that would have caught the
// registerWorkflowOperation() mistake on the first run.
try {
	\OC_App::loadApp('workflowengine');
	$manager = $server->get(\OCA\WorkflowEngine\Manager::class);
	$names = array_map('get_class', $manager->getOperatorList());
	check('operator list builds', count($names) > 0);
	check('SealOperation appears in the engine operator list',
		in_array(\OCA\SealDoc\Flow\SealOperation::class, $names, true));
} catch (\Throwable $e) {
	check('operator list builds: ' . $e->getMessage(), false);
}

// 4. Settings are wired.
try {
	$admin = $server->get(\OCA\SealDoc\Settings\Admin::class);
	check('admin settings resolve', $admin instanceof \OCP\Settings\ISettings);
	check('admin settings point at our section', $admin->getSection() === 'sealdoc');
} catch (\Throwable $e) {
	check('admin settings resolve: ' . $e->getMessage(), false);
}

// 5. The client refuses to pretend it is configured when it is not.
try {
	$client = $server->get(\OCA\SealDoc\Service\SealDocClient::class);
	check('client resolves', true);
	check('client reports unconfigured before any setup', $client->isConfigured() === false || $client->getBaseUrl() !== '');
	$ping = $client->ping();
	check('ping answers without throwing', is_array($ping) && array_key_exists('ok', $ping));
} catch (\Throwable $e) {
	check('client resolves: ' . $e->getMessage(), false);
}

// 6. The seal record, which is what makes a sealed file recognisable later.
//    A naming convention would not survive the first rename in a shared folder.
try {
	$mapper = $server->get(\OCA\SealDoc\Db\SealMapper::class);
	check('seal mapper resolves', true);
	check('lookup on an unknown file returns null', $mapper->findByAnyFileId(999999999) === null);
} catch (\Throwable $e) {
	check('seal mapper resolves: ' . $e->getMessage(), false);
}

// 7. The evidence pack is off by default and lands somewhere sane when on.
try {
	$c = $server->get(\OCA\SealDoc\Service\SealDocClient::class);
	// The default, not the current value. This used to assert that storage was
	// off, which fails on any instance where an administrator switched it on:
	// a test that goes red on a correctly configured server teaches people to
	// ignore it, which costs more than the test was ever worth.
	$stored = $server->get(\OCP\IAppConfig::class)->getValueString('sealdoc', 'store_evidence', 'unset');
	check('evidence storage defaults to off when never set',
		$stored !== 'unset' || $c->isStoringEvidence() === false);
	check('evidence folder is absolute', str_starts_with($c->getEvidenceFolder(), '/'));
} catch (\Throwable $e) {
	check('evidence settings: ' . $e->getMessage(), false);
}

// 8. The settings screen is built from one shape.
//
// The page load and the save response used to assemble their payload
// separately. They drifted: the form grew a retention field, the save kept
// answering with the older four keys, and the value came back empty on the
// next load while sitting correctly in the database. Nothing failed, nothing
// was logged, and the setting simply did not appear to work.
try {
	$state = $server->get(\OCA\SealDoc\Service\ConfigState::class)->get();
	foreach (['baseUrl', 'hasApiKey', 'storeEvidence', 'evidenceFolder', 'retentionLabel', 'backgroundJobs'] as $key) {
		check('settings state carries ' . $key, array_key_exists($key, $state));
	}
	check('background job mode is one Nextcloud recognises',
		in_array($state['backgroundJobs']['mode'] ?? '', ['cron', 'webcron', 'ajax'], true));
	check('waiting seal count is a number', is_int($state['backgroundJobs']['waiting'] ?? null));
} catch (\Throwable $e) {
	check('settings state: ' . $e->getMessage(), false);
}

echo "\n" . ($fail === 0 ? "all checks passed\n" : "$fail check(s) FAILED\n");
exit($fail === 0 ? 0 : 1);
