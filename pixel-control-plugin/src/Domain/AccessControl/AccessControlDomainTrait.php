<?php

namespace PixelControl\Domain\AccessControl;

use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Logger;
use ManiaControl\Players\Player;
use PixelControl\AccessControl\WhitelistCatalog;
use PixelControl\AccessControl\WhitelistState;
use PixelControl\Admin\AdminActionCatalog;
use PixelControl\Admin\AdminActionResult;
use PixelControl\VoteControl\VotePolicyCatalog;
use PixelControl\VoteControl\VotePolicyState;

trait AccessControlDomainTrait {
	private function initializeAccessControlSettings() {
		if (!$this->maniaControl) {
			return;
		}

		$settingManager = $this->maniaControl->getSettingManager();
		$settingManager->initSetting(
			$this,
			self::SETTING_WHITELIST_ENABLED,
			$this->readEnvString('PIXEL_CONTROL_WHITELIST_ENABLED', '0') === '1'
		);
		$settingManager->initSetting(
			$this,
			self::SETTING_WHITELIST_LOGINS,
			WhitelistCatalog::encodeLogins(
				WhitelistCatalog::parseLogins($this->readEnvString('PIXEL_CONTROL_WHITELIST_LOGINS', ''))
			)
		);
		$settingManager->initSetting(
			$this,
			self::SETTING_VOTE_POLICY_MODE,
			VotePolicyCatalog::normalizeMode(
				$this->readEnvString('PIXEL_CONTROL_VOTE_POLICY_MODE', VotePolicyCatalog::DEFAULT_MODE),
				VotePolicyCatalog::DEFAULT_MODE
			)
		);
	}

	private function initializeAccessControlState() {
		if (!$this->maniaControl) {
			return;
		}

		$this->whitelistState = new WhitelistState();
		$this->votePolicyState = new VotePolicyState();
		$this->whitelistRecentDeniedAt = array();
		$this->whitelistLastReconcileAt = 0;
		$this->whitelistGuestListLastSyncHash = '';
		$this->whitelistGuestListLastSyncAt = 0;
		$this->votePolicyLastCallVoteTimeoutMs = 0;
		$this->votePolicyStrictRuntimeApplied = false;

		$whitelistSource = $this->resolveWhitelistBootstrapSource();

		$whitelistEnabled = $this->resolveRuntimeBoolSetting(
			self::SETTING_WHITELIST_ENABLED,
			'PIXEL_CONTROL_WHITELIST_ENABLED',
			WhitelistCatalog::DEFAULT_ENABLED
		);

		$whitelistLogins = array();
		$whitelistLoginsFromEnv = $this->readEnvString('PIXEL_CONTROL_WHITELIST_LOGINS', '');
		if ($whitelistLoginsFromEnv !== '') {
			$whitelistLogins = WhitelistCatalog::parseLogins($whitelistLoginsFromEnv);
		} else {
			$whitelistLogins = WhitelistCatalog::parseLogins(
				$this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_WHITELIST_LOGINS)
			);
		}

		$this->whitelistState->bootstrap(
			array(
				'enabled' => $whitelistEnabled,
				'logins' => $whitelistLogins,
			),
			$whitelistSource,
			'plugin_bootstrap'
		);

		$votePolicySource = $this->resolveVotePolicyBootstrapSource();
		$votePolicyMode = VotePolicyCatalog::normalizeMode(
			$this->resolveRuntimeStringSetting(
				self::SETTING_VOTE_POLICY_MODE,
				'PIXEL_CONTROL_VOTE_POLICY_MODE',
				VotePolicyCatalog::DEFAULT_MODE
			),
			VotePolicyCatalog::DEFAULT_MODE
		);

		$this->votePolicyState->bootstrap(
			array('mode' => $votePolicyMode),
			$votePolicySource,
			'plugin_bootstrap'
		);

		$this->applyVotePolicyRuntimeState('bootstrap', true);
		$this->syncWhitelistGuestList('bootstrap', true);

