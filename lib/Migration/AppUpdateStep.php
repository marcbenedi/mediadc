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

use OCA\MediaDC\Db\SettingMapper;
use OCA\MediaDC\Migration\data\AppInitialData;
use OCA\MediaDC\Service\AppDataService;
use OCA\MediaDC\Service\UtilsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class AppUpdateStep implements IRepairStep {
	public function __construct(
		private readonly UtilsService $utils,
		private readonly AppDataService $appDataService,
		private readonly SettingMapper $settingMapper,
	) {
	}

	public function getName(): string {
		return 'Update settings along with MediaDC';
	}

	public function run(IOutput $output) {
		$output->startProgress(2);
		$output->advance(1, 'Sync settings changes');
		$this->utils->checkForSettingsUpdates(AppInitialData::$APP_INITIAL_DATA);

		$output->advance(1, 'Creating app data folders');
		$this->appDataService->createAppDataFolder('logs');

		// Ensure python_binary is set to false (system Python is used directly)
		try {
			$pythonBinarySetting = $this->settingMapper->findByName('python_binary');
			$pythonBinarySetting->setValue(json_encode(false));
			$this->settingMapper->update($pythonBinarySetting);
		} catch (\Exception $e) {
			// Setting not found
		}

		$output->finishProgress();
	}
}
