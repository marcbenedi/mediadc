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
 *
 */

namespace OCA\MediaDC\Service;

use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class AlbumService {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Get album names for multiple file IDs in one batch query.
	 *
	 * @param int[] $fileIds
	 * @return array<int, string[]> keyed by file ID, each value is an array of album names
	 */
	public function getAlbumsForFiles(array $fileIds): array {
		if (empty($fileIds) || !$this->appManager->isEnabledForUser('photos')) {
			return [];
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('paf.file_id', 'pa.name')
				->from('photos_albums', 'pa')
				->innerJoin('pa', 'photos_albums_files', 'paf', $qb->expr()->eq('pa.album_id', 'paf.album_id'))
				->where($qb->expr()->in('paf.file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)));

			$result = $qb->executeQuery();
			$albumsMap = [];
			while ($row = $result->fetch()) {
				$fileId = (int) $row['file_id'];
				$albumsMap[$fileId][] = $row['name'];
			}
			$result->closeCursor();

			return $albumsMap;
		} catch (\Exception $e) {
			$this->logger->debug('Could not query album data: ' . $e->getMessage());
			return [];
		}
	}
}