		$whitelistSnapshot = $this->getWhitelistSnapshot();
		$votePolicySnapshot = $this->getVotePolicySnapshot();
		Logger::log(
			'[PixelControl][access][bootstrap] whitelist_enabled=' . (!empty($whitelistSnapshot['enabled']) ? 'yes' : 'no')
			. ', whitelist_count=' . (isset($whitelistSnapshot['count']) ? (int) $whitelistSnapshot['count'] : 0)
			. ', vote_policy_mode=' . (isset($votePolicySnapshot['mode']) ? (string) $votePolicySnapshot['mode'] : VotePolicyCatalog::DEFAULT_MODE)
			. '.'
		);
	}

	private function resolveWhitelistBootstrapSource() {
		if (
			$this->isRuntimeEnvDefined('PIXEL_CONTROL_WHITELIST_ENABLED')
			|| $this->hasRuntimeEnvValue('PIXEL_CONTROL_WHITELIST_LOGINS')
		) {
			return WhitelistCatalog::UPDATE_SOURCE_ENV;
		}

		return WhitelistCatalog::UPDATE_SOURCE_SETTING;
	}

	private function resolveVotePolicyBootstrapSource() {
		if ($this->isRuntimeEnvDefined('PIXEL_CONTROL_VOTE_POLICY_MODE')) {
			return VotePolicyCatalog::UPDATE_SOURCE_ENV;
		}

		return VotePolicyCatalog::UPDATE_SOURCE_SETTING;
	}

	private function buildDefaultWhitelistSnapshot() {
		return array(
			'enabled' => WhitelistCatalog::DEFAULT_ENABLED,
			'logins' => array(),
			'count' => 0,
			'updated_at' => 0,
			'updated_by' => 'system',
			'update_source' => WhitelistCatalog::UPDATE_SOURCE_SETTING,
		);
	}

	private function buildDefaultVotePolicySnapshot() {
		return array(
			'mode' => VotePolicyCatalog::DEFAULT_MODE,
			'strict_mode' => false,
			'updated_at' => 0,
			'updated_by' => 'system',
			'update_source' => VotePolicyCatalog::UPDATE_SOURCE_SETTING,
			'available_modes' => array(
				VotePolicyCatalog::MODE_CANCEL_NON_ADMIN,
				VotePolicyCatalog::MODE_DISABLE_CALLVOTES,
			),
		);
	}

	private function getWhitelistSnapshot() {
		if ($this->whitelistState) {
			return $this->whitelistState->getSnapshot();
		}

		return $this->buildDefaultWhitelistSnapshot();
	}

	private function getVotePolicySnapshot() {
		if ($this->votePolicyState) {
			return $this->votePolicyState->getSnapshot();
		}

		return $this->buildDefaultVotePolicySnapshot();
	}

	private function buildWhitelistCapabilitySnapshot() {
		$snapshot = $this->getWhitelistSnapshot();
		$snapshot['guest_list_sync'] = array(
			'last_sync_at' => $this->whitelistGuestListLastSyncAt,
			'last_sync_hash' => $this->whitelistGuestListLastSyncHash,
		);

		return $snapshot;
	}

	private function handleAccessControlPlayerCallback(array $callbackArguments) {
		if (!$this->whitelistState || !$this->whitelistState->isEnabled()) {
			return;
		}

		$playerLogin = $this->extractAccessControlPlayerLogin($callbackArguments);
		if ($playerLogin === '') {
			return;
		}

		$this->enforceWhitelistForLogin($playerLogin, 'player_callback');
	}

	private function extractAccessControlPlayerLogin(array $callbackArguments) {
		if (empty($callbackArguments)) {
			return '';
		}

		foreach ($callbackArguments as $callbackArgument) {
			if ($callbackArgument instanceof Player) {
				if (method_exists($callbackArgument, 'isFakePlayer') && $callbackArgument->isFakePlayer()) {
					return '';
				}

				if (isset($callbackArgument->isServer) && $callbackArgument->isServer) {
					return '';
				}

				if (isset($callbackArgument->login)) {
					return WhitelistCatalog::normalizeLogin($callbackArgument->login);
				}
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
			return WhitelistCatalog::normalizeLogin($firstArgument[1][0]);
		}

		foreach ($callbackArguments as $callbackArgument) {
			if (!is_string($callbackArgument)) {
				continue;
			}

			$candidateLogin = trim($callbackArgument);
			if ($candidateLogin === '' || stripos($candidateLogin, 'PlayerManagerCallback.') === 0) {
				continue;
			}

			return WhitelistCatalog::normalizeLogin($candidateLogin);
		}

		return '';
	}

	private function enforceWhitelistForLogin($login, $source) {
		$normalizedLogin = WhitelistCatalog::normalizeLogin($login);
		if ($normalizedLogin === '') {
			return array('success' => false, 'code' => 'invalid_parameters', 'applied' => false);
		}

		if (!$this->whitelistState || !$this->whitelistState->isEnabled()) {
			return array('success' => false, 'code' => 'policy_disabled', 'applied' => false);
		}

		if ($this->whitelistState->hasLogin($normalizedLogin)) {
			return array('success' => true, 'code' => 'login_allowed', 'applied' => false);
		}

		$now = time();
		$lastDeniedAt = isset($this->whitelistRecentDeniedAt[$normalizedLogin])
			? (int) $this->whitelistRecentDeniedAt[$normalizedLogin]
			: 0;
		if (($now - $lastDeniedAt) < $this->whitelistDenyCooldownSeconds) {
			return array('success' => true, 'code' => 'deny_cooldown_active', 'applied' => false);
		}

		$this->whitelistRecentDeniedAt[$normalizedLogin] = $now;

		$kickApplied = false;
		$kickReason = '';
		if ($this->maniaControl && $this->maniaControl->getClient()) {
			try {
				$kickApplied = (bool) $this->maniaControl->getClient()->kick($normalizedLogin, 'Whitelist enabled: access denied.');
			} catch (\Throwable $throwable) {
				$kickReason = trim((string) $throwable->getMessage());
			}
		} else {
			$kickReason = 'client_unavailable';
		}

		if ($kickApplied) {
			Logger::logWarning(
				'[PixelControl][access][whitelist_denied] login=' . $normalizedLogin
				. ', source=' . (string) $source
				. ', action=kick_applied.'
			);
			return array('success' => true, 'code' => 'kick_applied', 'applied' => true);
		}

		Logger::logWarning(
			'[PixelControl][access][whitelist_denied] login=' . $normalizedLogin
			. ', source=' . (string) $source
			. ', action=kick_failed'
			. ($kickReason !== '' ? ', reason=' . $kickReason : '')
			. '.'
		);

		return array(
			'success' => false,
			'code' => 'kick_failed',
			'applied' => false,
			'details' => array('reason' => $kickReason),
		);
	}

	private function reconcileWhitelistForConnectedPlayers($source, $force = false) {
		if (!$this->whitelistState || !$this->whitelistState->isEnabled()) {
			return array(
				'success' => true,
				'code' => 'sweep_skipped_policy_disabled',
				'applied_count' => 0,
				'failed_count' => 0,
				'skipped_count' => 0,
				'cooldown_count' => 0,
			);
		}

		if (!$this->maniaControl || !$this->maniaControl->getPlayerManager()) {
			return array(
				'success' => false,
				'code' => 'capability_unavailable',
				'applied_count' => 0,
				'failed_count' => 0,
				'skipped_count' => 0,
				'cooldown_count' => 0,
			);
		}

		$now = time();
		if (!$force && ($now - $this->whitelistLastReconcileAt) < $this->whitelistReconcileIntervalSeconds) {
			return array(
				'success' => true,
				'code' => 'sweep_skipped_interval_guard',
				'applied_count' => 0,
				'failed_count' => 0,
				'skipped_count' => 0,
				'cooldown_count' => 0,
			);
		}

		$this->whitelistLastReconcileAt = $now;

		$players = $this->maniaControl->getPlayerManager()->getPlayers(false);
		if (!is_array($players)) {
			return array(
				'success' => true,
				'code' => 'sweep_no_players',
				'applied_count' => 0,
				'failed_count' => 0,
				'skipped_count' => 0,
				'cooldown_count' => 0,
			);
		}

		Logger::log(
			'[PixelControl][access][whitelist_sweep_start] source=' . (string) $source
			. ', force=' . ($force ? 'yes' : 'no')
			. ', player_count=' . count($players)
			. '.'
		);

		$appliedCount = 0;
		$failedCount = 0;
		$skippedCount = 0;
		$cooldownCount = 0;

		foreach ($players as $player) {
			if (!$player instanceof Player) {
				$skippedCount++;
				continue;
			}

			if ($this->shouldSkipWhitelistSweepPlayer($player)) {
				$skippedCount++;
				continue;
			}

			$login = isset($player->login) ? WhitelistCatalog::normalizeLogin($player->login) : '';
			if ($login === '') {
				$skippedCount++;
				continue;
			}

			$enforcementResult = $this->enforceWhitelistForLogin($login, $source . '_sweep');
			$enforcementCode = isset($enforcementResult['code']) ? (string) $enforcementResult['code'] : '';

			if (!empty($enforcementResult['applied'])) {
				$appliedCount++;
				continue;
			}

			if ($enforcementCode === 'deny_cooldown_active') {
				$cooldownCount++;
				continue;
			}

			if (!empty($enforcementResult['success'])) {
				$skippedCount++;
				continue;
			}

			$failedCount++;
		}

		$this->pruneWhitelistRecentDeniedCache();

		$logMessage = '[PixelControl][access][whitelist_sweep_result] source=' . (string) $source
			. ', force=' . ($force ? 'yes' : 'no')
			. ', applied=' . $appliedCount
			. ', failed=' . $failedCount
			. ', skipped=' . $skippedCount
			. ', cooldown=' . $cooldownCount
			. '.';

		if ($failedCount > 0) {
			Logger::logWarning($logMessage);
		} else {
			Logger::log($logMessage);
		}

		return array(
			'success' => ($failedCount === 0),
			'code' => ($failedCount === 0 ? 'sweep_completed' : 'sweep_partial_failure'),
			'applied_count' => $appliedCount,
			'failed_count' => $failedCount,
			'skipped_count' => $skippedCount,
			'cooldown_count' => $cooldownCount,
		);
	}

	private function shouldSkipWhitelistSweepPlayer(Player $player) {
		if (method_exists($player, 'isFakePlayer') && $player->isFakePlayer()) {
			return true;
		}

		if (isset($player->isServer) && $player->isServer) {
			return true;
		}

		return false;
	}

	private function pruneWhitelistRecentDeniedCache() {
		if (empty($this->whitelistRecentDeniedAt)) {
			return;
		}

		$now = time();
		$maxAge = max(30, $this->whitelistDenyCooldownSeconds * 10);
		foreach ($this->whitelistRecentDeniedAt as $login => $deniedAt) {
			if (!is_numeric($deniedAt) || ($now - (int) $deniedAt) > $maxAge) {
				unset($this->whitelistRecentDeniedAt[$login]);
			}
		}
	}

	private function handleVotePolicyCallback(array $callbackArguments) {
		if (!$this->votePolicyState) {
			return;
		}

		$votePayload = $this->parseVoteUpdatedPayload($callbackArguments);
		if (empty($votePayload['available'])) {
			return;
		}

		$mode = $this->votePolicyState->getMode();
		if ($mode !== VotePolicyCatalog::MODE_CANCEL_NON_ADMIN) {
			return;
		}

		$initiatorLogin = isset($votePayload['login']) ? WhitelistCatalog::normalizeLogin($votePayload['login']) : '';
		if ($initiatorLogin === '') {
			return;
		}

		if ($this->isVoteInitiatorPrivileged($initiatorLogin)) {
			return;
		}

		$cancelApplied = false;
		$cancelReason = '';
		if ($this->maniaControl && $this->maniaControl->getClient()) {
			try {
				$cancelApplied = (bool) $this->maniaControl->getClient()->cancelVote();
			} catch (\Throwable $throwable) {
				$cancelReason = trim((string) $throwable->getMessage());
			}
		}

		if ($cancelApplied) {
			Logger::logWarning(
				'[PixelControl][vote_policy][vote_cancelled] mode=' . $mode
				. ', initiator=' . $initiatorLogin
				. ', state=' . (isset($votePayload['state_name']) ? (string) $votePayload['state_name'] : '')
				. ', command=' . (isset($votePayload['command_name']) ? (string) $votePayload['command_name'] : '')
				. ', reason=non_admin_initiator.'
			);
			return;
		}

		Logger::logWarning(
			'[PixelControl][vote_policy][vote_cancel_failed] mode=' . $mode
			. ', initiator=' . $initiatorLogin
			. ', command=' . (isset($votePayload['command_name']) ? (string) $votePayload['command_name'] : '')
			. ($cancelReason !== '' ? ', reason=' . $cancelReason : '')
			. '.'
		);
	}

	private function parseVoteUpdatedPayload(array $callbackArguments) {
		$payload = array(
			'available' => false,
			'state_name' => '',
			'login' => '',
			'command_name' => '',
			'command_param' => '',
		);

		if (empty($callbackArguments)) {
			return $payload;
		}

		$callback = $callbackArguments[0];
		if (!is_array($callback) || !isset($callback[1]) || !is_array($callback[1])) {
			return $payload;
		}

		$rawVotePayload = $callback[1];
		if (isset($rawVotePayload[0]) && is_array($rawVotePayload[0])) {
			$rawVotePayload = $rawVotePayload[0];
		}

		if (!is_array($rawVotePayload)) {
			return $payload;
		}

		$stateName = '';
		$login = '';
		$commandName = '';
		$commandParam = '';

		if (isset($rawVotePayload[0])) {
			$stateName = trim((string) $rawVotePayload[0]);
		}
		if (isset($rawVotePayload[1])) {
			$login = trim((string) $rawVotePayload[1]);
		}
		if (isset($rawVotePayload[2])) {
			$commandName = trim((string) $rawVotePayload[2]);
		}
		if (isset($rawVotePayload[3])) {
			$commandParam = trim((string) $rawVotePayload[3]);
		}

		if (isset($rawVotePayload['StateName'])) {
			$stateName = trim((string) $rawVotePayload['StateName']);
		}
		if (isset($rawVotePayload['Login'])) {
			$login = trim((string) $rawVotePayload['Login']);
		}
		if (isset($rawVotePayload['CmdName'])) {
			$commandName = trim((string) $rawVotePayload['CmdName']);
		}
		if (isset($rawVotePayload['CmdParam'])) {
			$commandParam = trim((string) $rawVotePayload['CmdParam']);
		}

		$payload['state_name'] = $stateName;
		$payload['login'] = $login;
		$payload['command_name'] = $commandName;
		$payload['command_param'] = $commandParam;
		$payload['available'] = ($stateName !== '' || $login !== '' || $commandName !== '' || $commandParam !== '');

		return $payload;
	}

	private function isVoteInitiatorPrivileged($login) {
		if (!$this->maniaControl) {
			return false;
		}

		$player = $this->maniaControl->getPlayerManager()->getPlayer($login, true);
		if (!$player) {
			return false;
		}

		$authLevel = isset($player->authLevel) && is_numeric($player->authLevel)
			? (int) $player->authLevel
			: AuthenticationManager::AUTH_LEVEL_PLAYER;

		return $authLevel >= AuthenticationManager::AUTH_LEVEL_MODERATOR;
	}

	private function handleAccessControlPolicyTick() {
		$this->applyVotePolicyRuntimeState('periodic_tick', false);

		$whitelistSnapshot = $this->getWhitelistSnapshot();
		if (!empty($whitelistSnapshot['enabled'])) {
			$this->syncWhitelistGuestList('periodic_tick', false);
			$this->reconcileWhitelistForConnectedPlayers('periodic_tick', false);
			return;
		}

		$this->pruneWhitelistRecentDeniedCache();
	}

	private function applyVotePolicyRuntimeState($source, $force) {
		if (!$this->votePolicyState || !$this->maniaControl || !$this->maniaControl->getClient()) {
			return array('success' => false, 'code' => 'capability_unavailable', 'message' => 'Vote policy runtime client unavailable.');
		}

		$mode = $this->votePolicyState->getMode();
		if ($mode === VotePolicyCatalog::MODE_DISABLE_CALLVOTES) {
			if ($this->votePolicyStrictRuntimeApplied && !$force) {
				return array('success' => true, 'code' => 'strict_already_applied', 'message' => 'Strict vote mode already active.');
			}

			if (!$this->votePolicyStrictRuntimeApplied || $force) {
				$timeoutSnapshot = null;
				try {
					$timeoutSnapshot = $this->maniaControl->getClient()->getCallVoteTimeOut();
				} catch (\Throwable $throwable) {
					$timeoutSnapshot = null;
				}

				$currentTimeout = $this->resolveVoteTimeoutCurrentValue($timeoutSnapshot);
				if ($currentTimeout > 0) {
					$this->votePolicyLastCallVoteTimeoutMs = $currentTimeout;
				}
			}

			try {
				$this->maniaControl->getClient()->setCallVoteTimeOut(0);
				$this->votePolicyStrictRuntimeApplied = true;
				Logger::log(
					'[PixelControl][vote_policy][strict_applied] source=' . (string) $source
					. ', mode=' . $mode
					. ', timeout_ms=0.'
				);
				return array('success' => true, 'code' => 'strict_applied', 'message' => 'Strict vote mode applied.');
			} catch (\Throwable $throwable) {
				return array(
					'success' => false,
					'code' => 'native_rejected',
					'message' => 'Failed to set callvote timeout for strict mode.',
					'details' => array('reason' => trim((string) $throwable->getMessage())),
				);
			}
		}

		if ($this->votePolicyStrictRuntimeApplied && $this->votePolicyLastCallVoteTimeoutMs > 0) {
			try {
				$this->maniaControl->getClient()->setCallVoteTimeOut($this->votePolicyLastCallVoteTimeoutMs);
			} catch (\Throwable $throwable) {
				// Keep non-strict mode resilient when timeout restoration is not available.
			}
		}

		$this->votePolicyStrictRuntimeApplied = false;
		return array('success' => true, 'code' => 'cancel_mode_active', 'message' => 'Cancel-on-callback vote mode active.');
	}

	private function resolveVoteTimeoutCurrentValue($timeoutSnapshot) {
		if (is_numeric($timeoutSnapshot)) {
			return max(0, (int) $timeoutSnapshot);
		}

		if (!is_array($timeoutSnapshot)) {
			return 0;
		}

		if (isset($timeoutSnapshot['CurrentValue']) && is_numeric($timeoutSnapshot['CurrentValue'])) {
			return max(0, (int) $timeoutSnapshot['CurrentValue']);
		}

		if (isset($timeoutSnapshot[0]) && is_numeric($timeoutSnapshot[0])) {
			return max(0, (int) $timeoutSnapshot[0]);
		}

		foreach ($timeoutSnapshot as $key => $value) {
			if (!is_string($key) || strtolower($key) !== 'currentvalue' || !is_numeric($value)) {
				continue;
			}

			return max(0, (int) $value);
		}

		return 0;
	}

	private function syncWhitelistGuestList($source, $force) {
		if (!$this->whitelistState || !$this->maniaControl || !$this->maniaControl->getClient()) {
			return array('success' => false, 'code' => 'capability_unavailable', 'message' => 'Guest-list sync unavailable.');
		}

		$snapshot = $this->whitelistState->getSnapshot();
		$syncHash = sha1(
			(!empty($snapshot['enabled']) ? '1' : '0') . '|' . WhitelistCatalog::encodeLogins(
				(isset($snapshot['logins']) && is_array($snapshot['logins'])) ? $snapshot['logins'] : array()
			)
		);

		if (!$force && $syncHash === $this->whitelistGuestListLastSyncHash) {
			return array('success' => true, 'code' => 'sync_not_required', 'message' => 'Whitelist guest list already in sync.');
		}

		$client = $this->maniaControl->getClient();
		$logins = (isset($snapshot['logins']) && is_array($snapshot['logins'])) ? $snapshot['logins'] : array();
		$syncEnabled = !empty($snapshot['enabled']);

		try {
			$client->cleanGuestList();
			if ($syncEnabled) {
				foreach ($logins as $login) {
					$client->addGuest((string) $login);
				}
			}
		} catch (\Throwable $throwable) {
			Logger::logWarning(
				'[PixelControl][access][guest_sync_failed] source=' . (string) $source
				. ', reason=' . trim((string) $throwable->getMessage())
				. '.'
			);

			return array(
				'success' => false,
				'code' => 'guest_list_sync_failed',
				'message' => 'Whitelist guest list sync failed.',
				'details' => array('reason' => trim((string) $throwable->getMessage())),
			);
		}

		$saveGuestListReason = '';
		try {
			$client->saveGuestList('guestlist.txt');
		} catch (\Throwable $throwable) {
			$saveGuestListReason = trim((string) $throwable->getMessage());
			Logger::logWarning(
				'[PixelControl][access][guest_sync_save_warning] source=' . (string) $source
				. ', reason=' . $saveGuestListReason
				. ', fallback=connect_time_enforcement.'
			);
		}

		$this->whitelistGuestListLastSyncHash = $syncHash;
		$this->whitelistGuestListLastSyncAt = time();

		Logger::log(
			'[PixelControl][access][guest_sync_ok] source=' . (string) $source
			. ', enabled=' . ($syncEnabled ? 'yes' : 'no')
			. ', count=' . count($logins)
			. '.'
		);

		if ($saveGuestListReason !== '') {
			return array(
				'success' => true,
				'code' => 'guest_list_sync_degraded',
				'message' => 'Whitelist guest list synchronized in runtime (save warning ignored).',
				'details' => array('reason' => $saveGuestListReason),
			);
		}

		return array('success' => true, 'code' => 'guest_list_synced', 'message' => 'Whitelist guest list synchronized.');
	}

	private function shouldPersistWhitelistAfterAdminAction($actionName) {
		return in_array(
			$actionName,
			array(
				AdminActionCatalog::ACTION_WHITELIST_ENABLE,
				AdminActionCatalog::ACTION_WHITELIST_DISABLE,
				AdminActionCatalog::ACTION_WHITELIST_ADD,
				AdminActionCatalog::ACTION_WHITELIST_REMOVE,
				AdminActionCatalog::ACTION_WHITELIST_CLEAN,
			),
			true
		);
	}

	private function shouldPersistVotePolicyAfterAdminAction($actionName) {
		return $actionName === AdminActionCatalog::ACTION_VOTE_POLICY_SET;
	}

	private function persistWhitelistAfterAdminAction($actionName, AdminActionResult $actionResult, array $snapshotBeforeAction) {
		if ($actionName === AdminActionCatalog::ACTION_WHITELIST_SYNC) {
			$syncResult = $this->syncWhitelistGuestList('admin_action_sync', true);
			if (empty($syncResult['success'])) {
				return $syncResult;
			}

			return $this->buildWhitelistPersistenceResultWithReconcile(
				$syncResult,
				'admin_action_sync',
				(isset($syncResult['code']) ? (string) $syncResult['code'] : 'guest_list_synced'),
				(isset($syncResult['message']) ? (string) $syncResult['message'] : 'Whitelist guest list synchronized.')
			);
		}

		if (!$this->shouldPersistWhitelistAfterAdminAction($actionName)) {
			return array('success' => true, 'code' => 'not_required', 'message' => 'No whitelist persistence required.');
		}

		$actionDetails = $actionResult->getDetails();
		$currentSnapshot = (isset($actionDetails['whitelist']) && is_array($actionDetails['whitelist']))
			? $actionDetails['whitelist']
			: $this->getWhitelistSnapshot();

		$persistenceResult = $this->persistWhitelistSnapshot($currentSnapshot, $snapshotBeforeAction);
		if (empty($persistenceResult['success'])) {
			$rollbackResult = array();
			if (!empty($snapshotBeforeAction)) {
				$rollbackResult = $this->restoreWhitelistSnapshot(
					$snapshotBeforeAction,
					WhitelistCatalog::UPDATE_SOURCE_SETTING,
					'whitelist_persistence_rollback'
				);
			}

			return array(
				'success' => false,
				'code' => 'setting_write_failed',
				'message' => 'Whitelist settings persistence failed; runtime update rolled back.',
				'details' => array(
					'persistence' => isset($persistenceResult['details']) ? $persistenceResult['details'] : array(),
					'rollback' => isset($rollbackResult['details']) ? $rollbackResult['details'] : array(),
				),
			);
		}

		$syncResult = $this->syncWhitelistGuestList('admin_action', true);
		if (empty($syncResult['success'])) {
			return array(
				'success' => false,
				'code' => isset($syncResult['code']) ? (string) $syncResult['code'] : 'guest_list_sync_failed',
				'message' => isset($syncResult['message']) ? (string) $syncResult['message'] : 'Whitelist guest list sync failed.',
				'details' => isset($syncResult['details']) ? $syncResult['details'] : array(),
			);
		}

		return $this->buildWhitelistPersistenceResultWithReconcile(
			$syncResult,
			'admin_action',
			'persisted_and_synced',
			'Whitelist settings persisted and synced.'
		);
	}

	private function buildWhitelistPersistenceResultWithReconcile(array $syncResult, $source, $resultCode, $resultMessage) {
		$reconcileResult = $this->reconcileWhitelistForConnectedPlayers($source, true);
		$syncDetails = (isset($syncResult['details']) && is_array($syncResult['details'])) ? $syncResult['details'] : array();

		return array(
			'success' => true,
			'code' => (string) $resultCode,
			'message' => (string) $resultMessage,
			'details' => array(
				'sync' => array(
					'code' => isset($syncResult['code']) ? (string) $syncResult['code'] : 'guest_list_synced',
					'message' => isset($syncResult['message']) ? (string) $syncResult['message'] : 'Whitelist guest list synchronized.',
					'details' => $syncDetails,
				),
				'reconcile' => $reconcileResult,
			),
		);
	}

	private function persistVotePolicyAfterAdminAction($actionName, AdminActionResult $actionResult, array $snapshotBeforeAction) {
		if (!$this->shouldPersistVotePolicyAfterAdminAction($actionName)) {
			return array('success' => true, 'code' => 'not_required', 'message' => 'No vote policy persistence required.');
		}

		$actionDetails = $actionResult->getDetails();
		$currentSnapshot = (isset($actionDetails['vote_policy']) && is_array($actionDetails['vote_policy']))
			? $actionDetails['vote_policy']
			: $this->getVotePolicySnapshot();

		$persistenceResult = $this->persistVotePolicySnapshot($currentSnapshot, $snapshotBeforeAction);
		if (empty($persistenceResult['success'])) {
			$rollbackResult = array();
			if (!empty($snapshotBeforeAction)) {
				$rollbackResult = $this->restoreVotePolicySnapshot(
					$snapshotBeforeAction,
					VotePolicyCatalog::UPDATE_SOURCE_SETTING,
					'vote_policy_persistence_rollback'
				);
			}

			return array(
				'success' => false,
				'code' => 'setting_write_failed',
				'message' => 'Vote policy persistence failed; runtime update rolled back.',
				'details' => array(
					'persistence' => isset($persistenceResult['details']) ? $persistenceResult['details'] : array(),
					'rollback' => isset($rollbackResult['details']) ? $rollbackResult['details'] : array(),
				),
			);
		}

		$runtimeResult = $this->applyVotePolicyRuntimeState('admin_action', true);
		if (empty($runtimeResult['success'])) {
			return array(
				'success' => false,
				'code' => isset($runtimeResult['code']) ? (string) $runtimeResult['code'] : 'native_rejected',
				'message' => isset($runtimeResult['message']) ? (string) $runtimeResult['message'] : 'Vote policy runtime application failed.',
				'details' => isset($runtimeResult['details']) ? $runtimeResult['details'] : array(),
			);
		}

		return array('success' => true, 'code' => 'persisted_and_applied', 'message' => 'Vote policy persisted and applied.');
	}

	private function buildWhitelistSettingValueMap(array $whitelistSnapshot) {
		$enabled = !empty($whitelistSnapshot['enabled']);
		$logins = (isset($whitelistSnapshot['logins']) && is_array($whitelistSnapshot['logins'])) ? $whitelistSnapshot['logins'] : array();

		return array(
			self::SETTING_WHITELIST_ENABLED => $enabled,
			self::SETTING_WHITELIST_LOGINS => WhitelistCatalog::encodeLogins($logins),
		);
	}

	private function persistWhitelistSnapshot(array $whitelistSnapshot, array $previousSnapshot = array()) {
		if (!$this->maniaControl) {
			return array('success' => false, 'code' => 'capability_unavailable', 'message' => 'Whitelist persistence unavailable.');
		}

		$settingManager = $this->maniaControl->getSettingManager();
		$settingValues = $this->buildWhitelistSettingValueMap($whitelistSnapshot);
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
				'message' => 'Whitelist settings persisted.',
				'details' => array('written_settings' => $writtenSettings),
			);
		}

		$rollbackFailedSettings = array();
		if (!empty($writtenSettings) && !empty($previousSnapshot)) {
			$rollbackValues = $this->buildWhitelistSettingValueMap($previousSnapshot);
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
			'message' => 'Unable to persist whitelist settings snapshot.',
			'details' => array(
				'failed_settings' => $failedSettings,
				'written_settings' => $writtenSettings,
				'rollback_failed_settings' => $rollbackFailedSettings,
			),
		);
	}

	private function restoreWhitelistSnapshot(array $whitelistSnapshot, $updateSource, $updatedBy) {
		if (!$this->whitelistState) {
			return array('success' => false, 'code' => 'capability_unavailable', 'message' => 'Whitelist state unavailable for rollback.');
		}

		$this->whitelistState->bootstrap(
			array(
				'enabled' => !empty($whitelistSnapshot['enabled']),
				'logins' => (isset($whitelistSnapshot['logins']) && is_array($whitelistSnapshot['logins'])) ? $whitelistSnapshot['logins'] : array(),
			),
			WhitelistCatalog::normalizeUpdateSource($updateSource, WhitelistCatalog::UPDATE_SOURCE_SETTING),
			WhitelistCatalog::normalizeUpdatedBy($updatedBy, 'system')
		);
		$this->syncWhitelistGuestList('restore_snapshot', true);

		return array('success' => true, 'code' => 'rollback_applied', 'message' => 'Whitelist snapshot rollback applied.');
	}

	private function persistVotePolicySnapshot(array $votePolicySnapshot, array $previousSnapshot = array()) {
		if (!$this->maniaControl) {
			return array('success' => false, 'code' => 'capability_unavailable', 'message' => 'Vote policy persistence unavailable.');
		}

		$mode = VotePolicyCatalog::normalizeMode(
			(isset($votePolicySnapshot['mode']) ? $votePolicySnapshot['mode'] : VotePolicyCatalog::DEFAULT_MODE),
			VotePolicyCatalog::DEFAULT_MODE
		);

		$settingSaved = $this->maniaControl->getSettingManager()->setSetting($this, self::SETTING_VOTE_POLICY_MODE, $mode);
		if ($settingSaved) {
			return array('success' => true, 'code' => 'settings_persisted', 'message' => 'Vote policy settings persisted.');
		}

		if (!empty($previousSnapshot) && isset($previousSnapshot['mode'])) {
			$previousMode = VotePolicyCatalog::normalizeMode($previousSnapshot['mode'], VotePolicyCatalog::DEFAULT_MODE);
			$this->maniaControl->getSettingManager()->setSetting($this, self::SETTING_VOTE_POLICY_MODE, $previousMode);
		}

		return array('success' => false, 'code' => 'setting_write_failed', 'message' => 'Unable to persist vote policy setting.');
	}

	private function restoreVotePolicySnapshot(array $votePolicySnapshot, $updateSource, $updatedBy) {
		if (!$this->votePolicyState) {
			return array('success' => false, 'code' => 'capability_unavailable', 'message' => 'Vote policy state unavailable for rollback.');
		}

		$this->votePolicyState->bootstrap(
			array('mode' => isset($votePolicySnapshot['mode']) ? $votePolicySnapshot['mode'] : VotePolicyCatalog::DEFAULT_MODE),
			VotePolicyCatalog::normalizeUpdateSource($updateSource, VotePolicyCatalog::UPDATE_SOURCE_SETTING),
			VotePolicyCatalog::normalizeUpdatedBy($updatedBy, 'system')
		);
		$this->applyVotePolicyRuntimeState('restore_snapshot', true);

		return array('success' => true, 'code' => 'rollback_applied', 'message' => 'Vote policy snapshot rollback applied.');
	}
}
