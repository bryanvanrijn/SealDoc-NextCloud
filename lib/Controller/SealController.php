<?php

declare(strict_types=1);

namespace OCA\SealDoc\Controller;

use OCA\SealDoc\BackgroundJob\SealJob;
use OCA\SealDoc\Db\SealMapper;
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
	) {
		parent::__construct($appName, $request);
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
