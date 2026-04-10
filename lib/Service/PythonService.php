<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2024 Marc Benedi
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\MediaDC\Service;

use OCP\App\IAppManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for executing Python scripts, replacing cloud_py_api's PythonService.
 */
class PythonService {
	public function __construct(
		private readonly IConfig $config,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Run a Python script with the given parameters and environment variables.
	 *
	 * @param string $appId The app ID (used to locate the script)
	 * @param string $scriptName The script name relative to the app directory
	 * @param array $scriptParams Key-value pairs of script parameters
	 * @param bool $nonBlocking If true, run in background with nohup
	 * @param array $env Environment variables to set
	 * @param bool $binary If true, the script is a compiled binary (no python interpreter prefix)
	 *
	 * @return array|null Output array with 'output', 'result_code', 'errors' keys, or null if non-blocking
	 */
	public function run(
		string $appId,
		string $scriptName,
		array $scriptParams = [],
		bool $nonBlocking = false,
		array $env = [],
		bool $binary = false,
	): ?array {
		$formattedParams = $this->formatParams($scriptParams);

		// Absolute paths are used as-is; relative paths get the app directory prepended
		$scriptPath = str_starts_with($scriptName, '/')
			? $scriptName
			: $this->getWorkingDirectory($appId) . $scriptName;

		if ($binary) {
			$cmd = escapeshellarg($scriptPath) . ' ' . $formattedParams;
		} else {
			$cmd = 'python3 ' . escapeshellarg($scriptPath) . ' ' . $formattedParams;
		}

		$envPrefix = $this->formatEnv($env);

		if ($nonBlocking) {
			$logFile = $this->getLogFilePath($appId);
			// Use env(1) to set variables — inline VAR=val syntax doesn't work with nohup
			$fullCmd = 'nohup env ' . $envPrefix . ' ' . $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
			exec($fullCmd);
			return null;
		}

		// For blocking calls, inline VAR=val works since exec() runs via shell
		if ($envPrefix !== '') {
			$cmd = $envPrefix . ' ' . $cmd;
		}
		exec($cmd . ' 2>&1', $output, $resultCode);

		return [
			'output' => implode("\n", $output),
			'result_code' => $resultCode,
			'errors' => $resultCode !== 0 ? implode("\n", $output) : '',
		];
	}

	private function getWorkingDirectory(string $appId): string {
		try {
			return rtrim($this->appManager->getAppPath($appId), '/') . '/';
		} catch (\Exception $e) {
			$this->logger->warning('Could not resolve app path for ' . $appId . ': ' . $e->getMessage());
			return \OC::$SERVERROOT . '/apps/' . $appId . '/';
		}
	}

	private function getLogFilePath(string $appId): string {
		$ncDataFolder = $this->config->getSystemValue('datadirectory');
		$ncInstanceId = $this->config->getSystemValue('instanceid');
		return $ncDataFolder . '/appdata_' . $ncInstanceId . '/' . $appId . '/logs/output.log';
	}

	private function formatParams(array $params): string {
		$parts = [];
		foreach ($params as $key => $value) {
			$parts[] = escapeshellarg((string)$key) . ' ' . escapeshellarg((string)$value);
		}
		return implode(' ', $parts);
	}

	private function formatEnv(array $env): string {
		$parts = [];
		foreach ($env as $key => $value) {
			// Keys are safe identifiers (A-Z, 0-9, _) — don't quote them.
			// Quoting the key makes the shell treat it as a command, not a variable assignment.
			$safeKey = preg_replace('/[^A-Za-z0-9_]/', '', (string)$key);
			$parts[] = $safeKey . '=' . escapeshellarg((string)$value);
		}
		return implode(' ', $parts);
	}
}
