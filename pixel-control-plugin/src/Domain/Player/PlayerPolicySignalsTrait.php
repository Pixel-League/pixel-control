<?php

namespace PixelControl\Domain\Player;

use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Logger;
use ManiaControl\Players\Player;

trait PlayerPolicySignalsTrait {

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

}
