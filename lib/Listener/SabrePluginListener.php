<?php

declare(strict_types=1);

namespace OCA\SealDoc\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\SealDoc\Dav\SealedPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @template-implements IEventListener<SabrePluginAddEvent>
 */
class SabrePluginListener implements IEventListener {
	public function __construct(
		private SealedPlugin $plugin,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAddEvent) {
			return;
		}
		$event->getServer()->addPlugin($this->plugin);
	}
}
