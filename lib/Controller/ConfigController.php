<?php

declare(strict_types=1);

namespace OCA\SealDoc\Controller;

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
	) {
		parent::__construct($appName, $request);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function setConfig(?string $baseUrl = null, ?string $apiKey = null, ?bool $storeEvidence = null, ?string $evidenceFolder = null): DataResponse {
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

		return new DataResponse([
			'baseUrl' => $this->client->getBaseUrl(),
			'hasApiKey' => $this->client->hasApiKey(),
			'storeEvidence' => $this->client->isStoringEvidence(),
			'evidenceFolder' => $this->client->getEvidenceFolder(),
		]);
	}

	#[AuthorizedAdminSetting(settings: Admin::class)]
	public function test(): DataResponse {
		return new DataResponse($this->client->ping());
	}
}
