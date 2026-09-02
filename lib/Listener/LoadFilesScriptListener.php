<?php

declare(strict_types=1);

namespace OCA\SealDoc\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\SealDoc\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the client side of the app, and only inside the Files app.
 *
 * Not in the global scripts: the shield bundle is 286 KiB because it carries
 * @nextcloud/files, and there is no reason for someone reading a calendar to
 * download it.
 *
 * The order below is not cosmetic. addInitScript emits into the early group,
 * ahead of the Files bundles, which is the only place the DAV registration
 * can do its job; addScript emits after them, which is where everything that
 * draws belongs. js/dav-init.js explains what goes wrong when they swap.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadFilesScriptListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		Util::addInitScript(Application::APP_ID, 'dav-init');
		Util::addScript(Application::APP_ID, 'files-shield');
		Util::addScript(Application::APP_ID, 'sidebar');
		Util::addStyle(Application::APP_ID, 'admin');
	}
}
