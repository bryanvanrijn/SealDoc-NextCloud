/* SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Plain JavaScript with fetch, no build step and no axios.
 *
 * The first version called axios directly, on the assumption that Nextcloud
 * exposes it globally. It does not in Nextcloud 30: the handler threw
 * ReferenceError right after setting the "Testing..." label, so the button
 * looked like it was working forever and nothing ever reached the server. The
 * giveaway was the access log, which showed no request at all.
 *
 * fetch plus the request token needs nothing that has to be bundled, which is
 * also why this file can be copied into place without a toolchain.
 */
(function () {
	'use strict'

	const state = OCP.InitialState.loadState('sealdoc', 'config')

	const url = document.getElementById('sealdoc-base-url')
	const key = document.getElementById('sealdoc-api-key')
	const store = document.getElementById('sealdoc-store-evidence')
	const folder = document.getElementById('sealdoc-evidence-folder')
	const retention = document.getElementById('sealdoc-retention')
	const keyState = document.getElementById('sealdoc-key-state')
	const result = document.getElementById('sealdoc-result')

	url.value = state.baseUrl || ''
	store.checked = !!state.storeEvidence
	folder.value = state.evidenceFolder || ''
	retention.value = state.retentionLabel || ''
	renderKeyState(state.hasApiKey)

	function renderKeyState(has) {
		keyState.textContent = has ? t('sealdoc', 'A key is stored') : t('sealdoc', 'No key stored')
	}

	function say(message) {
		result.textContent = message
	}

	function request(method, path, body) {
		return fetch(OC.generateUrl(path), {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'requesttoken': OC.requestToken,
				'OCS-APIREQUEST': 'true',
			},
			body: body === undefined ? undefined : JSON.stringify(body),
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('HTTP ' + response.status)
			}
			return response.json()
		})
	}

	document.getElementById('sealdoc-save').addEventListener('click', function () {
		const body = {
			baseUrl: url.value,
			storeEvidence: store.checked,
			evidenceFolder: folder.value,
			retentionLabel: retention.value,
		}
		// Only send the key when the field was actually filled in. An empty
		// field means "leave what is stored alone", not "delete it"; the
		// administrator who came here to fix a typo in the URL should not lose
		// their credential by saving.
		if (key.value !== '') {
			body.apiKey = key.value
		}
		say(t('sealdoc', 'Saving...'))
		request('PUT', '/apps/sealdoc/config', body)
			.then(function (data) {
				key.value = ''
				url.value = data.baseUrl || ''
				folder.value = data.evidenceFolder || ''
				retention.value = data.retentionLabel || ''
				store.checked = !!data.storeEvidence
				renderKeyState(data.hasApiKey)
				say(t('sealdoc', 'Saved'))
			})
			.catch(function (e) {
				say(t('sealdoc', 'Could not save') + ' (' + e.message + ')')
			})
	})

	document.getElementById('sealdoc-test').addEventListener('click', function () {
		say(t('sealdoc', 'Testing...'))
		request('GET', '/apps/sealdoc/config/test')
			.then(function (data) {
				if (data.ok) {
					say(t('sealdoc', 'Server reachable'))
				} else if (data.reason === 'no_url') {
					say(t('sealdoc', 'Fill in a server URL first'))
				} else {
					say(t('sealdoc', 'Server not reachable'))
				}
			})
			.catch(function (e) {
				say(t('sealdoc', 'Server not reachable') + ' (' + e.message + ')')
			})
	})
})()
