/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-FileCopyrightText: 2026 IB Trieb GmbH & Co. KG
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Node, View } from '@nextcloud/files'
import type { ProgressDialog, ProgressState } from '../components/ProgressDialog.ts'

import ArchiveArrowUpSvg from '@mdi/svg/svg/archive-arrow-up.svg?raw'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { FileAction, Folder, Permission } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'

/** How often the browser asks the server how far the extraction got. */
const POLL_INTERVAL = 700

/** OCS endpoints reject requests that do not identify themselves as API calls. */
const OCS_HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * Create the id both requests use to talk about the same extraction.
 *
 * randomUUID is only exposed on secure origins; the fallback keeps the feature
 * working on plain-HTTP test instances instead of breaking the whole action.
 */
function generateJobId(): string {
	if (typeof crypto?.randomUUID === 'function') {
		return crypto.randomUUID()
	}

	return `job-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`
}

/**
 * Poll the progress endpoint until told to stop.
 *
 * Failures are deliberately swallowed: a missing job (the extraction has not
 * published its first state yet, or it already finished and was cleaned up) and
 * a hiccup on the network are both no reason to tear down the dialog. The POST
 * that does the actual work decides the outcome.
 *
 * @param jobId Id shared with the execute call
 * @param dialog Dialog to render the state into
 * @param isCancelled Returns true once polling should stop
 */
async function pollProgress(
	jobId: string,
	dialog: ProgressDialog,
	isCancelled: () => boolean,
): Promise<void> {
	const url = generateOcsUrl('/apps/extract/api/v1/extraction/progress/{jobId}', { jobId })

	while (!isCancelled()) {
		await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL))

		if (isCancelled()) {
			return
		}

		try {
			const { data } = await axios.get(url, { headers: OCS_HEADERS })
			const state = data?.ocs?.data as ProgressState | undefined

			if (state?.phase === undefined) {
				continue
			}

			if (state.phase === 'error') {
				dialog.fail(state.error || t('extract', 'Oops something went wrong.'))
				return
			}

			dialog.update(state)
		} catch (error) {
			// Keep polling - see the note above.
		}
	}
}

export const extractAction = new FileAction({
	id: 'extract',
	displayName: () => t('extract', 'Extract here'),
	iconSvgInline: () => ArchiveArrowUpSvg,

	enabled(nodes: Node[]) {
		if (nodes.length !== 1) {
			return false
		}

		const node = nodes[0]!

		const ct = node.attributes?.getcontenttype as string | undefined

		if (
			ct === 'application/zip'
			|| ct === 'application/x-tar'
			|| ct === 'application/gzip'
			|| ct === 'application/x-rar-compressed'
			|| ct === 'application/x-7z-compressed'
			|| ct === 'application/x-deb'
			|| ct === 'application/x-bzip2'
		) {
			return (node.permissions & Permission.UPDATE) !== 0
		}

		return false
	},

	async exec(node: Node, view: View, dir: string) {
		const archiveName = (node.attributes?.basename ?? '') as string
		const jobId = generateJobId()

		// Pulled in on demand: the file action itself is loaded on every Files
		// page, the dialog is only needed once somebody extracts something.
		const { ProgressDialog } = await import('../components/ProgressDialog.ts')
		const dialog = new ProgressDialog()
		dialog.open(archiveName)

		let finished = false
		const polling = pollProgress(jobId, dialog, () => finished)

		try {
			const url = generateOcsUrl('/apps/extract/api/v1/extraction/execute')
			const { data } = await axios.post(url, {
				nameOfFile: archiveName,
				directory: dir,
				external: node.attributes?.['mount-type']?.startsWith('external') ? 1 : 0,
				mime: node.attributes?.mime,
				jobId,
			}, { headers: OCS_HEADERS })

			const result = data.ocs.data

			// The server reports a failed extraction in the payload, not with an
			// HTTP error, so this has to be checked explicitly.
			if (result.code !== 1 || result.extracted === undefined) {
				dialog.fail(result.desc || t('extract', 'Oops something went wrong.'))
				return false
			}

			const extracted = result.extracted
			const folder = new Folder({
				id: extracted.fileId,
				source: extracted.source,
				root: extracted.root,
				owner: extracted.owner,
				permissions: extracted.permissions,
				mtime: new Date(extracted.mtime * 1000),
				attributes: {
					'mount-type': extracted['mount-type'],
					'owner-id': extracted.owner,
					'owner-display-name': extracted['owner-display-name'],
				},
			})

			emit('files:node:created', folder)
			dialog.finish()

			// First argument is the route name - passing the parameters there
			// silently navigated nowhere.
			window.OCP.Files.Router.goToRoute(
				null,
				{ view: 'files', fileid: String(extracted.fileId) },
				{ dir },
			)

			return true
		} catch (error) {
			console.error('Could not send extract request.', error)
			dialog.fail(t('extract', 'Oops something went wrong.'))
			return false
		} finally {
			finished = true
			await polling
		}
	},

	order: 25,
})
