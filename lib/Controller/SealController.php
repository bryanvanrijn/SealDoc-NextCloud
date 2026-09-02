<?php

declare(strict_types=1);

namespace OCA\SealDoc\Controller;

use OCA\SealDoc\BackgroundJob\SealJob;
use OCA\SealDoc\Db\SealMapper;
use OCA\SealDoc\Service\PassportReader;
use OCA\SealDoc\Service\QueueStatus;
use OCA\SealDoc\Service\SealFacts;
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
	/** Above this, the panel says "not checked" instead of reading the file. */
	private const VERIFY_MAX_BYTES = 64 * 1024 * 1024;

	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IJobList $jobList,
		private SealMapper $mapper,
		private SealDocClient $client,
		private QueueStatus $queue,
		private PassportReader $passports,
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

		// A seal written before this app stored passports has its answers
		// sitting in the evidence pack it wrote itself. Reading them back once
		// beats showing four question marks forever.
		$seal = $this->passports->backfill($seal);
		$facts = new SealFacts($seal);

		return new DataResponse([
			'sealed' => true,
			// Which of the three files this is. The pack used to answer "not
			// sealed" because the lookup did not know about it at all; now that
			// it does, saying "this is the evidence for that document" is both
			// true and more use than repeating the document's own panel.
			'role' => $facts->roleOf($fileId),
			'sealedAt' => $seal->getSealedAt(),
			'jobId' => $seal->getJobId(),
			'evidenceFileId' => $seal->getEvidenceFileId(),
			'sealedFileId' => $seal->getSealedFileId(),
			'sourceFileId' => $seal->getFileId(),
			// Null rather than false where the passport is silent: "we do not
			// know" and "it is not there" are different answers and the panel
			// renders them differently.
			'pdfa3b' => $facts->pdfA3b(),
			'pdfa3bValidation' => $facts->pdfA3bValidation(),
			'timestamp' => $facts->timestamp(),
			'timestampedAt' => $facts->timestampedAt(),
			'chainOfCustody' => $facts->chainOfCustody(),
			'auditTrail' => $facts->auditTrail(),
			'contentHash' => $facts->contentHash(),
			'outputHash' => $facts->outputHash(),
			'hasPassport' => $facts->hasPassport(),
			// SealDoc's own verdict on this evidence, from the ledger inside the
			// pack, passed through untouched. It is one sentence and it is the
			// most useful thing in the whole archive.
			'assuranceVerdict' => $facts->assuranceVerdict(),
			'assuranceNote' => $facts->assuranceNote(),
			'ledgerHashAlgorithm' => $facts->ledgerHashAlgorithm(),
			'ledgerPresent' => $facts->ledgerPresent(),
			// Recomputed here and now, over the bytes actually in Nextcloud.
			'integrity' => $this->verify($seal->getSealedFileId(), $facts->outputHash()),
			// True, false or null, and the caller must keep all three. This is
			// what decides whether the file list draws a plain shield or one
			// with a warning on it.
			'complete' => $facts->isComplete(),
			'missing' => $facts->missing(),
			'retentionLabel' => $this->client->getRetentionLabel(),
		]);
	}

	/**
	 * Hash the sealed file as it is stored right now and compare.
	 *
	 * WHY THE PANEL DOES THIS RATHER THAN JUST PRINTING THE NUMBER. A
	 * fingerprint nobody checks is decoration. Recomputing it turns the panel
	 * from a report about the past into a statement about the file in front of
	 * you: if somebody edited the sealed PDF in place, this is what says so.
	 *
	 * The algorithm is not declared anywhere in the passport, so it is found by
	 * trying the plausible ones rather than assumed. That also means the answer
	 * survives SealDoc changing it.
	 *
	 * @return array{state: string, algorithm: ?string}
	 */
	private function verify(int $sealedFileId, ?string $expected): array {
		if ($expected === null || $sealedFileId === 0) {
			return ['state' => 'unknown', 'algorithm' => null];
		}
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return ['state' => 'unknown', 'algorithm' => null];
			}
			$node = $this->rootFolder->getUserFolder($user->getUID())->getById($sealedFileId)[0] ?? null;
			if (!$node instanceof File) {
				return ['state' => 'gone', 'algorithm' => null];
			}
			// Reading a whole file into memory to answer a sidebar panel has a
			// limit. Past it the honest answer is "not checked", not a guess and
			// not an out-of-memory error in somebody's file list.
			if ($node->getSize() > self::VERIFY_MAX_BYTES) {
				return ['state' => 'unchecked', 'algorithm' => null];
			}
			$bytes = $node->getContent();
			foreach (['sha256' => 'SHA-256', 'sha384' => 'SHA-384', 'sha512' => 'SHA-512'] as $alg => $label) {
				if (hash_equals($expected, hash($alg, $bytes))) {
					return ['state' => 'match', 'algorithm' => $label];
				}
			}
			return ['state' => 'mismatch', 'algorithm' => null];
		} catch (\Throwable) {
			return ['state' => 'unknown', 'algorithm' => null];
		}
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
