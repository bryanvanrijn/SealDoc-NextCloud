<?php

declare(strict_types=1);

namespace OCA\SealDoc\Settings;

use OCA\SealDoc\AppInfo\Application;
use OCA\SealDoc\Service\SealDocClient;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private SealDocClient $client,
	) {
	}

	public function getForm(): TemplateResponse {
		// The key itself is never sent to the browser, only whether one is
		// set. An administrator does not need to read it back, and a settings
		// page that renders a live credential is a page that ends up in a
		// screenshot.
		$this->initialState->provideInitialState('config', [
			'baseUrl' => $this->client->getBaseUrl(),
			'hasApiKey' => $this->client->hasApiKey(),
		]);

		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');

		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
