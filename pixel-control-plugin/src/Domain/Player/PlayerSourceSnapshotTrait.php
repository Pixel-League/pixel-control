<?php

namespace PixelControl\Domain\Player;

use ManiaControl\Players\Player;
use ManiaControl\Players\PlayerManager;

trait PlayerSourceSnapshotTrait {
	private function resolvePlayerSourceCallback($sourceCallback, array $callbackArguments) {
		$normalizedSourceCallback = $this->normalizeIdentifier($sourceCallback, 'unknown');
		$isExplicitInfoChanged = $normalizedSourceCallback === 'playermanagercallback_playerinfochanged';

		if (
			$normalizedSourceCallback === 'playermanagercallback_playerconnect'
			|| $normalizedSourceCallback === 'playermanagercallback_playerdisconnect'
			|| $normalizedSourceCallback === 'playermanagercallback_playerinfoschanged'
		) {
			return $sourceCallback;
		}

		if (!$isExplicitInfoChanged && $normalizedSourceCallback !== 'maniacontrol_players_player' && $normalizedSourceCallback !== 'unknown') {
			return $sourceCallback;
		}

		if (empty($callbackArguments)) {
			if ($isExplicitInfoChanged) {
				return PlayerManager::CB_PLAYERINFOCHANGED;
			}

			return PlayerManager::CB_PLAYERINFOSCHANGED;
		}

		$player = $this->extractPlayerFromCallbackArguments($callbackArguments);
		if (!$player instanceof Player) {
			return PlayerManager::CB_PLAYERINFOCHANGED;
		}

		$currentPlayerSnapshot = $this->buildPlayerTelemetrySnapshot($player);
		$previousPlayerSnapshot = $this->resolvePreviousPlayerSnapshot($currentPlayerSnapshot);
		$beforeConnectivity = $this->resolvePlayerConnectivityState($previousPlayerSnapshot);
		$afterConnectivity = $this->resolvePlayerConnectivityState($currentPlayerSnapshot);
		$playerLogin = $this->resolvePlayerLoginFromSnapshots($currentPlayerSnapshot, $previousPlayerSnapshot);
		$connectedRosterState = $this->resolveConnectedRosterState($playerLogin);

		if ($connectedRosterState === false && $afterConnectivity === 'connected') {
			return PlayerManager::CB_PLAYERDISCONNECT;
		}

		if ($connectedRosterState === true && $afterConnectivity === 'disconnected') {
			return PlayerManager::CB_PLAYERCONNECT;
		}

		if ($beforeConnectivity !== 'connected' && ($afterConnectivity === 'connected' || $connectedRosterState === true)) {
			return PlayerManager::CB_PLAYERCONNECT;
		}

		if (($beforeConnectivity === 'connected' || $afterConnectivity === 'connected') && ($afterConnectivity === 'disconnected' || $connectedRosterState === false)) {
			return PlayerManager::CB_PLAYERDISCONNECT;
		}

		return PlayerManager::CB_PLAYERINFOCHANGED;
	}


	private function resolveConnectedRosterState($playerLogin) {
		if (!$this->maniaControl || !is_string($playerLogin)) {
			return null;
		}

		$playerLogin = trim($playerLogin);
		if ($playerLogin === '') {
			return null;
		}

		$connectedPlayer = $this->maniaControl->getPlayerManager()->getPlayer($playerLogin, true);
		if ($connectedPlayer instanceof Player) {
			return true;
		}

		return false;
	}


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

		foreach ($callbackArguments as $callbackArgument) {
			if ($callbackArgument instanceof Player) {
				return $callbackArgument;
			}
		}

		if (!$this->maniaControl) {
			return null;
		}

		foreach ($callbackArguments as $callbackArgument) {
			if (!is_string($callbackArgument)) {
				continue;
			}

			$candidateLogin = trim($callbackArgument);
			if ($candidateLogin === '') {
				continue;
			}

			if (stripos($candidateLogin, 'PlayerManagerCallback.') === 0) {
				continue;
			}

			$resolvedPlayer = $this->maniaControl->getPlayerManager()->getPlayer($candidateLogin);
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


	private function getSnapshotField($snapshot, $field) {
		if (!is_array($snapshot) || !array_key_exists($field, $snapshot)) {
			return null;
		}

		return $snapshot[$field];
	}

}
