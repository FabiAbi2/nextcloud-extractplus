<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 IB Trieb GmbH & Co. KG
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Stubs for the optional PECL rar extension.
 *
 * The extension is not installed on the analysis runner, and rar_list() in
 * particular is documented as returning a list of entries rather than the
 * RarArchive its signature suggests.
 */

namespace {
	class RarArchive {
	}

	class RarEntry {
		public function getName(): string {
		}

		public function getUnpackedSize(): int {
		}

		public function extract(string $dir, string $filepath = '', ?string $password = null, ?string $filename = null): bool {
		}
	}

	/**
	 * @return RarArchive|false
	 */
	function rar_open(string $filename, ?string $password = null, ?callable $volume_callback = null) {
	}

	/**
	 * @return list<RarEntry>|false
	 */
	function rar_list(RarArchive $rarfile) {
	}

	function rar_close(RarArchive $rarfile): bool {
	}
}
