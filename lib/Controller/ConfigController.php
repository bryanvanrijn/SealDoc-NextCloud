<?php

declare(strict_types=1);

namespace OCA\SealDoc\Controller;

use OCA\SealDoc\Service\ConfigState;
use OCA\SealDoc\Service\SealDocClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCA\SealDoc\Settings\Admin;

class ConfigController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private SealDocClient $client,
		private ConfigState $state,
	) {
		parent::__construct($appName, $request);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function setConfig(?string $baseUrl = null, ?string $apiKey = null, ?bool $storeEvidence = null, ?string $evidenceFolder = null, ?string $retentionLabel = null): DataResponse {
		if ($baseUrl !== null) {
			$this->client->setBaseUrl($baseUrl);
		}
		if ($storeEvidence !== null) {
			$this->client->setStoringEvidence($storeEvidence);
		}
		if ($evidenceFolder !== null) {
			$this->client->setEvidenceFolder($evidenceFolder);
		}
		// An empty string means "clear the key". A null means "leave it
		// alone", which is what the form sends when the administrator only
		// changed the URL. Collapsing those two would silently wipe a working
		// credential on every unrelated save.
		if ($apiKey !== null) {
			$this->client->setApiKey($apiKey);
		}
		// Was missing, and silently. The form sent the value, nothing read it,
		// and the field came back empty on the next load while the column sat
		// there empty too. A setting that accepts input and discards it is
		// worse than one that is not offered at all.
		if ($retentionLabel !== null) {
			$this->client->setRetentionLabel($retentionLabel);
		}

		// The same shape the page load renders, so a save can never hand the
		// form a different set of fields than it was built from.
		return new DataResponse($this->state->get());
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function test(): DataResponse {
		return new DataResponse($this->client->ping());
	}
}
