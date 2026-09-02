<?php

declare(strict_types=1);

namespace OCA\SealDoc\Settings;

use OCA\SealDoc\AppInfo\Application;
use OCA\SealDoc\Service\SealDocClient;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

class Admin implements IDelegatedSettings {
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
			'storeEvidence' => $this->client->isStoringEvidence(),
			'evidenceFolder' => $this->client->getEvidenceFolder(),
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

	/**
	 * Required by IDelegatedSettings, which the #[AuthorizedAdminSetting]
	 * attribute demands: it is typed class-string<IDelegatedSettings>, so
	 * pointing it at a class that only implements ISettings passes a type check
	 * and then rejects every request at runtime.
	 *
	 * Returning null for the name means the section is not offered for
	 * delegation to a non-admin group. That is the honest default here: the API
	 * key is one instance-wide credential that costs money to use, so handing
	 * its management to another group should be a deliberate change, not
	 * something that happens because this method returned a string.
	 */
	public function getName(): ?string {
		return null;
	}

	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
