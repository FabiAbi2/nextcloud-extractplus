<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 IB Trieb GmbH & Co. KG
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ExtractPlus\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Keeps the state of a running extraction where a second request can read it.
 *
 * The extraction itself happens inside one long POST request, so the progress
 * has to leave that request to be pollable. A distributed cache is used when the
 * instance has one configured; otherwise the state falls back to a JSON file in
 * the temp directory, which is enough for the single-server setups that run
 * without a memory cache.
 */
final class ProgressService {

	public const PHASE_PREPARING = 'preparing';
	public const PHASE_EXTRACTING = 'extracting';
	public const PHASE_REGISTERING = 'registering';
	public const PHASE_DONE = 'done';
	public const PHASE_ERROR = 'error';

	/** Progress state is worthless once the request that wrote it is long gone. */
	private const TTL = 3600;

	/**
	 * Throttle for progress writes. Extracting thousands of small files would
	 * otherwise spend more time reporting than extracting.
	 */
	private const MIN_WRITE_INTERVAL = 0.4;

	private ?ICache $cache = null;

	private float $lastWrite = 0.0;

	/** @var array<string, mixed> */
	private array $state = [];

	public function __construct(
		ICacheFactory $cacheFactory,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
		if ($cacheFactory->isAvailable()) {
			$this->cache = $cacheFactory->createDistributed('extract_progress');
		}
	}

	/**
	 * Derive an opaque storage key.
	 *
	 * The job id comes from the browser, so it is never used as a path or cache
	 * key directly. Mixing in the user id also keeps one user from polling
	 * another user's extraction.
	 */
	private function key(string $userId, string $jobId): string {
		return hash('sha256', $userId . '|' . $jobId);
	}

	private function fallbackPath(string $key): string {
		return $this->tempManager->getTempBaseDir() . '/extractplus-progress-' . $key . '.json';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function persist(string $userId, string $jobId, array $state): void {
		$key = $this->key($userId, $jobId);
		$encoded = json_encode($state, JSON_THROW_ON_ERROR);

		if ($this->cache !== null) {
			$this->cache->set($key, $encoded, self::TTL);
			return;
		}

		$path = $this->fallbackPath($key);
		// Write-then-rename so a poll can never read a half-written file.
		$tmp = $path . '.' . getmypid() . '.part';
		if (file_put_contents($tmp, $encoded) === false || !rename($tmp, $path)) {
			@unlink($tmp);
			$this->logger->debug('Could not persist extraction progress to ' . $path);
		}
	}

	/**
	 * Start tracking a job. Always written through, so the first poll finds it.
	 */
	public function start(string $userId, string $jobId, string $archiveName): void {
		$this->state = [
			'phase' => self::PHASE_PREPARING,
			'percent' => 0.0,
			'currentFile' => '',
			'archive' => $archiveName,
			'filesDone' => 0,
			'filesTotal' => 0,
			'error' => '',
			'startedAt' => time(),
		];
		$this->lastWrite = microtime(true);
		$this->persist($userId, $jobId, $this->state);
	}

	/**
	 * Report extraction progress. Throttled; the caller may call this per file.
	 *
	 * @param float $percent Completion of the extraction phase, 0-100.
	 */
	public function update(
		string $userId,
		string $jobId,
		float $percent,
		string $currentFile = '',
		int $filesDone = 0,
		int $filesTotal = 0,
	): void {
		$now = microtime(true);
		if ($now - $this->lastWrite < self::MIN_WRITE_INTERVAL) {
			return;
		}
		$this->lastWrite = $now;

		$this->state['phase'] = self::PHASE_EXTRACTING;
		$this->state['percent'] = max(0.0, min(100.0, $percent));
		$this->state['currentFile'] = $currentFile;
		$this->state['filesDone'] = $filesDone;
		$this->state['filesTotal'] = $filesTotal;
		$this->persist($userId, $jobId, $this->state);
	}

	/**
	 * Move to a new phase. Never throttled - phase changes drive the dialog.
	 */
	public function setPhase(string $userId, string $jobId, string $phase, string $error = ''): void {
		$this->state['phase'] = $phase;
		$this->state['error'] = $error;
		if ($phase === self::PHASE_REGISTERING || $phase === self::PHASE_DONE) {
			$this->state['percent'] = 100.0;
			$this->state['currentFile'] = '';
		}
		$this->lastWrite = microtime(true);
		$this->persist($userId, $jobId, $this->state);
	}

	/**
	 * Read the state of a job, or null when it is unknown or expired.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get(string $userId, string $jobId): ?array {
		$key = $this->key($userId, $jobId);

		if ($this->cache !== null) {
			$encoded = $this->cache->get($key);
		} else {
			$path = $this->fallbackPath($key);
			$encoded = is_file($path) ? file_get_contents($path) : false;
		}

		if (!is_string($encoded) || $encoded === '') {
			return null;
		}

		try {
			/** @var array<string, mixed> $decoded */
			$decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Drop the state of a finished job that the browser has already picked up.
	 */
	public function clear(string $userId, string $jobId): void {
		$key = $this->key($userId, $jobId);

		if ($this->cache !== null) {
			$this->cache->remove($key);
			return;
		}

		@unlink($this->fallbackPath($key));
	}
}
