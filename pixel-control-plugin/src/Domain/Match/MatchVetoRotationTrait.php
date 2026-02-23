<?php

namespace PixelControl\Domain\Match;

use ManiaControl\Maps\Map;

trait MatchVetoRotationTrait {

	private function resetVetoDraftActions() {
		$this->vetoDraftActions = array();
		$this->vetoDraftActionSequence = 0;
	}


	private function recordVetoDraftActionFromLifecycle($variant, $sourceCallback, array $callbackArguments, $adminAction = null) {
		$scriptPayload = $this->extractScriptCallbackPayload($callbackArguments);
		$rawActionValue = $this->extractFirstScalarPayloadValue(
			$scriptPayload,
			array('veto_action', 'draft_action', 'action_kind', 'action', 'kind', 'type', 'command')
		);
		$actionKind = $this->normalizeVetoActionKind($rawActionValue);
		$actionStatus = 'explicit';
		$actionSource = 'script_payload';

		if ($actionKind === 'unknown' && is_array($adminAction)) {
			$actionType = isset($adminAction['action_type']) ? (string) $adminAction['action_type'] : 'unknown';
			if ($actionType === 'map_loading' || $actionType === 'map_unloading') {
				$actionKind = 'lock';
				$actionStatus = 'inferred';
				$actionSource = 'admin_action';
			}
		}

		if ($actionKind === 'unknown' && $variant === 'map.begin') {
			$actionKind = 'lock';
			$actionStatus = 'inferred';
			$actionSource = 'map_boundary';
		}

		if ($actionKind === 'unknown') {
			return;
		}

		$currentMapSnapshot = $this->buildCurrentMapSnapshot();
		$mapUid = $this->extractFirstScalarPayloadValue($scriptPayload, array('map_uid', 'mapid', 'map_id', 'uid'));
		$mapName = $this->extractFirstScalarPayloadValue($scriptPayload, array('map_name', 'map'));
		if ($mapUid === '') {
			$mapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';
		}
		if ($mapName === '') {
			$mapName = isset($currentMapSnapshot['name']) ? (string) $currentMapSnapshot['name'] : '';
		}

		$actor = is_array($adminAction) && isset($adminAction['actor']) && is_array($adminAction['actor'])
			? $adminAction['actor']
			: $this->extractActorSnapshotFromPayload($scriptPayload);

		$this->vetoDraftActionSequence++;
		$orderIndex = $this->vetoDraftActionSequence;

		$fieldAvailability = array(
			'action_kind' => $actionKind !== 'unknown',
			'actor' => is_array($actor) && isset($actor['type']) && $actor['type'] !== 'unknown',
			'map_uid' => $mapUid !== '',
			'source_callback' => trim((string) $sourceCallback) !== '',
			'observed_at' => true,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		$this->vetoDraftActions[] = array(
			'order_index' => $orderIndex,
			'action_kind' => $actionKind,
			'action_status' => $actionStatus,
			'action_source' => $actionSource,
			'raw_action_value' => ($rawActionValue !== '' ? $rawActionValue : null),
			'source_callback' => $sourceCallback,
			'source_channel' => ($this->isScriptLifecycleCallback($sourceCallback) ? 'script' : 'maniaplanet'),
			'observed_at' => time(),
			'actor' => $actor,
			'map' => array(
				'uid' => $mapUid,
				'name' => $mapName,
			),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);

		if (count($this->vetoDraftActions) > $this->vetoDraftActionLimit) {
			$this->vetoDraftActions = array_slice($this->vetoDraftActions, -1 * $this->vetoDraftActionLimit);
		}
	}


	private function normalizeVetoActionKind($rawActionValue) {
		$normalizedValue = $this->normalizeIdentifier($rawActionValue, 'unknown');

		switch ($normalizedValue) {
			case 'ban':
			case 'pick':
			case 'pass':
			case 'lock':
				return $normalizedValue;
			case 'map_loading':
			case 'map_lock':
				return 'lock';
			default:
				return 'unknown';
		}
	}


	private function buildVetoDraftActionSnapshot(array $currentMapSnapshot) {
		$actions = $this->vetoDraftActions;
		$available = !empty($actions);
		$fieldAvailability = array(
			'actions' => $available,
			'current_map_uid' => isset($currentMapSnapshot['uid']) && trim((string) $currentMapSnapshot['uid']) !== '',
			'supported_action_kinds' => true,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $availableField) {
			if ($availableField) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'available' => $available,
			'status' => ($available ? 'partial' : 'unavailable'),
			'reason' => ($available
				? 'veto_actions_inferred_from_available_callbacks'
				: 'veto_callbacks_not_exposed_in_current_runtime'),
			'action_count' => count($actions),
			'supported_action_kinds' => array('ban', 'pick', 'pass', 'lock'),
			'actions' => $actions,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}


	private function buildVetoResultSnapshot(array $vetoDraftActions, array $currentMapSnapshot, array $mapPool, $variant) {
		$actions = isset($vetoDraftActions['actions']) && is_array($vetoDraftActions['actions'])
			? $vetoDraftActions['actions']
			: array();
		$actionCount = count($actions);
		$currentMapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';

		if ($actionCount === 0) {
			return array(
				'available' => false,
				'status' => 'unavailable',
				'reason' => 'veto_callbacks_not_exposed_in_current_runtime',
				'supported_fields' => array('actor', 'action', 'order', 'timestamp'),
			);
		}

		$lastAction = $actions[$actionCount - 1];
		$lastActionKind = isset($lastAction['action_kind']) ? (string) $lastAction['action_kind'] : 'unknown';

		$fieldAvailability = array(
			'action_count' => true,
			'current_map_uid' => $currentMapUid !== '',
			'last_action_kind' => $lastActionKind !== 'unknown',
			'played_map_order' => !empty($this->playedMapHistory),
			'map_pool' => !empty($mapPool),
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $availableField) {
			if ($availableField) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'available' => true,
			'status' => 'partial',
			'reason' => 'final_selection_inferred_from_partial_veto_actions',
			'variant' => $variant,
			'action_count' => $actionCount,
			'last_action_kind' => $lastActionKind,
			'selection_basis' => array(
				'current_map_uid' => $currentMapUid,
				'played_map_order' => $this->playedMapHistory,
				'map_pool_size' => count($mapPool),
			),
			'final_map' => $currentMapSnapshot,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}


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

		$vetoDraftActions = $this->buildVetoDraftActionSnapshot($currentMapSnapshot);
		$vetoResult = $this->buildVetoResultSnapshot($vetoDraftActions, $currentMapSnapshot, $mapPool, $variant);
		$vetoDraftMode = '';
		$vetoDraftSessionStatus = '';
		$seriesTargets = $this->getSeriesControlSnapshot();
		$matchmakingLifecycle = $this->buildMatchmakingLifecycleStatusSnapshot();
		$matchmakingReadyArmed = (bool) $this->vetoDraftMatchmakingReadyArmed;

		$authoritativeVetoSnapshots = $this->resolveAuthoritativeVetoDraftSnapshots();
		if (is_array($authoritativeVetoSnapshots)) {
			if (isset($authoritativeVetoSnapshots['actions']) && is_array($authoritativeVetoSnapshots['actions'])) {
				$vetoDraftActions = $authoritativeVetoSnapshots['actions'];
			}

			if (isset($authoritativeVetoSnapshots['result']) && is_array($authoritativeVetoSnapshots['result'])) {
				$vetoResult = $authoritativeVetoSnapshots['result'];
			}

			$vetoDraftMode = isset($authoritativeVetoSnapshots['mode']) ? trim((string) $authoritativeVetoSnapshots['mode']) : '';
			$vetoDraftSessionStatus = isset($authoritativeVetoSnapshots['status']) ? trim((string) $authoritativeVetoSnapshots['status']) : '';
		}

		$fieldAvailability = array(
			'map_pool' => !empty($mapPool),
			'current_map' => $currentMapUid !== '',
			'current_map_index' => $currentMapIndex !== null,
			'next_maps' => !empty($nextMaps),
			'played_map_order' => !empty($this->playedMapHistory),
			'veto_draft_actions' => isset($vetoDraftActions['available']) ? (bool) $vetoDraftActions['available'] : false,
			'veto_result' => is_array($vetoResult) && isset($vetoResult['status']),
			'veto_draft_mode' => $vetoDraftMode !== '',
			'veto_draft_session_status' => $vetoDraftSessionStatus !== '',
			'matchmaking_ready_armed' => true,
			'series_targets' => is_array($seriesTargets) && !empty($seriesTargets),
			'matchmaking_lifecycle' => is_array($matchmakingLifecycle) && isset($matchmakingLifecycle['status']),
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
			'veto_draft_mode' => $vetoDraftMode,
			'veto_draft_session_status' => $vetoDraftSessionStatus,
			'matchmaking_ready_armed' => $matchmakingReadyArmed,
			'series_targets' => $seriesTargets,
			'matchmaking_lifecycle' => $matchmakingLifecycle,
			'veto_draft_actions' => $vetoDraftActions,
			'veto_result' => $vetoResult,
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
