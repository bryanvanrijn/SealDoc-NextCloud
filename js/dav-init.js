/* SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Asks the Files app to include the two SealDoc WebDAV properties in its
 * directory listings. Nothing else. No imports, on purpose.
 *
 * WHY A SEPARATE FILE, AND WHY IT IS HAND-WRITTEN.
 *
 * The shield never drew on a hard refresh. Everything on the server was
 * right: the Sabre plugin registered, the property computed, and a PROPFIND
 * issued by hand returned sealed=true for exactly the right files. The one
 * request that mattered, the listing the Files app makes to fill the rows,
 * simply did not ask for them:
 *
 *   PROPFIND /remote.php/dav/files/<user>/      asked for sealdoc: no
 *   PROPFIND /remote.php/dav/files/<user>/x.md  asked for sealdoc: yes
 *
 * Scripts added through LoadAdditionalScriptsEvent are emitted last, after
 * the Files bundles. By the time registerDavProperty() ran inside the shield
 * bundle, the listing had already gone out. Later requests picked the
 * properties up, which is why this looked intermittent: navigating inside the
 * Files app made the shield appear and a refresh made it vanish again.
 *
 * Util::addInitScript puts a script in the early group instead. That group
 * blocks the first paint, so this file must stay small. Importing
 * registerDavProperty from @nextcloud/files pulled in 228 KiB, because that
 * package does not tree-shake down to one function. Writing the two lines by
 * hand costs well under a kilobyte.
 *
 * The registry is a plain global that @nextcloud/files seeds with the core
 * property list on first use. Two orderings are therefore possible and both
 * are handled: if the list already exists we append to it, and if it does not
 * we wait for whoever creates it. What we must never do is create it
 * ourselves, because an empty array would replace the core defaults and take
 * the whole file list down with it.
 */
(function () {
	'use strict'

	var NS = 'http://sealdoc.eu/ns'
	var PROPS = ['sealdoc:sealed', 'sealdoc:evidence-file-id']

	function add(list) {
		if (!Array.isArray(list)) {
			return
		}
		for (var i = 0; i < PROPS.length; i++) {
			if (list.indexOf(PROPS[i]) === -1) {
				list.push(PROPS[i])
			}
		}
		var ns = window._nc_dav_namespaces || {}
		ns.sealdoc = NS
		window._nc_dav_namespaces = ns
	}

	if (Array.isArray(window._nc_dav_properties)) {
		add(window._nc_dav_properties)
		return
	}

	// Not seeded yet. Hand the first writer their value back untouched and
	// append ours the moment it lands, then get out of the way: the property
	// is redefined as a plain value so nothing downstream keeps talking to an
	// accessor of ours.
	try {
		Object.defineProperty(window, '_nc_dav_properties', {
			configurable: true,
			enumerable: true,
			get: function () {
				return undefined
			},
			set: function (value) {
				Object.defineProperty(window, '_nc_dav_properties', {
					configurable: true,
					enumerable: true,
					writable: true,
					value: value,
				})
				add(value)
			},
		})
	} catch (e) {
		// A browser that will not let us do this still gets a working file
		// list; it only misses the shield. Failing loudly here would be worse.
	}
})()
