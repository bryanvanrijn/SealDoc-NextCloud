<?php

declare(strict_types=1);

namespace OCA\SealDoc\Controller;

use OCA\SealDoc\BackgroundJob\SealJob;
use OCA\SealDoc\Db\SealMapper;
use OCA\SealDoc\Service\QueueStatus;
use OCA\SealDoc\Service\SealDocClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Sealing a single document on request.
 *
 * A Flow rule covers "every invoice that lands in this folder", which is the
 * right answer for a process. It is the wrong answer for the first five
 * minutes: somebody who has just installed the app wants to try it on one file
 * and see what comes out, and telling them to go build a workflow rule first
 * is how an app gets uninstalled before it has done anything.
 */
class SealController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IJobList $jobList,
		private SealMapper $mapper,
		private SealDocClient $client,
		private QueueStatus $queue,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * What the sidebar panel shows.
	 *
	 * Everything here comes from the compliance passport that SealDoc produced,
	 * not from anything this app inferred. The distinction matters most in the
	 * unhappy case: a document can come back a valid PDF/A-3B with a complete
	 * custody chain and NO timestamp, because that path fails open. A panel
	 * that assumed the promise would show a green tick over a missing one.
	 */
	#[NoAdminRequired]
	public function info(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'not_logged_in'], Http::STATUS_UNAUTHORIZED);
		}

		$seal = $this->mapper->findByAnyFileId($fileId);
		if ($seal === null) {
			// Not sealed and waiting are different answers, and telling them
			// apart is the whole difference between "nothing happened" and
			// "this is on its way". A queued seal that sits for half an hour
			// because the instance runs background jobs in ajax mode looked
			// exactly like a broken app until the panel said so.
			return new DataResponse([
				'sealed' => false,
				'pending' => $this->queue->isWaitingFor($fileId, $user->getUID()),
				'backgroundJobsReliable' => $this->queue->isReliable(),
			]);
		}
		if ($seal->getUserId() !== $user->getUID()) {
			// Do not leak that a seal exists to somebody who only has the file
			// through a share; the evidence is the owner's to hand over.
			return new DataResponse(['sealed' => false]);
		}

		$passport = json_decode((string)$seal->getPassport(), true);
		$compliance = is_array($passport) ? ($passport['compliance'] ?? []) : [];

		return new DataResponse([
			'sealed' => true,
			'sealedAt' => $seal->getSealedAt(),
			'jobId' => $seal->getJobId(),
			'evidenceFileId' => $seal->getEvidenceFileId(),
			'sealedFileId' => $seal->getSealedFileId(),
			'sourceFileId' => $seal->getFileId(),
			// Null rather than false where the passport is missing: "we do not
			// know" and "it is not there" are different answers and the panel
			// renders them differently.
			'pdfa3b' => $compliance['pdfA3b'] ?? null,
			'pdfa3bValidation' => $compliance['pdfA3bValidation'] ?? null,
			'timestamp' => $compliance['rfc3161Timestamp'] ?? null,
			'timestampedAt' => is_array($passport) ? ($passport['timestampedAt'] ?? null) : null,
			'chainOfCustody' => $compliance['chainOfCustody'] ?? null,
			'auditTrail' => $compliance['immutableAuditTrail'] ?? null,
			'contentHash' => is_array($passport) ? ($passport['contentHash'] ?? null) : null,
			'outputHash' => is_array($passport) ? ($passport['outputHash'] ?? null) : null,
			'hasPassport' => is_array($passport),
			'retentionLabel' => $this->client->getRetentionLabel(),
		]);
	}

	#[NoAdminRequired]
	public function seal(int $fileId): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'not_logged_in'], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->client->isConfigured()) {
			return new DataResponse(['error' => 'not_configured'], Http::STATUS_PRECONDITION_FAILED);
		}

		$nodes = $this->rootFolder->getUserFolder($user->getUID())->getById($fileId);
		$node = $nodes[0] ?? null;
		if (!$node instanceof File) {
			return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->mapper->findBySourceFileId($fileId) !== null) {
			return new DataResponse(['status' => 'already_sealed']);
		}

		// Queued rather than done here, for the same reason the Flow action
		// queues: conversion and timestamping take seconds to a minute, and a
		// request that holds a browser open that long looks like a hang.
		$this->jobList->add(SealJob::class, [
			'fileId' => $fileId,
			'userId' => $user->getUID(),
		]);

		return new DataResponse(['status' => 'queued']);
	}
}
