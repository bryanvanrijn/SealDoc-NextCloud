<?php

declare(strict_types=1);

namespace OCA\SealDoc\Service;

use OCA\SealDoc\Db\Seal;

/**
 * Reads one seal's compliance passport and answers what is actually in it.
 *
 * WHY THIS IS A CLASS AND NOT A FEW LINES IN THE CONTROLLER. Three surfaces need
 * the same answers and used to derive them separately: the sidebar panel, the
 * shield in the file list, and the WebDAV property behind the shield. Three
 * readings of the same JSON is three chances to disagree, and the one that
 * disagreed was the one nobody looked at.
 *
 * Everything here returns null for "the passport does not say", never false.
 * That distinction is the whole point of the panel: a document can come back a
 * valid PDF/A-3B with a complete custody chain and no timestamp at all, and it
 * can also come back from a version of this app that stored no passport, and
 * those two must not render the same.
 */
class SealFacts {
	/** @var array<string, mixed>|null */
	private ?array $passport;

	/** @var array<string, mixed> */
	private array $compliance;

	/** @var array<string, mixed> */
	private array $assurance;

	public function __construct(
		private Seal $seal,
	) {
		$decoded = json_decode((string)$seal->getPassport(), true);
		$this->passport = is_array($decoded) ? $decoded : null;
		$c = $this->passport['compliance'] ?? null;
		$this->compliance = is_array($c) ? $c : [];

		$a = json_decode((string)$seal->getAssurance(), true);
		$this->assurance = is_array($a) ? $a : [];
	}

	public function hasPassport(): bool {
		return $this->passport !== null;
	}

	public function pdfA3b(): ?bool {
		return $this->flag('pdfA3b');
	}

	/**
	 * The verdict string the validator returned, e.g. "compliant" or
	 * "non-compliant". Kept verbatim rather than folded into the boolean: the
	 * panel used to print "validated against ISO 19005-3" next to a red cross,
	 * because it only checked that a verdict existed and never read it.
	 */
	public function pdfA3bValidation(): ?string {
		$v = $this->compliance['pdfA3bValidation'] ?? null;
		return is_string($v) && $v !== '' ? $v : null;
	}

	public function timestamp(): ?bool {
		return $this->flag('rfc3161Timestamp');
	}

	public function timestampedAt(): ?string {
		$v = $this->passport['timestampedAt'] ?? null;
		return is_string($v) && $v !== '' ? $v : null;
	}

	public function chainOfCustody(): ?bool {
		return $this->flag('chainOfCustody');
	}

	public function auditTrail(): ?bool {
		return $this->flag('immutableAuditTrail');
	}

	/**
	 * SealDoc's own verdict on this evidence, from the ledger in the pack.
	 *
	 * Returned verbatim and never paraphrased. On every seal made on the test
	 * instance this read "VALID-WITH-LOWER-ASSURANCE", with a note explaining
	 * that the chain is intact but the time rests on SealDoc's own clock. That
	 * is the most useful sentence in the whole pack and the app was showing
	 * none of it.
	 */
	public function assuranceVerdict(): ?string {
		$v = $this->assurance['verdict'] ?? null;
		return is_string($v) && $v !== '' ? $v : null;
	}

	public function assuranceNote(): ?string {
		$v = $this->assurance['note'] ?? null;
		return is_string($v) && $v !== '' ? $v : null;
	}

	/** 'none', 'tsa', or whatever the ledger says. Not interpreted here. */
	public function timeAnchor(): ?string {
		$v = $this->assurance['timestamp'] ?? null;
		return is_string($v) && $v !== '' ? $v : null;
	}

	/**
	 * Did the evidence pack contain a ledger at all?
	 *
	 * True, false, or null where no pack has been read. False is the one that
	 * matters: a pack without ledger.json has no hash chain and no verification
	 * keys, which is most of what makes it evidence, and it is still a
	 * perfectly valid zip with a perfectly valid passport inside.
	 */
	public function ledgerPresent(): ?bool {
		$v = $this->assurance['ledgerPresent'] ?? null;
		if (is_bool($v)) {
			return $v;
		}
		// Rows written before the flag existed carry the assurance block but not
		// the flag. A verdict can only have come out of a ledger, so its
		// presence answers the question without re-reading the pack.
		return $this->assuranceVerdict() !== null ? true : null;
	}

	/** The algorithm the ledger chains with, e.g. "SHA-384". */
	public function ledgerHashAlgorithm(): ?string {
		$v = $this->assurance['hashAlgorithm'] ?? null;
		return is_string($v) && $v !== '' ? $v : null;
	}

	public function contentHash(): ?string {
		return $this->hash('contentHash');
	}

	public function outputHash(): ?string {
		return $this->hash('outputHash');
	}

	/**
	 * Did this seal deliver everything the app claims a seal delivers?
	 *
	 * Three answers, and the third one matters. True when every guarantee the
	 * passport reports is present. False when at least one is explicitly
	 * missing. Null when there is no passport to read, which is not the same as
	 * "fine" and must never be shown as such.
	 *
	 * This is what the file list uses to decide between a plain shield and a
	 * shield with a warning, so getting it wrong is how a document with a
	 * failed PDF/A conversion ends up looking exactly like one without.
	 */
	public function isComplete(): ?bool {
		if (!$this->hasPassport()) {
			return null;
		}
		foreach ([$this->pdfA3b(), $this->timestamp(), $this->chainOfCustody(), $this->auditTrail()] as $flag) {
			if ($flag !== true) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Which guarantees are explicitly absent, by passport field name.
	 *
	 * Named rather than counted, because "one guarantee is missing" is not
	 * something anybody can act on and "no trusted timestamp" is.
	 *
	 * @return list<string>
	 */
	public function missing(): array {
		$out = [];
		foreach ([
			'pdfA3b' => $this->pdfA3b(),
			'rfc3161Timestamp' => $this->timestamp(),
			'chainOfCustody' => $this->chainOfCustody(),
			'immutableAuditTrail' => $this->auditTrail(),
		] as $name => $flag) {
			if ($flag === false) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * What this particular file is within the seal.
	 *
	 * The evidence pack used to answer "this document has not been sealed",
	 * because the lookup matched the source and the output and forgot the third
	 * file it had written itself. Telling the three apart is also just better:
	 * the pack is not the document, and saying so avoids the impression that
	 * the zip is the thing an auditor should be handed alone.
	 */
	public function roleOf(int $fileId): string {
		if ($fileId === $this->seal->getSealedFileId()) {
			return 'sealed';
		}
		if ($fileId === $this->seal->getEvidenceFileId() && $this->seal->getEvidenceFileId() !== 0) {
			return 'evidence';
		}
		if ($fileId === $this->seal->getFileId()) {
			return 'source';
		}
		return 'unknown';
	}

	private function flag(string $name): ?bool {
		$v = $this->compliance[$name] ?? null;
		return is_bool($v) ? $v : null;
	}

	private function hash(string $name): ?string {
		$v = $this->passport[$name] ?? null;
		return is_string($v) && $v !== '' ? $v : null;
	}
}
