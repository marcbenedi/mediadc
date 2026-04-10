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

use OCA\MediaDC\Db\SettingMapper;
use OCP\App\IAppManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Utility service replacing cloud_py_api's UtilsService.
 * Provides system detection and Python environment helpers.
 */
class PythonUtilsService {
	public function __construct(
		private readonly IConfig $config,
		private readonly SettingMapper $settingMapper,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Get environment variables needed by nc-py-api to connect to the database.
	 * These use the NC_ prefix so nc-py-api reads them directly instead of calling occ.
	 */
	public function getNcPyApiEnv(): array {
		return [
			'NC_dbtype' => $this->config->getSystemValueString('dbtype', 'sqlite3'),
			'NC_dbname' => $this->config->getSystemValueString('dbname', ''),
			'NC_dbuser' => $this->config->getSystemValueString('dbuser', ''),
			'NC_dbpassword' => $this->config->getSystemValueString('dbpassword', ''),
			'NC_dbhost' => $this->config->getSystemValueString('dbhost', ''),
			'NC_dbtableprefix' => $this->config->getSystemValueString('dbtableprefix', 'oc_'),
			'NC_datadirectory' => $this->config->getSystemValueString('datadirectory', ''),
		];
	}

	/**
	 * Get platform-specific binary name based on OS and architecture.
	 */
	public function getBinaryName(): string {
		$osArch = php_uname('m');

		if (PHP_OS_FAMILY !== 'Linux') {
			return 'unknown_' . $osArch;
		}

		// Detect musl vs glibc
		$isMusl = false;
		if (function_exists('exec')) {
			exec('ldd --version 2>&1', $output);
			$lddOutput = implode("\n", $output);
			if (str_contains($lddOutput, 'musl')) {
				$isMusl = true;
			}
		}

		return ($isMusl ? 'musllinux_' : 'manylinux_') . $osArch;
	}

	/**
	 * Check if a PHP function is enabled and not disabled.
	 */
	public function isFunctionEnabled(string $functionName): bool {
		if (!function_exists($functionName)) {
			return false;
		}

		$disabled = ini_get('disable_functions');
		if ($disabled !== false && $disabled !== '') {
			$disabledFunctions = array_map('trim', explode(',', $disabled));
			if (in_array($functionName, $disabledFunctions)) {
				return false;
			}
		}

		// Check suhosin blacklist
		$suhosinBlacklist = ini_get('suhosin.executor.func.blacklist');
		if ($suhosinBlacklist !== false && $suhosinBlacklist !== '') {
			$blacklisted = array_map('trim', explode(',', $suhosinBlacklist));
			if (in_array($functionName, $blacklisted)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Find the PHP interpreter path.
	 */
	public function getPhpInterpreter(): string {
		if (defined('PHP_BINARY') && PHP_BINARY !== '') {
			return PHP_BINARY;
		}

		$searchPaths = explode(PATH_SEPARATOR, $_SERVER['PATH'] ?? '');
		$searchPaths[] = PHP_BINDIR;

		$phpNames = ['php8.3', 'php8.2', 'php8', 'php'];
		foreach ($searchPaths as $path) {
			foreach ($phpNames as $name) {
				$fullPath = rtrim($path, '/') . '/' . $name;
				if (is_executable($fullPath)) {
					return $fullPath;
				}
			}
		}

		return 'php';
	}

	/**
	 * Check if running in a Snap environment.
	 */
	public function isSnapEnv(): bool {
		return getenv('SNAP') !== false;
	}

	/**
	 * Get Nextcloud log level as a string.
	 */
	public function getNCLogLevel(): string {
		$level = (int)$this->config->getSystemValue('loglevel', 2);
		return match ($level) {
			0 => 'DEBUG',
			1 => 'INFO',
			2 => 'WARNING',
			3 => 'ERROR',
			4 => 'FATAL',
			default => 'WARNING',
		};
	}

	/**
	 * Get the app-specific log level from settings.
	 */
	public function getCpaLogLevel(): string {
		try {
			$setting = $this->settingMapper->findByName('cpa_loglevel');
			return json_decode($setting->getValue()) ?? 'WARNING';
		} catch (\Exception $e) {
			return 'WARNING';
		}
	}

	/**
	 * Download a pre-compiled Python binary tarball from a URL and extract it.
	 *
	 * @return array{downloaded: bool, error?: string}
	 */
	public function downloadPythonBinaryDir(
		string $url,
		string $targetDir,
		string $binaryName,
	): array {
		$binaryPath = $targetDir . '/' . $binaryName;

		if (is_dir($binaryPath) && is_executable($binaryPath . '/main')) {
			return ['downloaded' => true];
		}

		$tmpFile = tempnam(sys_get_temp_dir(), 'mediadc_') . '.tar.gz';

		$ch = curl_init($url);
		if ($ch === false) {
			return ['downloaded' => false, 'error' => 'curl_init failed'];
		}

		$fp = fopen($tmpFile, 'w');
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 300);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		fclose($fp);

		if ($httpCode !== 200) {
			@unlink($tmpFile);
			$this->logger->error('Binary download failed: HTTP ' . $httpCode . ' for ' . $url . ' - ' . $error);
			return ['downloaded' => false, 'error' => 'HTTP ' . $httpCode];
		}

		// Extract
		try {
			$phar = new \PharData($tmpFile);
			$phar->extractTo($targetDir, null, true);
		} catch (\Exception $e) {
			@unlink($tmpFile);
			$this->logger->error('Binary extraction failed: ' . $e->getMessage());
			return ['downloaded' => false, 'error' => $e->getMessage()];
		}
		@unlink($tmpFile);

		// Make executable
		if (file_exists($binaryPath . '/main')) {
			chmod($binaryPath . '/main', 0755);
		}

		return ['downloaded' => true];
	}

	/**
	 * Get the resolved path to the pre-compiled binary, or null if not found.
	 */
	public function getBinaryPath(string $appId): ?string {
		$ncDataFolder = $this->config->getSystemValueString('datadirectory', '');
		$ncInstanceId = $this->config->getSystemValueString('instanceid', '');
		if ($ncDataFolder === '' || $ncInstanceId === '') {
			return null;
		}
		$binaryDir = $ncDataFolder . '/appdata_' . $ncInstanceId . '/' . $appId
			. '/binaries/' . $appId . '_' . $this->getBinaryName();
		$binaryPath = $binaryDir . '/main';
		return file_exists($binaryPath) ? $binaryPath : null;
	}

	/**
	 * Get system information for the admin settings page.
	 */
	public function getSystemInfo(string $appId = ''): array {
		$info = [
			'nextcloud_version' => $this->config->getSystemValueString('version'),
			'app_version' => $appId !== '' ? $this->appManager->getAppVersion($appId, false) : '',
			'php_version' => PHP_VERSION,
			'php_interpreter' => $this->getPhpInterpreter(),
			'os' => PHP_OS,
			'os_release' => php_uname('r'),
			'machine_type' => php_uname('m'),
			'is_snap' => $this->isSnapEnv(),
			'arch' => php_uname('m'),
			'exec_enabled' => $this->isFunctionEnabled('exec'),
		];

		// Check binary status
		if ($appId !== '') {
			$binaryPath = $this->getBinaryPath($appId);
			$info['binary_path'] = $binaryPath;
			$info['binary_found'] = $binaryPath !== null;
		}

		// Check for Python and dependencies
		if ($this->isFunctionEnabled('exec')) {
			exec('python3 --version 2>&1', $pyOutput, $pyResult);
			$info['python_version'] = $pyResult === 0 ? trim(implode('', $pyOutput)) : 'not found';

			exec('ffmpeg -version 2>&1', $ffmpegOutput, $ffmpegResult);
			$info['ffmpeg_available'] = $ffmpegResult === 0;

			// Check Python packages (only relevant when not using pre-compiled binary)
			$packages = ['numpy', 'scipy', 'PIL', 'pillow_heif', 'hexhamming', 'nc_py_api', 'pywt'];
			$info['python_packages'] = [];
			foreach ($packages as $pkg) {
				exec('python3 -c "import ' . $pkg . '" 2>&1', $pkgOutput, $pkgResult);
				$info['python_packages'][$pkg] = $pkgResult === 0;
				$pkgOutput = [];
			}
		} else {
			$info['python_version'] = 'exec() disabled';
			$info['ffmpeg_available'] = false;
			$info['python_packages'] = [];
		}

		return $info;
	}
}
