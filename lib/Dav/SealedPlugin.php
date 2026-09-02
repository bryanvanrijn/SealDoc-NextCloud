<?php

declare(strict_types=1);

namespace OCA\SealDoc\Dav;

use OCA\DAV\Connector\Sabre\Node;
use OCA\SealDoc\Db\SealMapper;
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

	public function __construct(
		private SealMapper $mapper,
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
			&& $propFind->getStatus(self::PROP_EVIDENCE_ID) === null) {
			return;
		}

		$seal = null;
		try {
			$seal = $this->mapper->findByAnyFileId($node->getId());
		} catch (\Throwable) {
			// A listing must never fail because of us. No answer is better
			// than a broken folder.
			return;
		}

		$propFind->handle(self::PROP_SEALED, static fn () => $seal !== null ? 'true' : 'false');
		$propFind->handle(self::PROP_EVIDENCE_ID, static fn () => (string)($seal?->getEvidenceFileId() ?? 0));
	}
}
