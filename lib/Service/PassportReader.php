<?php

declare(strict_types=1);

namespace OCA\SealDoc\Service;

use OCA\SealDoc\Db\Seal;
use OCA\SealDoc\Db\SealMapper;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/**
 * Gets the compliance passport out of an evidence pack.
 *
 * Two callers, one reader. The background job reads it out of the bytes it just
 * downloaded; the panel reads it out of a pack already stored in Nextcloud,
 * for seals written by a version of this app that did not keep it.
 *
 * That second path is a repair, not a feature. Without it, every document
 * sealed before the passport column existed renders as four question marks
 * forever, even though the answer is sitting in a zip file the app wrote
 * itself, two folders away.
 */
class PassportReader {
	/** Names to try, in order. The first is what SealDoc writes today. */
	private const CANDIDATES = [
		'compliance_passport.json',
		'compliance-passport.json',
		'passport.json',
	];

	public function __construct(
		private IRootFolder $rootFolder,
		private SealMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Pull the ledger's assurance block out of a pack held in memory.
	 *
	 * ledger.json states what the evidence is worth in one sentence, in
	 * SealDoc's own words. Kept verbatim: paraphrasing somebody else's verdict
	 * is how a compliance tool starts overclaiming.
	 */
	public function assuranceFromBytes(string $pack): ?string {
		$json = $this->fileFromBytes($pack, ['ledger.json'], '/(^|\/)ledger\.json$/i');
		if ($json === null) {
			// The pack opened and had no ledger in it. That is not "unknown",
			// it is a fact about this pack and a serious one, so it is recorded
			// rather than treated as a failure to read.
			//
			// Measured on a live SealDoc instance: six of seven packs contained
			// ledger.json and public-keys.jwk, and one did not. The odd one out
			// was a valid zip with a valid passport and no hash chain and no
			// verification keys, and nothing anywhere said so.
			return json_encode(['ledgerPresent' => false]) ?: null;
		}
		$ledger = json_decode($json, true);
		if (!is_array($ledger)) {
			return null;
		}
		$assurance = $ledger['assurance'] ?? null;
		if (!is_array($assurance)) {
			$assurance = [];
		}
		$assurance['ledgerPresent'] = true;
		// The hash algorithm lives one level up and belongs with the verdict:
		// the panel would otherwise show a fingerprint without saying what it
		// is a fingerprint of.
		if (isset($ledger['hashAlgorithm']) && is_string($ledger['hashAlgorithm'])) {
			$assurance['hashAlgorithm'] = $ledger['hashAlgorithm'];
		}
		$encoded = json_encode($assurance);
		return $encoded === false ? null : $encoded;
	}

	/**
	 * Pull the passport out of a pack held in memory.
	 *
	 * Tries a few names and then, if none match, any *_passport.json anywhere
	 * in the archive. A pack whose layout changed one release is not a reason
	 * to show a user four question marks.
	 */
	public function fromBytes(string $pack): ?string {
		return $this->fileFromBytes($pack, self::CANDIDATES, '/(^|\/)[a-z_-]*passport\.json$/i');
	}

	/**
	 * One entry out of a zip: the named candidates first, then a pattern.
	 *
	 * @param list<string> $candidates
	 */
	private function fileFromBytes(string $pack, array $candidates, string $pattern): ?string {
		$tmp = tempnam(sys_get_temp_dir(), 'sealdoc');
		if ($tmp === false) {
			return null;
		}
		try {
			file_put_contents($tmp, $pack);
			$zip = new \ZipArchive();
			if ($zip->open($tmp) !== true) {
				return null;
			}
			try {
				foreach ($candidates as $name) {
					$json = $zip->getFromName($name);
					if ($json !== false && $json !== '') {
						return $json;
					}
				}
				for ($i = 0; $i < $zip->numFiles; $i++) {
					$name = (string)$zip->getNameIndex($i);
					if (preg_match($pattern, $name) === 1) {
						$json = $zip->getFromIndex($i);
						if ($json !== false && $json !== '') {
							$this->logger->info('SealDoc found a pack entry under an unexpected name', ['name' => $name]);
							return $json;
						}
					}
				}
			} finally {
				$zip->close();
			}
			return null;
		} catch (\Throwable $e) {
			$this->logger->warning('SealDoc could not read an entry from the evidence pack', ['exception' => $e]);
			return null;
		} finally {
			@unlink($tmp);
		}
	}

	/**
	 * Fill in a missing passport from the evidence pack already on disk, once.
	 *
	 * Returns the seal either way, so callers can use the result without
	 * checking whether a repair happened. A failure here is logged and
	 * swallowed: the panel then shows "not stored", which is the honest answer
	 * and was the answer before this method existed.
	 */
	public function backfill(Seal $seal): Seal {
		$needsPassport = $seal->getPassport() === null || $seal->getPassport() === '';
		$needsAssurance = $seal->getAssurance() === null || $seal->getAssurance() === '';
		if (!$needsPassport && !$needsAssurance) {
			return $seal;
		}
		if ($seal->getEvidenceFileId() === 0) {
			return $seal;
		}

		try {
			$nodes = $this->rootFolder->getUserFolder($seal->getUserId())->getById($seal->getEvidenceFileId());
			$pack = $nodes[0] ?? null;
			if (!$pack instanceof File) {
				return $seal;
			}
			$bytes = $pack->getContent();
			$json = $needsPassport ? $this->fromBytes($bytes) : null;
			$assurance = $needsAssurance ? $this->assuranceFromBytes($bytes) : null;
			if ($json === null && $assurance === null) {
				return $seal;
			}
			if ($json !== null) {
				$seal->setPassport($json);
			}
			if ($assurance !== null) {
				$seal->setAssurance($assurance);
			}
			$this->mapper->update($seal);
			$this->logger->info('SealDoc recovered evidence details from a stored pack', [
				'sealId' => $seal->getId(),
				'evidenceFileId' => $seal->getEvidenceFileId(),
				'passport' => $json !== null,
				'assurance' => $assurance !== null,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('SealDoc could not recover a compliance passport', [
				'exception' => $e,
				'sealId' => $seal->getId(),
			]);
		}

		return $seal;
	}
}
