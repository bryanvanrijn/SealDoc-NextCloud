<?php

declare(strict_types=1);

namespace OCA\SealDoc\Listener;

use OCA\SealDoc\Flow\SealOperation;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;

/**
 * Makes the seal action appear under Administration settings > Flow.
 *
 * There is no IRegistrationContext::registerWorkflowOperation(), despite how
 * plausible that sounds; the first version of this app called it and Nextcloud
 * logged "Call to undefined method" on every request while the app looked
 * perfectly healthy in the app list. Flow operations are registered by
 * listening for the event the workflow engine dispatches when it builds its
 * list.
 *
 * @template-implements IEventListener<RegisterOperationsEvent>
 */
class RegisterOperationsListener implements IEventListener {
	public function __construct(
		private SealOperation $operation,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof RegisterOperationsEvent) {
			return;
		}
		$event->registerOperation($this->operation);
	}
}
