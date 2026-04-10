<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2021-2022 Andrey Borysenko <andrey18106x@gmail.com>
 *
 * @copyright Copyright (c) 2021-2022 Alexander Piskun <bigcat88@icloud.com>
 *
 * @author 2021-2022 Andrey Borysenko <andrey18106x@gmail.com>
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
 *
 */

namespace OCA\MediaDC\Migration;

use OCA\MediaDC\AppInfo\Application;
use OCA\MediaDC\Db\Setting;
use OCA\MediaDC\Db\SettingMapper;
use OCA\MediaDC\Migration\data\AppInitialData;
use OCA\MediaDC\Service\AppDataService;
use OCA\MediaDC\Service\PythonUtilsService;
use OCA\MediaDC\Service\UtilsService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class AppDataInitializationStep implements IRepairStep {
	public function __construct(
		private readonly SettingMapper $settingMapper,
		private readonly UtilsService $utils,
		private readonly AppDataService $appDataService,
		private readonly PythonUtilsService $pythonUtils,
		private readonly IAppManager $appManager,
	) {
	}

	public function getName(): string {
		return 'Initializing MediaDC data';
	}

	public function run(IOutput $output) {
		$output->startProgress(4);
		$output->advance(1, 'Filling database with initial data');
		$app_data = AppInitialData::$APP_INITIAL_DATA;

		if (count($this->settingMapper->findAll()) === 0 && isset($app_data['settings'])) {
			foreach ($app_data['settings'] as $setting) {
				$this->settingMapper->insert(new Setting([
					'name' => $setting['name'],
					'value' => is_array($setting['value'])
						? json_encode($setting['value'])
						: str_replace('\\', '', json_encode($setting['value'])),
					'displayName' => $setting['displayName'],
					'description' => $setting['description']
				]));
			}
		}

		$output->advance(1, 'Checking for initial data changes and syncing with database');
		$this->utils->checkForSettingsUpdates($app_data);

		$output->advance(1, 'Creating app data folders');
		$this->appDataService->createAppDataFolder('binaries');
		$this->appDataService->createAppDataFolder('logs');

		$output->advance(1, 'Downloading pre-compiled Python binary');
		$this->downloadBinary($output);

		$output->finishProgress();
	}

	private function downloadBinary(IOutput $output): void {
		$binaryName = Application::APP_ID . '_' . $this->pythonUtils->getBinaryName();
		$version = $this->appManager->getAppVersion(Application::APP_ID, false);
		$binariesFolder = $this->appDataService->getAppDataFolder('binaries');

		if (!isset($binariesFolder['path'])) {
			$output->warning('Could not resolve binaries folder path');
			$this->setPythonBinary(false);
			return;
		}

		$url = 'https://github.com/marcbenedi/mediadc/releases/download/v'
			. $version . '/' . $binaryName . '.tar.gz';

		$result = $this->pythonUtils->downloadPythonBinaryDir($url, $binariesFolder['path'], $binaryName);

		if ($result['downloaded']) {
			$this->setPythonBinary(true);
		} else {
			$output->warning('Could not download pre-compiled binary. '
				. 'Falling back to system Python (requires python3, pip, and ffmpeg).');
			$this->setPythonBinary(false);
		}
	}

	private function setPythonBinary(bool $value): void {
		try {
			$setting = $this->settingMapper->findByName('python_binary');
			$setting->setValue(json_encode($value));
			$this->settingMapper->update($setting);
		} catch (\Exception $e) {
			// Setting may not exist yet during initial install
		}
	}
}
