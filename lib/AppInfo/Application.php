<?php

declare(strict_types=1);

namespace OCA\SealDoc\AppInfo;

use OCA\SealDoc\Listener\RegisterOperationsListener;
use OCA\SealDoc\Listener\LoadFilesScriptListener;
use OCA\SealDoc\Listener\SabrePluginListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'sealdoc';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// The Flow operation is the whole point of the app: it is what lets an
		// administrator say "every file that lands in /Invoices gets sealed"
		// without anybody having to remember to do it.
		//
		// It is registered by listening for the event the workflow engine
		// dispatches, NOT via a registerWorkflowOperation() on this context.
		// That method does not exist; calling it makes Nextcloud log "Call to
		// undefined method" on every single request while the app still shows
		// as enabled, which is a failure mode you only find by reading the log.
		$context->registerEventListener(RegisterOperationsEvent::class, RegisterOperationsListener::class);

		// Adds the "is this sealed" WebDAV property, which is what lets the
		// Files list draw a shield without asking a second time per folder.
		$context->registerEventListener(SabrePluginAddEvent::class, SabrePluginListener::class);

		// The shield itself, loaded only inside the Files app. It is the one
		// bundled asset in this app and it carries @nextcloud/files, so it has
		// no business being on every page.
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
