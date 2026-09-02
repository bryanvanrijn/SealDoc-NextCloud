// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Hand-written rather than @nextcloud/webpack-vue-config, on purpose.
//
// That shared config is built for apps with Vue components, and it pulls in
// vue-loader, a node polyfill plugin and terser as undeclared peers. This app
// has one plain JavaScript entry with three imports and no Vue at all, so the
// shared config was three dependency conflicts and a broken build in exchange
// for nothing.
//
// The shield in the Files list is the only thing here that needs bundling:
// Nextcloud removed the global OCA.Files.fileActions API and the replacement
// lives in @nextcloud/files. Everything else in this app is PHP plus one
// hand-written script, which is why the rest can be copied into custom_apps
// and enabled without a toolchain.
const path = require('path')

module.exports = {
	entry: {
		'files-shield': path.join(__dirname, 'src', 'files-shield.js'),
	},
	output: {
		path: path.join(__dirname, 'js'),
		filename: '[name].js',
		clean: false,
	},
	resolve: {
		extensions: ['.js'],
		// Webpack 5 stopped shipping polyfills for Node built-ins, and
		// @nextcloud/files reaches for string_decoder through its WebDAV
		// layer. Listing the single module it actually needs rather than
		// pulling in a blanket node-polyfill plugin: this way the bundle only
		// carries what is used, and the day another built-in appears the build
		// says so instead of silently growing.
		fallback: {
			string_decoder: require.resolve('string_decoder/'),
			buffer: require.resolve('buffer/'),
		},
	},
	// Nextcloud serves these bundles directly; a source map keeps a stack trace
	// from a user's browser readable without shipping the sources inline.
	devtool: 'source-map',
}
