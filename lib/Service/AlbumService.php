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
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class AlbumService {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly ?string $userId,
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

	/**
	 * List the current user's Photos albums (id + name), sorted by name.
	 *
	 * @return array<int, array{album_id: int, name: string}>
	 */
	public function getAlbumsForUser(): array {
		if ($this->userId === null || !$this->appManager->isEnabledForUser('photos')) {
			return [];
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('album_id', 'name')
				->from('photos_albums')
				->where($qb->expr()->eq('user', $qb->createNamedParameter($this->userId)))
				->orderBy('name', 'ASC');

			$result = $qb->executeQuery();
			$albums = [];
			while ($row = $result->fetch()) {
				$albums[] = [
					'album_id' => (int) $row['album_id'],
					'name' => (string) $row['name'],
				];
			}
			$result->closeCursor();
			return $albums;
		} catch (\Exception $e) {
			$this->logger->debug('Could not list user albums: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Add a file to one of the user's Photos albums via the Photos app's own
	 * AlbumMapper. This is the same path the Photos web UI uses under the hood
	 * (invoked by its DAV layer), so album semantics — permissions, dedup, cache
	 * invalidation — are handled by the Photos app itself.
	 *
	 * The mapper lives in OCA\Photos\* which is not public OCP; we resolve it
	 * through the container and treat a missing/renamed class as a graceful
	 * "photos_unavailable" rather than a 500.
	 *
	 * @return array{success: bool, photos_disabled?: bool, photos_unavailable?: bool, already_in_album?: bool, not_found?: bool, not_permitted?: bool}
	 */
	public function addFileToAlbum(int $albumId, int $fileId): array {
		if ($this->userId === null) {
			return ['success' => false, 'not_permitted' => true];
		}
		if (!$this->appManager->isEnabledForUser('photos')) {
			return ['success' => false, 'photos_disabled' => true];
		}

		$mapperClass = 'OCA\\Photos\\Album\\AlbumMapper';
		if (!class_exists($mapperClass)) {
			return ['success' => false, 'photos_unavailable' => true];
		}

		// Verify the album belongs to the current user. AlbumMapper::addFile
		// blindly inserts with no ownership check of its own.
		if (!$this->userOwnsAlbum($albumId)) {
			return ['success' => false, 'album_not_found' => true];
		}

		try {
			$mapper = $this->container->get($mapperClass);
			$mapper->addFile($albumId, $fileId, $this->userId);
			return ['success' => true];
		} catch (\Throwable $e) {
			// Photos throws its own AlreadyInAlbumException wrapping a
			// UniqueConstraintViolationException — match by class name to avoid
			// a hard compile-time dep on the Photos namespace.
			if (str_ends_with(get_class($e), '\\AlreadyInAlbumException')) {
				return ['success' => false, 'already_in_album' => true];
			}
			if ($e instanceof DBException
				&& $e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return ['success' => false, 'already_in_album' => true];
			}
			$this->logger->warning('Failed to add file to album: ' . $e->getMessage(), ['exception' => $e]);
			return ['success' => false];
		}
	}

	private function userOwnsAlbum(int $albumId): bool {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('album_id')
				->from('photos_albums')
				->where($qb->expr()->eq('album_id', $qb->createNamedParameter($albumId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('user', $qb->createNamedParameter($this->userId)))
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$found = $result->fetch() !== false;
			$result->closeCursor();
			return $found;
		} catch (\Exception $e) {
			$this->logger->debug('Could not verify album ownership: ' . $e->getMessage());
			return false;
		}
	}
}
