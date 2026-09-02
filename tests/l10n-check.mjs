/* SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Compares the strings the code actually asks for against the strings each
 * language file actually has.
 *
 * WHY. Translations rot silently and in one direction: the English source
 * changes, the seven other files keep the old key, and every reader outside
 * English quietly gets English back with no error anywhere. The reverse rots
 * too, as dead keys nobody notices are stale.
 *
 * It also checks the two files per language against each other. l10n/x.json is
 * what the server reads and l10n/x.js is what the browser reads; when they
 * disagree the same string is translated in one place and not the other, which
 * is the kind of bug that only shows up in a screenshot.
 *
 *   node tests/l10n-check.mjs
 */
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

const ROOT = new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')
const SOURCES = [
	['src', /\.js$/],
	['js', /\.js$/],
	['templates', /\.php$/],
	['lib', /\.php$/],
]

/** Every file under dir matching re, recursively. */
function walk(dir, re, out = []) {
	let entries
	try {
		entries = readdirSync(join(ROOT, dir), { withFileTypes: true })
	} catch {
		return out
	}
	for (const e of entries) {
		const rel = dir + '/' + e.name
		if (e.isDirectory()) {
			walk(rel, re, out)
		} else if (re.test(e.name) && !/\.min\.js$|\.map$/.test(e.name)) {
			out.push(rel)
		}
	}
	return out
}

/**
 * Pull the literal out of t('sealdoc', '...'), n('sealdoc', '...', '...', n)
 * and $l->t('...').
 *
 * Only single-quoted literals, on purpose. A call whose text is built from a
 * variable cannot be extracted by anything, including the real gettext
 * tooling, so it is reported separately rather than silently dropped.
 */
function extract(text, file) {
	const used = new Set()
	const dynamic = []

	const q = "'((?:[^'\\\\]|\\\\.)*)'"
	const patterns = [
		new RegExp(`\\bt\\(\\s*'sealdoc'\\s*,\\s*${q}`, 'g'),
		new RegExp(`\\bn\\(\\s*'sealdoc'\\s*,\\s*${q}`, 'g'),
		new RegExp(`\\$l->t\\(\\s*${q}`, 'g'),
	]
	for (const re of patterns) {
		let m
		while ((m = re.exec(text)) !== null) {
			used.add(m[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\'))
		}
	}

	// Calls we could see but not read: a first argument that is not a literal.
	for (const re of [/\bt\(\s*'sealdoc'\s*,\s*[^'\s]/g, /\$l->t\(\s*[^'\s)]/g]) {
		let m
		while ((m = re.exec(text)) !== null) {
			dynamic.push(file + ':' + (text.slice(0, m.index).split('\n').length))
		}
	}

	return { used, dynamic }
}

const used = new Set()
const perFile = new Map()
const dynamic = []

for (const [dir, re] of SOURCES) {
	for (const file of walk(dir, re)) {
		const text = readFileSync(join(ROOT, file), 'utf8')
		const r = extract(text, file)
		// js/ holds webpack output as well as hand-written files. The bundles
		// are the same strings as their sources, and reading them back through
		// a minifier finds nothing, so they are skipped here entirely; the
		// staleness check below compares them as raw text instead.
		if (/^js\/(files-shield|sidebar|dav-init)\.js$/.test(file)) {
			continue
		}
		r.used.forEach((s) => used.add(s))
		perFile.set(file, r.used)
		dynamic.push(...r.dynamic)
	}
}

const langs = readdirSync(join(ROOT, 'l10n'))
	.filter((f) => f.endsWith('.json'))
	.map((f) => f.replace('.json', ''))
	.sort()

let fail = 0
const say = (ok, what) => {
	console.log((ok ? '  ok    ' : '  FAIL  ') + what)
	if (!ok) fail++
}

console.log('SealDoc l10n check\n')
console.log(`  ${used.size} translatable strings in the source, ${langs.length} languages: ${langs.join(', ')}\n`)

for (const lang of langs) {
	const json = JSON.parse(readFileSync(join(ROOT, 'l10n', lang + '.json'), 'utf8'))
	const tr = json.translations || {}
	const js = readFileSync(join(ROOT, 'l10n', lang + '.js'), 'utf8')

	const missing = [...used].filter((s) => !(s in tr))
	say(missing.length === 0, `${lang}: every source string is translated` +
		(missing.length ? ` (${missing.length} missing, first: ${JSON.stringify(missing[0]).slice(0, 90)})` : ''))

	const dead = Object.keys(tr).filter((k) => !used.has(k))
	say(dead.length === 0, `${lang}: no dead keys` +
		(dead.length ? ` (${dead.length}, first: ${JSON.stringify(dead[0]).slice(0, 90)})` : ''))

	// The .js is the same map wrapped in a register() call. Comparing the
	// parsed objects catches a hand-edit of one file and not the other.
	const inner = js.slice(js.indexOf('{'), js.lastIndexOf('}') + 1)
	let same = false
	try {
		same = JSON.stringify(JSON.parse(inner)) === JSON.stringify(tr)
	} catch { /* reported as a failure below */ }
	say(same, `${lang}: the .js and .json agree`)

	const declared = Number((js.match(/nplurals=(\d+)/) || [])[1] || 0)
	const wrong = Object.entries(tr)
		.filter(([, v]) => Array.isArray(v))
		.filter(([, v]) => v.length !== declared)
	say(declared > 0 && wrong.length === 0,
		`${lang}: plural arrays match nplurals=${declared}` +
		(wrong.length ? ` (${wrong.length} wrong)` : ''))
}

// A string used by the browser has to be inside the bundle the browser loads.
// A rebuild that did not happen is invisible until somebody reads a screenshot.
//
// Searched as raw text, not by parsing calls: the minifier renames t() and
// rewrites the quoting, so anything that looks for the call shape reports every
// string as missing and means nothing.
const BROWSER = [
	['src/sidebar.js', 'js/sidebar.js'],
	['src/files-shield.js', 'js/files-shield.js'],
]
const stale = []
for (const [src, out] of BROWSER) {
	const outText = readFileSync(join(ROOT, out), 'utf8')
	// Only the strings this file actually passes to t(), not every string that
	// happens to appear in it: the first version matched a phrase quoted inside
	// a comment and reported a bundle as stale that was perfectly current.
	for (const s of perFile.get(src) || []) {
		if (!outText.includes(s)) {
			stale.push(out + ': ' + JSON.stringify(s).slice(0, 60))
		}
	}
}
say(stale.length === 0, 'every browser string is in the built bundle' +
	(stale.length ? ` (${stale.length} stale, run npm run build; first ${stale[0]})` : ''))

say(dynamic.length === 0, `no translatable text built from variables` +
	(dynamic.length ? ` (${dynamic.length}: ${dynamic.slice(0, 3).join(', ')})` : ''))

console.log('\n' + (fail === 0 ? 'all checks passed' : fail + ' check(s) FAILED'))
process.exit(fail === 0 ? 0 : 1)
