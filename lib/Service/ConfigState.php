<?php

declare(strict_types=1);

namespace OCA\SealDoc\Service;

/**
 * Everything the settings screen renders, in one shape, in one place.
 *
 * Shared by the page load and by the save response. They used to build the
 * payload separately and drifted: the form gained a retention field, the save
 * kept returning the older four keys, and the value came back empty on the
 * next load while sitting correctly in the database. A setting that accepts
 * input and appears to discard it is worse than one that is not offered.
 */
class ConfigState {
	public function __construct(
		private SealDocClient $client,
		private QueueStatus $queue,
	) {
	}

	/** @return array<string, mixed> */
	public function get(): array {
		return [
			'baseUrl' => $this->client->getBaseUrl(),
			'hasApiKey' => $this->client->hasApiKey(),
			'storeEvidence' => $this->client->isStoringEvidence(),
			'evidenceFolder' => $this->client->getEvidenceFolder(),
			'retentionLabel' => $this->client->getRetentionLabel(),
			'backgroundJobs' => [
				'mode' => $this->queue->mode(),
				'reliable' => $this->queue->isReliable(),
				'waiting' => $this->queue->waiting(),
			],
		];
	}
}
