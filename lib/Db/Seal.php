<?php

declare(strict_types=1);

namespace OCA\SealDoc\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row per sealed document.
 *
 * This table exists so the app can answer "is this file sealed, and where is
 * its evidence" without guessing from a filename. A naming convention would
 * have been cheaper, and it breaks the first time somebody renames a file,
 * which in a shared folder is a matter of days.
 *
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getJobId()
 * @method void setJobId(string $jobId)
 * @method int getSealedFileId()
 * @method void setSealedFileId(int $sealedFileId)
 * @method int getEvidenceFileId()
 * @method void setEvidenceFileId(int $evidenceFileId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method ?string getPassport()
 * @method void setPassport(?string $passport)
 * @method ?string getAssurance()
 * @method void setAssurance(?string $assurance)
 * @method int getSealedAt()
 * @method void setSealedAt(int $sealedAt)
 */
class Seal extends Entity {
	protected int $fileId = 0;
	protected string $jobId = '';
	protected int $sealedFileId = 0;
	protected int $evidenceFileId = 0;
	protected string $userId = '';
	protected int $sealedAt = 0;
	protected ?string $passport = null;
	/** The evidence ledger's own assurance block, verbatim JSON. */
	protected ?string $assurance = null;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('sealedFileId', 'integer');
		$this->addType('evidenceFileId', 'integer');
		$this->addType('sealedAt', 'integer');
	}
}
