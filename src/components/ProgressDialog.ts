/**
 * SPDX-FileCopyrightText: 2026 IB Trieb GmbH & Co. KG
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * State the dialog renders, mirroring what the progress endpoint returns.
 */
export interface ProgressState {
	phase: string
	percent: number
	currentFile: string
	archive: string
	filesDone: number
	filesTotal: number
	error: string
}

const STYLE_ID = 'extractplus-progress-dialog-style'

/*
 * Styles live here instead of in a stylesheet so the dialog stays a single
 * lazily loaded chunk: the file action is loaded on every Files page, but this
 * module is only fetched once somebody actually extracts something.
 *
 * Every colour is a Nextcloud design token, so the dialog follows the server
 * theme (including dark mode and custom primary colours) without knowing about it.
 */
const STYLES = `
.extractplus-progress-dialog {
	border: none;
	border-radius: var(--border-radius-large, 12px);
	padding: 0;
	max-width: min(90vw, 480px);
	width: 480px;
	background-color: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.25);
}

.extractplus-progress-dialog::backdrop {
	background-color: rgba(0, 0, 0, 0.4);
}

.extractplus-progress-dialog__content {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.extractplus-progress-dialog__title {
	margin: 0;
	font-size: 1.15em;
	font-weight: bold;
}

.extractplus-progress-dialog__archive {
	margin: 0;
	color: var(--color-text-maxcontrast, #6b6b6b);
	overflow-wrap: anywhere;
}

.extractplus-progress-dialog__track {
	height: 6px;
	border-radius: 3px;
	background-color: var(--color-background-dark, #ededed);
	overflow: hidden;
}

.extractplus-progress-dialog__bar {
	height: 100%;
	width: 0;
	border-radius: 3px;
	background-color: var(--color-primary-element, #0082c9);
	transition: width 0.3s ease-out;
}

/* Phases without a measurable percentage slide instead of filling up. */
.extractplus-progress-dialog__bar--indeterminate {
	width: 35% !important;
	transition: none;
	animation: extractplus-progress-slide 1.4s ease-in-out infinite;
}

@keyframes extractplus-progress-slide {
	0% { margin-inline-start: -35%; }
	100% { margin-inline-start: 100%; }
}

@media (prefers-reduced-motion: reduce) {
	.extractplus-progress-dialog__bar {
		transition: none;
	}

	.extractplus-progress-dialog__bar--indeterminate {
		animation-duration: 3s;
	}
}

.extractplus-progress-dialog__status {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast, #6b6b6b);
}

.extractplus-progress-dialog__file {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	direction: rtl;
	text-align: start;
}

.extractplus-progress-dialog__percent {
	flex: 0 0 auto;
	font-variant-numeric: tabular-nums;
}

.extractplus-progress-dialog__error {
	margin: 0;
	color: var(--color-error, #c74e4e);
}

.extractplus-progress-dialog__buttons {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.extractplus-progress-dialog__button {
	min-height: 34px;
	padding: 0 16px;
	border: none;
	border-radius: var(--border-radius-element, var(--border-radius-pill, 17px));
	background-color: var(--color-background-dark, #ededed);
	color: var(--color-main-text, #222);
	cursor: pointer;
	font-size: inherit;
}

.extractplus-progress-dialog__button:hover,
.extractplus-progress-dialog__button:focus-visible {
	background-color: var(--color-background-hover, #e5e5e5);
}
`

/**
 * Modal progress dialog for a running extraction.
 *
 * Uses a native <dialog>, which brings the focus trap, the backdrop and
 * dismissal on Escape with it, so no dialog framework has to be bundled into
 * the Files page.
 */
export class ProgressDialog {
	private dialog: HTMLDialogElement

	private titleEl: HTMLHeadingElement

	private archiveEl: HTMLParagraphElement

	private barEl: HTMLDivElement

	private trackEl: HTMLDivElement

	private fileEl: HTMLSpanElement

	private percentEl: HTMLSpanElement

	private errorEl: HTMLParagraphElement

	private buttonsEl: HTMLDivElement

	private closeButton: HTMLButtonElement

