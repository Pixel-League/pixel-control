<?php

namespace PixelControl\Domain\TeamControl;

use ManiaControl\Logger;
use ManiaControl\Players\Player;
use PixelControl\Admin\AdminActionCatalog;
use PixelControl\Admin\AdminActionResult;
use PixelControl\TeamControl\TeamRosterCatalog;
use PixelControl\TeamControl\TeamRosterState;

trait TeamControlDomainTrait {
	private function initializeTeamControlSettings() {
		if (!$this->maniaControl) {
			return;
		}

		$settingManager = $this->maniaControl->getSettingManager();
		$settingManager->initSetting(
			$this,
			self::SETTING_TEAM_POLICY_ENABLED,
			TeamRosterCatalog::normalizeBool(
				$this->readEnvString('PIXEL_CONTROL_TEAM_POLICY_ENABLED', TeamRosterCatalog::DEFAULT_POLICY_ENABLED ? '1' : '0'),
				TeamRosterCatalog::DEFAULT_POLICY_ENABLED
			)
		);
		$settingManager->initSetting(
			$this,
			self::SETTING_TEAM_SWITCH_LOCK_ENABLED,
			TeamRosterCatalog::normalizeBool(
				$this->readEnvString('PIXEL_CONTROL_TEAM_SWITCH_LOCK_ENABLED', TeamRosterCatalog::DEFAULT_SWITCH_LOCK ? '1' : '0'),
				TeamRosterCatalog::DEFAULT_SWITCH_LOCK
			)
		);
		$settingManager->initSetting(
			$this,
			self::SETTING_TEAM_ROSTER_ASSIGNMENTS,
			TeamRosterCatalog::encodeAssignments(
				TeamRosterCatalog::parseAssignments(
					$this->readEnvString('PIXEL_CONTROL_TEAM_ROSTER_ASSIGNMENTS', '')
				)
			)
		);
	}

	private function initializeTeamControlState() {
		if (!$this->maniaControl) {
			return;
		}

		$this->teamRosterState = new TeamRosterState();
		$this->teamControlForcedTeamsState = null;
		$this->teamControlLastRuntimeApplyAt = 0;
		$this->teamControlLastRuntimeApplySource = 'bootstrap';
		$this->teamControlRecentForcedAt = array();
		$this->teamControlLastReconcileAt = 0;

		$policyEnabled = $this->resolveRuntimeBoolSetting(
			self::SETTING_TEAM_POLICY_ENABLED,
			'PIXEL_CONTROL_TEAM_POLICY_ENABLED',
			TeamRosterCatalog::DEFAULT_POLICY_ENABLED
		);
		$switchLockEnabled = $this->resolveRuntimeBoolSetting(
			self::SETTING_TEAM_SWITCH_LOCK_ENABLED,
			'PIXEL_CONTROL_TEAM_SWITCH_LOCK_ENABLED',
			TeamRosterCatalog::DEFAULT_SWITCH_LOCK
		);

		$assignments = array();
		$assignmentsFromEnv = $this->readEnvString('PIXEL_CONTROL_TEAM_ROSTER_ASSIGNMENTS', '');
		if ($assignmentsFromEnv !== '') {
			$assignments = TeamRosterCatalog::parseAssignments($assignmentsFromEnv);
		} else {
			$assignments = TeamRosterCatalog::parseAssignments(
				$this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_TEAM_ROSTER_ASSIGNMENTS)
			);
		}

		$this->teamRosterState->bootstrap(
			array(
				'policy_enabled' => $policyEnabled,
				'switch_lock_enabled' => $switchLockEnabled,
				'assignments' => $assignments,
			),
			$this->resolveTeamControlBootstrapSource(),
			'plugin_bootstrap'
		);

		$this->applyTeamControlRuntimePolicy('bootstrap', true);
		$this->enforceTeamRosterForAllPlayers('bootstrap', true);

