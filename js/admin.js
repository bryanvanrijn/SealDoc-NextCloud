/* SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Plain JavaScript on purpose. A Vue settings pane would look more like the
 * rest of Nextcloud, but it drags in a node toolchain and a build step for one
 * form with two fields. That cost is real for a small app that has to stay
 * compatible across three server releases a year. If this page ever grows, the
 * build step is the moment to reconsider, not before.
 */
(function () {
	'use strict'

	const state = OCP.InitialState.loadState('sealdoc', 'config')

	const url = document.getElementById('sealdoc-base-url')
	const key = document.getElementById('sealdoc-api-key')
	const keyState = document.getElementById('sealdoc-key-state')
	const result = document.getElementById('sealdoc-result')

	url.value = state.baseUrl || ''
	renderKeyState(state.hasApiKey)

	function renderKeyState(has) {
		keyState.textContent = has ? t('sealdoc', 'A key is stored') : t('sealdoc', 'No key stored')
	}

	function say(message) {
		result.textContent = message
	}

	document.getElementById('sealdoc-save').addEventListener('click', function () {
		const body = { baseUrl: url.value }
		// Only send the key when the field was actually filled in. An empty
		// field means "leave what is stored alone", not "delete it"; the
		// administrator who came here to fix a typo in the URL should not lose
		// their credential by saving.
		if (key.value !== '') {
			body.apiKey = key.value
		}
		say(t('sealdoc', 'Saving...'))
		axios.put(OC.generateUrl('/apps/sealdoc/config'), body)
			.then(function (response) {
				key.value = ''
				renderKeyState(response.data.hasApiKey)
				say(t('sealdoc', 'Saved'))
			})
			.catch(function () {
				say(t('sealdoc', 'Could not save'))
			})
	})

	document.getElementById('sealdoc-test').addEventListener('click', function () {
		say(t('sealdoc', 'Testing...'))
		axios.get(OC.generateUrl('/apps/sealdoc/config/test'))
			.then(function (response) {
				if (response.data.ok) {
					say(t('sealdoc', 'Server reachable'))
				} else if (response.data.reason === 'no_url') {
					say(t('sealdoc', 'Fill in a server URL first'))
				} else {
					say(t('sealdoc', 'Server not reachable'))
				}
			})
			.catch(function () {
				say(t('sealdoc', 'Server not reachable'))
			})
	})
})()
