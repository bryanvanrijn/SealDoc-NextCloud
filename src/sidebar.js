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

/**
 * The banner across the top: what this file is within the seal.
 *
 * Three files come out of one seal and they are not interchangeable. The pack
 * used to report "this document has not been sealed", because the lookup only
 * knew about two of them. Now that it knows about all three, saying which one
 * you are looking at is cheaper than making the reader work it out from the
 * filename.
 */
function roleBanner(info) {
	switch (info.role) {
	case 'evidence':
		return `<p class="sealdoc-role">${esc(t('sealdoc', 'This file is the evidence pack for a sealed document.'))}</p>`
	case 'source':
		return `<p class="sealdoc-role">${esc(t('sealdoc', 'This is the original. The sealed version is the file that carries the evidence.'))}</p>`
	default:
		return ''
	}
}

function render(el, info) {
	if (!info.sealed) {
		// Waiting and never-sealed are different answers. Collapsing them is
		// how a queued document that sat for half an hour, on an instance
		// running background jobs in ajax mode, looked exactly like an app
		// that had done nothing at all.
		if (!info.pending) {
			el.innerHTML = `<p class="sealdoc-empty">${esc(t('sealdoc', 'This document has not been sealed.'))}</p>`
			return
		}
		const waiting = [
			`<p class="sealdoc-when">${esc(t('sealdoc', 'Queued for sealing'))}</p>`,
			`<p class="sealdoc-fineprint">${esc(t('sealdoc', 'Sealing runs as a background job on the server. The sealed document and its evidence pack appear next to the original once it has run.'))}</p>`,
		]
		if (info.backgroundJobsReliable === false) {
			waiting.push(`<p class="sealdoc-warning">${esc(t('sealdoc', 'This server does not run background jobs on a schedule, so a queued document can wait a long time or never be picked up. An administrator can change this under Administration settings, Basic settings.'))}</p>`)
		}
		el.innerHTML = waiting.join('')
		return
	}

	const when = info.sealedAt ? new Date(info.sealedAt * 1000).toLocaleString() : '-'
	const parts = [roleBanner(info), `<p class="sealdoc-when">${esc(t('sealdoc', 'Sealed on'))} ${esc(when)}</p>`]

	// The headline before the detail. Somebody who opens this panel because
	// they are about to rely on the document should learn in the first line
	// whether they can, not have to scan four rows for a red cross.
	if (info.complete === false) {
		parts.push(`<p class="sealdoc-verdict sealdoc-verdict-gap">${esc(t('sealdoc', 'Sealed, but not everything this app promises was delivered.'))}</p>`)
	} else if (info.complete === null || info.complete === undefined) {
		parts.push(`<p class="sealdoc-verdict sealdoc-verdict-unknown">${esc(t('sealdoc', 'Sealed, but what the seal contains could not be read back.'))}</p>`)
	}

	parts.push('<ul class="sealdoc-list">')
	// The note repeats the validator's own verdict rather than announcing that
	// validation happened. It used to say "validated against ISO 19005-3"
	// whenever a verdict existed at all, which printed a reassuring line next
	// to a red cross on a document the validator had called non-compliant.
	parts.push(row(t('sealdoc', 'Archival format PDF/A-3B'), info.pdfa3b,
		info.pdfa3bValidation ? t('sealdoc', 'ISO 19005-3 validator: {verdict}', { verdict: info.pdfa3bValidation }) : ''))
	parts.push(row(t('sealdoc', 'Trusted timestamp (RFC 3161)'), info.timestamp,
		info.timestamp === false ? t('sealdoc', 'not attached to this document') : ''))
	parts.push(row(t('sealdoc', 'Chain of custody'), info.chainOfCustody))
	parts.push(row(t('sealdoc', 'Tamper-evident audit trail'), info.auditTrail))
	parts.push('</ul>')

	// Each consequence spelled out. Somebody deciding whether this file will
	// hold up needs to know what the gap costs, not the name of a JSON field.
	if (info.pdfa3b === false) {
		parts.push(`<p class="sealdoc-warning">${esc(t('sealdoc', 'The archival conversion did not produce a compliant PDF/A-3B. The file is named as sealed and its hashes still hold, but it does not meet the long-term format the seal is meant to guarantee.'))}</p>`)
	}
	if (info.timestamp === false) {
		parts.push(`<p class="sealdoc-warning">${esc(t('sealdoc', 'Without a trusted timestamp this document proves its own integrity, but not the moment it existed. A third party has only your word for the date.'))}</p>`)
	}
	if (info.chainOfCustody === false) {
		parts.push(`<p class="sealdoc-warning">${esc(t('sealdoc', 'No chain of custody was recorded, so the pack cannot show who handled this document or when.'))}</p>`)
	}
	if (info.auditTrail === false) {
		parts.push(`<p class="sealdoc-warning">${esc(t('sealdoc', 'No tamper-evident audit trail was recorded. Changes to the evidence itself would not be detectable.'))}</p>`)
	}
	if (!info.hasPassport) {
		parts.push(`<p class="sealdoc-warning">${esc(t('sealdoc', 'No compliance passport was stored for this seal, and none could be recovered from its evidence pack, so none of the rows above are confirmed.'))}</p>`)
	}

	if (info.retentionLabel) {
		// Labelled as the organisation's policy, never as law. SealDoc cannot
		// know what this document is or which jurisdiction it falls under.
		parts.push(`<h4 class="sealdoc-heading">${esc(t('sealdoc', 'Retention policy of this organisation'))}</h4>`)
		parts.push(`<p class="sealdoc-policy">${esc(info.retentionLabel)}</p>`)
		parts.push(`<p class="sealdoc-fineprint">${esc(t('sealdoc', 'Set by an administrator of this Nextcloud. SealDoc does not determine statutory retention periods.'))}</p>`)
	}

	// SealDoc's own words about what this evidence is worth, quoted rather than
	// summarised. The ledger says it in one sentence and the app has no business
	// improving on it.
	// A pack that opened and had no ledger in it. Six of seven packs from a live
	// SealDoc instance carried one; the seventh was a valid zip with a valid
	// passport, no hash chain and no verification keys, and nothing said so.
	if (info.ledgerPresent === false) {
		parts.push(`<p class="sealdoc-warning">${esc(t('sealdoc', 'This evidence pack contains no ledger, so it carries no hash chain and no verification keys. The document is sealed, but this pack on its own proves much less than a complete one. Sealing the document again should produce a full pack.'))}</p>`)
	}

	if (info.assuranceVerdict) {
		parts.push(`<h4 class="sealdoc-heading">${esc(t('sealdoc', 'What SealDoc says this evidence is worth'))}</h4>`)
		parts.push(`<p class="sealdoc-assurance"><code>${esc(info.assuranceVerdict)}</code></p>`)
		if (info.assuranceNote) {
			parts.push(`<p class="sealdoc-fineprint">${esc(info.assuranceNote)}</p>`)
		}
	}

	if (info.outputHash) {
		const state = (info.integrity && info.integrity.state) || 'unknown'
		const alg = (info.integrity && info.integrity.algorithm) || null
		parts.push(`<h4 class="sealdoc-heading">${esc(t('sealdoc', 'Fingerprint of the sealed document'))}</h4>`)
		parts.push(`<code class="sealdoc-hash">${esc(info.outputHash)}</code>`)
		// Recomputed over the bytes in Nextcloud, not copied from the passport.
		// A fingerprint nobody checks is decoration; this line is the difference
		// between a report about the past and a statement about the file you are
		// looking at.
		if (state === 'match') {
			parts.push(`<p class="sealdoc-verified">${esc(alg
				? t('sealdoc', 'Checked just now: the {algorithm} hash of the stored file still matches.', { algorithm: alg })
				: t('sealdoc', 'Checked just now: the hash of the stored file still matches.'))}</p>`)
		} else if (state === 'mismatch') {
			parts.push(`<p class="sealdoc-warning">${esc(t('sealdoc', 'The stored file no longer matches this fingerprint. It has been changed since it was sealed, and the evidence pack no longer describes it.'))}</p>`)
		} else if (state === 'gone') {
			parts.push(`<p class="sealdoc-warning">${esc(t('sealdoc', 'The sealed file could not be found, so the fingerprint could not be checked.'))}</p>`)
		} else if (state === 'unchecked') {
			parts.push(`<p class="sealdoc-fineprint">${esc(t('sealdoc', 'The file is too large to hash while you wait, so the fingerprint was not checked here.'))}</p>`)
		}
		if (info.ledgerHashAlgorithm) {
			parts.push(`<p class="sealdoc-fineprint">${esc(t('sealdoc', 'The evidence ledger chains its events with {algorithm}.', { algorithm: info.ledgerHashAlgorithm }))}</p>`)
		}
	}

	// The link points at the file you are NOT looking at, so the two halves of
	// the seal are always one click apart in either direction.
	if (info.role === 'evidence') {
		if (info.sealedFileId) {
			parts.push(`<p><a class="sealdoc-link" href="${esc(generateUrl('/f/{id}', { id: info.sealedFileId }))}">${esc(t('sealdoc', 'Open the sealed document'))}</a></p>`)
		}
	} else if (info.evidenceFileId) {
		parts.push(`<p><a class="sealdoc-link" href="${esc(generateUrl('/f/{id}', { id: info.evidenceFileId }))}">${esc(t('sealdoc', 'Open the evidence pack'))}</a></p>`)
	} else {
		parts.push(`<p class="sealdoc-fineprint">${esc(t('sealdoc', 'No evidence pack was stored. An administrator can switch that on in the SealDoc settings.'))}</p>`)
	}

	el.innerHTML = parts.filter(Boolean).join('')
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