	constructor() {
		ProgressDialog.injectStyles()

		this.dialog = document.createElement('dialog')
		this.dialog.className = 'extractplus-progress-dialog'
		this.dialog.setAttribute('aria-labelledby', 'extractplus-progress-dialog-title')

		const content = document.createElement('div')
		content.className = 'extractplus-progress-dialog__content'

		this.titleEl = document.createElement('h2')
		this.titleEl.className = 'extractplus-progress-dialog__title'
		this.titleEl.id = 'extractplus-progress-dialog-title'

		this.archiveEl = document.createElement('p')
		this.archiveEl.className = 'extractplus-progress-dialog__archive'

		this.trackEl = document.createElement('div')
		this.trackEl.className = 'extractplus-progress-dialog__track'
		this.trackEl.setAttribute('role', 'progressbar')
		this.trackEl.setAttribute('aria-valuemin', '0')
		this.trackEl.setAttribute('aria-valuemax', '100')

		this.barEl = document.createElement('div')
		this.barEl.className = 'extractplus-progress-dialog__bar'
		this.trackEl.appendChild(this.barEl)

		const status = document.createElement('div')
		status.className = 'extractplus-progress-dialog__status'
		// Screen readers should hear the phase changes, not every file name.
		status.setAttribute('aria-live', 'polite')

		this.fileEl = document.createElement('span')
		this.fileEl.className = 'extractplus-progress-dialog__file'

		this.percentEl = document.createElement('span')
		this.percentEl.className = 'extractplus-progress-dialog__percent'

		status.append(this.fileEl, this.percentEl)

		this.errorEl = document.createElement('p')
		this.errorEl.className = 'extractplus-progress-dialog__error'
		this.errorEl.hidden = true

		this.buttonsEl = document.createElement('div')
		this.buttonsEl.className = 'extractplus-progress-dialog__buttons'

		this.closeButton = document.createElement('button')
		this.closeButton.className = 'extractplus-progress-dialog__button'
		this.closeButton.type = 'button'
		this.closeButton.addEventListener('click', () => this.close())
		this.buttonsEl.appendChild(this.closeButton)

		content.append(this.titleEl, this.archiveEl, this.trackEl, status, this.errorEl, this.buttonsEl)
		this.dialog.appendChild(content)
	}

	private static injectStyles(): void {
		if (document.getElementById(STYLE_ID) !== null) {
			return
		}

		const style = document.createElement('style')
		style.id = STYLE_ID
		style.textContent = STYLES
		document.head.appendChild(style)
	}

	/**
	 * Show the dialog for an archive that is about to be extracted.
	 *
	 * @param archiveName Name of the archive, shown below the title
	 */
	open(archiveName: string): void {
		this.titleEl.textContent = t('extractplus', 'Extracting archive')
		this.archiveEl.textContent = archiveName
		this.errorEl.hidden = true
		// Closing the dialog only hides it; the extraction keeps running server side.
		this.closeButton.textContent = t('extractplus', 'Hide')
		this.setIndeterminate(true)
		this.fileEl.textContent = t('extractplus', 'Preparing…')
		this.percentEl.textContent = ''

		document.body.appendChild(this.dialog)
		this.dialog.showModal()
	}

	/**
	 * Render a state coming from the progress endpoint.
	 *
	 * @param state Current progress of the extraction
	 */
	update(state: ProgressState): void {
		if (state.phase === 'registering') {
			// The archive is unpacked; Nextcloud is indexing the new files now and
			// cannot report how far along that is.
			this.setIndeterminate(true)
			this.fileEl.textContent = t('extractplus', 'Adding files to Nextcloud…')
			this.percentEl.textContent = ''
			return
		}

		if (state.phase !== 'extracting') {
			return
		}

		const percent = Math.max(0, Math.min(100, state.percent))
		this.setIndeterminate(false)
		this.barEl.style.width = `${percent}%`
		this.trackEl.setAttribute('aria-valuenow', String(Math.round(percent)))
		this.percentEl.textContent = `${Math.round(percent)} %`

		if (state.currentFile !== '') {
			this.fileEl.textContent = state.currentFile
			this.fileEl.title = state.currentFile
		} else if (state.filesTotal > 0) {
			this.fileEl.textContent = t('extractplus', '{done} of {total} files', {
				done: String(state.filesDone),
				total: String(state.filesTotal),
			})
		}
	}

	/**
	 * Report that the extraction finished.
	 *
	 * The dialog stays up for a moment so the completed bar is actually seen
	 * instead of flashing past.
	 */
	finish(): void {
		this.setIndeterminate(false)
		this.barEl.style.width = '100%'
		this.trackEl.setAttribute('aria-valuenow', '100')
		this.percentEl.textContent = '100 %'
		this.fileEl.textContent = t('extractplus', 'Done')
		setTimeout(() => this.close(), 500)
	}

	/**
	 * Leave the dialog open showing why the extraction stopped.
	 *
	 * @param message Message to show to the user
	 */
	fail(message: string): void {
		this.setIndeterminate(false)
		this.barEl.style.width = '0'
		this.titleEl.textContent = t('extractplus', 'Extraction failed')
		this.fileEl.textContent = ''
		this.percentEl.textContent = ''
		this.errorEl.textContent = message
		this.errorEl.hidden = false
		this.closeButton.textContent = t('extractplus', 'Close')
		this.closeButton.focus()
	}

	close(): void {
		if (this.dialog.open) {
			this.dialog.close()
		}
		this.dialog.remove()
	}

	private setIndeterminate(indeterminate: boolean): void {
		this.barEl.classList.toggle('extractplus-progress-dialog__bar--indeterminate', indeterminate)
		if (indeterminate) {
			this.trackEl.removeAttribute('aria-valuenow')
			this.barEl.style.width = ''
		}
	}
}
