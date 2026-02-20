<?php

namespace PixelControl\Domain\Player;

use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\Structures\ShootMania\OnCaptureStructure;
use ManiaControl\Callbacks\Structures\ShootMania\OnHitNearMissArmorEmptyBaseStructure;
use ManiaControl\Callbacks\Structures\ShootMania\OnHitStructure;
use ManiaControl\Callbacks\Structures\ShootMania\OnScoresStructure;
use ManiaControl\Callbacks\Structures\ShootMania\Models\Position;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Logger;
use ManiaControl\ManiaControl;
use ManiaControl\Maps\Map;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Plugins\PluginManager;
use ManiaControl\Players\Player;
use PixelControl\Api\AsyncPixelControlApiClient;
use PixelControl\Api\DeliveryError;
use PixelControl\Api\EventEnvelope;
use PixelControl\Api\PixelControlApiClientInterface;
use PixelControl\Callbacks\CallbackRegistry;
use PixelControl\Queue\EventQueueInterface;
use PixelControl\Queue\InMemoryEventQueue;
use PixelControl\Queue\QueueItem;
use PixelControl\Retry\ExponentialBackoffRetryPolicy;
use PixelControl\Retry\RetryPolicyInterface;
use PixelControl\Stats\PlayerCombatStatsStore;
trait PlayerDomainTrait {
	private function resolvePlayerTransitionDefinition($sourceCallback) {
		switch ($this->normalizeIdentifier($sourceCallback, 'unknown')) {
			case 'playermanagercallback_playerconnect':
				return array(
					'event_kind' => 'player.connect',
					'transition_kind' => 'connectivity',
					'forced_connectivity' => 'connected',
				);
			case 'playermanagercallback_playerdisconnect':
				return array(
					'event_kind' => 'player.disconnect',
					'transition_kind' => 'connectivity',
					'forced_connectivity' => 'disconnected',
				);
			case 'playermanagercallback_playerinfochanged':
				return array(
					'event_kind' => 'player.info_changed',
					'transition_kind' => 'state_change',
					'forced_connectivity' => null,
				);
			case 'playermanagercallback_playerinfoschanged':
				return array(
					'event_kind' => 'player.infos_changed',
					'transition_kind' => 'batch_refresh',
					'forced_connectivity' => null,
				);
			default:
				return array(
					'event_kind' => 'player.unknown',
					'transition_kind' => 'unknown',
					'forced_connectivity' => null,
				);
		}
	}

