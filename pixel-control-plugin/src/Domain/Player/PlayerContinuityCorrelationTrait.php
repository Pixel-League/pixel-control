<?php

namespace PixelControl\Domain\Player;

use ManiaControl\Players\Player;

trait PlayerContinuityCorrelationTrait {

	private function nextPlayerTransitionSequence() {
		$this->playerTransitionSequence++;
		return $this->playerTransitionSequence;
	}


	private function buildReconnectContinuityTelemetry(
		array $transitionDefinition,
		$currentPlayerSnapshot,
		$previousPlayerSnapshot,
		$sourceCallback,
		$transitionSequence,
		$observedAt
	) {
		$eventKind = isset($transitionDefinition['event_kind']) ? (string) $transitionDefinition['event_kind'] : 'player.unknown';
		$transitionKind = isset($transitionDefinition['transition_kind']) ? (string) $transitionDefinition['transition_kind'] : 'unknown';
		$playerLogin = $this->resolvePlayerLoginFromSnapshots($currentPlayerSnapshot, $previousPlayerSnapshot);
		$beforeConnectivity = $this->resolvePlayerConnectivityState($previousPlayerSnapshot);
		$afterConnectivity = $this->resolvePlayerConnectivityState($currentPlayerSnapshot);

		if ($playerLogin === '') {
			$fieldAvailability = array(
				'player_login' => false,
				'session_id' => false,
				'session_ordinal' => false,
				'connected_before' => $beforeConnectivity !== 'unknown',
				'connected_after' => $afterConnectivity !== 'unknown',
				'transition_sequence' => true,
			);

			return array(
				'identity_key' => 'unknown',
				'player_login' => '',
				'event_kind' => $eventKind,
				'transition_kind' => $transitionKind,
				'transition_state' => 'unavailable',
				'continuity_state' => 'unknown',
				'session_id' => null,
				'session_ordinal' => null,
				'previous_session_id' => null,
				'reconnect_count' => 0,
				'connected_before' => $beforeConnectivity,
				'connected_after' => $afterConnectivity,
				'last_disconnect_at' => null,
				'seconds_since_last_disconnect' => null,
				'source_callback' => $sourceCallback,
				'observed_at' => (int) $observedAt,
				'ordering' => array(
					'global_transition_sequence' => (int) $transitionSequence,
					'player_transition_sequence' => null,
				),
				'field_availability' => $fieldAvailability,
				'missing_fields' => array('player_login', 'session_id', 'session_ordinal'),
			);
		}

		if (!isset($this->playerSessionStateCache[$playerLogin]) || !is_array($this->playerSessionStateCache[$playerLogin])) {
			$this->playerSessionStateCache[$playerLogin] = $this->buildDefaultPlayerSessionState($playerLogin);
		}

		$sessionState = $this->playerSessionStateCache[$playerLogin];
		$sessionState['player_transition_count'] = isset($sessionState['player_transition_count'])
			? ((int) $sessionState['player_transition_count']) + 1
			: 1;

		$previousSessionId = isset($sessionState['session_id']) ? trim((string) $sessionState['session_id']) : '';
		$transitionState = 'state_update';
		$continuityState = 'continuous';
		$secondsSinceLastDisconnect = null;

		$lastDisconnectAt = isset($sessionState['last_disconnect_at']) && is_numeric($sessionState['last_disconnect_at'])
			? (int) $sessionState['last_disconnect_at']
			: 0;

		$isConnectEvent = ($eventKind === 'player.connect') || ($beforeConnectivity === 'disconnected' && $afterConnectivity === 'connected');
		$isDisconnectEvent = ($eventKind === 'player.disconnect') || $afterConnectivity === 'disconnected';

		if ($isConnectEvent) {
			$sessionState['session_ordinal'] = max(0, (int) $sessionState['session_ordinal']) + 1;
			$sessionState['session_id'] = $this->buildPlayerSessionId($playerLogin, $sessionState['session_ordinal']);
			$sessionState['last_connect_at'] = (int) $observedAt;
			$sessionState['last_connect_transition_sequence'] = (int) $transitionSequence;

			$isReconnect = ($lastDisconnectAt > 0) || (isset($sessionState['last_connectivity_state']) && $sessionState['last_connectivity_state'] === 'disconnected');
			if ($isReconnect) {
				$sessionState['reconnect_count'] = isset($sessionState['reconnect_count']) ? ((int) $sessionState['reconnect_count']) + 1 : 1;
				$transitionState = 'reconnect';
				$continuityState = 'resumed';
				if ($lastDisconnectAt > 0) {
					$secondsSinceLastDisconnect = max(0, (int) $observedAt - $lastDisconnectAt);
				}
			} else if ((int) $sessionState['session_ordinal'] === 1) {
				$transitionState = 'initial_connect';
				$continuityState = 'continuous';
			} else {
				$transitionState = 'connect';
				$continuityState = 'continuous';
			}
		} else if ($isDisconnectEvent) {
			$transitionState = 'disconnect';
			$continuityState = 'disconnected';
			$sessionState['last_disconnect_at'] = (int) $observedAt;
			$sessionState['last_disconnect_transition_sequence'] = (int) $transitionSequence;
		} else if ($transitionKind === 'batch_refresh') {
			$transitionState = 'batch_refresh';
			$continuityState = ($afterConnectivity === 'disconnected') ? 'disconnected' : 'continuous';
		} else if ($transitionKind === 'state_change') {
			$transitionState = 'state_change';
			$continuityState = ($afterConnectivity === 'disconnected') ? 'disconnected' : 'continuous';
		} else {
			$transitionState = 'state_update';
			$continuityState = ($afterConnectivity === 'disconnected') ? 'disconnected' : 'continuous';
		}

		$sessionState['last_connectivity_state'] = $afterConnectivity;
		$sessionState['last_event_kind'] = $eventKind;
		$sessionState['last_seen_at'] = (int) $observedAt;
		$sessionState['last_transition_sequence'] = (int) $transitionSequence;

		if (is_array($currentPlayerSnapshot) && array_key_exists('team_id', $currentPlayerSnapshot)) {
			$sessionState['last_team_id'] = $currentPlayerSnapshot['team_id'];
		}

		$this->playerSessionStateCache[$playerLogin] = $sessionState;

		$resolvedSessionId = isset($sessionState['session_id']) ? trim((string) $sessionState['session_id']) : '';
		$resolvedSessionOrdinal = isset($sessionState['session_ordinal']) ? (int) $sessionState['session_ordinal'] : 0;
		$resolvedReconnectCount = isset($sessionState['reconnect_count']) ? (int) $sessionState['reconnect_count'] : 0;
		$resolvedLastDisconnectAt = isset($sessionState['last_disconnect_at']) && is_numeric($sessionState['last_disconnect_at'])
			? (int) $sessionState['last_disconnect_at']
			: null;

		$fieldAvailability = array(
			'player_login' => $playerLogin !== '',
			'session_id' => $resolvedSessionId !== '',
			'session_ordinal' => $resolvedSessionOrdinal > 0,
			'previous_session_id' => $previousSessionId !== '',
			'connected_before' => $beforeConnectivity !== 'unknown',
			'connected_after' => $afterConnectivity !== 'unknown',
			'last_disconnect_at' => $resolvedLastDisconnectAt !== null,
			'seconds_since_last_disconnect' => $secondsSinceLastDisconnect !== null,
			'transition_sequence' => true,
			'player_transition_sequence' => isset($sessionState['player_transition_count']) && (int) $sessionState['player_transition_count'] > 0,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'identity_key' => 'player_login:' . $playerLogin,
			'player_login' => $playerLogin,
			'event_kind' => $eventKind,
			'transition_kind' => $transitionKind,
			'transition_state' => $transitionState,
			'continuity_state' => $continuityState,
			'session_id' => ($resolvedSessionId !== '' ? $resolvedSessionId : null),
			'session_ordinal' => ($resolvedSessionOrdinal > 0 ? $resolvedSessionOrdinal : null),
			'previous_session_id' => ($previousSessionId !== '' ? $previousSessionId : null),
			'reconnect_count' => $resolvedReconnectCount,
			'connected_before' => $beforeConnectivity,
			'connected_after' => $afterConnectivity,
			'last_disconnect_at' => $resolvedLastDisconnectAt,
			'seconds_since_last_disconnect' => $secondsSinceLastDisconnect,
			'source_callback' => $sourceCallback,
			'observed_at' => (int) $observedAt,
			'ordering' => array(
				'global_transition_sequence' => (int) $transitionSequence,
				'player_transition_sequence' => isset($sessionState['player_transition_count']) ? (int) $sessionState['player_transition_count'] : null,
			),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}


	private function buildSideChangeTelemetry(
		array $transitionDefinition,
		$currentPlayerSnapshot,
		$previousPlayerSnapshot,
		array $stateDelta,
		$sourceCallback,
		$transitionSequence,
		$observedAt
	) {
		$playerLogin = $this->resolvePlayerLoginFromSnapshots($currentPlayerSnapshot, $previousPlayerSnapshot);
		$previousTeamId = $this->getSnapshotField($previousPlayerSnapshot, 'team_id');
		$currentTeamId = $this->getSnapshotField($currentPlayerSnapshot, 'team_id');
		$previousSide = $this->buildSideProjectionFromSnapshot($previousPlayerSnapshot);
		$currentSide = $this->buildSideProjectionFromSnapshot($currentPlayerSnapshot);

		$teamChanged = false;
		if (isset($stateDelta['team_id']) && is_array($stateDelta['team_id']) && isset($stateDelta['team_id']['changed'])) {
			$teamChanged = (bool) $stateDelta['team_id']['changed'];
		} else {
			$teamChanged = ($previousTeamId !== $currentTeamId);
		}

		$sideChanged = ($previousSide !== $currentSide) && $previousSide !== 'unknown' && $currentSide !== 'unknown';
		$detected = ($playerLogin !== '') && ($teamChanged || $sideChanged);

		$transitionKind = 'none';
		if ($playerLogin === '') {
			$transitionKind = 'unavailable';
		} else if ($detected && ($previousSide === 'unassigned' || $currentSide === 'unassigned')) {
			$transitionKind = 'assignment_change';
		} else if ($detected && $sideChanged) {
			$transitionKind = 'side_change';
		} else if ($detected && $teamChanged) {
			$transitionKind = 'team_change';
		}

		$dedupeKey = null;
		if ($playerLogin !== '' && $detected) {
			$dedupeKey = 'pc-side-' . sha1(
				$playerLogin . '|'
				. (string) $previousTeamId . '|'
				. (string) $currentTeamId . '|'
				. (string) $transitionSequence . '|'
				. (string) $sourceCallback
			);
		}

		$playerTransitionSequence = null;
		if ($playerLogin !== '' && isset($this->playerSessionStateCache[$playerLogin]['player_transition_count'])) {
			$playerTransitionSequence = (int) $this->playerSessionStateCache[$playerLogin]['player_transition_count'];
		}

		$fieldAvailability = array(
			'player_login' => $playerLogin !== '',
			'previous_team_id' => $previousTeamId !== null,
			'current_team_id' => $currentTeamId !== null,
			'previous_side' => $previousSide !== 'unknown',
			'current_side' => $currentSide !== 'unknown',
			'dedupe_key' => $dedupeKey !== null,
			'transition_sequence' => true,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'detected' => $detected,
			'transition_kind' => $transitionKind,
			'event_kind' => isset($transitionDefinition['event_kind']) ? (string) $transitionDefinition['event_kind'] : 'player.unknown',
			'player_login' => $playerLogin,
			'previous_team_id' => $previousTeamId,
			'current_team_id' => $currentTeamId,
			'previous_side' => $previousSide,
			'current_side' => $currentSide,
			'team_changed' => $teamChanged,
			'side_changed' => $sideChanged,
			'dedupe_key' => $dedupeKey,
			'source_callback' => $sourceCallback,
			'observed_at' => (int) $observedAt,
			'ordering' => array(
				'global_transition_sequence' => (int) $transitionSequence,
				'player_transition_sequence' => $playerTransitionSequence,
			),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}


	private function buildDefaultPlayerSessionState($playerLogin) {
		return array(
			'identity_key' => 'player_login:' . $playerLogin,
			'session_id' => '',
			'session_ordinal' => 0,
			'reconnect_count' => 0,
			'player_transition_count' => 0,
			'last_connectivity_state' => 'unknown',
			'last_disconnect_at' => 0,
			'last_disconnect_transition_sequence' => 0,
			'last_connect_at' => 0,
			'last_connect_transition_sequence' => 0,
			'last_seen_at' => 0,
			'last_transition_sequence' => 0,
			'last_event_kind' => 'player.unknown',
			'last_team_id' => null,
		);
	}


	private function buildPlayerSessionId($playerLogin, $sessionOrdinal) {
		$normalizedLogin = $this->normalizeIdentifier($playerLogin, 'unknown_player');
		$normalizedOrdinal = max(1, (int) $sessionOrdinal);

		return 'pc-session-' . $normalizedLogin . '-' . $normalizedOrdinal;
	}


	private function buildSideProjectionFromSnapshot($snapshot) {
		if (!is_array($snapshot)) {
			return 'unknown';
		}

		$isSpectator = $this->getSnapshotField($snapshot, 'is_spectator');
		if ($isSpectator === true) {
			return 'spectator';
		}

		$teamId = $this->getSnapshotField($snapshot, 'team_id');
		if ($teamId === null || $teamId === '') {
			return 'unassigned';
		}

		if (!is_numeric($teamId)) {
			return 'unknown';
		}

		return 'team_' . (int) $teamId;
	}


	private function buildRosterStateTelemetry($currentPlayerSnapshot, $previousPlayerSnapshot) {
		$currentState = $this->buildPlayerRosterState($currentPlayerSnapshot);
		$previousState = $this->buildPlayerRosterState($previousPlayerSnapshot);

		$fieldAvailability = array(
			'current_snapshot' => is_array($currentPlayerSnapshot),
			'previous_snapshot' => is_array($previousPlayerSnapshot),
			'aggregate' => $this->maniaControl !== null,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'current' => $currentState,
			'previous' => $previousState,
			'delta' => $this->buildRosterStateDelta($previousState, $currentState),
			'aggregate' => $this->buildRosterAggregateSnapshot(),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}


	private function buildPlayerRosterState($snapshot) {
		if (!is_array($snapshot)) {
			return array(
				'connectivity_state' => 'unknown',
				'spectator_state' => 'unknown',
				'team_id' => null,
				'readiness_state' => 'unknown',
				'eligibility_state' => 'unknown',
				'has_player_slot' => null,
				'can_join_round' => null,
				'forced_spectator_state' => null,
			);
		}

		$spectatorState = 'unknown';
		$isSpectator = $this->getSnapshotField($snapshot, 'is_spectator');
		$isTemporarySpectator = $this->getSnapshotField($snapshot, 'is_temporary_spectator');
		$isPureSpectator = $this->getSnapshotField($snapshot, 'is_pure_spectator');

		if ($isSpectator === false) {
			$spectatorState = 'player';
		} else if ($isSpectator === true && $isTemporarySpectator === true) {
			$spectatorState = 'temporary_spectator';
		} else if ($isSpectator === true && $isPureSpectator === true) {
			$spectatorState = 'pure_spectator';
		} else if ($isSpectator === true) {
			$spectatorState = 'spectator';
		}

		return array(
			'connectivity_state' => $this->resolvePlayerConnectivityState($snapshot),
			'spectator_state' => $spectatorState,
			'team_id' => $this->getSnapshotField($snapshot, 'team_id'),
			'readiness_state' => $this->resolvePlayerReadinessState($snapshot),
			'eligibility_state' => $this->resolvePlayerEligibilityState($snapshot),
			'has_player_slot' => $this->getSnapshotField($snapshot, 'has_player_slot'),
			'can_join_round' => $this->resolvePlayerCanJoinRound($snapshot),
			'forced_spectator_state' => $this->getSnapshotField($snapshot, 'forced_spectator_state'),
		);
	}


	private function buildRosterStateDelta(array $previousState, array $currentState) {
		return array(
			'connectivity_state' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['connectivity_state']) ? $previousState['connectivity_state'] : 'unknown',
				isset($currentState['connectivity_state']) ? $currentState['connectivity_state'] : 'unknown'
			),
			'spectator_state' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['spectator_state']) ? $previousState['spectator_state'] : 'unknown',
				isset($currentState['spectator_state']) ? $currentState['spectator_state'] : 'unknown'
			),
			'team_id' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['team_id']) ? $previousState['team_id'] : null,
				isset($currentState['team_id']) ? $currentState['team_id'] : null
			),
			'readiness_state' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['readiness_state']) ? $previousState['readiness_state'] : 'unknown',
				isset($currentState['readiness_state']) ? $currentState['readiness_state'] : 'unknown'
			),
			'eligibility_state' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['eligibility_state']) ? $previousState['eligibility_state'] : 'unknown',
				isset($currentState['eligibility_state']) ? $currentState['eligibility_state'] : 'unknown'
			),
			'has_player_slot' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['has_player_slot']) ? $previousState['has_player_slot'] : null,
				isset($currentState['has_player_slot']) ? $currentState['has_player_slot'] : null
			),
			'can_join_round' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['can_join_round']) ? $previousState['can_join_round'] : null,
				isset($currentState['can_join_round']) ? $currentState['can_join_round'] : null
			),
		);
	}


	private function buildRosterAggregateSnapshot() {
		$aggregate = array(
			'player_count' => 0,
			'connected_count' => 0,
			'spectator_count' => 0,
			'temporary_spectator_count' => 0,
			'ready_count' => 0,
			'eligibility' => array(
				'eligible' => 0,
				'restricted' => 0,
				'ineligible' => 0,
				'unknown' => 0,
			),
			'readiness' => array(
				'ready' => 0,
				'connected_idle' => 0,
				'joining' => 0,
				'waiting_slot' => 0,
				'spectating' => 0,
				'spectating_temporary' => 0,
				'disconnected' => 0,
				'unknown' => 0,
			),
			'team_distribution' => array(),
		);

		if (!$this->maniaControl) {
			return $aggregate;
		}

		$players = $this->maniaControl->getPlayerManager()->getPlayers(false);
		if (!is_array($players)) {
			return $aggregate;
		}

		foreach ($players as $player) {
			if (!$player instanceof Player) {
				continue;
			}

			$aggregate['player_count']++;
			$playerSnapshot = $this->buildPlayerTelemetrySnapshot($player);
			if (!is_array($playerSnapshot)) {
				continue;
			}

			$connectivityState = $this->resolvePlayerConnectivityState($playerSnapshot);
			if ($connectivityState === 'connected') {
				$aggregate['connected_count']++;
			}

			if ($this->getSnapshotField($playerSnapshot, 'is_spectator') === true) {
				$aggregate['spectator_count']++;
			}

			if ($this->getSnapshotField($playerSnapshot, 'is_temporary_spectator') === true) {
				$aggregate['temporary_spectator_count']++;
			}

			$readinessState = $this->resolvePlayerReadinessState($playerSnapshot);
			if (!array_key_exists($readinessState, $aggregate['readiness'])) {
				$aggregate['readiness'][$readinessState] = 0;
			}
			$aggregate['readiness'][$readinessState]++;
			if ($readinessState === 'ready') {
				$aggregate['ready_count']++;
			}

			$eligibilityState = $this->resolvePlayerEligibilityState($playerSnapshot);
			if (!array_key_exists($eligibilityState, $aggregate['eligibility'])) {
				$aggregate['eligibility'][$eligibilityState] = 0;
			}
			$aggregate['eligibility'][$eligibilityState]++;

			$teamId = $this->getSnapshotField($playerSnapshot, 'team_id');
			$teamKey = ($teamId === null ? 'unknown' : (string) $teamId);
			if (!array_key_exists($teamKey, $aggregate['team_distribution'])) {
				$aggregate['team_distribution'][$teamKey] = 0;
			}
			$aggregate['team_distribution'][$teamKey]++;
		}

		ksort($aggregate['eligibility']);
		ksort($aggregate['readiness']);
		ksort($aggregate['team_distribution']);

		return $aggregate;
	}


	private function buildPlayerAdminCorrelation($currentPlayerSnapshot, array $transitionDefinition) {
		$this->pruneRecentAdminActionContexts();

		$playerLogin = trim((string) $this->getSnapshotField($currentPlayerSnapshot, 'login'));
		$hasRecentAdminActions = !empty($this->recentAdminActionContexts);
		if ($playerLogin === '' || !$hasRecentAdminActions) {
			return $this->buildEmptyPlayerAdminCorrelation($playerLogin !== '', $hasRecentAdminActions);
		}

		$now = time();
		for ($index = count($this->recentAdminActionContexts) - 1; $index >= 0; $index--) {
			$adminContext = $this->recentAdminActionContexts[$index];
			if (!is_array($adminContext) || !isset($adminContext['observed_at'])) {
				continue;
			}

			$secondsSinceAdminAction = max(0, $now - (int) $adminContext['observed_at']);
			if ($secondsSinceAdminAction > $this->adminCorrelationWindowSeconds) {
				continue;
			}

			$inferenceReasons = array();
			$confidence = 'low';

			$actorLogin = isset($adminContext['actor_login']) ? trim((string) $adminContext['actor_login']) : '';
			$targetId = isset($adminContext['target_id']) ? trim((string) $adminContext['target_id']) : '';
			$targetScope = isset($adminContext['target_scope']) ? trim((string) $adminContext['target_scope']) : 'unknown';

			if ($actorLogin !== '' && strcasecmp($actorLogin, $playerLogin) === 0) {
				$inferenceReasons[] = 'actor_login_match';
				$confidence = 'high';
			}

			if ($targetId !== '' && strcasecmp($targetId, $playerLogin) === 0) {
				$inferenceReasons[] = 'target_id_match';
				$confidence = 'high';
			}

			if (
				$targetScope === 'server'
				|| $targetScope === 'match'
				|| $targetScope === 'map'
				|| $targetScope === 'round'
			) {
				$inferenceReasons[] = 'target_scope_' . $targetScope;
				if ($confidence !== 'high') {
					$confidence = 'medium';
				}
			}

			if (isset($transitionDefinition['transition_kind']) && $transitionDefinition['transition_kind'] === 'batch_refresh') {
				$inferenceReasons[] = 'batch_refresh_transition';
			}

			if (empty($inferenceReasons)) {
				continue;
			}

			$fieldAvailability = array(
				'admin_event_id' => isset($adminContext['event_id']) && trim((string) $adminContext['event_id']) !== '',
				'action_name' => isset($adminContext['action_name']) && trim((string) $adminContext['action_name']) !== '',
				'target_scope' => $targetScope !== '',
				'actor_login' => $actorLogin !== '',
				'target_id' => $targetId !== '',
				'seconds_since_admin_action' => true,
			);

			$missingFields = array();
			foreach ($fieldAvailability as $field => $available) {
				if ($available) {
					continue;
				}

				$missingFields[] = $field;
			}

			return array(
				'correlated' => true,
				'window_seconds' => $this->adminCorrelationWindowSeconds,
				'confidence' => $confidence,
				'matched_by' => $inferenceReasons[0],
				'seconds_since_admin_action' => $secondsSinceAdminAction,
				'inference_reasons' => $inferenceReasons,
				'admin_event' => array(
					'event_id' => isset($adminContext['event_id']) ? (string) $adminContext['event_id'] : '',
					'event_name' => isset($adminContext['event_name']) ? (string) $adminContext['event_name'] : '',
					'source_callback' => isset($adminContext['source_callback']) ? (string) $adminContext['source_callback'] : '',
					'action_name' => isset($adminContext['action_name']) ? (string) $adminContext['action_name'] : '',
					'action_type' => isset($adminContext['action_type']) ? (string) $adminContext['action_type'] : 'unknown',
					'action_phase' => isset($adminContext['action_phase']) ? (string) $adminContext['action_phase'] : 'unknown',
					'target_scope' => $targetScope,
					'target_id' => $targetId,
					'initiator_kind' => isset($adminContext['initiator_kind']) ? (string) $adminContext['initiator_kind'] : 'unknown',
					'actor_login' => $actorLogin,
					'observed_at' => isset($adminContext['observed_at']) ? (int) $adminContext['observed_at'] : 0,
				),
				'field_availability' => $fieldAvailability,
				'missing_fields' => $missingFields,
			);
		}

		return $this->buildEmptyPlayerAdminCorrelation(true, true);
	}


	private function buildEmptyPlayerAdminCorrelation($hasPlayerLogin, $hasRecentAdminActions) {
		$fieldAvailability = array(
			'player_login' => (bool) $hasPlayerLogin,
			'recent_admin_actions' => (bool) $hasRecentAdminActions,
			'admin_event' => false,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'correlated' => false,
			'window_seconds' => $this->adminCorrelationWindowSeconds,
			'confidence' => 'none',
			'matched_by' => 'none',
			'seconds_since_admin_action' => null,
			'inference_reasons' => array(),
			'admin_event' => null,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}


	private function resolvePlayerTransitionSequenceForLogin($playerLogin) {
		$normalizedPlayerLogin = trim((string) $playerLogin);
		if ($normalizedPlayerLogin === '') {
			return null;
		}

		if (!isset($this->playerSessionStateCache[$normalizedPlayerLogin]) || !is_array($this->playerSessionStateCache[$normalizedPlayerLogin])) {
			return null;
		}

		$sessionState = $this->playerSessionStateCache[$normalizedPlayerLogin];
		if (!isset($sessionState['player_transition_count']) || !is_numeric($sessionState['player_transition_count'])) {
			return null;
		}

		$transitionCount = (int) $sessionState['player_transition_count'];
		if ($transitionCount < 1) {
			return null;
		}

		return $transitionCount;
	}

}