		$snapshot = $this->getTeamRosterSnapshot();
		Logger::log(
			'[PixelControl][team][bootstrap] policy_enabled=' . (!empty($snapshot['policy_enabled']) ? 'yes' : 'no')
			. ', switch_lock=' . (!empty($snapshot['switch_lock_enabled']) ? 'yes' : 'no')
			. ', assignment_count=' . (isset($snapshot['assignment_count']) ? (int) $snapshot['assignment_count'] : 0)
			. ', source=' . (isset($snapshot['update_source']) ? (string) $snapshot['update_source'] : TeamRosterCatalog::UPDATE_SOURCE_SETTING)
			. '.'
		);
	}

	private function getTeamRosterSnapshot() {
		if ($this->teamRosterState) {
			return $this->teamRosterState->getSnapshot();
		}

		return $this->buildDefaultTeamRosterSnapshot();
	}

	private function buildDefaultTeamRosterSnapshot() {
		return array(
			'policy_enabled' => TeamRosterCatalog::DEFAULT_POLICY_ENABLED,
			'switch_lock_enabled' => TeamRosterCatalog::DEFAULT_SWITCH_LOCK,
			'assignments' => array(),
			'assignment_entries' => array(),
			'assignment_count' => 0,
			'updated_at' => 0,
			'updated_by' => 'system',
			'update_source' => TeamRosterCatalog::UPDATE_SOURCE_SETTING,
			'policy' => array(
				'teams' => array('0|blue|team_a', '1|red|team_b'),
				'mode_scope' => 'team_mode_only',
			),
		);
	}

	private function buildTeamControlCapabilitySnapshot() {
		$snapshot = $this->getTeamRosterSnapshot();
		$snapshot['runtime'] = array(
			'team_mode_active' => $this->isTeamControlTeamModeActive(),
			'forced_teams_enabled' => $this->resolveTeamControlCurrentForcedTeams(false),
			'forced_club_links' => $this->resolveTeamControlForcedClubLinks(),
			'team_info' => $this->resolveTeamControlTeamInfoSnapshot(),
			'last_runtime_apply_at' => $this->teamControlLastRuntimeApplyAt,
			'last_runtime_apply_source' => $this->teamControlLastRuntimeApplySource,
		);

		return $snapshot;
	}

	private function handleTeamControlPlayerCallback(array $callbackArguments) {
		if (!$this->teamRosterState || !$this->teamRosterState->isPolicyEnabled()) {
			return;
		}

		$this->applyTeamControlRuntimePolicy('player_callback', false);

		$login = $this->extractTeamControlPlayerLogin($callbackArguments);
		if ($login === '') {
			return;
		}

		$this->enforceTeamRosterForLogin($login, 'player_callback', false);
	}

	private function handleTeamControlLifecycleCallback(array $callbackArguments) {
		if (!$this->teamRosterState || !$this->teamRosterState->isPolicyEnabled()) {
			return;
		}

		$sourceCallback = $this->extractSourceCallback($callbackArguments);
		$variant = $this->resolveLifecycleVariant($sourceCallback, $callbackArguments);

		$this->applyTeamControlRuntimePolicy('lifecycle_' . $variant, false);

		if ($variant === 'map.begin' || $variant === 'match.begin' || $variant === 'round.begin') {
			$this->enforceTeamRosterForAllPlayers('lifecycle_' . $variant, true);
		}
	}

	private function handleTeamControlPolicyTick() {
		$this->applyTeamControlRuntimePolicy('periodic_tick', false);

		if (!$this->teamRosterState || !$this->teamRosterState->isPolicyEnabled()) {
			return;
		}

		if (!$this->isTeamControlTeamModeActive()) {
			return;
		}

		$now = time();
		if (($now - $this->teamControlLastReconcileAt) < $this->teamControlReconcileIntervalSeconds) {
			return;
		}

		$this->teamControlLastReconcileAt = $now;
		$this->enforceTeamRosterForAllPlayers('periodic_tick', false);
	}

	private function applyTeamControlRuntimePolicy($source, $force) {
		if (!$this->teamRosterState || !$this->maniaControl || !$this->maniaControl->getClient()) {
			return array(
				'success' => false,
				'code' => 'capability_unavailable',
				'message' => 'Team policy runtime client unavailable.',
			);
		}

		$teamModeActive = $this->isTeamControlTeamModeActive();
		$policyEnabled = $this->teamRosterState->isPolicyEnabled();
		$desiredForcedTeams = ($policyEnabled && $teamModeActive);
		$currentForcedTeams = $this->resolveTeamControlCurrentForcedTeams($desiredForcedTeams);

		if (!$force && $currentForcedTeams === $desiredForcedTeams) {
			$this->teamControlForcedTeamsState = $currentForcedTeams;
			return array(
				'success' => true,
				'code' => 'runtime_already_applied',
				'message' => 'Team policy runtime state already applied.',
			);
		}

		try {
			$applied = (bool) $this->maniaControl->getClient()->setForcedTeams($desiredForcedTeams);
			if (!$applied) {
				return array(
					'success' => false,
					'code' => 'native_rejected',
					'message' => 'Failed to set forced-team runtime policy.',
				);
			}
		} catch (\Throwable $throwable) {
			return array(
				'success' => false,
				'code' => 'native_exception',
				'message' => 'Failed to set forced-team runtime policy.',
				'details' => array('reason' => trim((string) $throwable->getMessage())),
			);
		}

		$this->teamControlForcedTeamsState = $desiredForcedTeams;
		$this->teamControlLastRuntimeApplyAt = time();
		$this->teamControlLastRuntimeApplySource = trim((string) $source);

		Logger::log(
			'[PixelControl][team][policy_applied] source=' . (string) $source
			. ', policy_enabled=' . ($policyEnabled ? 'yes' : 'no')
			. ', team_mode=' . ($teamModeActive ? 'yes' : 'no')
			. ', forced_teams=' . ($desiredForcedTeams ? 'yes' : 'no')
			. '.'
		);

		return array(
			'success' => true,
			'code' => 'runtime_applied',
			'message' => 'Team policy runtime state applied.',
		);
	}

	private function enforceTeamRosterForAllPlayers($source, $force) {
		if (!$this->maniaControl || !$this->teamRosterState || !$this->teamRosterState->isPolicyEnabled()) {
			return array('success' => false, 'code' => 'policy_disabled', 'applied_count' => 0, 'failure_count' => 0);
		}

		if (!$this->isTeamControlTeamModeActive()) {
			return array('success' => false, 'code' => 'capability_unavailable', 'applied_count' => 0, 'failure_count' => 0);
		}

		$players = $this->maniaControl->getPlayerManager()->getPlayers(false);
		if (!is_array($players)) {
			return array('success' => true, 'code' => 'no_players', 'applied_count' => 0, 'failure_count' => 0);
		}

		$appliedCount = 0;
		$failureCount = 0;
		foreach ($players as $player) {
			if (!$player instanceof Player) {
				continue;
			}

			$login = isset($player->login) ? TeamRosterCatalog::normalizeLogin($player->login) : '';
			if ($login === '') {
				continue;
			}

			$result = $this->enforceTeamRosterForLogin($login, $source, $force);
			if (!empty($result['applied'])) {
				$appliedCount++;
			}

			if (empty($result['success']) && isset($result['code'])) {
				$failureCount++;
			}
		}

		if ($appliedCount > 0 || $failureCount > 0) {
			Logger::log(
				'[PixelControl][team][reconcile] source=' . (string) $source
				. ', applied=' . $appliedCount
				. ', failed=' . $failureCount
				. '.'
			);
		}

		$this->pruneTeamControlRecentForcedCache();

		return array(
			'success' => true,
			'code' => 'reconcile_completed',
			'applied_count' => $appliedCount,
			'failure_count' => $failureCount,
		);
	}

	private function enforceTeamRosterForLogin($login, $source, $force = false) {
		if (!$this->teamRosterState || !$this->teamRosterState->isPolicyEnabled()) {
			return array('success' => false, 'code' => 'policy_disabled', 'applied' => false);
		}

		if (!$this->isTeamControlTeamModeActive()) {
			return array('success' => false, 'code' => 'capability_unavailable', 'applied' => false);
		}

		if (!$this->maniaControl || !$this->maniaControl->getClient()) {
			return array('success' => false, 'code' => 'capability_unavailable', 'applied' => false);
		}

		$normalizedLogin = TeamRosterCatalog::normalizeLogin($login);
		if ($normalizedLogin === '') {
			return array('success' => false, 'code' => 'invalid_parameters', 'applied' => false);
		}

		$assignedTeamId = $this->teamRosterState->getAssignedTeamId($normalizedLogin);
		if ($assignedTeamId === null) {
			return array('success' => true, 'code' => 'assignment_missing', 'applied' => false);
		}

		$player = $this->maniaControl->getPlayerManager()->getPlayer($normalizedLogin, true);
		if (!$player instanceof Player) {
			return array('success' => true, 'code' => 'target_not_found', 'applied' => false);
		}

		if (isset($player->isServer) && $player->isServer) {
			return array('success' => true, 'code' => 'skipped_server_player', 'applied' => false);
		}

		if (method_exists($player, 'isFakePlayer') && $player->isFakePlayer()) {
			return array('success' => true, 'code' => 'skipped_fake_player', 'applied' => false);
		}

		$currentTeamId = (isset($player->teamId) && is_numeric($player->teamId)) ? (int) $player->teamId : null;
		$switchLockEnabled = $this->teamRosterState->isSwitchLockEnabled();

		if (!$force && $currentTeamId !== null && $currentTeamId === (int) $assignedTeamId) {
			return array('success' => true, 'code' => 'already_aligned', 'applied' => false);
		}

		if (!$force && !$switchLockEnabled && $currentTeamId !== null && $currentTeamId !== (int) $assignedTeamId) {
			return array('success' => true, 'code' => 'switch_lock_disabled', 'applied' => false);
		}

		$now = time();
		$lastForcedAt = isset($this->teamControlRecentForcedAt[$normalizedLogin]) ? (int) $this->teamControlRecentForcedAt[$normalizedLogin] : 0;
		if (!$force && ($now - $lastForcedAt) < $this->teamControlForceCooldownSeconds) {
			return array('success' => true, 'code' => 'force_cooldown', 'applied' => false);
		}

		try {
			$applied = (bool) $this->maniaControl->getClient()->forcePlayerTeam($normalizedLogin, (int) $assignedTeamId);
			if (!$applied) {
				return array(
					'success' => false,
					'code' => 'native_rejected',
					'applied' => false,
					'target_login' => $normalizedLogin,
					'team_id' => (int) $assignedTeamId,
				);
			}
		} catch (\Throwable $throwable) {
			return array(
				'success' => false,
				'code' => 'native_exception',
				'applied' => false,
				'target_login' => $normalizedLogin,
				'team_id' => (int) $assignedTeamId,
				'details' => array('reason' => trim((string) $throwable->getMessage())),
			);
		}

		$this->teamControlRecentForcedAt[$normalizedLogin] = $now;

		Logger::log(
			'[PixelControl][team][enforce_applied] source=' . (string) $source
			. ', login=' . $normalizedLogin
			. ', team_id=' . (int) $assignedTeamId
			. ', switch_lock=' . ($switchLockEnabled ? 'yes' : 'no')
			. ', forced=' . ($force ? 'yes' : 'no')
			. '.'
		);

		return array(
			'success' => true,
			'code' => 'force_applied',
			'applied' => true,
			'target_login' => $normalizedLogin,
			'team_id' => (int) $assignedTeamId,
		);
	}

	private function shouldPersistTeamRosterAfterAdminAction($actionName) {
		return in_array(
			$actionName,
			array(
				AdminActionCatalog::ACTION_TEAM_POLICY_SET,
				AdminActionCatalog::ACTION_TEAM_ROSTER_ASSIGN,
				AdminActionCatalog::ACTION_TEAM_ROSTER_UNASSIGN,
			),
			true
		);
	}

	private function persistTeamRosterAfterAdminAction($actionName, AdminActionResult $actionResult, array $snapshotBeforeAction) {
		if (!$this->shouldPersistTeamRosterAfterAdminAction($actionName)) {
			return array('success' => true, 'code' => 'not_required', 'message' => 'No team-roster persistence required.');
		}

		$actionDetails = $actionResult->getDetails();
		$currentSnapshot = (isset($actionDetails['team_roster']) && is_array($actionDetails['team_roster']))
			? $actionDetails['team_roster']
			: $this->getTeamRosterSnapshot();

		$persistenceResult = $this->persistTeamRosterSnapshot($currentSnapshot, $snapshotBeforeAction);
		if (empty($persistenceResult['success'])) {
			$rollbackResult = array();
			if (!empty($snapshotBeforeAction)) {
				$rollbackResult = $this->restoreTeamRosterSnapshot(
					$snapshotBeforeAction,
					TeamRosterCatalog::UPDATE_SOURCE_SETTING,
					'team_roster_persistence_rollback'
				);
			}

			return array(
				'success' => false,
				'code' => 'setting_write_failed',
				'message' => 'Team-roster settings persistence failed; runtime update rolled back.',
				'details' => array(
					'persistence' => isset($persistenceResult['details']) ? $persistenceResult['details'] : array(),
					'rollback' => isset($rollbackResult['details']) ? $rollbackResult['details'] : array(),
				),
			);
		}

		$runtimeResult = $this->applyTeamControlRuntimePolicy('admin_action', true);
		if (empty($runtimeResult['success'])) {
			return array(
				'success' => false,
				'code' => isset($runtimeResult['code']) ? (string) $runtimeResult['code'] : 'native_rejected',
				'message' => isset($runtimeResult['message']) ? (string) $runtimeResult['message'] : 'Team policy runtime update failed.',
				'details' => isset($runtimeResult['details']) ? $runtimeResult['details'] : array(),
			);
		}

		if ($actionName === AdminActionCatalog::ACTION_TEAM_ROSTER_ASSIGN) {
			$targetLogin = '';
			if (isset($actionDetails['details']) && is_array($actionDetails['details']) && isset($actionDetails['details']['target_login'])) {
				$targetLogin = trim((string) $actionDetails['details']['target_login']);
			}

			if ($targetLogin !== '') {
				$this->enforceTeamRosterForLogin($targetLogin, 'admin_action_assign', true);
			}
		}

		if ($actionName === AdminActionCatalog::ACTION_TEAM_POLICY_SET) {
			$this->enforceTeamRosterForAllPlayers('admin_action_policy_set', true);
		}

		return array('success' => true, 'code' => 'persisted_and_applied', 'message' => 'Team-roster settings persisted and runtime applied.');
	}

	private function buildTeamRosterSettingValueMap(array $teamRosterSnapshot) {
		$policyEnabled = !empty($teamRosterSnapshot['policy_enabled']);
		$switchLockEnabled = !empty($teamRosterSnapshot['switch_lock_enabled']);
		$assignments = (isset($teamRosterSnapshot['assignments']) && is_array($teamRosterSnapshot['assignments']))
			? $teamRosterSnapshot['assignments']
			: array();

		return array(
			self::SETTING_TEAM_POLICY_ENABLED => $policyEnabled,
			self::SETTING_TEAM_SWITCH_LOCK_ENABLED => $switchLockEnabled,
			self::SETTING_TEAM_ROSTER_ASSIGNMENTS => TeamRosterCatalog::encodeAssignments($assignments),
		);
	}

	private function persistTeamRosterSnapshot(array $teamRosterSnapshot, array $previousSnapshot = array()) {
		if (!$this->maniaControl) {
			return array('success' => false, 'code' => 'capability_unavailable', 'message' => 'Team-roster persistence unavailable.');
		}

		$settingManager = $this->maniaControl->getSettingManager();
		$settingValues = $this->buildTeamRosterSettingValueMap($teamRosterSnapshot);
		$writtenSettings = array();
		$failedSettings = array();

		foreach ($settingValues as $settingName => $settingValue) {
			$settingSaved = $settingManager->setSetting($this, $settingName, $settingValue);
			if ($settingSaved) {
				$writtenSettings[] = $settingName;
				continue;
			}

			$failedSettings[] = $settingName;
		}

		if (empty($failedSettings)) {
			return array(
				'success' => true,
				'code' => 'settings_persisted',
				'message' => 'Team-roster settings persisted.',
				'details' => array('written_settings' => $writtenSettings),
			);
		}

		$rollbackFailedSettings = array();
		if (!empty($writtenSettings) && !empty($previousSnapshot)) {
			$rollbackValues = $this->buildTeamRosterSettingValueMap($previousSnapshot);
			foreach ($writtenSettings as $writtenSettingName) {
				if (!array_key_exists($writtenSettingName, $rollbackValues)) {
					continue;
				}

				$rollbackSaved = $settingManager->setSetting($this, $writtenSettingName, $rollbackValues[$writtenSettingName]);
				if (!$rollbackSaved) {
					$rollbackFailedSettings[] = $writtenSettingName;
				}
			}
		}

		return array(
			'success' => false,
			'code' => 'setting_write_failed',
			'message' => 'Unable to persist team-roster settings snapshot.',
			'details' => array(
				'failed_settings' => $failedSettings,
				'written_settings' => $writtenSettings,
				'rollback_failed_settings' => $rollbackFailedSettings,
			),
		);
	}

	private function restoreTeamRosterSnapshot(array $teamRosterSnapshot, $updateSource, $updatedBy) {
		if (!$this->teamRosterState) {
			return array('success' => false, 'code' => 'capability_unavailable', 'message' => 'Team-roster state unavailable for rollback.');
		}

		$this->teamRosterState->bootstrap(
			array(
				'policy_enabled' => !empty($teamRosterSnapshot['policy_enabled']),
				'switch_lock_enabled' => !empty($teamRosterSnapshot['switch_lock_enabled']),
				'assignments' => (isset($teamRosterSnapshot['assignments']) && is_array($teamRosterSnapshot['assignments'])) ? $teamRosterSnapshot['assignments'] : array(),
			),
			TeamRosterCatalog::normalizeUpdateSource($updateSource, TeamRosterCatalog::UPDATE_SOURCE_SETTING),
			TeamRosterCatalog::normalizeUpdatedBy($updatedBy, 'system')
		);

		$this->applyTeamControlRuntimePolicy('restore_snapshot', true);
		$this->enforceTeamRosterForAllPlayers('restore_snapshot', true);

		return array('success' => true, 'code' => 'rollback_applied', 'message' => 'Team-roster snapshot rollback applied.');
	}

	private function resolveTeamControlBootstrapSource() {
		$envKeys = array(
			'PIXEL_CONTROL_TEAM_POLICY_ENABLED',
			'PIXEL_CONTROL_TEAM_SWITCH_LOCK_ENABLED',
			'PIXEL_CONTROL_TEAM_ROSTER_ASSIGNMENTS',
		);

		foreach ($envKeys as $envKey) {
			if ($this->hasRuntimeEnvValue($envKey)) {
				return TeamRosterCatalog::UPDATE_SOURCE_ENV;
			}
		}

		return TeamRosterCatalog::UPDATE_SOURCE_SETTING;
	}

	private function isTeamControlTeamModeActive() {
		if (!$this->maniaControl) {
			return false;
		}

		$scriptManager = $this->maniaControl->getServer()->getScriptManager();
		if (!$scriptManager || !method_exists($scriptManager, 'modeIsTeamMode')) {
			return false;
		}

		try {
			return (bool) $scriptManager->modeIsTeamMode();
		} catch (\Throwable $throwable) {
			return false;
		}
	}

	private function resolveTeamControlCurrentForcedTeams($fallback) {
		if (!$this->maniaControl || !$this->maniaControl->getClient()) {
			return (bool) $fallback;
		}

		try {
			$currentForcedTeams = $this->maniaControl->getClient()->getForcedTeams();
			if (is_bool($currentForcedTeams)) {
				return $currentForcedTeams;
			}

			if (is_numeric($currentForcedTeams)) {
				return ((int) $currentForcedTeams) !== 0;
			}

			if (is_string($currentForcedTeams)) {
				$normalizedValue = strtolower(trim($currentForcedTeams));
				if (in_array($normalizedValue, array('1', 'true', 'yes', 'on', 'enabled'), true)) {
					return true;
				}

				if (in_array($normalizedValue, array('0', 'false', 'no', 'off', 'disabled'), true)) {
					return false;
				}
			}
		} catch (\Throwable $throwable) {
			return (bool) $fallback;
		}

		return (bool) $fallback;
	}

	private function resolveTeamControlForcedClubLinks() {
		if (!$this->maniaControl || !$this->maniaControl->getClient()) {
			return array();
		}

		try {
			$clubLinks = $this->maniaControl->getClient()->getForcedClubLinks();
			if (!is_array($clubLinks)) {
				return array();
			}

			$normalized = array();
			foreach ($clubLinks as $index => $clubLink) {
				$normalized[(string) $index] = trim((string) $clubLink);
			}

			return $normalized;
		} catch (\Throwable $throwable) {
			return array();
		}
	}

	private function resolveTeamControlTeamInfoSnapshot() {
		if (!$this->maniaControl || !$this->maniaControl->getClient()) {
			return array();
		}

		$teamInfoById = array();
		foreach (array(0, 1, 2) as $teamId) {
			try {
				$teamInfo = $this->maniaControl->getClient()->getTeamInfo((int) $teamId);
				$teamInfoById[(string) $teamId] = $this->normalizeTeamInfoValue($teamInfo);
			} catch (\Throwable $throwable) {
				$teamInfoById[(string) $teamId] = array(
					'available' => false,
					'error' => trim((string) $throwable->getMessage()),
				);
			}
		}

		return $teamInfoById;
	}

	private function normalizeTeamInfoValue($value) {
		if (is_null($value) || is_scalar($value)) {
			return $value;
		}

		if (is_array($value)) {
			$normalizedArray = array();
			foreach ($value as $key => $entry) {
				$normalizedArray[(string) $key] = $this->normalizeTeamInfoValue($entry);
			}

			return $normalizedArray;
		}

		if (is_object($value)) {
			$encodedValue = json_encode($value);
			if (is_string($encodedValue)) {
				$decodedValue = json_decode($encodedValue, true);
				if (is_array($decodedValue)) {
					return $this->normalizeTeamInfoValue($decodedValue);
				}
			}

			$objectProperties = get_object_vars($value);
			if (is_array($objectProperties) && !empty($objectProperties)) {
				return $this->normalizeTeamInfoValue($objectProperties);
			}
		}

		return array('type' => gettype($value));
	}

	private function extractTeamControlPlayerLogin(array $callbackArguments) {
		if (empty($callbackArguments)) {
			return '';
		}

		foreach ($callbackArguments as $callbackArgument) {
			if (!$callbackArgument instanceof Player) {
				continue;
			}

			if (method_exists($callbackArgument, 'isFakePlayer') && $callbackArgument->isFakePlayer()) {
				return '';
			}

			if (isset($callbackArgument->isServer) && $callbackArgument->isServer) {
				return '';
			}

			if (isset($callbackArgument->login)) {
				return TeamRosterCatalog::normalizeLogin($callbackArgument->login);
			}
		}

		$firstArgument = $callbackArguments[0];
		if (
			is_array($firstArgument)
			&& isset($firstArgument[1])
			&& is_array($firstArgument[1])
			&& isset($firstArgument[1][0])
			&& is_string($firstArgument[1][0])
		) {
			return TeamRosterCatalog::normalizeLogin($firstArgument[1][0]);
		}

		foreach ($callbackArguments as $callbackArgument) {
			if (!is_string($callbackArgument)) {
				continue;
			}

			$candidateLogin = trim($callbackArgument);
			if ($candidateLogin === '' || stripos($candidateLogin, 'PlayerManagerCallback.') === 0) {
				continue;
			}

			return TeamRosterCatalog::normalizeLogin($candidateLogin);
		}

		return '';
	}

	private function pruneTeamControlRecentForcedCache() {
		if (empty($this->teamControlRecentForcedAt)) {
			return;
		}

		$now = time();
		$maxAge = max(30, $this->teamControlForceCooldownSeconds * 10);
		foreach ($this->teamControlRecentForcedAt as $login => $forcedAt) {
			if (!is_numeric($forcedAt) || ($now - (int) $forcedAt) > $maxAge) {
				unset($this->teamControlRecentForcedAt[$login]);
			}
		}
	}
}
