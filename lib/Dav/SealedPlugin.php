<?php

declare(strict_types=1);

namespace OCA\SealDoc\Dav;

use OCA\DAV\Connector\Sabre\Node;
use OCA\SealDoc\Db\SealMapper;
use OCP\IUserSession;
use OCA\SealDoc\Service\SealFacts;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\INode;

/**
 * Exposes "is this file sealed" as a WebDAV property.
 *
 * WHY A DAV PROPERTY AND NOT AN ENDPOINT. The Files list decides per row
 * whether to draw the shield, and that decision has to be synchronous: the
 * file-action API has no way to await an answer. An endpoint would mean one
 * request per folder at best and a flash of missing shields at worst. A DAV
 * property rides along in the PROPFIND the list already makes, so the answer is
 * there before the first row is drawn.
 *
 * The properties are only computed when the client asks for them, which the
 * Files app does only when our own JavaScript has registered them. A client
 * that does not know about SealDoc pays nothing.
 */
class SealedPlugin extends ServerPlugin {
	public const NS = 'http://sealdoc.eu/ns';
	public const PROP_SEALED = '{' . self::NS . '}sealed';
	public const PROP_EVIDENCE_ID = '{' . self::NS . '}evidence-file-id';
	/**
	 * 'true', 'false' or 'unknown'. Three values and not a boolean, because a
	 * seal whose passport was never stored is not the same as one that came
	 * back with a guarantee missing, and the file list has to be able to tell
	 * them apart without asking a second time per row.
	 */
	public const PROP_COMPLETE = '{' . self::NS . '}complete';
	/** Which of the three files this is: source, sealed or evidence. */
	public const PROP_ROLE = '{' . self::NS . '}role';
	/** So the shield on an evidence pack can point at the document it proves. */
	public const PROP_SEALED_ID = '{' . self::NS . '}sealed-file-id';

	public function __construct(
		private SealMapper $mapper,
		private IUserSession $userSession,
	) {
	}

	public function initialize(Server $server): void {
		$server->xml->namespaceMap[self::NS] = 'sealdoc';
		$server->on('propFind', [$this, 'propFind']);
	}

	public function propFind(PropFind $propFind, INode $node): void {
		if (!$node instanceof Node) {
			return;
		}

		// Only touch the database when the client actually asked. Nextcloud
		// issues PROPFIND for every listing in the instance, including from
		// desktop and mobile clients that will never draw a shield.
		if ($propFind->getStatus(self::PROP_SEALED) === null
			&& $propFind->getStatus(self::PROP_EVIDENCE_ID) === null
			&& $propFind->getStatus(self::PROP_COMPLETE) === null
			&& $propFind->getStatus(self::PROP_ROLE) === null
			&& $propFind->getStatus(self::PROP_SEALED_ID) === null) {
			return;
		}

		$seal = null;
		try {
			$seal = $this->mapper->findByAnyFileId($node->getId());

			// The controller already refuses to tell a share recipient that a
			// seal exists, with a comment saying the evidence is the owner's to
			// hand over. This file had no such check at all and published
			// sealed, complete, role and two private file ids to anyone who
			// could PROPFIND the node. A shared file keeps the owner's fileid,
			// so the lookup matched for the recipient too, and the two surfaces
			// then contradicted each other on screen: a shield in the row, and
			// "this document has not been sealed" in the panel.
			$user = $this->userSession->getUser();
			if ($seal !== null && ($user === null || $seal->getUserId() !== $user->getUID())) {
				$seal = null;
			}
		} catch (\Throwable) {
			// A listing must never fail because of us. No answer is better
			// than a broken folder.
			return;
		}

		// Deliberately not repaired here. PassportReader::backfill can recover a
		// missing passport from the stored pack, but that is a file read and a
		// database write, and this method runs once per row in every listing in
		// the instance. 'unknown' is the honest answer in a file list; the panel
		// does the repair when somebody actually looks.
		$facts = $seal === null ? null : new SealFacts($seal);
		$fileId = $node->getId();

		$propFind->handle(self::PROP_SEALED, static fn () => $seal !== null ? 'true' : 'false');
		$propFind->handle(self::PROP_EVIDENCE_ID, static fn () => (string)($seal?->getEvidenceFileId() ?? 0));
		$propFind->handle(self::PROP_COMPLETE, static function () use ($facts) {
			if ($facts === null) {
				return 'false';
			}
			return match ($facts->isComplete()) {
				true => 'true',
				false => 'false',
				default => 'unknown',
			};
		});
		$propFind->handle(self::PROP_ROLE, static fn () => $facts?->roleOf($fileId) ?? 'none');
		$propFind->handle(self::PROP_SEALED_ID, static fn () => (string)($seal?->getSealedFileId() ?? 0));
	}
}
