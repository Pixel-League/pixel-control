<?php

namespace PixelControl\VetoDraft;

use ManiaControl\Maps\MapManager;

class ManiaControlMapRuntimeAdapter implements MapRuntimeAdapterInterface {
	/** @var MapManager $mapManager */
	private $mapManager;

	public function __construct(MapManager $mapManager) {
		$this->mapManager = $mapManager;
	}

	public function clearQueue() {
		$mapQueue = $this->mapManager->getMapQueue();
		if (!$mapQueue) {
			return false;
		}

		$mapQueue->clearMapQueue(null);
		return true;
	}

	public function enqueueMap($mapUid) {
		$mapUid = trim((string) $mapUid);
		if ($mapUid === '') {
			return false;
		}

		$mapQueue = $this->mapManager->getMapQueue();
		if (!$mapQueue) {
			return false;
		}

		return (bool) $mapQueue->serverAddMapToMapQueue($mapUid);
	}

	public function launchFirstMap($firstMapUid) {
		$normalizedFirstMapUid = strtolower(trim((string) $firstMapUid));
		if ($normalizedFirstMapUid === '') {
			return array(
				'enabled' => true,
				'applied' => false,
				'code' => 'map_uid_missing',
				'message' => 'Cannot launch map without a UID.',
			);
		}

		$skipApplied = $this->skipToMap($normalizedFirstMapUid);

		return array(
			'enabled' => true,
			'applied' => $skipApplied,
			'code' => $skipApplied ? 'skipped_to_opener' : 'skip_failed',
			'message' => $skipApplied ? 'Skipped to first selected map.' : 'Failed to skip to first selected map.',
			'first_map_uid' => $normalizedFirstMapUid,
			'current_map_uid' => $this->getCurrentMapUid(),
		);
	}

	public function getCurrentMapUid() {
		$currentMap = $this->mapManager->getCurrentMap();
		if (!is_object($currentMap) || !isset($currentMap->uid)) {
			return '';
		}

		return trim((string) $currentMap->uid);
	}

	public function skipCurrentMap() {
		$mapActions = $this->mapManager->getMapActions();
		if (!$mapActions) {
			return false;
		}

		return (bool) $mapActions->skipMap();
	}

	public function skipToMap($mapUid) {
		$normalizedMapUid = strtolower(trim((string) $mapUid));
		if ($normalizedMapUid === '') {
			return false;
		}

		$mapActions = $this->mapManager->getMapActions();
		if (!$mapActions) {
			return false;
		}

		return (bool) $mapActions->skipToMapByUid($normalizedMapUid);
	}
}
