/**
 * SPDX-FileCopyrightText: 2026 IB Trieb GmbH & Co. KG
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Workaround for a Windows-only bug in @nextcloud/vite-config (<= 2.5.4).
 *
 * REUSELicensesPlugin.findPackage() walks up the directory tree with
 * path.dirname() and only stops at '/', '.' or dirname(cwd()). Rollup hands it
 * POSIX-style paths ("D:/Dev/app/..."), while dirname(cwd()) is "D:\Dev", so the
 * comparison never matches and dirname('D:/') === 'D:/' recurses forever until
 * the build dies with "JavaScript heap out of memory".
 *
 * Adding a `dir === dirname(dir)` fixed-point guard stops the walk at any
 * filesystem root. No-op on non-Windows and when already patched.
 */
import { readFile, writeFile } from 'node:fs/promises'

const TARGET = 'node_modules/@nextcloud/vite-config/dist/plugins/REUSELicensesPlugin.js'
const NEEDLE = "if (!dir || dir === '/' || dir === '.' || dir === dirname(cwd())) {"
const FIXED = "if (!dir || dir === '/' || dir === '.' || dir === dirname(dir) || dir === dirname(cwd())) {"

if (process.platform !== 'win32') {
	process.exit(0)
}

let source
try {
	source = await readFile(TARGET, 'utf8')
} catch {
	// Dependency not installed (yet) - nothing to do.
	process.exit(0)
}

if (source.includes(FIXED)) {
	process.exit(0)
}

if (!source.includes(NEEDLE)) {
	console.warn(`[patch-vite-config-win] pattern not found in ${TARGET}, skipping. `
		+ 'If the production build runs out of memory, check whether the upstream bug was fixed.')
	process.exit(0)
}

await writeFile(TARGET, source.replace(NEEDLE, FIXED))
console.log('[patch-vite-config-win] applied root-directory guard to REUSELicensesPlugin')
