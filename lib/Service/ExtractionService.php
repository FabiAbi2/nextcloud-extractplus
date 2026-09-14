<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2012-2022 Paul Lereverend <paulereverend@gmail.com>
 * SPDX-FileCopyrightText: 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * SPDX-FileCopyrightText: 2026 IB Trieb GmbH & Co. KG
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ExtractPlus\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Unpacks an archive and reports how far it got.
 *
 * Every extractor takes an optional progress callback with the signature
 * `fn(float $percent, string $currentFile, int $filesDone, int $filesTotal)`.
 * Reporting is best effort: the zip extractor knows the uncompressed size up
 * front, the external tools are asked to print their progress and are parsed,
 * and anything that cannot be measured still reports the file it works on.
 */
final class ExtractionService {

	/**
	 * Aim for a few hundred progress callbacks over the whole archive - enough
	 * for a smooth bar, few enough to not drown the extraction in bookkeeping.
	 */
	private const TARGET_UPDATES = 200;

	public function __construct(
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @psalm-return array{code: 0|1, desc?: string}
	 */
	public function extractZip(string $file, string $extractTo, ?callable $onProgress = null): array {
		if (!extension_loaded('zip')) {
			return ['code' => 0, 'desc' => $this->l->t('Zip extension is not available')];
		}

		$zip = new ZipArchive();

		if ($zip->open($file) !== true) {
			return ['code' => 0, 'desc' => $this->l->t('Cannot open Zip file')];
		}

		$total = $zip->numFiles;

		if ($onProgress === null || $total <= 1) {
			$success = $zip->extractTo($extractTo);
			$zip->close();
			return ['code' => $success ? 1 : 0];
		}

		// Uncompressed size is a far better progress metric than the file count,
		// because archives routinely mix one huge file with hundreds of tiny ones.
		$totalBytes = 0;
		for ($i = 0; $i < $total; $i++) {
			$stat = $zip->statIndex($i);
			if ($stat !== false) {
				$totalBytes += (int)$stat['size'];
			}
		}

		// Extract in batches; one extractTo() call per entry is measurably slower
		// on archives that consist of many small files.
		$batchSize = max(1, (int)ceil($total / self::TARGET_UPDATES));
		$doneBytes = 0;
		$success = true;

		for ($offset = 0; $offset < $total; $offset += $batchSize) {
			$names = [];
			$batchBytes = 0;
			$lastName = '';

			for ($i = $offset; $i < min($offset + $batchSize, $total); $i++) {
				$stat = $zip->statIndex($i);
				if ($stat === false) {
					continue;
				}
				$names[] = $stat['name'];
				$batchBytes += (int)$stat['size'];
				$lastName = $stat['name'];
			}

			if ($names === []) {
				continue;
			}

			if (!$zip->extractTo($extractTo, $names)) {
				$success = false;
				break;
			}

			$doneBytes += $batchBytes;
			$filesDone = min($offset + $batchSize, $total);
			$percent = $totalBytes > 0
				? ($doneBytes / $totalBytes) * 100
				: ($filesDone / $total) * 100;

			$onProgress($percent, $lastName, $filesDone, $total);
		}

		$zip->close();

		return ['code' => $success ? 1 : 0];
	}

	/**
	 * @psalm-return array{code: 0|1, desc?: string}
	 */
	public function extractRar(string $file, string $extractTo, ?callable $onProgress = null): array {
		if (extension_loaded('rar')) {
			return $this->extractRarWithExtension($file, $extractTo, $onProgress);
		}

		$unrar = $this->findBinary(['unrar', 'unrar-free']);
		if ($unrar === null) {
			return [
				'code' => 0,
				'desc' => $this->l->t('Oops something went wrong. Check that you have rar extension or unrar installed'),
			];
		}

		// -o+ overwrite, -y assume yes, the trailing slash marks the target directory.
		$result = $this->runWithProgress(
			[$unrar, 'x', '-o+', '-y', $file, rtrim($extractTo, '/') . '/'],
			$onProgress,
		);

		if ($result['code'] !== 0) {
			$this->logger->error('unrar failed with exit code ' . $result['code'] . ': ' . $result['tail']);
			return [
				'code' => 0,
				'desc' => $this->l->t('Oops something went wrong. Check that you have rar extension or unrar installed'),
			];
		}

		return ['code' => 1];
	}

	/**
	 * @psalm-return array{code: 0|1, desc?: string}
	 */
	private function extractRarWithExtension(string $file, string $extractTo, ?callable $onProgress): array {
		$archive = rar_open($file);
		if ($archive === false) {
			return ['code' => 0, 'desc' => $this->l->t('Cannot open Rar file')];
		}

		$entries = rar_list($archive);
		if ($entries === false) {
			rar_close($archive);
			return ['code' => 0, 'desc' => $this->l->t('Cannot open Rar file')];
		}

		$total = count($entries);
		$totalBytes = 0;
		foreach ($entries as $entry) {
			$totalBytes += (int)$entry->getUnpackedSize();
		}

		$doneBytes = 0;
		$filesDone = 0;

		foreach ($entries as $entry) {
			if (!$entry->extract($extractTo)) {
				rar_close($archive);
				return ['code' => 0, 'desc' => $this->l->t('Oops something went wrong.')];
			}

			$doneBytes += (int)$entry->getUnpackedSize();
			$filesDone++;

			if ($onProgress !== null) {
				$percent = $totalBytes > 0
					? ($doneBytes / $totalBytes) * 100
					: ($filesDone / max(1, $total)) * 100;
				$onProgress($percent, $entry->getName(), $filesDone, $total);
			}
		}

		rar_close($archive);

		return ['code' => 1];
	}

	/**
	 * Tar, gzip, bzip2, 7z, deb - everything handled by the 7-Zip family.
	 *
	 * @psalm-return array{code: 0|1, desc?: string}
	 */
	public function extractOther(string $file, string $extractTo, ?callable $onProgress = null): array {
		// 7zz ships with the official 7-Zip release, 7z comes from p7zip-full and
		// 7za is the reduced standalone build that older setups have.
		$binary = $this->findBinary(['7z', '7zz', '7za']);
		if ($binary === null) {
			$this->logger->error('Neither 7z, 7zz nor 7za was found in PATH. Is 7-Zip installed?');
			return ['code' => 0, 'desc' => $this->l->t('Oops something went wrong.')];
		}

		// -bsp1 sends the progress indicator to stdout, -bb1 logs every file name.
		$result = $this->runWithProgress(
			[$binary, 'x', '-y', '-bsp1', '-bb1', $file, '-o' . $extractTo],
			$onProgress,
		);

		if ($result['code'] !== 0) {
			$this->logger->error($binary . ' failed with exit code ' . $result['code'] . ': ' . $result['tail']);
			return ['code' => 0, 'desc' => $this->l->t('Oops something went wrong.')];
		}

		return ['code' => 1];
	}

	/**
	 * Locate an executable so the extraction itself can skip the shell.
	 *
	 * @param list<string> $candidates
	 */
	private function findBinary(array $candidates): ?string {
		foreach ($candidates as $candidate) {
			$found = @shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null');
			if (is_string($found) && trim($found) !== '') {
				return trim($found);
			}
		}

		return null;
	}

	/**
	 * Run an extraction tool and turn whatever it prints into progress updates.
	 *
	 * The command is passed as an array, so it runs directly instead of through a
	 * shell and no argument needs escaping.
	 *
	 * @param list<string> $command
	 *
	 * @return array{code: int, tail: string}
	 */
	private function runWithProgress(array $command, ?callable $onProgress): array {
		$descriptors = [
			0 => ['file', '/dev/null', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = @proc_open($command, $descriptors, $pipes);
		if (!is_resource($process)) {
			return ['code' => -1, 'tail' => 'could not start ' . ($command[0] ?? '?')];
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$percent = 0.0;
		$currentFile = '';
		$pending = '';
		$tail = '';

		while (true) {
			$read = [$pipes[1], $pipes[2]];
			$write = null;
			$except = null;

			if (@stream_select($read, $write, $except, 1) === false) {
				break;
			}

			$gotData = false;
			foreach ($read as $stream) {
				$chunk = fread($stream, 8192);
				if ($chunk === false || $chunk === '') {
					continue;
				}
				$gotData = true;

				// Keep a bounded tail of the output around for error reporting.
				$tail = substr($tail . $chunk, -2000);

				if ($stream !== $pipes[1]) {
					continue;
				}

				// Progress indicators overwrite themselves with carriage returns
				// and backspaces, so treat both as line separators.
				$pending = str_replace(["\r", "\x08"], "\n", $pending . $chunk);
				$lines = explode("\n", $pending);
				$pending = (string)array_pop($lines);

				foreach ($lines as $line) {
					$this->parseProgressLine($line, $percent, $currentFile);
				}

				if ($onProgress !== null) {
					$onProgress($percent, $currentFile, 0, 0);
				}
			}

			$status = proc_get_status($process);
			if (!$status['running']) {
				// Drain whatever is still buffered before giving up the pipes.
				while (($chunk = fread($pipes[1], 8192)) !== false && $chunk !== '') {
					$tail = substr($tail . $chunk, -2000);
				}
				while (($chunk = fread($pipes[2], 8192)) !== false && $chunk !== '') {
					$tail = substr($tail . $chunk, -2000);
				}
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);

				return ['code' => $status['exitcode'], 'tail' => trim($tail)];
			}

			if (!$gotData) {
				// Nothing to read while the tool is still working - do not spin.
				usleep(50000);
			}
		}

		fclose($pipes[1]);
		fclose($pipes[2]);

		return ['code' => proc_close($process), 'tail' => trim($tail)];
	}

	/**
	 * Pull a percentage and a file name out of one line of tool output.
	 *
	 * 7-Zip with -bsp1 -bb1 prints " 42% 13 - dir/file.txt" and "- dir/file.txt",
	 * unrar prints "Extracting  dir/file.txt   45%". Both are handled here; a
	 * line that carries neither leaves the current values untouched.
	 */
	private function parseProgressLine(string $line, float &$percent, string &$currentFile): void {
		$line = trim($line);
		if ($line === '') {
			return;
		}

		if (preg_match('/(\d{1,3})%/', $line, $matches) === 1) {
			$percent = min(100.0, (float)$matches[1]);
		}

		// "<digits>% <count> - <name>" or "- <name>" (7-Zip)
		if (preg_match('/(?:^|\s)-\s+(.+)$/', $line, $matches) === 1) {
			$currentFile = trim($matches[1]);
			return;
		}

		// "Extracting  <name>    OK" / "Extracting  <name>   45%" (unrar)
		if (preg_match('/^(?:Extracting|Creating)\s+(.+?)(?:\s+(?:OK|\d{1,3}%|\.\.\.))*$/i', $line, $matches) === 1) {
			$currentFile = trim($matches[1]);
		}
	}
}
