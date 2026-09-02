/* SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The shield in the Files list.
 *
 * A folder of invoices should say at a glance which documents carry proof and
 * which do not. That is the whole reason this app has a build step: Nextcloud
 * removed the global OCA.Files.fileActions API, and the replacement lives in
 * @nextcloud/files, which has to be bundled.
 *
 * Two halves have to agree for this to work. The server contributes the
 * property (lib/Dav/SealedPlugin.php), and the client has to ASK for it, which
 * is what registerDavProperty below does. Register only one of the two and the
 * shield silently never appears, with nothing in any log to say why.
 */
import { registerFileAction, FileAction, registerDavProperty, Node } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const NS = 'http://sealdoc.eu/ns'

// Ask for our properties in the PROPFIND the Files list already makes. Without
// this the server never computes them, which is also why an unaware client
// pays nothing for this app being installed.
registerDavProperty('sealdoc:sealed', { sealdoc: NS })
registerDavProperty('sealdoc:evidence-file-id', { sealdoc: NS })

const isSealed = (node) => String(node?.attributes?.['sealdoc:sealed'] ?? 'false') === 'true'
const evidenceId = (node) => Number(node?.attributes?.['sealdoc:evidence-file-id'] ?? 0)

// Inline SVG rather than an icon component: one shield, no extra dependency,
// and it inherits the row's colour like every other inline action.
const shield = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
	<path d="M12 2 4 5v6.5c0 4.6 3.4 8.5 8 9.5 4.6-1 8-4.9 8-9.5V5l-8-3Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
	<path d="m8.5 12 2.6 2.6L16 9.7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`

registerFileAction(new FileAction({
	id: 'sealdoc-evidence',

	displayName: () => t('sealdoc', 'Sealed: open the evidence'),
	title: () => t('sealdoc', 'This document has been sealed. Open its evidence pack.'),
	iconSvgInline: () => shield,

	// Drawn on the row itself rather than hidden in the menu. A badge you have
	// to open a menu to discover is not a badge.
	inline: () => true,

	// Only on sealed files. enabled() is synchronous, which is exactly why the
	// answer arrives as a DAV property instead of a request.
	enabled: (nodes) => nodes.length === 1 && nodes[0] instanceof Node && isSealed(nodes[0]),

	async exec(node) {
		const id = evidenceId(node)
		if (!id) {
			// Sealed, but the pack was not stored: the administrator left
			// "Also store the evidence pack" off, or fetching it failed after
			// the seal succeeded. Say so rather than navigating nowhere.
			window.OCP?.Toast?.warning?.(t('sealdoc', 'This document is sealed, but no evidence pack was stored for it.'))
			return null
		}
		window.location.href = generateUrl('/f/{id}', { id })
		return null
	},

	order: -50,
}))
