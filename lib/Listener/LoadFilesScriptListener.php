<?php

declare(strict_types=1);

namespace OCA\SealDoc\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\SealDoc\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the shield bundle, and only inside the Files app.
 *
 * Not in the global scripts: this bundle is 424 KiB because it carries
 * @nextcloud/files, and there is no reason for someone reading a calendar to
 * download it.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadFilesScriptListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		Util::addScript(Application::APP_ID, 'files-shield');
	}
}
