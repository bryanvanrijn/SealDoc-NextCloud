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
const sealedId = (node) => Number(attr(node, 'sealed-file-id') ?? 0)

/**
 * 'true', 'false' or 'unknown'. Kept as three values all the way to the icon.
 *
 * A document whose PDF/A conversion came back non-compliant, or that got no
 * timestamp, used to draw exactly the same shield as one that got everything.
 * That is the single worst thing this app could do: the shield is the whole
 * claim, and a claim that cannot be wrong is not worth reading.
 */
const completeness = (node) => {
	const v = String(attr(node, 'complete') ?? 'unknown')
	return v === 'true' || v === 'false' ? v : 'unknown'
}

/** 'source', 'sealed', 'evidence' or 'none'. */
const roleOf = (node) => String(attr(node, 'role') ?? 'none')
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

const SHIELD = 'M12 2 4 5v6.5c0 4.6 3.4 8.5 8 9.5 4.6-1 8-4.9 8-9.5V5l-8-3Z'

const wrap = (inner) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
	<path d="${SHIELD}" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
	${inner}
</svg>`

// A tick, an exclamation mark and a question mark. Three icons because there
// are three answers, and because somebody scanning a folder should be able to
// see which documents will not hold up without opening anything.
const shieldIcon = wrap('<path d="m8.5 12 2.6 2.6L16 9.7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>')
const shieldGapIcon = wrap('<path d="M12 7.5v5.2M12 16.2v.6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>')
// The original, which is part of a seal but carries none of it. An empty
// outline: present, and deliberately not a tick.
const shieldSourceIcon = wrap('<path d="M9 12.5h6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>')
const shieldUnknownIcon = wrap('<path d="M10.1 9.6a2 2 0 1 1 2.6 2.2c-.5.2-.8.7-.8 1.3v.4M12 16.3v.6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>')

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
	displayName: ([node]) => {
		if (roleOf(node) === 'evidence') {
			return t('sealdoc', 'Evidence pack: open the sealed document')
		}
		// The input file. It matches the seal, which is how you get from it to
		// the evidence, but it is not the artefact the evidence describes: it
		// was never converted and never hashed into the pack. Giving it the
		// same label as the output put "This document has been sealed" on an
		// unconverted .txt sitting right next to the PDF that really was.
		if (roleOf(node) === 'source') {
			return t('sealdoc', 'Open the sealed document')
		}
		switch (completeness(node)) {
		case 'false':
			return t('sealdoc', 'Sealed, with gaps: open the evidence')
		case 'unknown':
			return t('sealdoc', 'Sealed, contents unverified: open the evidence')
		default:
			return t('sealdoc', 'Sealed: open the evidence')
		}
	},
	title: ([node]) => {
		if (roleOf(node) === 'evidence') {
			return t('sealdoc', 'This is the evidence pack for a sealed document.')
		}
		if (roleOf(node) === 'source') {
			return t('sealdoc', 'This is the original. The sealed version is the file that carries the evidence.')
		}
		switch (completeness(node)) {
		case 'false':
			return t('sealdoc', 'This document was sealed, but at least one guarantee is missing. Open the Seal panel to see which.')
		case 'unknown':
			return t('sealdoc', 'This document was sealed, but what the seal contains could not be read back.')
		default:
			return t('sealdoc', 'This document has been sealed. Open its evidence pack.')
		}
	},
	iconSvgInline: ([node]) => {
		if (roleOf(node) === 'source') {
			return shieldSourceIcon
		}
		switch (completeness(node)) {
		case 'false':
			return shieldGapIcon
		case 'unknown':
			return shieldUnknownIcon
		default:
			return shieldIcon
		}
	},

	// Drawn on the row itself. A badge you have to open a menu to discover is
	// not a badge.
	inline: () => true,
	enabled: (nodes) => nodes.length === 1 && isSealed(nodes[0]),

	async exec(node) {
		// On the pack itself, "open the evidence" would reopen the file you are
		// already looking at, and on the original it would skip past the file
		// that actually carries the seal. Both are one step from the sealed
		// document, so both go there.
		if (roleOf(node) === 'evidence' || roleOf(node) === 'source') {
			const doc = sealedId(node)
			if (doc) {
				window.location.href = generateUrl('/f/{id}', { id: doc })
				return null
			}
			toast('warning', t('sealdoc', 'The sealed document this pack belongs to could not be found. It may have been deleted.'))
			return null
		}
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