	private function buildPlayerPayload($sourceCallback, array $callbackArguments) {
		$transitionDefinition = $this->resolvePlayerTransitionDefinition($sourceCallback);
		$transitionSequence = $this->nextPlayerTransitionSequence();
		$observedAt = time();
		$player = $this->extractPlayerFromCallbackArguments($callbackArguments);
		$currentPlayerSnapshot = $this->buildPlayerTelemetrySnapshot($player);

		if (is_array($currentPlayerSnapshot) && $transitionDefinition['forced_connectivity'] !== null) {
			$currentPlayerSnapshot['is_connected'] = $transitionDefinition['forced_connectivity'] === 'connected';
			$currentPlayerSnapshot['connectivity_state'] = $transitionDefinition['forced_connectivity'];
			$currentPlayerSnapshot['readiness_state'] = $this->resolvePlayerReadinessState($currentPlayerSnapshot);
			$currentPlayerSnapshot['eligibility_state'] = $this->resolvePlayerEligibilityState($currentPlayerSnapshot);
			$currentPlayerSnapshot['can_join_round'] = $this->resolvePlayerCanJoinRound($currentPlayerSnapshot);
		}

		$previousPlayerSnapshot = $this->resolvePreviousPlayerSnapshot($currentPlayerSnapshot);
		$stateDelta = $this->buildPlayerStateDelta($previousPlayerSnapshot, $currentPlayerSnapshot);
		$permissionSignals = $this->buildPlayerPermissionSignals($currentPlayerSnapshot, $stateDelta);
		$rosterState = $this->buildRosterStateTelemetry($currentPlayerSnapshot, $previousPlayerSnapshot);
		$adminCorrelation = $this->buildPlayerAdminCorrelation($currentPlayerSnapshot, $transitionDefinition);
		$reconnectContinuity = $this->buildReconnectContinuityTelemetry(
			$transitionDefinition,
			$currentPlayerSnapshot,
			$previousPlayerSnapshot,
			$sourceCallback,
			$transitionSequence,
			$observedAt
		);
		$sideChange = $this->buildSideChangeTelemetry(
			$transitionDefinition,
			$currentPlayerSnapshot,
			$previousPlayerSnapshot,
			$stateDelta,
			$sourceCallback,
			$transitionSequence,
			$observedAt
		);
		$rosterSnapshot = $this->buildPlayerSnapshot();
		$constraintSignals = $this->buildPlayerConstraintSignals(
			$transitionDefinition,
			$currentPlayerSnapshot,
			$previousPlayerSnapshot,
			$stateDelta,
			$rosterSnapshot,
			$sourceCallback,
			$transitionSequence,
			$observedAt
		);

		$fieldAvailability = array(
			'player' => is_array($currentPlayerSnapshot),
			'player_login' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['login']) && trim((string) $currentPlayerSnapshot['login']) !== '',
			'previous_player' => is_array($previousPlayerSnapshot),
			'team_id' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['team_id']) && $currentPlayerSnapshot['team_id'] !== null,
			'is_spectator' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['is_spectator']) && $currentPlayerSnapshot['is_spectator'] !== null,
			'auth_level' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['auth_level']) && $currentPlayerSnapshot['auth_level'] !== null,
			'is_referee' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['is_referee']) && $currentPlayerSnapshot['is_referee'] !== null,
			'readiness_state' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['readiness_state']) && trim((string) $currentPlayerSnapshot['readiness_state']) !== '',
			'eligibility_state' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['eligibility_state']) && trim((string) $currentPlayerSnapshot['eligibility_state']) !== '',
			'roster_state' => is_array($rosterState),
			'admin_correlation' => is_array($adminCorrelation),
			'reconnect_continuity' => is_array($reconnectContinuity),
			'side_change' => is_array($sideChange),
			'constraint_signals' => is_array($constraintSignals),
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		$payload = array(
			'event_kind' => $transitionDefinition['event_kind'],
			'transition_kind' => $transitionDefinition['transition_kind'],
			'source_callback' => $sourceCallback,
			'player' => $currentPlayerSnapshot,
			'previous_player' => $previousPlayerSnapshot,
			'state_delta' => $stateDelta,
			'permission_signals' => $permissionSignals,
			'roster_state' => $rosterState,
			'admin_correlation' => $adminCorrelation,
			'reconnect_continuity' => $reconnectContinuity,
			'side_change' => $sideChange,
			'constraint_signals' => $constraintSignals,
			'roster_snapshot' => $rosterSnapshot,
			'tracked_player_cache_size' => count($this->playerStateCache),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
			'raw_callback_summary' => $this->buildPayloadSummary($callbackArguments),
		);

		if ($transitionDefinition['transition_kind'] === 'batch_refresh') {
			$payload['batch_scope'] = 'server_roster_refresh';
		}

		$this->updatePlayerStateCache($transitionDefinition, $currentPlayerSnapshot);

		return $payload;
	}

	private function extractPlayerFromCallbackArguments(array $callbackArguments) {
		if (empty($callbackArguments)) {
			return null;
		}

		$firstArgument = $callbackArguments[0];
		if ($firstArgument instanceof Player) {
			return $firstArgument;
		}

		if (is_string($firstArgument) && $this->maniaControl) {
			$resolvedPlayer = $this->maniaControl->getPlayerManager()->getPlayer($firstArgument);
			if ($resolvedPlayer instanceof Player) {
				return $resolvedPlayer;
			}
		}

		return null;
	}

	private function buildPlayerTelemetrySnapshot($player) {
		if (!$player instanceof Player) {
			return null;
		}

		$authLevel = isset($player->authLevel) ? (int) $player->authLevel : null;

		$snapshot = array(
			'login' => isset($player->login) ? (string) $player->login : '',
			'nickname' => isset($player->nickname) ? (string) $player->nickname : '',
			'team_id' => isset($player->teamId) ? (int) $player->teamId : null,
			'is_spectator' => isset($player->isSpectator) ? (bool) $player->isSpectator : null,
			'is_temporary_spectator' => isset($player->isTemporarySpectator) ? (bool) $player->isTemporarySpectator : null,
			'is_pure_spectator' => isset($player->isPureSpectator) ? (bool) $player->isPureSpectator : null,
			'is_connected' => isset($player->isConnected) ? (bool) $player->isConnected : null,
			'has_joined_game' => isset($player->hasJoinedGame) ? (bool) $player->hasJoinedGame : null,
			'forced_spectator_state' => isset($player->forcedSpectatorState) ? (int) $player->forcedSpectatorState : null,
			'auth_level' => $authLevel,
			'auth_name' => $this->resolveAuthLevelName($authLevel),
			'auth_role' => $this->resolveAuthLevelRole($authLevel),
			'is_referee' => isset($player->isReferee) ? (bool) $player->isReferee : null,
			'has_player_slot' => isset($player->hasPlayerSlot) ? (bool) $player->hasPlayerSlot : null,
			'is_managed_by_other_server' => isset($player->isManagedByAnOtherServer) ? (bool) $player->isManagedByAnOtherServer : null,
			'is_broadcasting' => isset($player->isBroadcasting) ? (bool) $player->isBroadcasting : null,
			'is_podium_ready' => isset($player->isPodiumReady) ? (bool) $player->isPodiumReady : null,
			'is_official' => isset($player->isOfficial) ? (bool) $player->isOfficial : null,
			'is_server' => isset($player->isServer) ? (bool) $player->isServer : null,
			'is_fake' => method_exists($player, 'isFakePlayer') ? (bool) $player->isFakePlayer() : null,
		);

		$snapshot['connectivity_state'] = $this->resolvePlayerConnectivityState($snapshot);
		$snapshot['readiness_state'] = $this->resolvePlayerReadinessState($snapshot);
		$snapshot['eligibility_state'] = $this->resolvePlayerEligibilityState($snapshot);
		$snapshot['can_join_round'] = $this->resolvePlayerCanJoinRound($snapshot);

		return $snapshot;
	}

	private function buildPlayerStateDelta($previousPlayerSnapshot, $currentPlayerSnapshot) {
		return array(
			'connectivity' => $this->buildPlayerStateDeltaEntry(
				$this->resolvePlayerConnectivityState($previousPlayerSnapshot),
				$this->resolvePlayerConnectivityState($currentPlayerSnapshot)
			),
			'spectator' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'is_spectator'),
				$this->getSnapshotField($currentPlayerSnapshot, 'is_spectator')
			),
			'team_id' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'team_id'),
				$this->getSnapshotField($currentPlayerSnapshot, 'team_id')
			),
			'auth_level' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'auth_level'),
				$this->getSnapshotField($currentPlayerSnapshot, 'auth_level')
			),
			'auth_role' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'auth_role'),
				$this->getSnapshotField($currentPlayerSnapshot, 'auth_role')
			),
			'is_referee' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'is_referee'),
				$this->getSnapshotField($currentPlayerSnapshot, 'is_referee')
			),
			'has_player_slot' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'has_player_slot'),
				$this->getSnapshotField($currentPlayerSnapshot, 'has_player_slot')
			),
			'has_joined_game' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'has_joined_game'),
				$this->getSnapshotField($currentPlayerSnapshot, 'has_joined_game')
			),
			'forced_spectator_state' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'forced_spectator_state'),
				$this->getSnapshotField($currentPlayerSnapshot, 'forced_spectator_state')
			),
			'is_temporary_spectator' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'is_temporary_spectator'),
				$this->getSnapshotField($currentPlayerSnapshot, 'is_temporary_spectator')
			),
			'readiness_state' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'readiness_state'),
				$this->getSnapshotField($currentPlayerSnapshot, 'readiness_state')
			),
			'eligibility_state' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'eligibility_state'),
				$this->getSnapshotField($currentPlayerSnapshot, 'eligibility_state')
			),
			'can_join_round' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'can_join_round'),
				$this->getSnapshotField($currentPlayerSnapshot, 'can_join_round')
			),
		);
	}

	private function buildPlayerStateDeltaEntry($before, $after) {
		return array(
			'before' => $before,
			'after' => $after,
			'changed' => $before !== $after,
		);
	}

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

	private function resolvePlayerLoginFromSnapshots($currentPlayerSnapshot, $previousPlayerSnapshot) {
		$currentLogin = trim((string) $this->getSnapshotField($currentPlayerSnapshot, 'login'));
		if ($currentLogin !== '') {
			return $currentLogin;
		}

		$previousLogin = trim((string) $this->getSnapshotField($previousPlayerSnapshot, 'login'));
		if ($previousLogin !== '') {
			return $previousLogin;
		}

		return '';
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

	private function resolvePlayerConnectivityState($snapshot) {
		if (!is_array($snapshot) || !array_key_exists('is_connected', $snapshot) || $snapshot['is_connected'] === null) {
			return 'unknown';
		}

		return $snapshot['is_connected'] ? 'connected' : 'disconnected';
	}

	private function resolvePlayerReadinessState($snapshot) {
		if (!is_array($snapshot)) {
			return 'unknown';
		}

		$connectivityState = $this->resolvePlayerConnectivityState($snapshot);
		if ($connectivityState === 'disconnected') {
			return 'disconnected';
		}

		$isSpectator = $this->getSnapshotField($snapshot, 'is_spectator');
		$isTemporarySpectator = $this->getSnapshotField($snapshot, 'is_temporary_spectator');
		$hasPlayerSlot = $this->getSnapshotField($snapshot, 'has_player_slot');
		$hasJoinedGame = $this->getSnapshotField($snapshot, 'has_joined_game');

		if ($isSpectator === true) {
			if ($isTemporarySpectator === true) {
				return 'spectating_temporary';
			}

			return 'spectating';
		}

		if ($hasPlayerSlot === false) {
			return 'waiting_slot';
		}

		if ($hasJoinedGame === false) {
			return 'joining';
		}

		if ($connectivityState === 'connected' && $hasPlayerSlot === true && $hasJoinedGame === true) {
			return 'ready';
		}

		if ($connectivityState === 'connected') {
			return 'connected_idle';
		}

		return 'unknown';
	}

	private function resolvePlayerEligibilityState($snapshot) {
		if (!is_array($snapshot)) {
			return 'unknown';
		}

		$connectivityState = $this->resolvePlayerConnectivityState($snapshot);
		if ($connectivityState === 'disconnected') {
			return 'ineligible';
		}

		$isServer = $this->getSnapshotField($snapshot, 'is_server');
		$isManagedByOtherServer = $this->getSnapshotField($snapshot, 'is_managed_by_other_server');
		$hasPlayerSlot = $this->getSnapshotField($snapshot, 'has_player_slot');
		$isSpectator = $this->getSnapshotField($snapshot, 'is_spectator');
		$forcedSpectatorState = $this->getSnapshotField($snapshot, 'forced_spectator_state');

		if ($isServer === true) {
			return 'ineligible';
		}

		if ($isManagedByOtherServer === true) {
			return 'restricted';
		}

		if ($hasPlayerSlot === false || $isSpectator === true) {
			return 'restricted';
		}

		if (is_numeric($forcedSpectatorState) && (int) $forcedSpectatorState > 0) {
			return 'restricted';
		}

		if ($connectivityState === 'connected') {
			return 'eligible';
		}

		return 'unknown';
	}

	private function resolvePlayerCanJoinRound($snapshot) {
		if (!is_array($snapshot)) {
			return null;
		}

		$eligibilityState = isset($snapshot['eligibility_state']) ? (string) $snapshot['eligibility_state'] : $this->resolvePlayerEligibilityState($snapshot);
		$readinessState = isset($snapshot['readiness_state']) ? (string) $snapshot['readiness_state'] : $this->resolvePlayerReadinessState($snapshot);

		if ($eligibilityState === 'ineligible' || $eligibilityState === 'restricted') {
			return false;
		}

		if ($readinessState === 'ready' || $readinessState === 'connected_idle') {
			return true;
		}

		if (
			$readinessState === 'joining'
			|| $readinessState === 'waiting_slot'
			|| $readinessState === 'spectating'
			|| $readinessState === 'spectating_temporary'
			|| $readinessState === 'disconnected'
		) {
			return false;
		}

		return null;
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

	private function resolveAuthLevelName($authLevel) {
		if ($authLevel === null || !is_numeric($authLevel)) {
			return 'Unknown';
		}

		$authLevelName = AuthenticationManager::getAuthLevelName((int) $authLevel);
		if (!is_string($authLevelName)) {
			return 'Unknown';
		}

		$normalizedAuthLevelName = trim($authLevelName);
		if ($normalizedAuthLevelName === '' || $normalizedAuthLevelName === '-') {
			return 'Unknown';
		}

		return $normalizedAuthLevelName;
	}

	private function resolveAuthLevelRole($authLevel) {
		$authLevelName = $this->resolveAuthLevelName($authLevel);
		switch ($authLevelName) {
			case AuthenticationManager::AUTH_NAME_MASTERADMIN:
				return 'master_admin';
			case AuthenticationManager::AUTH_NAME_SUPERADMIN:
				return 'super_admin';
			case AuthenticationManager::AUTH_NAME_ADMIN:
				return 'admin';
			case AuthenticationManager::AUTH_NAME_MODERATOR:
				return 'moderator';
			case AuthenticationManager::AUTH_NAME_PLAYER:
				return 'player';
			default:
			return 'unknown';
		}
	}

	private function resolvePreviousPlayerSnapshot($currentPlayerSnapshot) {
		if (!is_array($currentPlayerSnapshot) || !isset($currentPlayerSnapshot['login'])) {
			return null;
		}

		$playerLogin = trim((string) $currentPlayerSnapshot['login']);
		if ($playerLogin === '' || !isset($this->playerStateCache[$playerLogin]) || !is_array($this->playerStateCache[$playerLogin])) {
			return null;
		}

		return $this->playerStateCache[$playerLogin];
	}

	private function updatePlayerStateCache(array $transitionDefinition, $currentPlayerSnapshot) {
		if (!is_array($currentPlayerSnapshot) || !isset($currentPlayerSnapshot['login'])) {
			return;
		}

		$playerLogin = trim((string) $currentPlayerSnapshot['login']);
		if ($playerLogin === '') {
			return;
		}

		$snapshotToPersist = $currentPlayerSnapshot;
		if (isset($transitionDefinition['forced_connectivity']) && $transitionDefinition['forced_connectivity'] === 'disconnected') {
			$snapshotToPersist['is_connected'] = false;
			$snapshotToPersist['connectivity_state'] = 'disconnected';
			$snapshotToPersist['readiness_state'] = $this->resolvePlayerReadinessState($snapshotToPersist);
			$snapshotToPersist['eligibility_state'] = $this->resolvePlayerEligibilityState($snapshotToPersist);
			$snapshotToPersist['can_join_round'] = $this->resolvePlayerCanJoinRound($snapshotToPersist);
		}

		$this->playerStateCache[$playerLogin] = $snapshotToPersist;
	}

	private function buildPlayerPermissionSignals($currentPlayerSnapshot, array $stateDelta) {
		if (!is_array($currentPlayerSnapshot)) {
			$fieldAvailability = array(
				'auth_level' => false,
				'is_referee' => false,
				'has_player_slot' => false,
				'readiness_state' => false,
				'eligibility_state' => false,
				'can_join_round' => false,
				'forced_spectator_state' => false,
				'is_temporary_spectator' => false,
				'is_managed_by_other_server' => false,
			);

			return array(
				'auth_level' => null,
				'auth_name' => 'Unknown',
				'auth_role' => 'unknown',
				'is_referee' => null,
				'has_player_slot' => null,
				'can_admin_actions' => null,
				'readiness_state' => 'unknown',
				'eligibility_state' => 'unknown',
				'can_join_round' => null,
				'forced_spectator_state' => null,
				'is_temporary_spectator' => null,
				'is_managed_by_other_server' => null,
				'auth_level_changed' => false,
				'role_changed' => false,
				'slot_changed' => false,
				'readiness_changed' => false,
				'eligibility_changed' => false,
				'field_availability' => $fieldAvailability,
				'missing_fields' => array_keys($fieldAvailability),
			);
		}

		$authLevel = $this->getSnapshotField($currentPlayerSnapshot, 'auth_level');
		$canAdminActions = null;
		if ($authLevel !== null) {
			$canAdminActions = ((int) $authLevel >= 1);
		}

		$fieldAvailability = array(
			'auth_level' => $authLevel !== null,
			'is_referee' => $this->getSnapshotField($currentPlayerSnapshot, 'is_referee') !== null,
			'has_player_slot' => $this->getSnapshotField($currentPlayerSnapshot, 'has_player_slot') !== null,
			'readiness_state' => trim((string) $this->getSnapshotField($currentPlayerSnapshot, 'readiness_state')) !== '',
			'eligibility_state' => trim((string) $this->getSnapshotField($currentPlayerSnapshot, 'eligibility_state')) !== '',
			'can_join_round' => $this->getSnapshotField($currentPlayerSnapshot, 'can_join_round') !== null,
			'forced_spectator_state' => $this->getSnapshotField($currentPlayerSnapshot, 'forced_spectator_state') !== null,
			'is_temporary_spectator' => $this->getSnapshotField($currentPlayerSnapshot, 'is_temporary_spectator') !== null,
			'is_managed_by_other_server' => $this->getSnapshotField($currentPlayerSnapshot, 'is_managed_by_other_server') !== null,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'auth_level' => $authLevel,
			'auth_name' => $this->getSnapshotField($currentPlayerSnapshot, 'auth_name'),
			'auth_role' => $this->getSnapshotField($currentPlayerSnapshot, 'auth_role'),
			'is_referee' => $this->getSnapshotField($currentPlayerSnapshot, 'is_referee'),
			'has_player_slot' => $this->getSnapshotField($currentPlayerSnapshot, 'has_player_slot'),
			'can_admin_actions' => $canAdminActions,
			'readiness_state' => $this->getSnapshotField($currentPlayerSnapshot, 'readiness_state'),
			'eligibility_state' => $this->getSnapshotField($currentPlayerSnapshot, 'eligibility_state'),
			'can_join_round' => $this->getSnapshotField($currentPlayerSnapshot, 'can_join_round'),
			'forced_spectator_state' => $this->getSnapshotField($currentPlayerSnapshot, 'forced_spectator_state'),
			'is_temporary_spectator' => $this->getSnapshotField($currentPlayerSnapshot, 'is_temporary_spectator'),
			'is_managed_by_other_server' => $this->getSnapshotField($currentPlayerSnapshot, 'is_managed_by_other_server'),
			'auth_level_changed' => isset($stateDelta['auth_level']['changed']) ? (bool) $stateDelta['auth_level']['changed'] : false,
			'role_changed' => isset($stateDelta['auth_role']['changed']) ? (bool) $stateDelta['auth_role']['changed'] : false,
			'slot_changed' => isset($stateDelta['has_player_slot']['changed']) ? (bool) $stateDelta['has_player_slot']['changed'] : false,
			'readiness_changed' => isset($stateDelta['readiness_state']['changed']) ? (bool) $stateDelta['readiness_state']['changed'] : false,
			'eligibility_changed' => isset($stateDelta['eligibility_state']['changed']) ? (bool) $stateDelta['eligibility_state']['changed'] : false,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	private function buildPlayerConstraintSignals(
		array $transitionDefinition,
		$currentPlayerSnapshot,
		$previousPlayerSnapshot,
		array $stateDelta,
		array $rosterSnapshot,
		$sourceCallback,
		$transitionSequence,
		$observedAt
	) {
		$policyContext = $this->resolvePlayerConstraintPolicyContext(false);
		$playerLogin = $this->resolvePlayerLoginFromSnapshots($currentPlayerSnapshot, $previousPlayerSnapshot);
		$playerTeamId = $this->getSnapshotField($currentPlayerSnapshot, 'team_id');
		$previousTeamId = $this->getSnapshotField($previousPlayerSnapshot, 'team_id');
		$hasPlayerSlot = $this->getSnapshotField($currentPlayerSnapshot, 'has_player_slot');
		$forcedSpectatorState = $this->getSnapshotField($currentPlayerSnapshot, 'forced_spectator_state');
		$isSpectator = $this->getSnapshotField($currentPlayerSnapshot, 'is_spectator');

		$teamChanged = false;
		if (isset($stateDelta['team_id']) && is_array($stateDelta['team_id']) && isset($stateDelta['team_id']['changed'])) {
			$teamChanged = (bool) $stateDelta['team_id']['changed'];
		} else {
			$teamChanged = ($playerTeamId !== $previousTeamId);
		}

		$forcedTeamsEnabled = null;
		if (array_key_exists('forced_teams_enabled', $policyContext)) {
			$forcedTeamsEnabled = $policyContext['forced_teams_enabled'];
		}

		$keepPlayerSlots = null;
		if (array_key_exists('keep_player_slots', $policyContext)) {
			$keepPlayerSlots = $policyContext['keep_player_slots'];
		}

		$maxPlayersCurrent = null;
		$maxPlayersNext = null;
		if (isset($policyContext['max_players']) && is_array($policyContext['max_players'])) {
			if (isset($policyContext['max_players']['current']) && is_numeric($policyContext['max_players']['current'])) {
				$maxPlayersCurrent = max(0, (int) $policyContext['max_players']['current']);
			}
			if (isset($policyContext['max_players']['next']) && is_numeric($policyContext['max_players']['next'])) {
				$maxPlayersNext = max(0, (int) $policyContext['max_players']['next']);
			}
		}

		$maxSpectatorsCurrent = null;
		$maxSpectatorsNext = null;
		if (isset($policyContext['max_spectators']) && is_array($policyContext['max_spectators'])) {
			if (isset($policyContext['max_spectators']['current']) && is_numeric($policyContext['max_spectators']['current'])) {
				$maxSpectatorsCurrent = max(0, (int) $policyContext['max_spectators']['current']);
			}
			if (isset($policyContext['max_spectators']['next']) && is_numeric($policyContext['max_spectators']['next'])) {
				$maxSpectatorsNext = max(0, (int) $policyContext['max_spectators']['next']);
			}
		}

		$activePlayers = (isset($rosterSnapshot['active']) && is_numeric($rosterSnapshot['active']))
			? max(0, (int) $rosterSnapshot['active'])
			: null;
		$availablePlayerSlots = null;
		$playerCapacityUtilization = null;
		if ($activePlayers !== null && $maxPlayersCurrent !== null && $maxPlayersCurrent > 0) {
			$availablePlayerSlots = max(0, $maxPlayersCurrent - $activePlayers);
			$playerCapacityUtilization = round($activePlayers / $maxPlayersCurrent, 4);
		}

		$forcedTeamPolicyState = 'unavailable';
		$forcedTeamReason = 'forced_team_policy_unavailable';
		if ($forcedTeamsEnabled === true) {
			if ($playerTeamId === null || $playerTeamId === '') {
				$forcedTeamPolicyState = 'enforced_missing_assignment';
				$forcedTeamReason = 'forced_team_policy_enabled_missing_team_assignment';
			} else if ($teamChanged) {
				$forcedTeamPolicyState = 'enforced_assignment_changed';
				$forcedTeamReason = 'forced_team_policy_enabled_team_changed';
			} else {
				$forcedTeamPolicyState = 'enforced_assignment_stable';
				$forcedTeamReason = 'forced_team_policy_enabled';
			}
		} else if ($forcedTeamsEnabled === false) {
			$forcedTeamPolicyState = 'disabled';
			$forcedTeamReason = 'forced_team_policy_disabled';
		}

		$slotPolicyState = 'unavailable';
		$slotPolicyReason = 'slot_policy_unavailable';
		if ($hasPlayerSlot === true) {
			if ($isSpectator === true && $keepPlayerSlots === true) {
				$slotPolicyState = 'slot_retained_while_spectating';
				$slotPolicyReason = 'slot_retained_by_keep_player_slots';
			} else {
				$slotPolicyState = 'slot_assigned';
				$slotPolicyReason = 'slot_assigned';
			}
		} else if ($hasPlayerSlot === false) {
			$slotPolicyState = 'slot_restricted';
			if (is_numeric($forcedSpectatorState) && (int) $forcedSpectatorState > 0) {
				$slotPolicyReason = 'slot_restricted_by_forced_spectator_state';
			} else if ($availablePlayerSlots !== null && $availablePlayerSlots === 0) {
				$slotPolicyReason = 'slot_restricted_player_limit_reached_or_reserved';
			} else {
				$slotPolicyReason = 'slot_restricted_signal_detected';
			}
		} else if (isset($policyContext['available']) && $policyContext['available']) {
			$slotPolicyState = 'slot_state_unknown';
			$slotPolicyReason = 'slot_policy_available_player_slot_unknown';
		}

		$playerTransitionSequence = $this->resolvePlayerTransitionSequenceForLogin($playerLogin);

		$fieldAvailability = array(
			'policy_context' => isset($policyContext['available']) ? (bool) $policyContext['available'] : false,
			'forced_teams_enabled' => $forcedTeamsEnabled !== null,
			'keep_player_slots' => $keepPlayerSlots !== null,
			'max_players_current' => $maxPlayersCurrent !== null,
			'max_spectators_current' => $maxSpectatorsCurrent !== null,
			'player_team_id' => $playerTeamId !== null,
			'has_player_slot' => $hasPlayerSlot !== null,
			'forced_spectator_state' => $forcedSpectatorState !== null,
			'player_transition_sequence' => $playerTransitionSequence !== null,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'event_kind' => isset($transitionDefinition['event_kind']) ? (string) $transitionDefinition['event_kind'] : 'player.unknown',
			'source_callback' => (string) $sourceCallback,
			'observed_at' => (int) $observedAt,
			'policy_context' => array(
				'available' => isset($policyContext['available']) ? (bool) $policyContext['available'] : false,
				'source' => isset($policyContext['source']) ? (string) $policyContext['source'] : 'unavailable',
				'captured_at' => isset($policyContext['captured_at']) ? (int) $policyContext['captured_at'] : 0,
				'cache_age_seconds' => isset($policyContext['cache_age_seconds']) ? (int) $policyContext['cache_age_seconds'] : null,
				'cache_ttl_seconds' => $this->playerConstraintPolicyTtlSeconds,
				'unavailable_reason' => isset($policyContext['unavailable_reason'])
					? (string) $policyContext['unavailable_reason']
					: 'unknown',
				'failure_codes' => (isset($policyContext['failure_codes']) && is_array($policyContext['failure_codes']))
					? $policyContext['failure_codes']
					: array(),
			),
			'forced_team_policy' => array(
				'available' => $forcedTeamsEnabled !== null,
				'enabled' => $forcedTeamsEnabled,
				'policy_state' => $forcedTeamPolicyState,
				'reason' => $forcedTeamReason,
				'player_team_id' => $playerTeamId,
				'previous_team_id' => $previousTeamId,
				'team_changed' => $teamChanged,
			),
			'slot_policy' => array(
				'available' => ($keepPlayerSlots !== null || $maxPlayersCurrent !== null || $maxSpectatorsCurrent !== null),
				'keep_player_slots' => $keepPlayerSlots,
				'max_players' => array(
					'current' => $maxPlayersCurrent,
					'next' => $maxPlayersNext,
				),
				'max_spectators' => array(
					'current' => $maxSpectatorsCurrent,
					'next' => $maxSpectatorsNext,
				),
				'policy_state' => $slotPolicyState,
				'reason' => $slotPolicyReason,
				'has_player_slot' => $hasPlayerSlot,
				'forced_spectator_state' => $forcedSpectatorState,
				'pressure' => array(
					'active_players' => $activePlayers,
					'max_players_current' => $maxPlayersCurrent,
					'available_player_slots' => $availablePlayerSlots,
					'player_capacity_utilization' => $playerCapacityUtilization,
				),
			),
			'ordering' => array(
				'global_transition_sequence' => (int) $transitionSequence,
				'player_transition_sequence' => $playerTransitionSequence,
			),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	private function resolvePlayerConstraintPolicyContext($allowRefresh = true) {
		$now = time();
		$hasCachedContext = is_array($this->playerConstraintPolicyCache) && $this->playerConstraintPolicyCapturedAt > 0;

		if ($hasCachedContext) {
			$cachedContext = $this->playerConstraintPolicyCache;
			$cachedContext['cache_age_seconds'] = max(0, $now - $this->playerConstraintPolicyCapturedAt);
			$isStale = ((int) $cachedContext['cache_age_seconds']) > $this->playerConstraintPolicyTtlSeconds;
			if (!$isStale || !$allowRefresh) {
				if ($isStale) {
					$cachedContext['stale'] = true;
					if (isset($cachedContext['available']) && !$cachedContext['available']) {
						$cachedContext['unavailable_reason'] = 'policy_context_stale_refresh_deferred';
					}
				}
				return $cachedContext;
			}
		}

		if (!$allowRefresh) {
			return array(
				'available' => false,
				'source' => 'dedicated_api',
				'captured_at' => $now,
				'cache_age_seconds' => 0,
				'unavailable_reason' => 'policy_context_refresh_deferred',
				'forced_teams_enabled' => null,
				'keep_player_slots' => null,
				'max_players' => $this->buildServerLimitPolicySnapshot(null, 'max_players_unavailable'),
				'max_spectators' => $this->buildServerLimitPolicySnapshot(null, 'max_spectators_unavailable'),
				'failure_codes' => array('policy_context_refresh_deferred'),
				'field_availability' => array(
					'forced_teams_enabled' => false,
					'keep_player_slots' => false,
					'max_players' => false,
					'max_spectators' => false,
				),
				'missing_fields' => array('forced_teams_enabled', 'keep_player_slots', 'max_players', 'max_spectators'),
			);
		}

		if (!$this->maniaControl || !$this->maniaControl->getClient()) {
			$context = array(
				'available' => false,
				'source' => 'dedicated_api',
				'captured_at' => $now,
				'cache_age_seconds' => 0,
				'unavailable_reason' => 'dedicated_client_unavailable',
				'forced_teams_enabled' => null,
				'keep_player_slots' => null,
				'max_players' => $this->buildServerLimitPolicySnapshot(null, 'max_players_unavailable'),
				'max_spectators' => $this->buildServerLimitPolicySnapshot(null, 'max_spectators_unavailable'),
				'failure_codes' => array('dedicated_client_unavailable'),
				'field_availability' => array(
					'forced_teams_enabled' => false,
					'keep_player_slots' => false,
					'max_players' => false,
					'max_spectators' => false,
				),
				'missing_fields' => array('forced_teams_enabled', 'keep_player_slots', 'max_players', 'max_spectators'),
			);

			$this->playerConstraintPolicyCache = $context;
			$this->playerConstraintPolicyCapturedAt = $now;
			return $context;
		}

		$client = $this->maniaControl->getClient();
		$forcedTeamsEnabled = null;
		$keepPlayerSlots = null;
		$maxPlayersSnapshot = $this->buildServerLimitPolicySnapshot(null, 'max_players_unavailable');
		$maxSpectatorsSnapshot = $this->buildServerLimitPolicySnapshot(null, 'max_spectators_unavailable');
		$failureCodes = array();

		try {
			$forcedTeamsEnabled = (bool) $client->getForcedTeams();
		} catch (\Throwable $throwable) {
			$failureCodes[] = 'forced_teams_fetch_failed';
			$this->logPlayerConstraintPolicyFetchFailure('forced_teams', $throwable);
		}

		try {
			$keepPlayerSlots = (bool) $client->isKeepingPlayerSlots();
		} catch (\Throwable $throwable) {
			$failureCodes[] = 'keep_player_slots_fetch_failed';
			$this->logPlayerConstraintPolicyFetchFailure('keep_player_slots', $throwable);
		}

		try {
			$maxPlayersSnapshot = $this->buildServerLimitPolicySnapshot($client->getMaxPlayers(), 'max_players_unavailable');
		} catch (\Throwable $throwable) {
			$failureCodes[] = 'max_players_fetch_failed';
			$this->logPlayerConstraintPolicyFetchFailure('max_players', $throwable);
		}

		try {
			$maxSpectatorsSnapshot = $this->buildServerLimitPolicySnapshot($client->getMaxSpectators(), 'max_spectators_unavailable');
		} catch (\Throwable $throwable) {
			$failureCodes[] = 'max_spectators_fetch_failed';
			$this->logPlayerConstraintPolicyFetchFailure('max_spectators', $throwable);
		}

		$fieldAvailability = array(
			'forced_teams_enabled' => $forcedTeamsEnabled !== null,
			'keep_player_slots' => $keepPlayerSlots !== null,
			'max_players' => isset($maxPlayersSnapshot['available']) ? (bool) $maxPlayersSnapshot['available'] : false,
			'max_spectators' => isset($maxSpectatorsSnapshot['available']) ? (bool) $maxSpectatorsSnapshot['available'] : false,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		$contextAvailable = false;
		foreach ($fieldAvailability as $available) {
			if ($available) {
				$contextAvailable = true;
				break;
			}
		}

		$unavailableReason = 'available';
		if (!$contextAvailable) {
			$unavailableReason = (!empty($failureCodes) ? 'dedicated_policy_fetch_failed' : 'dedicated_policy_not_exposed');
		}

		$context = array(
			'available' => $contextAvailable,
			'source' => 'dedicated_api',
			'captured_at' => $now,
			'cache_age_seconds' => 0,
			'unavailable_reason' => $unavailableReason,
			'forced_teams_enabled' => $forcedTeamsEnabled,
			'keep_player_slots' => $keepPlayerSlots,
			'max_players' => $maxPlayersSnapshot,
			'max_spectators' => $maxSpectatorsSnapshot,
			'failure_codes' => array_values(array_unique($failureCodes)),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);

		$this->playerConstraintPolicyCache = $context;
		$this->playerConstraintPolicyCapturedAt = $now;

		return $context;
	}

	private function buildServerLimitPolicySnapshot($rawServerLimit, $unavailableReason) {
		$currentValue = $this->resolveServerLimitValue($rawServerLimit, 'CurrentValue');
		$nextValue = $this->resolveServerLimitValue($rawServerLimit, 'NextValue');

		if ($currentValue === null && $nextValue !== null) {
			$currentValue = $nextValue;
		}

		if ($nextValue === null && $currentValue !== null) {
			$nextValue = $currentValue;
		}

		$available = ($currentValue !== null || $nextValue !== null);

		return array(
			'available' => $available,
			'current' => $currentValue,
			'next' => $nextValue,
			'reason' => ($available ? 'available' : (string) $unavailableReason),
		);
	}

	private function resolveServerLimitValue($rawServerLimit, $preferredKey) {
		if (is_numeric($rawServerLimit)) {
			return max(0, (int) $rawServerLimit);
		}

		if (!is_array($rawServerLimit)) {
			return null;
		}

		if (isset($rawServerLimit[$preferredKey]) && is_numeric($rawServerLimit[$preferredKey])) {
			return max(0, (int) $rawServerLimit[$preferredKey]);
		}

		$normalizedPreferredKey = strtolower((string) $preferredKey);
		foreach ($rawServerLimit as $rawKey => $rawValue) {
			if (!is_string($rawKey) || strtolower($rawKey) !== $normalizedPreferredKey || !is_numeric($rawValue)) {
				continue;
			}

			return max(0, (int) $rawValue);
		}

		return null;
	}

	private function logPlayerConstraintPolicyFetchFailure($policyKey, \Throwable $throwable) {
		$now = time();
		if (($now - $this->playerConstraintPolicyErrorLogAt) < $this->playerConstraintPolicyErrorCooldownSeconds) {
			return;
		}

		$this->playerConstraintPolicyErrorLogAt = $now;
		$reason = trim((string) $throwable->getMessage());
		if ($reason === '') {
			$reason = 'unknown';
		}

		Logger::logWarning(
			'[PixelControl][player][policy_fetch_failed] policy=' . (string) $policyKey
			. ', reason=' . $reason
			. '.'
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

	private function getSnapshotField($snapshot, $field) {
		if (!is_array($snapshot) || !array_key_exists($field, $snapshot)) {
			return null;
		}

		return $snapshot[$field];
	}
}
