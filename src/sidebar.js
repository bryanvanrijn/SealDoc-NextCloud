/* SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The Seal panel in the file sidebar.
 *
 * Its job is to report what the evidence says, including the parts that are
 * missing. That is not a styling preference. A document can come back a valid
 * PDF/A-3B with a complete custody chain and NO timestamp, because that path
 * fails open, and nothing anywhere told anyone. A panel of green ticks would
 * have hidden exactly the fact an auditor came for.
 *
 * So a missing guarantee is rendered in red and named, an unknown one is
 * rendered as unknown, and neither is quietly rounded up to a tick.
 */
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const icon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20">
	<path d="M12 2 4 5v6.5c0 4.6 3.4 8.5 8 9.5 4.6-1 8-4.9 8-9.5V5l-8-3Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
	<path d="m8.5 12 2.6 2.6L16 9.7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
	'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]))

/** true -> yes, false -> no, null/undefined -> unknown. Three states, not two. */
function state(value) {
	if (value === true) {
		return { cls: 'sealdoc-yes', mark: '✓' }
	}
	if (value === false) {
		return { cls: 'sealdoc-no', mark: '✕' }
	}
	return { cls: 'sealdoc-unknown', mark: '?' }
}

function row(label, value, note) {
	const s = state(value)
	return `<li class="sealdoc-row ${s.cls}">
		<span class="sealdoc-mark" aria-hidden="true">${s.mark}</span>
		<span class="sealdoc-label">${esc(label)}</span>
		${note ? `<span class="sealdoc-note">${esc(note)}</span>` : ''}
	</li>`
}

function render(el, info) {
	if (!info.sealed) {
		el.innerHTML = `<p class="sealdoc-empty">${esc(t('sealdoc', 'This document has not been sealed.'))}</p>`
		return
	}

	const when = info.sealedAt ? new Date(info.sealedAt * 1000).toLocaleString() : '-'

	const parts = [`<p class="sealdoc-when">${esc(t('sealdoc', 'Sealed on'))} ${esc(when)}</p>`]

	parts.push('<ul class="sealdoc-list">')
	parts.push(row(t('sealdoc', 'Archival format PDF/A-3B'), info.pdfa3b,
		info.pdfa3bValidation ? t('sealdoc', 'validated against ISO 19005-3') : ''))
	parts.push(row(t('sealdoc', 'Trusted timestamp (RFC 3161)'), info.timestamp,
		info.timestamp === false ? t('sealdoc', 'not attached to this document') : ''))
	parts.push(row(t('sealdoc', 'Chain of custody'), info.chainOfCustody))
	parts.push(row(t('sealdoc', 'Tamper-evident audit trail'), info.auditTrail))
	parts.push('</ul>')

	// Spelled out rather than left to the reader of a red cross. Somebody
	// deciding whether this file will hold up needs the consequence, not the
	// field name.
	if (info.timestamp === false) {
		parts.push(`<p class="sealdoc-warning">${esc(t('sealdoc', 'Without a trusted timestamp this document proves its own integrity, but not the moment it existed. A third party has only your word for the date.'))}</p>`)
	}

	if (!info.hasPassport) {
		parts.push(`<p class="sealdoc-warning">${esc(t('sealdoc', 'No compliance passport was stored for this seal, so what it contains could not be read back.'))}</p>`)
	}

	if (info.retentionLabel) {
		// Labelled as the organisation's policy, never as law. SealDoc cannot
		// know what this document is or which jurisdiction it falls under.
		parts.push(`<h4 class="sealdoc-heading">${esc(t('sealdoc', 'Retention policy of this organisation'))}</h4>`)
		parts.push(`<p class="sealdoc-policy">${esc(info.retentionLabel)}</p>`)
		parts.push(`<p class="sealdoc-fineprint">${esc(t('sealdoc', 'Set by an administrator of this Nextcloud. SealDoc does not determine statutory retention periods.'))}</p>`)
	}

	if (info.outputHash) {
		parts.push(`<h4 class="sealdoc-heading">${esc(t('sealdoc', 'Fingerprint of the sealed document'))}</h4>`)
		parts.push(`<code class="sealdoc-hash">${esc(info.outputHash)}</code>`)
	}

	if (info.evidenceFileId) {
		parts.push(`<p><a class="sealdoc-link" href="${esc(generateUrl('/f/{id}', { id: info.evidenceFileId }))}">${esc(t('sealdoc', 'Open the evidence pack'))}</a></p>`)
	} else {
		parts.push(`<p class="sealdoc-fineprint">${esc(t('sealdoc', 'No evidence pack was stored. An administrator can switch that on in the SealDoc settings.'))}</p>`)
	}

	el.innerHTML = parts.join('')
}

function registerTab() {
	const Sidebar = window.OCA?.Files?.Sidebar
	if (!Sidebar || typeof Sidebar.registerTab !== 'function') {
		return false
	}

	Sidebar.registerTab(new Sidebar.Tab({
		id: 'sealdoc',
		name: t('sealdoc', 'Seal'),
		iconSvg: icon,

		async mount(el, fileInfo) {
			el.classList.add('sealdoc-tab')
			el.innerHTML = `<p class="sealdoc-empty">${esc(t('sealdoc', 'Loading...'))}</p>`
			try {
				const { data } = await axios.get(generateUrl('/apps/sealdoc/seal/{fileId}', { fileId: fileInfo.id }))
				render(el, data)
			} catch (e) {
				el.innerHTML = `<p class="sealdoc-warning">${esc(t('sealdoc', 'Could not load the seal details.'))}</p>`
			}
		},

		update() {},
		destroy() {},

		// Shown for every file, not only sealed ones. "This document has not
		// been sealed" is useful information; a tab that disappears leaves the
		// reader wondering whether they missed it.
		enabled: (fileInfo) => Boolean(fileInfo) && fileInfo.type !== 'dir',
	}))
	return true
}

// The sidebar is registered by the Files app, which may not have run yet.
if (!registerTab()) {
	window.addEventListener('DOMContentLoaded', registerTab)
}
