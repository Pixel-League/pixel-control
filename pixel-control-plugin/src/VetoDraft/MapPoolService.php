<?php

namespace PixelControl\VetoDraft;

use ManiaControl\Maps\Map;
use ManiaControl\Maps\MapManager;

class MapPoolService {
	public function buildMapPool(MapManager $mapManager) {
		$mapPool = array();
		$maps = $mapManager->getMaps();
		$rotationIndex = 0;

		foreach ($maps as $map) {
			if (!$map instanceof Map) {
				continue;
			}

			$rotationIndex++;
			$mapPool[] = $this->buildMapIdentityFromObject($map, $rotationIndex);
		}

		return $mapPool;
	}

	public function resolveMapUidFromSelection(array $mapPool, $selection) {
		$normalizedSelection = trim((string) $selection);
		if ($normalizedSelection === '') {
			return '';
		}

		if (is_numeric($normalizedSelection)) {
			$index = (int) $normalizedSelection;
			if ($index > 0) {
				$mapIndex = $index - 1;
				if (isset($mapPool[$mapIndex]) && isset($mapPool[$mapIndex]['uid'])) {
					return (string) $mapPool[$mapIndex]['uid'];
				}
			}
		}

		$selectionLower = strtolower($normalizedSelection);
		foreach ($mapPool as $map) {
			if (!isset($map['uid'])) {
				continue;
			}

			$mapUid = strtolower((string) $map['uid']);
			if ($mapUid === $selectionLower) {
				return (string) $map['uid'];
			}
		}

		$normalizedSelectionName = $this->normalizeMapName($normalizedSelection);
		if ($normalizedSelectionName === '') {
			return '';
		}

		$nameMatches = array();
		foreach ($mapPool as $map) {
			$mapName = isset($map['name']) ? (string) $map['name'] : '';
			$normalizedMapName = $this->normalizeMapName($mapName);
			if ($normalizedMapName === '') {
				continue;
			}

			if ($normalizedMapName !== $normalizedSelectionName) {
				continue;
			}

			if (!isset($map['uid'])) {
				continue;
			}

			$nameMatches[] = (string) $map['uid'];
		}

		if (count($nameMatches) !== 1) {
			return '';
		}

		return $nameMatches[0];
	}

	public function findMapIdentityByUid(array $mapPool, $mapUid) {
		$normalizedUid = strtolower(trim((string) $mapUid));
		if ($normalizedUid === '') {
			return null;
		}

		foreach ($mapPool as $map) {
			if (!isset($map['uid'])) {
				continue;
			}

			$candidateUid = strtolower((string) $map['uid']);
			if ($candidateUid === $normalizedUid) {
				return $map;
			}
		}

		return null;
	}

	public function buildMapListRows(array $mapPool, $includeMapUid = true) {
		$includeMapUid = (bool) $includeMapUid;
		$rows = array();
		foreach ($mapPool as $index => $map) {
			$displayIndex = $index + 1;
			$mapName = isset($map['name']) ? trim((string) $map['name']) : '';
			if ($mapName === '') {
				$mapName = 'Unknown Map';
			}

			$mapUid = isset($map['uid']) ? (string) $map['uid'] : '';
			$rows[] = '#'
				. $displayIndex
				. ' '
				. $mapName
				. (($includeMapUid && $mapUid !== '') ? ' [' . $mapUid . ']' : '');
		}

		return $rows;
	}

	private function buildMapIdentityFromObject(Map $map, $rotationIndex) {
		$mapName = '';
		if (method_exists($map, 'getName')) {
			$mapName = trim((string) call_user_func(array($map, 'getName')));
		}
		if ($mapName === '' && isset($map->name)) {
			$mapName = trim((string) $map->name);
		}

		$mxId = null;
		if (isset($map->mx) && is_object($map->mx) && isset($map->mx->id)) {
			$mxId = (int) $map->mx->id;
		}

		return array(
			'uid' => isset($map->uid) ? (string) $map->uid : '',
			'name' => $mapName,
			'file' => isset($map->fileName) ? (string) $map->fileName : '',
			'environment' => isset($map->environment) ? (string) $map->environment : '',
			'map_type' => isset($map->mapType) ? (string) $map->mapType : '',
			'rotation_index' => (int) $rotationIndex,
			'external_ids' => array(
				'mx_id' => $mxId,
			),
		);
	}

	private function normalizeMapName($mapName) {
		$normalizedMapName = strtolower(trim((string) $mapName));
		if ($normalizedMapName === '') {
			return '';
		}

		$normalizedMapName = preg_replace('/\$[0-9a-zA-Z]{1,3}/', '', $normalizedMapName);
		$normalizedMapName = preg_replace('/[^a-z0-9]+/', '', $normalizedMapName);

		return trim((string) $normalizedMapName);
	}
}
