<?php

namespace PixelControl\Domain\Match;

use ManiaControl\Maps\Map;

trait MatchVetoRotationTrait {

	private function buildLifecycleMapRotationTelemetry($variant, $sourceCallback) {
		if ($variant !== 'map.begin' && $variant !== 'map.end') {
			return null;
		}

		$currentMapSnapshot = $this->buildCurrentMapSnapshot();
		if ($variant === 'map.begin') {
			$this->recordPlayedMapOrderEntry($currentMapSnapshot, $sourceCallback);
		}

		$mapPool = $this->buildMapPoolSnapshot();
		$currentMapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';
		$currentMapIndex = null;
		if ($currentMapUid !== '') {
			foreach ($mapPool as $index => $mapIdentity) {
				if (!isset($mapIdentity['uid'])) {
					continue;
				}

				if ((string) $mapIdentity['uid'] === $currentMapUid) {
					$currentMapIndex = (int) $index;
					break;
				}
			}
		}

		$nextMaps = array();
		$mapPoolSize = count($mapPool);
		if ($currentMapIndex !== null && $mapPoolSize > 0) {
			for ($step = 1; $step <= min(3, $mapPoolSize); $step++) {
				$nextIndex = ($currentMapIndex + $step) % $mapPoolSize;
				if (!isset($mapPool[$nextIndex])) {
					continue;
				}

				$nextMaps[] = $mapPool[$nextIndex];
			}
		}

		$fieldAvailability = array(
			'map_pool' => !empty($mapPool),
			'current_map' => $currentMapUid !== '',
			'current_map_index' => $currentMapIndex !== null,
			'next_maps' => !empty($nextMaps),
			'played_map_order' => !empty($this->playedMapHistory),
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'variant' => $variant,
			'map_pool_size' => $mapPoolSize,
			'current_map' => $currentMapSnapshot,
			'current_map_index' => $currentMapIndex,
			'next_maps' => $nextMaps,
			'map_pool' => $mapPool,
			'played_map_count' => count($this->playedMapHistory),
			'played_map_order' => $this->playedMapHistory,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}


	private function buildMapPoolSnapshot() {
		if (!$this->maniaControl) {
			return array();
		}

		$mapPool = array();
		$maps = $this->maniaControl->getMapManager()->getMaps();
		if (!is_array($maps)) {
			return $mapPool;
		}

		foreach (array_values($maps) as $index => $map) {
			if (!$map instanceof Map) {
				continue;
			}

			$mapPool[] = $this->buildMapIdentityFromObject($map, $index);
		}

		return $mapPool;
	}


	private function buildMapIdentityFromObject(Map $map, $rotationIndex) {
		$identity = array(
			'uid' => isset($map->uid) ? (string) $map->uid : '',
			'name' => isset($map->name) ? (string) $map->name : '',
			'file' => isset($map->fileName) ? (string) $map->fileName : '',
			'environment' => isset($map->environment) ? (string) $map->environment : '',
			'map_type' => isset($map->mapType) ? (string) $map->mapType : '',
			'rotation_index' => (int) $rotationIndex,
			'external_ids' => array(),
		);

		if (isset($map->mx) && is_object($map->mx) && isset($map->mx->id) && is_numeric($map->mx->id)) {
			$identity['external_ids']['mx_id'] = (int) $map->mx->id;
		}

		if (empty($identity['external_ids'])) {
			$identity['external_ids'] = null;
		}

		return $identity;
	}


	private function recordPlayedMapOrderEntry(array $currentMapSnapshot, $sourceCallback) {
		$mapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';
		if ($mapUid === '') {
			return;
		}

		$isRepeat = false;
		if (!empty($this->playedMapHistory)) {
			$lastPlayedMap = $this->playedMapHistory[count($this->playedMapHistory) - 1];
			$lastUid = (isset($lastPlayedMap['uid']) ? (string) $lastPlayedMap['uid'] : '');
			$isRepeat = ($lastUid !== '' && $lastUid === $mapUid);
		}

		$this->playedMapHistory[] = array(
			'order' => count($this->playedMapHistory) + 1,
			'uid' => $mapUid,
			'name' => isset($currentMapSnapshot['name']) ? (string) $currentMapSnapshot['name'] : '',
			'file' => isset($currentMapSnapshot['file']) ? (string) $currentMapSnapshot['file'] : '',
			'environment' => isset($currentMapSnapshot['environment']) ? (string) $currentMapSnapshot['environment'] : '',
			'is_repeat' => $isRepeat,
			'observed_at' => time(),
			'source_callback' => (string) $sourceCallback,
		);

		if (count($this->playedMapHistory) > $this->playedMapHistoryLimit) {
			$this->playedMapHistory = array_slice($this->playedMapHistory, -1 * $this->playedMapHistoryLimit);
		}
	}

}
