/* SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Two file actions in the Files list.
 *
 *   "Seal with SealDoc"          on anything not yet sealed
 *   the shield, "open evidence"  on anything that is
 *
 * The seal action exists because a Flow rule is the right answer for a process
 * and the wrong answer for the first five minutes. Somebody who has just
 * installed the app wants to try it on one file; telling them to go build a
 * workflow rule first is how an app gets uninstalled before it has done
 * anything. It doubles as the cheapest diagnostic there is: if this entry
 * appears in the row menu, this bundle loaded.
 *
 * Three parts have to agree for the shield. The server contributes the
 * property (lib/Dav/SealedPlugin.php), the client has to ask for it in time
 * (js/dav-init.js, and read the comment there before moving it back here) and
 * this file draws it. Break any one of them and the shield silently never
 * appears, with nothing in any log to say why.
 */
import { registerFileAction, FileAction } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const NS = 'http://sealdoc.eu/ns'

/**
 * Read one of our properties off a node.
 *
 * Tries both keyings on purpose. Depending on the version, the DAV layer hands
 * attributes back under the prefixed name or under the bare local name, and
 * guessing wrong produces a shield that never draws while every server-side
 * check reports healthy. Accepting both costs one line and removes a whole
 * class of silent failure.
 */
const attr = (node, name) => node?.attributes?.[`sealdoc:${name}`]
	?? node?.attributes?.[name]
	?? node?.attributes?.[`{${NS}}${name}`]

const isSealed = (node) => String(attr(node, 'sealed') ?? 'false') === 'true'
const evidenceId = (node) => Number(attr(node, 'evidence-file-id') ?? 0)
/**
 * Is this a file rather than a folder?
 *
 * Checked by value, not with `instanceof Node` and `FileType.File`. That was
 * the first version and it made "Seal with SealDoc" vanish from every row while
 * the shield, which has no such check, drew perfectly. One of the two imports
 * did not behave as assumed and there is no way to tell which from the outside:
 * the predicate just returns false and the entry silently never renders.
 *
 * A string comparison with a fallback on the mime type cannot fail that way.
 */
const isFile = (node) => {
	const type = String(node?.type ?? '')
	if (type === 'file') {
		return true
	}
	if (type === 'folder') {
		return false
	}
	return Boolean(node?.mime)
}

const shieldIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
	<path d="M12 2 4 5v6.5c0 4.6 3.4 8.5 8 9.5 4.6-1 8-4.9 8-9.5V5l-8-3Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
	<path d="m8.5 12 2.6 2.6L16 9.7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`

const sealIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
	<path d="M12 2 4 5v6.5c0 4.6 3.4 8.5 8 9.5 4.6-1 8-4.9 8-9.5V5l-8-3Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
	<path d="M12 8.5v5M9.5 11h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
</svg>`

const toast = (kind, message) => {
	const api = window.OCP?.Toast
	if (api && typeof api[kind] === 'function') {
		api[kind](message)
	} else {
		// Better a browser alert than a click that appears to do nothing.
		window.alert(message)
	}
}

registerFileAction(new FileAction({
	id: 'sealdoc-seal',
	displayName: () => t('sealdoc', 'Seal with SealDoc'),
	iconSvgInline: () => sealIcon,
	enabled: (nodes) => nodes.length === 1 && isFile(nodes[0]) && !isSealed(nodes[0]),

	async exec(node) {
		try {
			const { data } = await axios.post(generateUrl('/apps/sealdoc/seal/{fileId}', { fileId: node.fileid }))
			if (data.status === 'already_sealed') {
				toast('info', t('sealdoc', 'This document was already sealed.'))
			} else {
				// Honest about the delay rather than pretending it is done, and
				// it does not say "shortly". On an instance running background
				// jobs in ajax mode, which is Nextcloud's default, "shortly"
				// turned out to mean twenty-five minutes and counting. The Seal
				// panel says whether this server runs them on a schedule.
				toast('success', t('sealdoc', 'Queued for sealing. The sealed document appears next to the original once the server has run the job.'))
			}
			return true
		} catch (e) {
			const reason = e?.response?.data?.error
			if (reason === 'not_configured') {
				toast('error', t('sealdoc', 'SealDoc is not configured yet. An administrator has to set the server URL and API key.'))
			} else {
				toast('error', t('sealdoc', 'Could not queue this document for sealing.'))
			}
			return false
		}
	},

	order: 20,
}))

registerFileAction(new FileAction({
	id: 'sealdoc-evidence',
	displayName: () => t('sealdoc', 'Sealed: open the evidence'),
	title: () => t('sealdoc', 'This document has been sealed. Open its evidence pack.'),
	iconSvgInline: () => shieldIcon,

	// Drawn on the row itself. A badge you have to open a menu to discover is
	// not a badge.
	inline: () => true,
	enabled: (nodes) => nodes.length === 1 && isSealed(nodes[0]),

	async exec(node) {
		const id = evidenceId(node)
		if (!id) {
			// Sealed, but no pack stored: either the administrator left
			// "Also store the evidence pack" off, or fetching it failed after
			// the seal itself succeeded. Say so rather than navigating nowhere.
			toast('warning', t('sealdoc', 'This document is sealed, but no evidence pack was stored for it.'))
			return null
		}
		window.location.href = generateUrl('/f/{id}', { id })
		return null
	},

	order: -50,
}))
