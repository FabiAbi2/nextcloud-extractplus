<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ExtractPlus;

/**
 * @psalm-type ExtractplusFolder = array{
 *     fileId: int,
 *     source: string,
 *     root: string,
 *     owner: ?string,
 *     permissions: int,
 *     mtime: int,
 *     mount-type: string,
 *     owner-display-name: ?string,
 * }
 * @psalm-type ExtractplusProgress = array{
 *     phase: string,
 *     percent: float,
 *     currentFile: string,
 *     archive: string,
 *     filesDone: int,
 *     filesTotal: int,
 *     error: string,
 *     startedAt: int,
 * }
 * @psalm-type ExtractplusCapabilities = array{
 *     features: list<string>,
 *     config: array{
 *     },
 *     version: string,
 * }
 */
final class ResponseDefinitions {
}
