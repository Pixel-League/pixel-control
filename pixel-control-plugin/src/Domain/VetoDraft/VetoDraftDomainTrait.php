<?php

namespace PixelControl\Domain\VetoDraft;

use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Communication\CommunicationAnswer;
use ManiaControl\Logger;
use ManiaControl\Players\Player;
use PixelControl\VetoDraft\ManiaControlMapRuntimeAdapter;
use PixelControl\VetoDraft\MapPoolService;
use PixelControl\VetoDraft\MatchmakingLifecycleCatalog;
use PixelControl\VetoDraft\TournamentSequenceBuilder;
use PixelControl\VetoDraft\VetoDraftCatalog;
use PixelControl\VetoDraft\VetoDraftCoordinator;
use PixelControl\VetoDraft\VetoDraftQueueApplier;

trait VetoDraftDomainTrait {
	private function initializeVetoDraftSettings() {
		if (!$this->maniaControl) {
			return;
		}

		$settingManager = $this->maniaControl->getSettingManager();
		$settingManager->initSetting($this, self::SETTING_VETO_DRAFT_ENABLED, $this->readEnvString('PIXEL_CONTROL_VETO_DRAFT_ENABLED', '0') === '1');
		$settingManager->initSetting($this, self::SETTING_VETO_DRAFT_COMMAND, $this->readEnvString('PIXEL_CONTROL_VETO_DRAFT_COMMAND', VetoDraftCatalog::DEFAULT_COMMAND));
		$settingManager->initSetting($this, self::SETTING_VETO_DRAFT_DEFAULT_MODE, $this->readEnvString('PIXEL_CONTROL_VETO_DRAFT_DEFAULT_MODE', VetoDraftCatalog::MODE_MATCHMAKING_VOTE));
		$settingManager->initSetting(
			$this,
			self::SETTING_VETO_DRAFT_MATCHMAKING_DURATION_SECONDS,
			$this->resolveRuntimeIntSetting(
				self::SETTING_VETO_DRAFT_MATCHMAKING_DURATION_SECONDS,
				'PIXEL_CONTROL_VETO_DRAFT_MATCHMAKING_DURATION_SECONDS',
				VetoDraftCatalog::DEFAULT_MATCHMAKING_DURATION_SECONDS,
				10
			)
		);
		$settingManager->initSetting(
			$this,
			self::SETTING_VETO_DRAFT_MATCHMAKING_AUTOSTART_MIN_PLAYERS,
			$this->resolveRuntimeIntSetting(
				self::SETTING_VETO_DRAFT_MATCHMAKING_AUTOSTART_MIN_PLAYERS,
				'PIXEL_CONTROL_VETO_DRAFT_MATCHMAKING_AUTOSTART_MIN_PLAYERS',
				VetoDraftCatalog::DEFAULT_MATCHMAKING_AUTOSTART_MIN_PLAYERS,
				1
			)
		);
		$settingManager->initSetting(
			$this,
			self::SETTING_VETO_DRAFT_TOURNAMENT_ACTION_TIMEOUT_SECONDS,
			$this->resolveRuntimeIntSetting(
				self::SETTING_VETO_DRAFT_TOURNAMENT_ACTION_TIMEOUT_SECONDS,
				'PIXEL_CONTROL_VETO_DRAFT_TOURNAMENT_ACTION_TIMEOUT_SECONDS',
				VetoDraftCatalog::DEFAULT_TOURNAMENT_ACTION_TIMEOUT_SECONDS,
				10
			)
		);
		$settingManager->initSetting(
			$this,
			self::SETTING_VETO_DRAFT_DEFAULT_BEST_OF,
			VetoDraftCatalog::sanitizeBestOf(
				$this->readEnvString('PIXEL_CONTROL_VETO_DRAFT_DEFAULT_BEST_OF', (string) VetoDraftCatalog::DEFAULT_BEST_OF),
				VetoDraftCatalog::DEFAULT_BEST_OF
			)
		);
		$settingManager->initSetting($this, self::SETTING_VETO_DRAFT_LAUNCH_IMMEDIATELY, $this->readEnvString('PIXEL_CONTROL_VETO_DRAFT_LAUNCH_IMMEDIATELY', '1') !== '0');
	}

	private function initializeVetoDraftFeature() {
		if (!$this->maniaControl) {
			return;
		}

		$this->vetoDraftMapPoolService = new MapPoolService();
		$this->vetoDraftQueueApplier = new VetoDraftQueueApplier();
		$this->vetoDraftCoordinator = new VetoDraftCoordinator($this->vetoDraftMapPoolService, new TournamentSequenceBuilder());

		$this->vetoDraftEnabled = $this->resolveRuntimeBoolSetting(self::SETTING_VETO_DRAFT_ENABLED, 'PIXEL_CONTROL_VETO_DRAFT_ENABLED', false);
		$this->vetoDraftCommandName = VetoDraftCatalog::normalizeCommandName(
			$this->resolveRuntimeStringSetting(self::SETTING_VETO_DRAFT_COMMAND, 'PIXEL_CONTROL_VETO_DRAFT_COMMAND', VetoDraftCatalog::DEFAULT_COMMAND),
			VetoDraftCatalog::DEFAULT_COMMAND
		);
		$this->vetoDraftDefaultMode = VetoDraftCatalog::normalizeMode(
			$this->resolveRuntimeStringSetting(self::SETTING_VETO_DRAFT_DEFAULT_MODE, 'PIXEL_CONTROL_VETO_DRAFT_DEFAULT_MODE', VetoDraftCatalog::MODE_MATCHMAKING_VOTE),
			VetoDraftCatalog::MODE_MATCHMAKING_VOTE
		);
		$this->vetoDraftMatchmakingDurationSeconds = $this->resolveRuntimeIntSetting(
			self::SETTING_VETO_DRAFT_MATCHMAKING_DURATION_SECONDS,
			'PIXEL_CONTROL_VETO_DRAFT_MATCHMAKING_DURATION_SECONDS',
			VetoDraftCatalog::DEFAULT_MATCHMAKING_DURATION_SECONDS,
			10
		);
		$this->vetoDraftMatchmakingAutostartMinPlayers = $this->resolveRuntimeIntSetting(
			self::SETTING_VETO_DRAFT_MATCHMAKING_AUTOSTART_MIN_PLAYERS,
			'PIXEL_CONTROL_VETO_DRAFT_MATCHMAKING_AUTOSTART_MIN_PLAYERS',
			VetoDraftCatalog::DEFAULT_MATCHMAKING_AUTOSTART_MIN_PLAYERS,
			1
		);
		$this->vetoDraftMatchmakingAutostartArmed = true;
		$this->vetoDraftMatchmakingAutostartSuppressed = false;
		$this->vetoDraftTournamentActionTimeoutSeconds = $this->resolveRuntimeIntSetting(
			self::SETTING_VETO_DRAFT_TOURNAMENT_ACTION_TIMEOUT_SECONDS,
			'PIXEL_CONTROL_VETO_DRAFT_TOURNAMENT_ACTION_TIMEOUT_SECONDS',
			VetoDraftCatalog::DEFAULT_TOURNAMENT_ACTION_TIMEOUT_SECONDS,
			10
		);
		$this->vetoDraftDefaultBestOf = VetoDraftCatalog::sanitizeBestOf(
			$this->resolveRuntimeIntSetting(
				self::SETTING_VETO_DRAFT_DEFAULT_BEST_OF,
				'PIXEL_CONTROL_VETO_DRAFT_DEFAULT_BEST_OF',
				VetoDraftCatalog::DEFAULT_BEST_OF,
				1
			),
			VetoDraftCatalog::DEFAULT_BEST_OF
		);
		$this->vetoDraftDefaultBestOf = $this->resolveSeriesControlBestOfDefault();
		$this->vetoDraftLaunchImmediately = $this->resolveRuntimeBoolSetting(
			self::SETTING_VETO_DRAFT_LAUNCH_IMMEDIATELY,
			'PIXEL_CONTROL_VETO_DRAFT_LAUNCH_IMMEDIATELY',
			true
		);

		$this->defineVetoDraftPermissions();
		$this->registerVetoDraftEntryPoints();
		$this->resetMatchmakingLifecycleContextState();
		$this->syncVetoDraftTelemetryState();

		Logger::log(
			'[PixelControl][veto][bootstrap] enabled=' . ($this->vetoDraftEnabled ? 'yes' : 'no')
			. ', command=' . $this->vetoDraftCommandName
			. ', default_mode=' . $this->vetoDraftDefaultMode
			. ', default_bo=' . $this->vetoDraftDefaultBestOf
			. ', matchmaking_duration=' . $this->vetoDraftMatchmakingDurationSeconds
			. ', matchmaking_autostart_min_players=' . $this->vetoDraftMatchmakingAutostartMinPlayers
			. ', action_timeout=' . $this->vetoDraftTournamentActionTimeoutSeconds
			. ', launch_immediately=' . ($this->vetoDraftLaunchImmediately ? 'yes' : 'no')
			. '.'
		);
	}

	private function defineVetoDraftPermissions() {
		if (!$this->maniaControl) {
			return;
		}

		$authenticationManager = $this->maniaControl->getAuthenticationManager();
		$authenticationManager->definePluginPermissionLevel($this, VetoDraftCatalog::RIGHT_CONTROL, AuthenticationManager::AUTH_LEVEL_MODERATOR);
		$authenticationManager->definePluginPermissionLevel($this, VetoDraftCatalog::RIGHT_OVERRIDE, AuthenticationManager::AUTH_LEVEL_ADMIN);
	}

	private function registerVetoDraftEntryPoints() {
		if (!$this->maniaControl || !$this->vetoDraftEnabled) {
			return;
		}

		$commandNames = array($this->vetoDraftCommandName);
		if ($this->vetoDraftCommandName !== VetoDraftCatalog::DEFAULT_COMMAND) {
			$commandNames[] = VetoDraftCatalog::DEFAULT_COMMAND;
		}

		$this->maniaControl->getCommandManager()->registerCommandListener(
			$commandNames,
			$this,
			'handleVetoDraftCommand',
			false,
			'Map draft/veto control surface for matchmaking vote and tournament pick-ban.'
		);
		$this->maniaControl->getCommandManager()->registerCommandListener(
			$commandNames,
			$this,
			'handleVetoDraftCommand',
			true,
			'Map draft/veto admin command alias (//) for operator workflows.'
		);

		$this->maniaControl->getCommunicationManager()->registerCommunicationListener(
			VetoDraftCatalog::COMMUNICATION_START,
			$this,
			'handleVetoDraftCommunicationStart'
		);
		$this->maniaControl->getCommunicationManager()->registerCommunicationListener(
			VetoDraftCatalog::COMMUNICATION_ACTION,
			$this,
			'handleVetoDraftCommunicationAction'
		);
		$this->maniaControl->getCommunicationManager()->registerCommunicationListener(
			VetoDraftCatalog::COMMUNICATION_STATUS,
			$this,
			'handleVetoDraftCommunicationStatus'
		);
		$this->maniaControl->getCommunicationManager()->registerCommunicationListener(
			VetoDraftCatalog::COMMUNICATION_CANCEL,
			$this,
			'handleVetoDraftCommunicationCancel'
		);
	}

	private function unregisterVetoDraftEntryPoints() {
		if (!$this->maniaControl) {
			return;
		}

		$this->maniaControl->getCommandManager()->unregisterCommandListener($this);
		$this->maniaControl->getCommunicationManager()->unregisterCommunicationListener($this);
	}

	public function handleVetoDraftCommand(array $chatCallback, Player $player) {
		if (!$this->vetoDraftEnabled || !$this->vetoDraftCoordinator) {
			$this->maniaControl->getChat()->sendError('Map draft/veto feature is disabled.', $player);
			return;
		}

		$request = $this->parseVetoDraftCommandRequest($chatCallback);
		$operation = isset($request['operation']) ? (string) $request['operation'] : '';
		$parameters = isset($request['parameters']) && is_array($request['parameters']) ? $request['parameters'] : array();
		$positionals = isset($parameters['_positionals']) && is_array($parameters['_positionals']) ? $parameters['_positionals'] : array();

		if ($operation === '' || $operation === 'help' || $operation === 'list') {
			$this->sendVetoDraftHelp($player);
			return;
		}

			switch ($operation) {
				case 'status':
					$this->sendVetoDraftStatusToPlayer($player);
			return;

			case 'config':
				if (!$this->hasVetoControlPermission($player)) {
					$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
					return;
				}

				$this->sendVetoDraftConfigToPlayer($player);
			return;

			case 'mode':
				if (!$this->hasVetoControlPermission($player)) {
					$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
					return;
				}

				$modeSelection = '';
				if (isset($parameters['mode'])) {
					$modeSelection = (string) $parameters['mode'];
				} else if (isset($positionals[0])) {
					$modeSelection = (string) $positionals[0];
				}

				if (trim($modeSelection) === '') {
					$this->maniaControl->getChat()->sendError('Usage: //' . $this->vetoDraftCommandName . ' mode <matchmaking|tournament>.', $player);
					return;
				}

				$modeUpdateResult = $this->updateVetoDraftDefaultMode($modeSelection);
				if (empty($modeUpdateResult['success'])) {
					$this->maniaControl->getChat()->sendError(isset($modeUpdateResult['message']) ? (string) $modeUpdateResult['message'] : 'Unable to update default mode.', $player);
					return;
				}

				$this->maniaControl->getChat()->sendSuccess(isset($modeUpdateResult['message']) ? (string) $modeUpdateResult['message'] : 'Default mode updated.', $player);
				$this->maniaControl->getChat()->sendInformation(
					'Draft defaults: mode=' . $this->vetoDraftDefaultMode
					. ', matchmaking_duration=' . $this->vetoDraftMatchmakingDurationSeconds . 's'
					. ', min_players=' . $this->vetoDraftMatchmakingAutostartMinPlayers
					. ', launch_immediately=' . ($this->vetoDraftLaunchImmediately ? 'yes' : 'no')
					. '.',
					$player
				);
			return;

			case 'duration':
				if (!$this->hasVetoControlPermission($player)) {
					$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
					return;
				}

				$durationValue = null;
				if (isset($parameters['duration'])) {
					$durationValue = $parameters['duration'];
				} else if (isset($parameters['duration_seconds'])) {
					$durationValue = $parameters['duration_seconds'];
				} else if (isset($positionals[0])) {
					$durationValue = $positionals[0];
				}

				if ($durationValue === null || trim((string) $durationValue) === '') {
					$this->maniaControl->getChat()->sendError('Usage: //' . $this->vetoDraftCommandName . ' duration <seconds>.', $player);
					return;
				}

				$durationUpdateResult = $this->updateVetoDraftMatchmakingDuration($durationValue);
				if (empty($durationUpdateResult['success'])) {
					$this->maniaControl->getChat()->sendError(isset($durationUpdateResult['message']) ? (string) $durationUpdateResult['message'] : 'Unable to update matchmaking duration.', $player);
					return;
				}

				$this->maniaControl->getChat()->sendSuccess(isset($durationUpdateResult['message']) ? (string) $durationUpdateResult['message'] : 'Matchmaking duration updated.', $player);
				$this->maniaControl->getChat()->sendInformation(
					'Draft defaults: mode=' . $this->vetoDraftDefaultMode
					. ', matchmaking_duration=' . $this->vetoDraftMatchmakingDurationSeconds . 's'
					. ', min_players=' . $this->vetoDraftMatchmakingAutostartMinPlayers
					. ', launch_immediately=' . ($this->vetoDraftLaunchImmediately ? 'yes' : 'no')
					. '.',
					$player
				);
			return;

			case 'min_players':
				if (!$this->hasVetoControlPermission($player)) {
					$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
					return;
				}

				$minPlayersValue = null;
				if (isset($parameters['min_players'])) {
					$minPlayersValue = $parameters['min_players'];
				} else if (isset($parameters['players'])) {
					$minPlayersValue = $parameters['players'];
				} else if (isset($positionals[0])) {
					$minPlayersValue = $positionals[0];
				}

				if ($minPlayersValue === null || trim((string) $minPlayersValue) === '') {
					$this->maniaControl->getChat()->sendError('Usage: //' . $this->vetoDraftCommandName . ' min_players <int>.', $player);
					return;
				}

				$minPlayersUpdateResult = $this->updateVetoDraftMatchmakingAutostartMinPlayers($minPlayersValue);
				if (empty($minPlayersUpdateResult['success'])) {
					$this->maniaControl->getChat()->sendError(isset($minPlayersUpdateResult['message']) ? (string) $minPlayersUpdateResult['message'] : 'Unable to update matchmaking auto-start threshold.', $player);
					return;
				}

				$this->maniaControl->getChat()->sendSuccess(isset($minPlayersUpdateResult['message']) ? (string) $minPlayersUpdateResult['message'] : 'Matchmaking auto-start threshold updated.', $player);
				$this->maniaControl->getChat()->sendInformation(
					'Draft defaults: mode=' . $this->vetoDraftDefaultMode
					. ', matchmaking_duration=' . $this->vetoDraftMatchmakingDurationSeconds . 's'
					. ', min_players=' . $this->vetoDraftMatchmakingAutostartMinPlayers
					. ', launch_immediately=' . ($this->vetoDraftLaunchImmediately ? 'yes' : 'no')
					. '.',
					$player
				);
			return;

			case 'maps':
				$this->sendCurrentMapPoolToPlayer($player);
			return;

			case 'start':
				if (!$this->hasVetoControlPermission($player)) {
					$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
					return;
				}

				$startResult = $this->executeVetoDraftStartRequest($player, $parameters, $positionals);
				if (!isset($startResult['success']) || !$startResult['success']) {
					$this->maniaControl->getChat()->sendError(isset($startResult['message']) ? (string) $startResult['message'] : 'Unable to start draft/veto session.', $player);
					return;
				}

				$this->maniaControl->getChat()->sendSuccess(isset($startResult['message']) ? (string) $startResult['message'] : 'Draft/veto session started.', $player);
				$this->vetoDraftLastAppliedSessionId = '';
				$this->resetMatchmakingLifecycleContext('session_started', 'chat_start', false);
				$this->broadcastVetoDraftSessionOverview();
				$this->syncVetoDraftTelemetryState();
			return;

			case 'cancel':
				if (!$this->hasVetoControlPermission($player)) {
					$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
					return;
				}

				$cancelResult = $this->vetoDraftCoordinator->cancelActiveSession(time(), 'cancelled_by_chat');
				if (empty($cancelResult['success'])) {
					$this->maniaControl->getChat()->sendError(isset($cancelResult['message']) ? (string) $cancelResult['message'] : 'Unable to cancel draft/veto session.', $player);
					return;
				}

				$this->maniaControl->getChat()->sendSuccess(isset($cancelResult['message']) ? (string) $cancelResult['message'] : 'Draft/veto session cancelled.', $player);
				$this->resetMatchmakingLifecycleContext('session_cancelled', 'chat_cancel', true);
				$this->syncVetoDraftTelemetryState();
			return;

			case 'vote':
				$selection = $this->extractSelectionFromRequest($parameters, $positionals, 0);
				if ($selection === '') {
					$this->maniaControl->getChat()->sendError('Usage: //' . $this->vetoDraftCommandName . ' vote <map_uid|index>.', $player);
					return;
				}

				$autoStartResult = $this->ensureConfiguredMatchmakingSessionForPlayerAction('chat_vote');
				if (empty($autoStartResult['success'])) {
					$this->maniaControl->getChat()->sendError(isset($autoStartResult['message']) ? (string) $autoStartResult['message'] : 'No active veto session.', $player);
					return;
				}

				$voteResult = $this->vetoDraftCoordinator->castMatchmakingVote(isset($player->login) ? (string) $player->login : '', $selection, time());
				if (empty($voteResult['success'])) {
					$this->maniaControl->getChat()->sendError(isset($voteResult['message']) ? (string) $voteResult['message'] : 'Vote failed.', $player);
					return;
				}

				$this->maniaControl->getChat()->sendSuccess(isset($voteResult['message']) ? (string) $voteResult['message'] : 'Vote recorded.', $player);
				$this->syncVetoDraftTelemetryState();
			return;

			case 'action':
			case 'ban':
			case 'pick':
				$selection = $this->extractSelectionFromRequest($parameters, $positionals, 0);
				if ($selection === '') {
					$this->maniaControl->getChat()->sendError('Usage: //' . $this->vetoDraftCommandName . ' action <map_uid|index>.', $player);
					return;
				}

				$allowOverride = $this->resolveVetoOverrideFlag($parameters, $player);
				$actionResult = $this->vetoDraftCoordinator->applyTournamentAction(
					isset($player->login) ? (string) $player->login : '',
					$selection,
					time(),
					'chat_command',
					$allowOverride
				);
				if (empty($actionResult['success'])) {
					$this->maniaControl->getChat()->sendError(isset($actionResult['message']) ? (string) $actionResult['message'] : 'Action failed.', $player);
					return;
				}

				$this->maniaControl->getChat()->sendSuccess(isset($actionResult['message']) ? (string) $actionResult['message'] : 'Action applied.', $player);
				$this->broadcastVetoDraftSessionOverview();
				$this->handleDraftCompletionIfNeeded('chat_action');
				$this->syncVetoDraftTelemetryState();
			return;

			default:
				$this->maniaControl->getChat()->sendError('Unknown veto command. Use //' . $this->vetoDraftCommandName . ' help.', $player);
			return;
		}
	}

	public function handleVetoDraftCommunicationStart($data) {
		if (!$this->vetoDraftEnabled || !$this->vetoDraftCoordinator) {
			return new CommunicationAnswer(array('success' => false, 'code' => 'feature_disabled', 'message' => 'Map draft/veto feature is disabled.'), true);
		}

		$payload = $this->normalizeCommunicationPayload($data);
		$mode = isset($payload['mode']) ? (string) $payload['mode'] : $this->vetoDraftDefaultMode;
		$mode = VetoDraftCatalog::normalizeMode($mode, $this->vetoDraftDefaultMode);

		$result = null;
		$mapPool = $this->vetoDraftMapPoolService->buildMapPool($this->maniaControl->getMapManager());
		if ($mode === VetoDraftCatalog::MODE_TOURNAMENT_DRAFT) {
			$captainA = isset($payload['captain_a']) ? (string) $payload['captain_a'] : '';
			$captainB = isset($payload['captain_b']) ? (string) $payload['captain_b'] : '';
			$bestOf = isset($payload['best_of']) ? $payload['best_of'] : $this->resolveSeriesControlBestOfDefault();
			$starter = isset($payload['starter']) ? (string) $payload['starter'] : VetoDraftCatalog::STARTER_RANDOM;
			$timeout = isset($payload['action_timeout_seconds']) ? $payload['action_timeout_seconds'] : $this->vetoDraftTournamentActionTimeoutSeconds;

			$result = $this->vetoDraftCoordinator->startTournament($mapPool, $captainA, $captainB, $bestOf, $starter, $timeout, time());
		} else {
			$durationSeconds = isset($payload['duration_seconds']) ? $payload['duration_seconds'] : $this->vetoDraftMatchmakingDurationSeconds;
			$result = $this->vetoDraftCoordinator->startMatchmaking($mapPool, $durationSeconds, time());
		}

		$this->syncVetoDraftTelemetryState();

		if (isset($result['success']) && $result['success']) {
			$this->vetoDraftLastAppliedSessionId = '';
			$this->resetMatchmakingLifecycleContext('session_started', 'communication_start', false);
			$this->broadcastVetoDraftSessionOverview();
		}

		return new CommunicationAnswer($result, empty($result['success']));
	}

	public function handleVetoDraftCommunicationAction($data) {
		if (!$this->vetoDraftEnabled || !$this->vetoDraftCoordinator) {
			return new CommunicationAnswer(array('success' => false, 'code' => 'feature_disabled', 'message' => 'Map draft/veto feature is disabled.'), true);
		}

		$payload = $this->normalizeCommunicationPayload($data);
		$actorLogin = isset($payload['actor_login']) ? (string) $payload['actor_login'] : '';
		$selection = isset($payload['map']) ? (string) $payload['map'] : '';
		if ($selection === '' && isset($payload['selection'])) {
			$selection = (string) $payload['selection'];
		}

		$operation = isset($payload['operation']) ? strtolower(trim((string) $payload['operation'])) : '';
		$statusSnapshot = $this->vetoDraftCoordinator->getStatusSnapshot();
		$activeSession = !empty($statusSnapshot['active']);
		$activeMode = isset($statusSnapshot['mode']) ? (string) $statusSnapshot['mode'] : '';
		$isMatchmakingAction = ($operation === 'vote') || ($activeSession && $activeMode === VetoDraftCatalog::MODE_MATCHMAKING_VOTE);

		if ($isMatchmakingAction) {
			$autoStartResult = $this->ensureConfiguredMatchmakingSessionForPlayerAction('communication_vote');
			if (empty($autoStartResult['success'])) {
				return new CommunicationAnswer($autoStartResult, true);
			}

			$result = $this->vetoDraftCoordinator->castMatchmakingVote($actorLogin, $selection, time());
		} else {
			$allowOverride = !empty($payload['allow_override']) || !empty($payload['force']);
			$result = $this->vetoDraftCoordinator->applyTournamentAction($actorLogin, $selection, time(), 'communication', $allowOverride);
		}

		$this->handleDraftCompletionIfNeeded('communication_action');
		$this->syncVetoDraftTelemetryState();

		return new CommunicationAnswer($result, empty($result['success']));
	}

	public function handleVetoDraftCommunicationStatus($data) {
		if (!$this->vetoDraftEnabled || !$this->vetoDraftCoordinator) {
			return new CommunicationAnswer(array('enabled' => false, 'status' => array('active' => false, 'mode' => '', 'session' => array('status' => 'disabled'))), false);
		}

		return new CommunicationAnswer(array(
			'enabled' => true,
			'command' => $this->vetoDraftCommandName,
			'default_mode' => $this->vetoDraftDefaultMode,
			'matchmaking_duration_seconds' => $this->vetoDraftMatchmakingDurationSeconds,
			'matchmaking_autostart_min_players' => $this->vetoDraftMatchmakingAutostartMinPlayers,
			'launch_immediately' => $this->vetoDraftLaunchImmediately,
			'series_targets' => $this->getSeriesControlSnapshot(),
			'matchmaking_lifecycle' => $this->buildMatchmakingLifecycleStatusSnapshot(),
			'communication' => array(
				'start' => VetoDraftCatalog::COMMUNICATION_START,
				'action' => VetoDraftCatalog::COMMUNICATION_ACTION,
				'status' => VetoDraftCatalog::COMMUNICATION_STATUS,
				'cancel' => VetoDraftCatalog::COMMUNICATION_CANCEL,
			),
			'status' => $this->vetoDraftCoordinator->getStatusSnapshot(),
		), false);
	}

	public function handleVetoDraftCommunicationCancel($data) {
		if (!$this->vetoDraftEnabled || !$this->vetoDraftCoordinator) {
			return new CommunicationAnswer(array('success' => false, 'code' => 'feature_disabled', 'message' => 'Map draft/veto feature is disabled.'), true);
		}

		$payload = $this->normalizeCommunicationPayload($data);
		$reason = isset($payload['reason']) ? (string) $payload['reason'] : 'cancelled_by_communication';
		$result = $this->vetoDraftCoordinator->cancelActiveSession(time(), $reason);
		if (!empty($result['success'])) {
			$this->resetMatchmakingLifecycleContext('session_cancelled', 'communication_cancel', true);
		}
		$this->syncVetoDraftTelemetryState();

		return new CommunicationAnswer($result, empty($result['success']));
	}

	public function handleVetoDraftTimerTick() {
		if (!$this->vetoDraftEnabled || !$this->vetoDraftCoordinator) {
			return;
		}

		$timestamp = time();
		$this->evaluateMatchmakingAutostartThreshold($timestamp);

		$tickResult = $this->vetoDraftCoordinator->tick($timestamp);
		$events = isset($tickResult['events']) && is_array($tickResult['events']) ? $tickResult['events'] : array();
		foreach ($events as $event) {
			$eventType = isset($event['type']) ? (string) $event['type'] : '';
			if ($eventType === 'matchmaking_countdown') {
				$this->broadcastMatchmakingCountdownAnnouncement($event);
			}

			if ($eventType === 'matchmaking_completed') {
				$this->handleDraftCompletionIfNeeded('timer_matchmaking_complete', isset($event['snapshot']) && is_array($event['snapshot']) ? $event['snapshot'] : array());
			}

			if ($eventType === 'tournament_timeout_auto_action') {
				$this->broadcastVetoDraftSessionOverview();
				$this->handleDraftCompletionIfNeeded('timer_tournament_timeout', isset($event['snapshot']) && is_array($event['snapshot']) ? $event['snapshot'] : array());
			}
		}

		$this->evaluateMatchmakingLifecycleRuntimeFallback($timestamp);

		$this->syncVetoDraftTelemetryState();
	}

	private function executeVetoDraftStartRequest(Player $player, array $parameters, array $positionals) {
		$modeSelection = isset($parameters['mode']) ? (string) $parameters['mode'] : '';
		if ($modeSelection === '' && isset($positionals[0])) {
			$modeSelection = (string) $positionals[0];
		}
		$modeSelection = VetoDraftCatalog::normalizeMode($modeSelection, $this->vetoDraftDefaultMode);

		$mapPool = $this->vetoDraftMapPoolService->buildMapPool($this->maniaControl->getMapManager());
		if ($modeSelection === VetoDraftCatalog::MODE_TOURNAMENT_DRAFT) {
			$captainA = isset($parameters['captain_a']) ? (string) $parameters['captain_a'] : (isset($positionals[1]) ? (string) $positionals[1] : '');
			$captainB = isset($parameters['captain_b']) ? (string) $parameters['captain_b'] : (isset($positionals[2]) ? (string) $positionals[2] : '');
			$bestOf = isset($parameters['bo']) ? $parameters['bo'] : (isset($parameters['best_of']) ? $parameters['best_of'] : (isset($positionals[3]) ? $positionals[3] : $this->resolveSeriesControlBestOfDefault()));
			$starter = isset($parameters['starter']) ? (string) $parameters['starter'] : VetoDraftCatalog::STARTER_RANDOM;
			$timeout = isset($parameters['timeout']) ? $parameters['timeout'] : (isset($parameters['action_timeout_seconds']) ? $parameters['action_timeout_seconds'] : $this->vetoDraftTournamentActionTimeoutSeconds);

			return $this->vetoDraftCoordinator->startTournament($mapPool, $captainA, $captainB, $bestOf, $starter, $timeout, time());
		}

		$durationSeconds = isset($parameters['duration']) ? $parameters['duration'] : (isset($parameters['duration_seconds']) ? $parameters['duration_seconds'] : (isset($positionals[1]) ? $positionals[1] : $this->vetoDraftMatchmakingDurationSeconds));
		return $this->vetoDraftCoordinator->startMatchmaking($mapPool, $durationSeconds, time());
	}

	private function parseVetoDraftCommandRequest(array $chatCallback) {
		$commandText = '';
		if (isset($chatCallback[1]) && is_array($chatCallback[1]) && isset($chatCallback[1][2])) {
			$commandText = trim((string) $chatCallback[1][2]);
		}

		if ($commandText === '') {
			return array('operation' => '', 'parameters' => array());
		}

		$tokens = preg_split('/\s+/', $commandText);
		if (empty($tokens)) {
			return array('operation' => '', 'parameters' => array());
		}

		array_shift($tokens);
		if (empty($tokens)) {
			return array('operation' => '', 'parameters' => array());
		}

		$operation = strtolower(trim((string) array_shift($tokens)));
		$parameters = $this->parseArgumentTokens($tokens);

		return array(
			'operation' => $operation,
			'parameters' => $parameters,
		);
	}

	private function sendVetoDraftHelp(Player $player) {
		$helpContext = $this->buildVetoDraftHelpContext($player);
		$helpLines = array_merge(
			$this->buildVetoDraftHelpCommonLines($helpContext),
			$this->buildVetoDraftHelpModeLines($helpContext),
			$this->buildVetoDraftHelpAdminLines($helpContext)
		);

		foreach ($helpLines as $helpLine) {
			$this->maniaControl->getChat()->sendInformation($helpLine, $player);
		}
	}

	private function buildVetoDraftHelpContext(Player $player) {
		$isControlAdmin = $this->hasVetoControlPermission($player);
		$hasOverride = $this->maniaControl->getAuthenticationManager()->checkPluginPermission($this, $player, VetoDraftCatalog::RIGHT_OVERRIDE);
		$effectiveModeContext = $this->resolveVetoDraftEffectiveModeContext();

		return array(
			'is_control_admin' => $isControlAdmin,
			'has_override' => $hasOverride,
			'effective_mode' => isset($effectiveModeContext['effective_mode']) ? (string) $effectiveModeContext['effective_mode'] : VetoDraftCatalog::MODE_MATCHMAKING_VOTE,
			'mode_source' => isset($effectiveModeContext['mode_source']) ? (string) $effectiveModeContext['mode_source'] : 'configured_default',
			'configured_default_mode' => isset($effectiveModeContext['configured_default_mode']) ? (string) $effectiveModeContext['configured_default_mode'] : VetoDraftCatalog::MODE_MATCHMAKING_VOTE,
			'active_session' => !empty($effectiveModeContext['active_session']),
			'fallback_applied' => !empty($effectiveModeContext['fallback_applied']),
			'fallback_from_mode' => isset($effectiveModeContext['fallback_from_mode']) ? (string) $effectiveModeContext['fallback_from_mode'] : '',
		);
	}

	private function resolveVetoDraftEffectiveModeContext() {
		$configuredDefaultMode = VetoDraftCatalog::normalizeMode($this->vetoDraftDefaultMode, VetoDraftCatalog::MODE_MATCHMAKING_VOTE);
		if ($configuredDefaultMode === '') {
			$configuredDefaultMode = VetoDraftCatalog::MODE_MATCHMAKING_VOTE;
		}

		$activeSession = false;
		$activeSessionMode = '';
		if ($this->vetoDraftCoordinator) {
			$statusSnapshot = $this->vetoDraftCoordinator->getStatusSnapshot();
			$activeSession = !empty($statusSnapshot['active']);
			if ($activeSession && isset($statusSnapshot['mode'])) {
				$activeSessionMode = trim((string) $statusSnapshot['mode']);
			}
		}

		$candidateMode = $activeSession ? $activeSessionMode : $configuredDefaultMode;
		$effectiveMode = VetoDraftCatalog::normalizeMode($candidateMode, '');
		$modeSource = $activeSession ? 'active_session' : 'configured_default';
		$fallbackApplied = false;
		$fallbackFromMode = '';

		if ($effectiveMode === '') {
			$fallbackApplied = true;
			$fallbackFromMode = $candidateMode;
			$effectiveMode = VetoDraftCatalog::normalizeMode($configuredDefaultMode, VetoDraftCatalog::MODE_MATCHMAKING_VOTE);
			$modeSource = 'configured_default_fallback';
		}

		if ($effectiveMode === '') {
			$fallbackApplied = true;
			if ($fallbackFromMode === '') {
				$fallbackFromMode = $candidateMode;
			}
			$effectiveMode = VetoDraftCatalog::MODE_MATCHMAKING_VOTE;
			$modeSource = 'safety_default';
		}

		return array(
			'effective_mode' => $effectiveMode,
			'configured_default_mode' => $configuredDefaultMode,
			'active_session' => $activeSession,
			'mode_source' => $modeSource,
			'fallback_applied' => $fallbackApplied,
			'fallback_from_mode' => $fallbackFromMode,
		);
	}

	private function buildVetoDraftHelpCommonLines(array $helpContext) {
		$effectiveMode = isset($helpContext['effective_mode']) ? (string) $helpContext['effective_mode'] : VetoDraftCatalog::MODE_MATCHMAKING_VOTE;
		$effectiveModeLabel = $this->formatVetoDraftModeLabel($effectiveMode);
		$isControlAdmin = !empty($helpContext['is_control_admin']);
		$commonCommands = '/' . $this->vetoDraftCommandName . ' help | status | maps';
		if ($isControlAdmin) {
			$commonCommands .= ' | config';
		}
		$commonCommands .= '.';

		return array(
			'Map Draft/Veto command: /' . $this->vetoDraftCommandName . ' ...',
			'Effective mode: ' . $effectiveModeLabel . '.',
			'Common commands: ' . $commonCommands,
		);
	}

	private function formatVetoDraftModeLabel($mode) {
		$normalizedMode = VetoDraftCatalog::normalizeMode($mode, '');
		if ($normalizedMode === VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
			return 'MatchMaking';
		}

		if ($normalizedMode === VetoDraftCatalog::MODE_TOURNAMENT_DRAFT) {
			return 'Tournament';
		}

		$fallback = trim((string) $mode);
		if ($fallback === '') {
			return 'MatchMaking';
		}

		return ucwords(str_replace('_', ' ', strtolower($fallback)));
	}

	private function buildVetoDraftHelpModeLines(array $helpContext) {
		$effectiveMode = isset($helpContext['effective_mode']) ? (string) $helpContext['effective_mode'] : VetoDraftCatalog::MODE_MATCHMAKING_VOTE;

		if ($effectiveMode === VetoDraftCatalog::MODE_TOURNAMENT_DRAFT) {
			return array(
				'Tournament flow: /' . $this->vetoDraftCommandName . ' action <map_uid|index>.',
			);
		}

		return array(
			'Matchmaking flow: /' . $this->vetoDraftCommandName . ' vote <map_uid|index>.',
		);
	}

	private function buildVetoDraftHelpAdminLines(array $helpContext) {
		if (empty($helpContext['is_control_admin'])) {
			return array();
		}

		$effectiveMode = isset($helpContext['effective_mode']) ? (string) $helpContext['effective_mode'] : VetoDraftCatalog::MODE_MATCHMAKING_VOTE;
		$adminHelpLines = array();

		if ($effectiveMode === VetoDraftCatalog::MODE_TOURNAMENT_DRAFT) {
			$adminHelpLines[] = 'Admin tournament: //' . $this->vetoDraftCommandName . ' start tournament captain_a=<login> captain_b=<login> [bo=3] [starter=random] [timeout=45] [launch=1].';
			$adminHelpLines[] = 'Admin defaults: //' . $this->vetoDraftCommandName . ' mode <matchmaking|tournament> | min_players <int>.';
		} else {
			$adminHelpLines[] = 'Admin matchmaking: //' . $this->vetoDraftCommandName . ' start matchmaking [duration=60] [launch=1].';
			$adminHelpLines[] = 'Admin defaults: //' . $this->vetoDraftCommandName . ' mode <matchmaking|tournament> | duration <seconds> | min_players <int>.';
		}

		$adminHelpLines[] = 'Admin series policy: //pcadmin match.bo.get | //pcadmin match.bo.set best_of=<odd>.';

		$adminHelpLines[] = 'Admin session: //' . $this->vetoDraftCommandName . ' cancel.';

		if (!empty($helpContext['has_override'])) {
			$adminHelpLines[] = 'Admin override: //' . $this->vetoDraftCommandName . ' action <map_uid|index> force=1.';
		}

		return $adminHelpLines;
	}

	private function sendCurrentMapPoolToPlayer(Player $player) {
		$mapPool = $this->vetoDraftMapPoolService->buildMapPool($this->maniaControl->getMapManager());
		if (empty($mapPool)) {
			$this->maniaControl->getChat()->sendInformation('No maps found in server map pool.', $player);
			return;
		}

		$includeMapUid = $this->hasVetoControlPermission($player);
		$this->maniaControl->getChat()->sendInformation('Current map pool (' . count($mapPool) . ' maps):', $player);
		foreach ($this->vetoDraftMapPoolService->buildMapListRows($mapPool, $includeMapUid) as $row) {
			$this->maniaControl->getChat()->sendInformation($row, $player);
		}
	}

	private function sendVetoDraftStatusToPlayer(Player $player) {
		$statusSnapshot = $this->vetoDraftCoordinator ? $this->vetoDraftCoordinator->getStatusSnapshot() : array('active' => false, 'mode' => '', 'session' => array('status' => 'idle'));
		if (!$this->hasVetoControlPermission($player)) {
			$publicStatusLines = $this->buildPublicVetoResultStatusLines($statusSnapshot);
			foreach ($publicStatusLines as $publicStatusLine) {
				$this->maniaControl->getChat()->sendInformation($publicStatusLine, $player);
			}

			return;
		}

		$active = !empty($statusSnapshot['active']);
		$mode = isset($statusSnapshot['mode']) ? (string) $statusSnapshot['mode'] : '';
		$session = isset($statusSnapshot['session']) && is_array($statusSnapshot['session']) ? $statusSnapshot['session'] : array();
		$sessionStatus = isset($session['status']) ? (string) $session['status'] : 'idle';

		$this->maniaControl->getChat()->sendInformation(
			'Map draft/veto status: active=' . ($active ? 'yes' : 'no')
			. ', mode=' . ($mode !== '' ? $mode : 'none')
			. ', state=' . $sessionStatus
			. ', min_players=' . $this->vetoDraftMatchmakingAutostartMinPlayers
			. '.',
			$player
		);

		$matchmakingLifecycle = $this->buildMatchmakingLifecycleStatusSnapshot();
		$this->maniaControl->getChat()->sendInformation(
			'Matchmaking lifecycle: status=' . (isset($matchmakingLifecycle['status']) ? (string) $matchmakingLifecycle['status'] : 'idle')
			. ', stage=' . (isset($matchmakingLifecycle['stage']) ? (string) $matchmakingLifecycle['stage'] : MatchmakingLifecycleCatalog::STAGE_IDLE)
			. ', ready=' . (!empty($matchmakingLifecycle['ready_for_next_players']) ? 'yes' : 'no')
			. '.',
			$player
		);

		if (!$active && $this->vetoDraftDefaultMode === VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
			$connectedHumanPlayers = $this->countConnectedHumanPlayersForVetoAutoStart();
			$this->maniaControl->getChat()->sendInformation(
				'No active session: threshold auto-start waits for '
				. $this->vetoDraftMatchmakingAutostartMinPlayers
				. ' connected player(s) (currently '
				. $connectedHumanPlayers
				. '). You can also use /'
				. $this->vetoDraftCommandName
				. ' vote <index|uid> to start immediately.',
				$player
			);
		}

		if ($mode === VetoDraftCatalog::MODE_MATCHMAKING_VOTE && isset($session['vote_totals']) && is_array($session['vote_totals'])) {
			foreach ($session['vote_totals'] as $voteTotal) {
				$mapName = isset($voteTotal['map_name']) ? (string) $voteTotal['map_name'] : 'Unknown';
				$voteCount = isset($voteTotal['vote_count']) ? (int) $voteTotal['vote_count'] : 0;
				$this->maniaControl->getChat()->sendInformation('- ' . $mapName . ': ' . $voteCount . ' vote(s)', $player);
			}
		}

		if ($mode === VetoDraftCatalog::MODE_TOURNAMENT_DRAFT && isset($session['current_step']) && is_array($session['current_step'])) {
			$currentStep = $session['current_step'];
			$this->maniaControl->getChat()->sendInformation(
				'Current turn: action=' . (isset($currentStep['action_kind']) ? (string) $currentStep['action_kind'] : 'unknown')
				. ', team=' . (isset($currentStep['team']) ? (string) $currentStep['team'] : 'unknown')
				. ', step=' . (isset($currentStep['order_index']) ? (int) $currentStep['order_index'] : 0)
				. '.',
				$player
			);
		}

		$this->maniaControl->getChat()->sendInformation($this->buildSeriesControlSnapshotSummaryLine(), $player);
	}

	private function sendVetoDraftConfigToPlayer(Player $player) {
		$this->maniaControl->getChat()->sendInformation(
			'Draft defaults: mode=' . $this->vetoDraftDefaultMode
			. ', matchmaking_duration=' . $this->vetoDraftMatchmakingDurationSeconds . 's'
			. ', min_players=' . $this->vetoDraftMatchmakingAutostartMinPlayers
			. ', launch_immediately=' . ($this->vetoDraftLaunchImmediately ? 'yes' : 'no')
			. '.',
			$player
		);
		$this->maniaControl->getChat()->sendInformation($this->buildSeriesControlSnapshotSummaryLine(), $player);

		$activeVetoSession = ($this->vetoDraftCoordinator ? $this->vetoDraftCoordinator->hasActiveSession() : false);
		$this->maniaControl->getChat()->sendInformation(
			'Active session policy: ' . ($activeVetoSession ? 'updates apply to next draft start' : 'updates apply immediately for next draft start') . '.',
			$player
		);
	}

	private function broadcastVetoDraftSessionOverview() {
		if (!$this->maniaControl || !$this->vetoDraftCoordinator) {
			return;
		}

		$statusSnapshot = $this->vetoDraftCoordinator->getStatusSnapshot();
		$mode = isset($statusSnapshot['mode']) ? (string) $statusSnapshot['mode'] : '';
		$session = isset($statusSnapshot['session']) && is_array($statusSnapshot['session']) ? $statusSnapshot['session'] : array();
		$sessionStatus = isset($session['status']) ? (string) $session['status'] : 'idle';

		if ($mode === '') {
			return;
		}

		$this->broadcastVetoDraftAudienceLine(
			'[PixelControl] Draft/Veto mode=' . $mode . ', status=' . $sessionStatus . '.',
			'all'
		);

		if ($mode === VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
			if ($sessionStatus === VetoDraftCatalog::STATUS_RUNNING) {
				$startedAt = isset($session['started_at']) ? (int) $session['started_at'] : 0;
				$endsAt = isset($session['ends_at']) ? (int) $session['ends_at'] : 0;
				$durationSeconds = max(0, $endsAt - $startedAt);
				$this->broadcastVetoDraftAudienceLine(
					'[PixelControl] Matchmaking veto launched (duration=' . $durationSeconds . 's).',
					'all'
				);
			}

			$mapPool = isset($session['map_pool']) && is_array($session['map_pool']) ? $session['map_pool'] : array();
			if (!empty($mapPool)) {
				$this->broadcastVetoDraftAudienceLine('[PixelControl] Vote on a map with //' . $this->vetoDraftCommandName . ' vote <index>.', 'players');
				$this->broadcastVetoDraftAudienceLine('[PixelControl] Vote on a map with //' . $this->vetoDraftCommandName . ' vote <index|uid>.', 'admins');
				$this->broadcastVetoDraftRoleAwareMapRows('[PixelControl] Available maps:', '[PixelControl] Map vote IDs:', $mapPool);
			}
		}

		if ($mode === VetoDraftCatalog::MODE_TOURNAMENT_DRAFT) {
			if (isset($session['captains']) && is_array($session['captains'])) {
				$this->broadcastVetoDraftAudienceLine(
					'[PixelControl] Captains: team_a=' . (isset($session['captains'][VetoDraftCatalog::TEAM_A]) ? (string) $session['captains'][VetoDraftCatalog::TEAM_A] : 'n/a')
					. ', team_b=' . (isset($session['captains'][VetoDraftCatalog::TEAM_B]) ? (string) $session['captains'][VetoDraftCatalog::TEAM_B] : 'n/a')
					. '.',
					'all'
				);
			}

			if (isset($session['current_step']) && is_array($session['current_step'])) {
				$currentStep = $session['current_step'];
				$turnPrefix = '[PixelControl] Turn #' . (isset($currentStep['order_index']) ? (int) $currentStep['order_index'] : 0)
					. ': ' . (isset($currentStep['team']) ? (string) $currentStep['team'] : 'unknown')
					. ' -> ' . (isset($currentStep['action_kind']) ? (string) $currentStep['action_kind'] : 'unknown');

				$this->broadcastVetoDraftAudienceLine(
					$turnPrefix . ' (use //' . $this->vetoDraftCommandName . ' action <index>).',
					'players'
				);
				$this->broadcastVetoDraftAudienceLine(
					$turnPrefix . ' (use //' . $this->vetoDraftCommandName . ' action <index|uid>).',
					'admins'
				);
			}

			$availableMaps = isset($session['available_maps']) && is_array($session['available_maps']) ? $session['available_maps'] : array();
			if (!empty($availableMaps)) {
				$this->broadcastVetoDraftRoleAwareMapRows('[PixelControl] Available veto maps:', '[PixelControl] Available veto IDs:', $availableMaps);
			}
		}
	}

	private function handleDraftCompletionIfNeeded($source, array $sessionSnapshot = array()) {
		if (!$this->vetoDraftCoordinator || !$this->vetoDraftQueueApplier || !$this->maniaControl) {
			return;
		}

		if (empty($sessionSnapshot)) {
			$statusSnapshot = $this->vetoDraftCoordinator->getStatusSnapshot();
			$sessionSnapshot = isset($statusSnapshot['session']) && is_array($statusSnapshot['session'])
				? $statusSnapshot['session']
				: array();
		}

		$sessionStatus = isset($sessionSnapshot['status']) ? (string) $sessionSnapshot['status'] : '';
		if ($sessionStatus !== VetoDraftCatalog::STATUS_COMPLETED) {
			return;
		}

		$sessionMode = isset($sessionSnapshot['mode']) ? (string) $sessionSnapshot['mode'] : '';

		$sessionId = isset($sessionSnapshot['session_id']) ? trim((string) $sessionSnapshot['session_id']) : '';
		if ($sessionId !== '' && $sessionId === $this->vetoDraftLastAppliedSessionId) {
			return;
		}

		$mapOrder = $this->extractMapOrderFromSession($sessionSnapshot);
		if (empty($mapOrder)) {
			return;
		}

		$runtimeAdapter = new ManiaControlMapRuntimeAdapter($this->maniaControl->getMapManager());
		$result = $this->vetoDraftQueueApplier->applySeriesMapOrder($runtimeAdapter, $mapOrder, $this->vetoDraftLaunchImmediately);
		if (!isset($result['success']) || !$result['success']) {
			$resultDetails = (isset($result['details']) && is_array($result['details'])) ? $result['details'] : array();
			Logger::logWarning(
				'[PixelControl][veto][apply_failed] source=' . $source
				. ', code=' . (isset($result['code']) ? (string) $result['code'] : 'unknown')
				. ', branch=' . (isset($resultDetails['apply_branch']) ? (string) $resultDetails['apply_branch'] : 'unknown')
				. ', opener=' . (isset($resultDetails['opener_map_uid']) ? (string) $resultDetails['opener_map_uid'] : 'unknown')
				. ', current=' . (isset($resultDetails['current_map_uid']) ? (string) $resultDetails['current_map_uid'] : 'unknown')
				. ', message=' . (isset($result['message']) ? (string) $result['message'] : 'Map order apply failed')
				. '.'
			);
			$this->maniaControl->getChat()->sendError('[PixelControl] Draft/Veto completed but map queue apply failed.', null);
			return;
		}

		$resultDetails = (isset($result['details']) && is_array($result['details'])) ? $result['details'] : array();
		$applyBranch = isset($resultDetails['apply_branch']) ? (string) $resultDetails['apply_branch'] : 'unknown';
		$openerMapUid = isset($resultDetails['opener_map_uid']) ? (string) $resultDetails['opener_map_uid'] : '';
		$currentMapUid = isset($resultDetails['current_map_uid']) ? (string) $resultDetails['current_map_uid'] : '';
		$skipResult = isset($resultDetails['skip']) && is_array($resultDetails['skip']) ? $resultDetails['skip'] : array();

		$this->maniaControl->getChat()->sendSuccess('[PixelControl] Draft/Veto completed and map order applied.', null);
		$displayOrder = implode(' -> ', $mapOrder);
		$this->broadcastVetoDraftAudienceLine('[PixelControl] Series order: ' . $displayOrder . '.', 'admins');

		if ($applyBranch === 'opener_differs') {
			$this->broadcastVetoDraftAudienceLine(
				'[PixelControl] Completion branch: opener differs from current map; queued full order and skipped to opener [' . $openerMapUid . '].',
				'admins'
			);
		} else if ($applyBranch === 'opener_already_current') {
			$this->broadcastVetoDraftAudienceLine(
				'[PixelControl] Completion branch: opener already current [' . $openerMapUid . ']; queued remaining maps only (no skip).',
				'admins'
			);
		} else {
			$this->broadcastVetoDraftAudienceLine(
				'[PixelControl] Completion branch: ' . $applyBranch . ' (opener=' . ($openerMapUid !== '' ? $openerMapUid : 'unknown') . ', current=' . ($currentMapUid !== '' ? $currentMapUid : 'unknown') . ').',
				'admins'
			);
		}

		if (!empty($skipResult)) {
			$this->broadcastVetoDraftAudienceLine(
				'[PixelControl] Opener jump: code=' . (isset($skipResult['code']) ? (string) $skipResult['code'] : 'unknown')
				. ', applied=' . (!empty($skipResult['applied']) ? 'yes' : 'no')
				. '.',
				'admins'
			);
		}

		$queuedMapOrder = (isset($resultDetails['queued_map_uids']) && is_array($resultDetails['queued_map_uids']))
			? $resultDetails['queued_map_uids']
			: $mapOrder;
		$queuedMapRowsForPlayers = $this->buildQueuedMapRows($queuedMapOrder, $sessionSnapshot, false);
		$queuedMapRowsForAdmins = $this->buildQueuedMapRows($queuedMapOrder, $sessionSnapshot, true);

		if (!empty($queuedMapRowsForPlayers)) {
			$this->broadcastVetoDraftAudienceLine('[PixelControl] Queued maps:', 'players');
			foreach ($queuedMapRowsForPlayers as $queuedMapRow) {
				$this->broadcastVetoDraftAudienceLine('[PixelControl] ' . $queuedMapRow, 'players');
			}
		}

		if (!empty($queuedMapRowsForAdmins)) {
			$this->broadcastVetoDraftAudienceLine('[PixelControl] Queued maps:', 'admins');
			foreach ($queuedMapRowsForAdmins as $queuedMapRow) {
				$this->broadcastVetoDraftAudienceLine('[PixelControl] ' . $queuedMapRow, 'admins');
			}
		}

		if (empty($queuedMapRowsForPlayers) && empty($queuedMapRowsForAdmins)) {
			$this->broadcastVetoDraftAudienceLine('[PixelControl] Queued maps: none.', 'all');
		}

		Logger::log(
			'[PixelControl][veto][apply_success] source=' . $source
			. ', branch=' . $applyBranch
			. ', opener=' . ($openerMapUid !== '' ? $openerMapUid : 'unknown')
			. ', current=' . ($currentMapUid !== '' ? $currentMapUid : 'unknown')
			. ', skip_code=' . (isset($skipResult['code']) ? (string) $skipResult['code'] : 'unknown')
			. ', queued_count=' . (isset($resultDetails['queued_count']) ? (int) $resultDetails['queued_count'] : 0)
			. '.'
		);

		$this->vetoDraftLastAppliedSessionId = $sessionId;
		if ($sessionMode === VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
			$this->armMatchmakingLifecycleContext($sessionSnapshot, $resultDetails, $source);
		}
	}

	private function syncVetoDraftTelemetryState() {
		if (!$this->vetoDraftCoordinator) {
			$this->vetoDraftCompatibilitySnapshot = null;
			return;
		}

		$this->vetoDraftCompatibilitySnapshot = $this->vetoDraftCoordinator->buildCompatibilitySnapshots($this->vetoDraftEnabled);
		if (
			is_array($this->vetoDraftCompatibilitySnapshot)
			&& isset($this->vetoDraftCompatibilitySnapshot['actions'])
			&& is_array($this->vetoDraftCompatibilitySnapshot['actions'])
			&& isset($this->vetoDraftCompatibilitySnapshot['actions']['actions'])
			&& is_array($this->vetoDraftCompatibilitySnapshot['actions']['actions'])
		) {
			$this->vetoDraftActions = $this->vetoDraftCompatibilitySnapshot['actions']['actions'];
			$this->vetoDraftActionSequence = count($this->vetoDraftActions);
		}
	}

	private function resolveAuthoritativeVetoDraftSnapshots() {
		if (!$this->vetoDraftCoordinator || !$this->vetoDraftEnabled) {
			return null;
		}

		if (!is_array($this->vetoDraftCompatibilitySnapshot)) {
			$this->syncVetoDraftTelemetryState();
		}

		return $this->vetoDraftCompatibilitySnapshot;
	}

	private function resetMatchmakingLifecycleContextState() {
		$this->vetoDraftMatchmakingLifecycleContext = null;
		$this->vetoDraftMatchmakingLifecycleLastSnapshot = null;
	}

	public function handleMatchmakingLifecycleFromCallback(array $callbackArguments) {
		if (!$this->vetoDraftEnabled || !$this->maniaControl) {
			return;
		}

		if (!is_array($this->vetoDraftMatchmakingLifecycleContext) || empty($this->vetoDraftMatchmakingLifecycleContext['active'])) {
			return;
		}

		$sourceCallback = $this->extractSourceCallback($callbackArguments);
		$lifecycleVariant = $this->resolveLifecycleVariant($sourceCallback, $callbackArguments);

		if ($lifecycleVariant === 'map.begin') {
			$this->handleMatchmakingLifecycleMapBegin($sourceCallback, $callbackArguments);
			return;
		}

		if ($lifecycleVariant === 'map.end') {
			$this->handleMatchmakingLifecycleMapEnd($sourceCallback, $callbackArguments);
		}
	}

	private function buildMatchmakingLifecycleStatusSnapshot() {
		if (is_array($this->vetoDraftMatchmakingLifecycleContext)) {
			return $this->vetoDraftMatchmakingLifecycleContext;
		}

		if (is_array($this->vetoDraftMatchmakingLifecycleLastSnapshot)) {
			return $this->vetoDraftMatchmakingLifecycleLastSnapshot;
		}

		$vetoStatusSnapshot = $this->vetoDraftCoordinator ? $this->vetoDraftCoordinator->getStatusSnapshot() : array();
		$vetoSessionActive = !empty($vetoStatusSnapshot['active']);

		return array(
			'active' => false,
			'status' => 'idle',
			'stage' => MatchmakingLifecycleCatalog::STAGE_IDLE,
			'stage_order' => MatchmakingLifecycleCatalog::stageOrder(),
			'mode' => VetoDraftCatalog::MODE_MATCHMAKING_VOTE,
			'session_id' => '',
			'selected_map' => array(
				'uid' => '',
				'name' => '',
			),
			'ready_for_next_players' => !$vetoSessionActive,
			'actions' => array(),
			'history' => array(),
			'updated_at' => 0,
			'resolution_reason' => 'idle',
			'field_availability' => array(
				'session_id' => false,
				'selected_map_uid' => false,
				'history' => false,
			),
			'missing_fields' => array('session_id', 'selected_map_uid', 'history'),
		);
	}

	private function armMatchmakingLifecycleContext(array $sessionSnapshot, array $applyResultDetails, $source) {
		$selectedMapUid = isset($sessionSnapshot['winner_map_uid']) ? trim((string) $sessionSnapshot['winner_map_uid']) : '';
		$selectedMapName = '';
		if (isset($sessionSnapshot['winner_map']) && is_array($sessionSnapshot['winner_map']) && isset($sessionSnapshot['winner_map']['name'])) {
			$selectedMapName = trim((string) $sessionSnapshot['winner_map']['name']);
		}

		if ($selectedMapUid === '') {
			Logger::logWarning(
				'[PixelControl][veto][matchmaking_lifecycle][arm_skipped] source=' . trim((string) $source)
				. ', reason=winner_map_uid_missing.'
			);
			return;
		}

		$sessionId = isset($sessionSnapshot['session_id']) ? trim((string) $sessionSnapshot['session_id']) : '';
		$now = time();

		$this->vetoDraftMatchmakingLifecycleContext = array(
			'active' => true,
			'status' => 'running',
			'stage' => MatchmakingLifecycleCatalog::STAGE_IDLE,
			'stage_order' => MatchmakingLifecycleCatalog::stageOrder(),
			'mode' => VetoDraftCatalog::MODE_MATCHMAKING_VOTE,
			'session_id' => $sessionId,
			'selected_map' => array(
				'uid' => $selectedMapUid,
				'name' => $selectedMapName,
			),
			'ready_for_next_players' => false,
			'observed_map_uid' => '',
			'session' => $sessionSnapshot,
			'queue_apply' => $applyResultDetails,
			'actions' => array(
				'match_start' => array('attempted' => false),
				'kick_all_players' => array('attempted' => false),
				'map_change' => array('attempted' => false),
				'match_end_mark' => array('attempted' => false),
			),
			'history' => array(),
			'created_at' => $now,
			'updated_at' => $now,
			'resolution_reason' => 'pending',
			'field_availability' => array(
				'session_id' => ($sessionId !== ''),
				'selected_map_uid' => true,
				'history' => true,
			),
			'missing_fields' => ($sessionId !== '') ? array() : array('session_id'),
		);

		$this->recordMatchmakingLifecycleStage(
			MatchmakingLifecycleCatalog::STAGE_VETO_COMPLETED,
			trim((string) $source),
			array('selected_map_uid' => $selectedMapUid)
		);
	}

	private function resetMatchmakingLifecycleContext($reason, $source, $preserveLastSnapshot) {
		$reason = trim((string) $reason);
		$source = trim((string) $source);
		$preserveLastSnapshot = (bool) $preserveLastSnapshot;

		if (is_array($this->vetoDraftMatchmakingLifecycleContext)) {
			$snapshot = $this->vetoDraftMatchmakingLifecycleContext;
			$snapshot['active'] = false;
			$snapshot['status'] = ($reason === 'session_cancelled') ? 'cancelled' : 'reset';
			$snapshot['resolution_reason'] = ($reason !== '') ? $reason : 'reset';
			$snapshot['ready_for_next_players'] = false;
			$snapshot['updated_at'] = time();

			if ($preserveLastSnapshot) {
				$this->vetoDraftMatchmakingLifecycleLastSnapshot = $snapshot;
			}

			Logger::log(
				'[PixelControl][veto][matchmaking_lifecycle][reset] session=' . (isset($snapshot['session_id']) ? (string) $snapshot['session_id'] : 'unknown')
				. ', reason=' . ($reason !== '' ? $reason : 'unspecified')
				. ', source=' . ($source !== '' ? $source : 'unknown')
				. '.'
			);
		}

		if (!$preserveLastSnapshot) {
			$this->vetoDraftMatchmakingLifecycleLastSnapshot = null;
		}

		$this->vetoDraftMatchmakingLifecycleContext = null;
	}

	private function completeMatchmakingLifecycleContext($status, $reason, $source, array $details = array()) {
		if (!is_array($this->vetoDraftMatchmakingLifecycleContext)) {
			return;
		}

		$status = trim((string) $status);
		if ($status === '') {
			$status = 'completed';
		}

		$reason = trim((string) $reason);
		$source = trim((string) $source);

		$snapshot = $this->vetoDraftMatchmakingLifecycleContext;
		$snapshot['active'] = false;
		$snapshot['status'] = $status;
		$snapshot['resolution_reason'] = ($reason !== '') ? $reason : 'completed';
		$snapshot['ready_for_next_players'] = ($status === 'completed');
		$snapshot['updated_at'] = time();
		if (!empty($details)) {
			$snapshot['completion_details'] = $details;
		}

		$this->vetoDraftMatchmakingLifecycleContext = null;
		$this->vetoDraftMatchmakingLifecycleLastSnapshot = $snapshot;

		if ($status === 'completed') {
			$this->vetoDraftMatchmakingAutostartArmed = true;
			$this->vetoDraftMatchmakingAutostartSuppressed = false;
			Logger::log(
				'[PixelControl][veto][matchmaking_lifecycle][ready] session=' . (isset($snapshot['session_id']) ? (string) $snapshot['session_id'] : 'unknown')
				. ', source=' . ($source !== '' ? $source : 'unknown')
				. ', reason=' . ($snapshot['resolution_reason'] !== '' ? (string) $snapshot['resolution_reason'] : 'completed')
				. '.'
			);
		}
	}

	private function recordMatchmakingLifecycleStage($stage, $source, array $details = array()) {
		if (!is_array($this->vetoDraftMatchmakingLifecycleContext)) {
			return;
		}

		$normalizedStage = trim((string) $stage);
		if ($normalizedStage === '') {
			return;
		}

		$previousStage = isset($this->vetoDraftMatchmakingLifecycleContext['stage'])
			? (string) $this->vetoDraftMatchmakingLifecycleContext['stage']
			: MatchmakingLifecycleCatalog::STAGE_IDLE;
		$previousIndex = MatchmakingLifecycleCatalog::stageIndex($previousStage);
		$nextIndex = MatchmakingLifecycleCatalog::stageIndex($normalizedStage);

		if ($nextIndex >= 0 && $previousIndex >= $nextIndex) {
			return;
		}

		$timestamp = time();
		$source = trim((string) $source);

		$this->vetoDraftMatchmakingLifecycleContext['stage'] = $normalizedStage;
		$this->vetoDraftMatchmakingLifecycleContext['updated_at'] = $timestamp;
		$this->vetoDraftMatchmakingLifecycleContext['history'][] = array(
			'order_index' => count($this->vetoDraftMatchmakingLifecycleContext['history']) + 1,
			'stage' => $normalizedStage,
			'observed_at' => $timestamp,
			'source' => ($source !== '' ? $source : 'unknown'),
			'details' => $details,
		);

		if (count($this->vetoDraftMatchmakingLifecycleContext['history']) > $this->vetoDraftMatchmakingLifecycleHistoryLimit) {
			$this->vetoDraftMatchmakingLifecycleContext['history'] = array_slice(
				$this->vetoDraftMatchmakingLifecycleContext['history'],
				-1 * $this->vetoDraftMatchmakingLifecycleHistoryLimit
			);
		}

		Logger::log(
			'[PixelControl][veto][matchmaking_lifecycle][stage] session=' . (isset($this->vetoDraftMatchmakingLifecycleContext['session_id']) ? (string) $this->vetoDraftMatchmakingLifecycleContext['session_id'] : 'unknown')
			. ', stage=' . $normalizedStage
			. ', source=' . ($source !== '' ? $source : 'unknown')
			. '.'
		);
	}

	private function handleMatchmakingLifecycleMapBegin($sourceCallback, array $callbackArguments) {
		if (!is_array($this->vetoDraftMatchmakingLifecycleContext)) {
			return;
		}

		$selectedMapUid = isset($this->vetoDraftMatchmakingLifecycleContext['selected_map']['uid'])
			? strtolower(trim((string) $this->vetoDraftMatchmakingLifecycleContext['selected_map']['uid']))
			: '';
		if ($selectedMapUid === '') {
			$this->completeMatchmakingLifecycleContext('failed', 'selected_map_uid_missing', 'map.begin');
			return;
		}

		$observedMapUid = strtolower($this->resolveLifecycleMapUidFromCallbackArguments($callbackArguments));
		if ($observedMapUid === '' || $observedMapUid !== $selectedMapUid) {
			return;
		}

		$this->vetoDraftMatchmakingLifecycleContext['observed_map_uid'] = $observedMapUid;
		$this->ensureMatchmakingLifecycleMatchStart($sourceCallback, $observedMapUid, false);
	}

	private function handleMatchmakingLifecycleMapEnd($sourceCallback, array $callbackArguments) {
		if (!is_array($this->vetoDraftMatchmakingLifecycleContext)) {
			return;
		}

		$selectedMapUid = isset($this->vetoDraftMatchmakingLifecycleContext['selected_map']['uid'])
			? strtolower(trim((string) $this->vetoDraftMatchmakingLifecycleContext['selected_map']['uid']))
			: '';
		if ($selectedMapUid === '') {
			$this->completeMatchmakingLifecycleContext('failed', 'selected_map_uid_missing', 'map.end');
			return;
		}

		$observedMapUid = strtolower($this->resolveLifecycleMapUidFromCallbackArguments($callbackArguments));
		if ($observedMapUid === '' || $observedMapUid !== $selectedMapUid) {
			return;
		}

		if (!$this->ensureMatchmakingLifecycleMatchStart($sourceCallback, $observedMapUid, true)) {
			return;
		}

		$this->finalizeMatchmakingLifecycleAfterSelectedMapEnd($sourceCallback, $observedMapUid, $observedMapUid, true);
	}

	private function evaluateMatchmakingLifecycleRuntimeFallback($timestamp) {
		if (!is_array($this->vetoDraftMatchmakingLifecycleContext) || empty($this->vetoDraftMatchmakingLifecycleContext['active'])) {
			return;
		}

		$timestamp = max(0, (int) $timestamp);

		$selectedMapUid = isset($this->vetoDraftMatchmakingLifecycleContext['selected_map']['uid'])
			? strtolower(trim((string) $this->vetoDraftMatchmakingLifecycleContext['selected_map']['uid']))
			: '';
		if ($selectedMapUid === '') {
			$this->completeMatchmakingLifecycleContext('failed', 'selected_map_uid_missing', 'runtime_poll');
			return;
		}

		$currentMapUid = $this->resolveCurrentLifecycleMapUid();

		$queueApplyCurrentMapUid = '';
		if (
			isset($this->vetoDraftMatchmakingLifecycleContext['queue_apply'])
			&& is_array($this->vetoDraftMatchmakingLifecycleContext['queue_apply'])
			&& isset($this->vetoDraftMatchmakingLifecycleContext['queue_apply']['current_map_uid'])
		) {
			$queueApplyCurrentMapUid = strtolower(trim((string) $this->vetoDraftMatchmakingLifecycleContext['queue_apply']['current_map_uid']));
		}

		if ($currentMapUid !== '' && $currentMapUid === $selectedMapUid) {
			$this->vetoDraftMatchmakingLifecycleContext['observed_map_uid'] = $currentMapUid;
			$this->ensureMatchmakingLifecycleMatchStart('runtime_poll', $currentMapUid, true);
			return;
		}

		$createdAt = isset($this->vetoDraftMatchmakingLifecycleContext['created_at'])
			? max(0, (int) $this->vetoDraftMatchmakingLifecycleContext['created_at'])
			: 0;
		$elapsedSinceArmed = ($createdAt > 0 && $timestamp > 0) ? max(0, $timestamp - $createdAt) : 0;

		$currentStage = isset($this->vetoDraftMatchmakingLifecycleContext['stage'])
			? (string) $this->vetoDraftMatchmakingLifecycleContext['stage']
			: MatchmakingLifecycleCatalog::STAGE_IDLE;
		$currentStageIndex = MatchmakingLifecycleCatalog::stageIndex($currentStage);
		$selectedLoadedIndex = MatchmakingLifecycleCatalog::stageIndex(MatchmakingLifecycleCatalog::STAGE_SELECTED_MAP_LOADED);
		$matchStartedIndex = MatchmakingLifecycleCatalog::stageIndex(MatchmakingLifecycleCatalog::STAGE_MATCH_STARTED);
		$readyIndex = MatchmakingLifecycleCatalog::stageIndex(MatchmakingLifecycleCatalog::STAGE_READY_FOR_NEXT_PLAYERS);

		if ($currentStageIndex < $selectedLoadedIndex && $elapsedSinceArmed >= 3) {
			if (!$this->ensureMatchmakingLifecycleMatchStart('runtime_poll_timeout', $selectedMapUid, true)) {
				return;
			}

			$currentStage = isset($this->vetoDraftMatchmakingLifecycleContext['stage'])
				? (string) $this->vetoDraftMatchmakingLifecycleContext['stage']
				: MatchmakingLifecycleCatalog::STAGE_IDLE;
			$currentStageIndex = MatchmakingLifecycleCatalog::stageIndex($currentStage);
		}

		if ($currentMapUid === '') {
			return;
		}

		if ($currentStageIndex < $matchStartedIndex || $currentStageIndex >= $readyIndex) {
			if (
				$currentStageIndex < $selectedLoadedIndex
				&& $queueApplyCurrentMapUid !== ''
				&& $currentMapUid !== $queueApplyCurrentMapUid
				&& $currentMapUid !== $selectedMapUid
			) {
				if (!$this->ensureMatchmakingLifecycleMatchStart('runtime_poll', $selectedMapUid, true)) {
					return;
				}

				$this->finalizeMatchmakingLifecycleAfterSelectedMapEnd(
					'runtime_poll',
					$selectedMapUid,
					$currentMapUid,
					false
				);
			}

			return;
		}

		$this->finalizeMatchmakingLifecycleAfterSelectedMapEnd(
			'runtime_poll',
			$selectedMapUid,
			$currentMapUid,
			false
		);
	}

	private function ensureMatchmakingLifecycleMatchStart($sourceCallback, $observedMapUid, $inferred) {
		if (!is_array($this->vetoDraftMatchmakingLifecycleContext)) {
			return false;
		}

		$sourceCallback = trim((string) $sourceCallback);
		$observedMapUid = strtolower(trim((string) $observedMapUid));
		$inferred = (bool) $inferred;

		$details = array('map_uid' => $observedMapUid);
		if ($inferred) {
			$details['inferred'] = true;
		}

		$this->recordMatchmakingLifecycleStage(
			MatchmakingLifecycleCatalog::STAGE_SELECTED_MAP_LOADED,
			$sourceCallback,
			$details
		);

		$startResult = isset($this->vetoDraftMatchmakingLifecycleContext['actions']['match_start'])
			? $this->vetoDraftMatchmakingLifecycleContext['actions']['match_start']
			: array('attempted' => false);

		if (empty($startResult['attempted'])) {
			$startResult = $this->executeMatchmakingLifecycleStartMatchAction();
			$this->vetoDraftMatchmakingLifecycleContext['actions']['match_start'] = $startResult;
			$this->logMatchmakingLifecycleAction('start_match', $startResult, $sourceCallback . ($inferred ? '#inferred' : ''));
		}

		if (empty($startResult['success'])) {
			$this->completeMatchmakingLifecycleContext(
				'failed',
				'match_start_failed',
				$sourceCallback,
				array('action' => $startResult)
			);
			return false;
		}

		$this->recordMatchmakingLifecycleStage(
			MatchmakingLifecycleCatalog::STAGE_MATCH_STARTED,
			$sourceCallback,
			$details
		);

		return true;
	}

	private function finalizeMatchmakingLifecycleAfterSelectedMapEnd($sourceCallback, $selectedMapUid, $observedCurrentMapUid, $mapChangeSkipRequired) {
		if (!is_array($this->vetoDraftMatchmakingLifecycleContext)) {
			return;
		}

		$sourceCallback = trim((string) $sourceCallback);
		$selectedMapUid = strtolower(trim((string) $selectedMapUid));
		$observedCurrentMapUid = strtolower(trim((string) $observedCurrentMapUid));
		$mapChangeSkipRequired = (bool) $mapChangeSkipRequired;

		$this->recordMatchmakingLifecycleStage(
			MatchmakingLifecycleCatalog::STAGE_SELECTED_MAP_FINISHED,
			$sourceCallback,
			array(
				'map_uid' => $selectedMapUid,
				'current_map_uid' => $observedCurrentMapUid,
			)
		);

		$kickResult = $this->executeMatchmakingLifecycleKickAllPlayersAction();
		$this->vetoDraftMatchmakingLifecycleContext['actions']['kick_all_players'] = $kickResult;
		$this->logMatchmakingLifecycleAction('kick_all_players', $kickResult, $sourceCallback);
		$this->recordMatchmakingLifecycleStage(
			MatchmakingLifecycleCatalog::STAGE_PLAYERS_REMOVED,
			$sourceCallback,
			array(
				'attempted' => isset($kickResult['attempted_count']) ? (int) $kickResult['attempted_count'] : 0,
				'succeeded' => isset($kickResult['succeeded_count']) ? (int) $kickResult['succeeded_count'] : 0,
				'failed' => isset($kickResult['failed_count']) ? (int) $kickResult['failed_count'] : 0,
				'current_map_uid' => $observedCurrentMapUid,
			)
		);

		$mapChangeResult = $this->executeMatchmakingLifecycleMapChangeAction($mapChangeSkipRequired, $observedCurrentMapUid);
		$this->vetoDraftMatchmakingLifecycleContext['actions']['map_change'] = $mapChangeResult;
		$this->logMatchmakingLifecycleAction('map_change', $mapChangeResult, $sourceCallback);
		if (empty($mapChangeResult['success'])) {
			$this->completeMatchmakingLifecycleContext(
				'failed',
				'map_change_failed',
				$sourceCallback,
				array('action' => $mapChangeResult)
			);
			return;
		}

		$this->recordMatchmakingLifecycleStage(
			MatchmakingLifecycleCatalog::STAGE_MAP_CHANGED,
			$sourceCallback,
			array(
				'code' => isset($mapChangeResult['code']) ? (string) $mapChangeResult['code'] : 'unknown',
				'skip_required' => $mapChangeSkipRequired,
				'current_map_uid' => $observedCurrentMapUid,
			)
		);

		$matchEndResult = $this->executeMatchmakingLifecycleMarkMatchEndedAction();
		$this->vetoDraftMatchmakingLifecycleContext['actions']['match_end_mark'] = $matchEndResult;
		$this->logMatchmakingLifecycleAction('match_end_mark', $matchEndResult, $sourceCallback);
		if (empty($matchEndResult['success'])) {
			$this->completeMatchmakingLifecycleContext(
				'failed',
				'match_end_mark_failed',
				$sourceCallback,
				array('action' => $matchEndResult)
			);
			return;
		}

		$this->recordMatchmakingLifecycleStage(
			MatchmakingLifecycleCatalog::STAGE_MATCH_ENDED,
			$sourceCallback,
			array(
				'code' => isset($matchEndResult['code']) ? (string) $matchEndResult['code'] : 'unknown',
				'current_map_uid' => $observedCurrentMapUid,
			)
		);
		$this->recordMatchmakingLifecycleStage(
			MatchmakingLifecycleCatalog::STAGE_READY_FOR_NEXT_PLAYERS,
			$sourceCallback,
			array(
				'ready' => true,
				'current_map_uid' => $observedCurrentMapUid,
			)
		);

		$this->completeMatchmakingLifecycleContext('completed', 'selected_map_cycle_completed', $sourceCallback);
	}

	private function executeMatchmakingLifecycleStartMatchAction() {
		if (!$this->maniaControl) {
			return array(
				'attempted' => true,
				'success' => false,
				'code' => 'runtime_unavailable',
				'message' => 'ManiaControl runtime is unavailable.',
				'attempts' => array(),
				'observed_at' => time(),
			);
		}

		$attempts = array();
		$attempts[] = $this->invokeMatchmakingLifecycleModeScriptEvent(
			'Maniaplanet.StartMatch.Start',
			array('PixelControl.MatchmakingLifecycle.Start.' . uniqid())
		);
		$attempts[] = $this->invokeMatchmakingLifecycleModeScriptCommands(array(
			'Command_ForceWarmUp' => false,
			'Command_SetPause' => false,
			'Command_ForceEndRound' => false,
		));
		$attempts[] = $this->invokeMatchmakingLifecycleWarmupStop();

		$success = false;
		foreach ($attempts as $attempt) {
			if (!empty($attempt['success'])) {
				$success = true;
				break;
			}
		}

		return array(
			'attempted' => true,
			'success' => $success,
			'code' => $success ? 'match_start_triggered' : 'match_start_dispatch_failed',
			'message' => $success ? 'Match-start signal dispatched.' : 'All match-start dispatch attempts failed.',
			'attempts' => $attempts,
			'observed_at' => time(),
		);
	}

	private function executeMatchmakingLifecycleKickAllPlayersAction() {
		if (!$this->maniaControl) {
			return array(
				'attempted' => true,
				'success' => false,
				'code' => 'runtime_unavailable',
				'message' => 'ManiaControl runtime is unavailable.',
				'attempted_count' => 0,
				'succeeded_count' => 0,
				'failed_count' => 0,
				'failed_logins' => array(),
				'observed_at' => time(),
			);
		}

		$playerManager = $this->maniaControl->getPlayerManager();
		$players = $playerManager ? $playerManager->getPlayers(false) : array();
		if (!is_array($players)) {
			$players = array();
		}

		$attemptedCount = 0;
		$succeededCount = 0;
		$failedCount = 0;
		$failedLogins = array();
		$attemptedLogins = array();

		foreach ($players as $player) {
			if (!$player instanceof Player || !isset($player->login)) {
				continue;
			}

			$playerLogin = trim((string) $player->login);
			if ($playerLogin === '') {
				continue;
			}

			$attemptedCount++;
			$attemptedLogins[] = $playerLogin;

			try {
				if (method_exists($player, 'isFakePlayer') && $player->isFakePlayer()) {
					$this->maniaControl->getClient()->disconnectFakePlayer($playerLogin);
				} else {
					$this->maniaControl->getClient()->kick($playerLogin, 'Match finished: preparing next veto session.');
				}
				$succeededCount++;
			} catch (\Throwable $throwable) {
				$failedCount++;
				$failedLogins[] = array(
					'login' => $playerLogin,
					'error' => $throwable->getMessage(),
				);
			}
		}

		$success = ($failedCount === 0);

		return array(
			'attempted' => true,
			'success' => $success,
			'code' => $success ? 'kick_all_completed' : 'kick_all_partial_failure',
			'message' => $success
				? 'Kick-all routine completed.'
				: 'Kick-all routine completed with one or more failures.',
			'attempted_count' => $attemptedCount,
			'succeeded_count' => $succeededCount,
			'failed_count' => $failedCount,
			'attempted_logins' => $attemptedLogins,
			'failed_logins' => $failedLogins,
			'observed_at' => time(),
		);
	}

	private function executeMatchmakingLifecycleMapChangeAction($skipRequired = true, $observedCurrentMapUid = '') {
		$skipRequired = (bool) $skipRequired;
		$observedCurrentMapUid = strtolower(trim((string) $observedCurrentMapUid));

		if (!$skipRequired) {
			return array(
				'attempted' => true,
				'success' => true,
				'code' => 'map_change_already_observed',
				'message' => 'Map change already observed from runtime state.',
				'attempts' => array(
					array(
						'entrypoint' => 'runtime_observation',
						'success' => true,
						'error' => '',
					)
				),
				'observed_current_map_uid' => $observedCurrentMapUid,
				'observed_at' => time(),
			);
		}

		if (!$this->maniaControl) {
			return array(
				'attempted' => true,
				'success' => false,
				'code' => 'runtime_unavailable',
				'message' => 'ManiaControl runtime is unavailable.',
				'attempts' => array(),
				'observed_at' => time(),
			);
		}

		$attempts = array();
		$runtimeAdapter = new ManiaControlMapRuntimeAdapter($this->maniaControl->getMapManager());
		$skipApplied = $runtimeAdapter->skipCurrentMap();
		$attempts[] = array(
			'entrypoint' => 'MapRuntimeAdapterInterface::skipCurrentMap',
			'success' => (bool) $skipApplied,
			'error' => '',
		);

		if (!$skipApplied) {
			try {
				$mapActions = $this->maniaControl->getMapManager()->getMapActions();
				$fallbackApplied = $mapActions ? (bool) $mapActions->skipMap() : false;
				$attempts[] = array(
					'entrypoint' => 'MapActions::skipMap',
					'success' => $fallbackApplied,
					'error' => '',
				);
				$skipApplied = $skipApplied || $fallbackApplied;
			} catch (\Throwable $throwable) {
				$attempts[] = array(
					'entrypoint' => 'MapActions::skipMap',
					'success' => false,
					'error' => $throwable->getMessage(),
				);
			}
		}

		return array(
			'attempted' => true,
			'success' => $skipApplied,
			'code' => $skipApplied ? 'map_change_triggered' : 'map_change_failed',
			'message' => $skipApplied ? 'Map change triggered through map skip.' : 'Failed to trigger map change by map skip.',
			'attempts' => $attempts,
			'observed_at' => time(),
		);
	}

	private function executeMatchmakingLifecycleMarkMatchEndedAction() {
		if (!$this->maniaControl) {
			return array(
				'attempted' => true,
				'success' => false,
				'code' => 'runtime_unavailable',
				'message' => 'ManiaControl runtime is unavailable.',
				'attempts' => array(),
				'observed_at' => time(),
			);
		}

		$attempts = array();
		$attempts[] = $this->invokeMatchmakingLifecycleModeScriptEvent(
			'Maniaplanet.EndMatch.Start',
			array('PixelControl.MatchmakingLifecycle.End.' . uniqid())
		);

		$checkEndMatchApplied = false;
		$errorMessage = '';
		try {
			if (method_exists($this->maniaControl->getClient(), 'checkEndMatchCondition')) {
				$checkEndMatchApplied = (bool) $this->maniaControl->getClient()->checkEndMatchCondition();
			}
		} catch (\Throwable $throwable) {
			$errorMessage = $throwable->getMessage();
		}
		$attempts[] = array(
			'entrypoint' => 'Client::checkEndMatchCondition',
			'success' => $checkEndMatchApplied,
			'error' => $errorMessage,
		);

		$attempts[] = $this->invokeMatchmakingLifecycleModeScriptCommands(array(
			'Command_ForceEndRound' => true,
		));

		$success = false;
		foreach ($attempts as $attempt) {
			if (!empty($attempt['success'])) {
				$success = true;
				break;
			}
		}

		return array(
			'attempted' => true,
			'success' => $success,
			'code' => $success ? 'match_end_marked' : 'match_end_mark_failed',
			'message' => $success ? 'Match-end mark dispatched.' : 'Failed to dispatch match-end mark.',
			'attempts' => $attempts,
			'observed_at' => time(),
		);
	}

	private function resolveLifecycleMapUidFromCallbackArguments(array $callbackArguments) {
		$mapUid = $this->extractMapUidFromMixedValue($callbackArguments);
		if ($mapUid !== '') {
			return $mapUid;
		}

		return $this->resolveCurrentLifecycleMapUid();
	}

	private function resolveCurrentLifecycleMapUid() {
		$currentMapSnapshot = $this->buildCurrentMapSnapshot();
		if (isset($currentMapSnapshot['uid'])) {
			$mapUid = strtolower(trim((string) $currentMapSnapshot['uid']));
			if ($mapUid !== '') {
				return $mapUid;
			}
		}

		if (!$this->maniaControl) {
			return '';
		}

		try {
			$currentMapInfo = $this->maniaControl->getClient()->getCurrentMapInfo();
		} catch (\Throwable $throwable) {
			return '';
		}

		if (!is_object($currentMapInfo)) {
			return '';
		}

		$mapUid = '';
		if (isset($currentMapInfo->uid)) {
			$mapUid = trim((string) $currentMapInfo->uid);
		} elseif (isset($currentMapInfo->uId)) {
			$mapUid = trim((string) $currentMapInfo->uId);
		} elseif (isset($currentMapInfo->UId)) {
			$mapUid = trim((string) $currentMapInfo->UId);
		}

		if ($mapUid === '') {
			return '';
		}

		return strtolower($mapUid);
	}

	private function extractMapUidFromMixedValue($value) {
		if (is_object($value) && isset($value->UId)) {
			$mapUid = trim((string) $value->UId);
			if ($mapUid !== '') {
				return $mapUid;
			}
		}

		if (is_object($value) && isset($value->uid)) {
			$mapUid = trim((string) $value->uid);
			if ($mapUid !== '') {
				return $mapUid;
			}
		}

		if (!is_array($value)) {
			return '';
		}

		foreach ($value as $key => $nestedValue) {
			if (!is_string($key) || !is_scalar($nestedValue)) {
				continue;
			}

			$normalizedKey = strtolower(trim($key));
			if ($normalizedKey !== 'uid' && $normalizedKey !== 'mapuid' && $normalizedKey !== 'map_uid') {
				continue;
			}

			$mapUid = trim((string) $nestedValue);
			if ($mapUid !== '') {
				return $mapUid;
			}
		}

		foreach ($value as $nestedValue) {
			$nestedMapUid = $this->extractMapUidFromMixedValue($nestedValue);
			if ($nestedMapUid !== '') {
				return $nestedMapUid;
			}
		}

		return '';
	}

	private function invokeMatchmakingLifecycleModeScriptEvent($eventName, array $eventPayload = array()) {
		$eventName = trim((string) $eventName);
		if ($eventName === '') {
			return array(
				'entrypoint' => 'ModeScriptEventManager::triggerModeScriptEvent',
				'success' => false,
				'error' => 'event_name_missing',
			);
		}

		try {
			$this->maniaControl->getModeScriptEventManager()->triggerModeScriptEvent($eventName, $eventPayload);
			return array(
				'entrypoint' => 'ModeScriptEventManager::triggerModeScriptEvent',
				'event' => $eventName,
				'success' => true,
				'error' => '',
			);
		} catch (\Throwable $throwable) {
			return array(
				'entrypoint' => 'ModeScriptEventManager::triggerModeScriptEvent',
				'event' => $eventName,
				'success' => false,
				'error' => $throwable->getMessage(),
			);
		}
	}

	private function invokeMatchmakingLifecycleModeScriptCommands(array $commands) {
		try {
			$this->maniaControl->getClient()->sendModeScriptCommands($commands);
			return array(
				'entrypoint' => 'Client::sendModeScriptCommands',
				'success' => true,
				'error' => '',
				'commands' => $commands,
			);
		} catch (\Throwable $throwable) {
			return array(
				'entrypoint' => 'Client::sendModeScriptCommands',
				'success' => false,
				'error' => $throwable->getMessage(),
				'commands' => $commands,
			);
		}
	}

	private function invokeMatchmakingLifecycleWarmupStop() {
		try {
			$this->maniaControl->getModeScriptEventManager()->stopManiaPlanetWarmup();
			return array(
				'entrypoint' => 'ModeScriptEventManager::stopManiaPlanetWarmup',
				'success' => true,
				'error' => '',
			);
		} catch (\Throwable $throwable) {
			return array(
				'entrypoint' => 'ModeScriptEventManager::stopManiaPlanetWarmup',
				'success' => false,
				'error' => $throwable->getMessage(),
			);
		}
	}

	private function logMatchmakingLifecycleAction($actionName, array $actionResult, $source) {
		Logger::log(
			'[PixelControl][veto][matchmaking_lifecycle][action] session=' . (is_array($this->vetoDraftMatchmakingLifecycleContext) && isset($this->vetoDraftMatchmakingLifecycleContext['session_id'])
				? (string) $this->vetoDraftMatchmakingLifecycleContext['session_id']
				: (is_array($this->vetoDraftMatchmakingLifecycleLastSnapshot) && isset($this->vetoDraftMatchmakingLifecycleLastSnapshot['session_id']) ? (string) $this->vetoDraftMatchmakingLifecycleLastSnapshot['session_id'] : 'unknown'))
			. ', action=' . trim((string) $actionName)
			. ', source=' . trim((string) $source)
			. ', success=' . (!empty($actionResult['success']) ? 'yes' : 'no')
			. ', code=' . (isset($actionResult['code']) ? (string) $actionResult['code'] : 'unknown')
			. '.'
		);
	}

	private function ensureConfiguredMatchmakingSessionForPlayerAction($source) {
		if (!$this->vetoDraftCoordinator || !$this->vetoDraftMapPoolService || !$this->maniaControl) {
			return array(
				'success' => false,
				'code' => 'capability_unavailable',
				'message' => 'Map draft/veto capability is unavailable.',
			);
		}

		$statusSnapshot = $this->vetoDraftCoordinator->getStatusSnapshot();
		if (!empty($statusSnapshot['active'])) {
			return array(
				'success' => true,
				'code' => 'session_already_active',
				'message' => 'Draft/veto session already active.',
				'started' => false,
			);
		}

		if ($this->vetoDraftDefaultMode !== VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
			return array(
				'success' => false,
				'code' => 'session_not_running',
				'message' => 'No active veto session. Configured mode is ' . $this->vetoDraftDefaultMode . '; automatic player-start is available only for matchmaking_vote in this phase.',
			);
		}

		$startResult = $this->startConfiguredMatchmakingSession($source, time());
		if (empty($startResult['success'])) {
			return $startResult;
		}

		Logger::log(
			'[PixelControl][veto][auto_start] source=' . trim((string) $source)
			. ', mode=' . $this->vetoDraftDefaultMode
			. ', duration=' . $this->vetoDraftMatchmakingDurationSeconds
			. '.'
		);

		return $startResult;
	}

	private function startConfiguredMatchmakingSession($source, $timestamp) {
		if (!$this->vetoDraftCoordinator || !$this->vetoDraftMapPoolService || !$this->maniaControl) {
			return array(
				'success' => false,
				'code' => 'capability_unavailable',
				'message' => 'Map draft/veto capability is unavailable.',
			);
		}

		$timestamp = max(0, (int) $timestamp);
		$mapPool = $this->vetoDraftMapPoolService->buildMapPool($this->maniaControl->getMapManager());
		$startResult = $this->vetoDraftCoordinator->startMatchmaking(
			$mapPool,
			$this->vetoDraftMatchmakingDurationSeconds,
			$timestamp
		);
		$this->syncVetoDraftTelemetryState();

		if (empty($startResult['success'])) {
			return $startResult;
		}

		$this->vetoDraftLastAppliedSessionId = '';
		$this->vetoDraftMatchmakingAutostartArmed = false;
		$this->vetoDraftMatchmakingAutostartSuppressed = false;
		$this->resetMatchmakingLifecycleContext('session_started', 'auto_start_matchmaking', false);
		$this->broadcastVetoDraftSessionOverview();

		return array(
			'success' => true,
			'code' => 'matchmaking_started',
			'message' => 'Configured matchmaking veto started.',
			'started' => true,
			'details' => $startResult,
			'source' => trim((string) $source),
		);
	}

	private function evaluateMatchmakingAutostartThreshold($timestamp) {
		if (!$this->vetoDraftCoordinator || !$this->vetoDraftEnabled || !$this->maniaControl) {
			return;
		}

		if ($this->vetoDraftDefaultMode !== VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
			$this->vetoDraftMatchmakingAutostartArmed = true;
			$this->vetoDraftMatchmakingAutostartSuppressed = false;
			return;
		}

		if ($this->vetoDraftCoordinator->hasActiveSession()) {
			return;
		}

		$connectedHumanPlayers = $this->countConnectedHumanPlayersForVetoAutoStart();
		$threshold = max(1, (int) $this->vetoDraftMatchmakingAutostartMinPlayers);

		if ($connectedHumanPlayers < $threshold) {
			$shouldLogBelowThreshold = (!$this->vetoDraftMatchmakingAutostartArmed) || $this->vetoDraftMatchmakingAutostartSuppressed;
			$wasArmed = $this->vetoDraftMatchmakingAutostartArmed;

			$this->vetoDraftMatchmakingAutostartArmed = true;
			$this->vetoDraftMatchmakingAutostartSuppressed = false;

			if ($shouldLogBelowThreshold) {
				Logger::log(
					'[PixelControl][veto][autostart][below_threshold] connected=' . $connectedHumanPlayers
					. ', threshold=' . $threshold
					. ', mode=' . $this->vetoDraftDefaultMode
					. '.'
				);
			}

			if (!$wasArmed) {
				Logger::log(
					'[PixelControl][veto][autostart][armed] connected=' . $connectedHumanPlayers
					. ', threshold=' . $threshold
					. '.'
				);
			}
			return;
		}

		if ($this->vetoDraftMatchmakingAutostartArmed) {
			$startResult = $this->startConfiguredMatchmakingSession('timer_threshold', $timestamp);
			if (!empty($startResult['success'])) {
				Logger::log(
					'[PixelControl][veto][autostart][triggered] connected=' . $connectedHumanPlayers
					. ', threshold=' . $threshold
					. ', duration=' . $this->vetoDraftMatchmakingDurationSeconds
					. '.'
				);
				return;
			}

			Logger::logWarning(
				'[PixelControl][veto][autostart][trigger_failed] connected=' . $connectedHumanPlayers
				. ', threshold=' . $threshold
				. ', code=' . (isset($startResult['code']) ? (string) $startResult['code'] : 'unknown')
				. ', message=' . (isset($startResult['message']) ? (string) $startResult['message'] : 'failed to start configured matchmaking veto')
				. '.'
			);
			return;
		}

		if (!$this->vetoDraftMatchmakingAutostartSuppressed) {
			$this->vetoDraftMatchmakingAutostartSuppressed = true;
			Logger::log(
				'[PixelControl][veto][autostart][suppressed] connected=' . $connectedHumanPlayers
				. ', threshold=' . $threshold
				. ', reason=already_triggered_until_below_threshold.'
			);
		}
	}

	private function countConnectedHumanPlayersForVetoAutoStart() {
		if (!$this->maniaControl) {
			return 0;
		}

		$playerManager = $this->maniaControl->getPlayerManager();
		if (!$playerManager) {
			return 0;
		}

		$playerCount = $playerManager->getPlayerCount(false, true);
		if (!is_numeric($playerCount)) {
			return 0;
		}

		return max(0, (int) $playerCount);
	}

	private function broadcastMatchmakingCountdownAnnouncement(array $event) {
		if (!$this->maniaControl) {
			return;
		}

		$remainingSeconds = isset($event['remaining_seconds']) ? (int) $event['remaining_seconds'] : 0;
		if ($remainingSeconds <= 0) {
			return;
		}

		$sessionId = isset($event['session_id']) ? trim((string) $event['session_id']) : '';
		$this->maniaControl->getChat()->sendInformation('[PixelControl] Matchmaking veto ends in ' . $remainingSeconds . 's.', null);
		Logger::log(
			'[PixelControl][veto][countdown] session=' . ($sessionId !== '' ? $sessionId : 'unknown')
			. ', remaining=' . $remainingSeconds
			. '.'
		);
	}

	private function updateVetoDraftDefaultMode($modeSelection) {
		$normalizedMode = VetoDraftCatalog::normalizeMode($modeSelection, '');
		if ($normalizedMode === '') {
			return array(
				'success' => false,
				'code' => 'invalid_mode',
				'message' => 'Invalid mode. Expected matchmaking_vote or tournament_draft.',
			);
		}

		$previousMode = $this->vetoDraftDefaultMode;
		$this->vetoDraftDefaultMode = $normalizedMode;
		$settingSaved = $this->maniaControl->getSettingManager()->setSetting($this, self::SETTING_VETO_DRAFT_DEFAULT_MODE, $normalizedMode);
		if (!$settingSaved) {
			$this->vetoDraftDefaultMode = $previousMode;
			return array(
				'success' => false,
				'code' => 'setting_write_failed',
				'message' => 'Unable to persist default mode setting.',
			);
		}

		return array(
			'success' => true,
			'code' => 'default_mode_updated',
			'message' => 'Default veto mode updated to ' . $normalizedMode . '.',
		);
	}

	private function updateVetoDraftMatchmakingDuration($durationSeconds) {
		if (!is_numeric($durationSeconds)) {
			return array(
				'success' => false,
				'code' => 'invalid_duration',
				'message' => 'Invalid duration. Expected a numeric value in seconds.',
			);
		}

		$normalizedDuration = VetoDraftCatalog::sanitizePositiveInt(
			$durationSeconds,
			$this->vetoDraftMatchmakingDurationSeconds,
			10
		);
		$previousDuration = $this->vetoDraftMatchmakingDurationSeconds;
		$this->vetoDraftMatchmakingDurationSeconds = $normalizedDuration;

		$settingSaved = $this->maniaControl->getSettingManager()->setSetting(
			$this,
			self::SETTING_VETO_DRAFT_MATCHMAKING_DURATION_SECONDS,
			$normalizedDuration
		);
		if (!$settingSaved) {
			$this->vetoDraftMatchmakingDurationSeconds = $previousDuration;
			return array(
				'success' => false,
				'code' => 'setting_write_failed',
				'message' => 'Unable to persist matchmaking duration setting.',
			);
		}

		return array(
			'success' => true,
			'code' => 'matchmaking_duration_updated',
			'message' => 'Default matchmaking veto duration updated to ' . $normalizedDuration . ' second(s).',
		);
	}

	private function updateVetoDraftMatchmakingAutostartMinPlayers($minPlayers) {
		if (!is_numeric($minPlayers)) {
			return array(
				'success' => false,
				'code' => 'invalid_min_players',
				'message' => 'Invalid min_players value. Expected a numeric integer.',
			);
		}

		$normalizedMinPlayers = VetoDraftCatalog::sanitizePositiveInt(
			$minPlayers,
			$this->vetoDraftMatchmakingAutostartMinPlayers,
			1
		);

		$previousMinPlayers = $this->vetoDraftMatchmakingAutostartMinPlayers;
		$this->vetoDraftMatchmakingAutostartMinPlayers = $normalizedMinPlayers;

		$settingSaved = $this->maniaControl->getSettingManager()->setSetting(
			$this,
			self::SETTING_VETO_DRAFT_MATCHMAKING_AUTOSTART_MIN_PLAYERS,
			$normalizedMinPlayers
		);
		if (!$settingSaved) {
			$this->vetoDraftMatchmakingAutostartMinPlayers = $previousMinPlayers;
			return array(
				'success' => false,
				'code' => 'setting_write_failed',
				'message' => 'Unable to persist matchmaking auto-start threshold setting.',
			);
		}

		$this->vetoDraftMatchmakingAutostartArmed = true;
		$this->vetoDraftMatchmakingAutostartSuppressed = false;

		return array(
			'success' => true,
			'code' => 'matchmaking_autostart_min_players_updated',
			'message' => 'Matchmaking auto-start threshold updated to ' . $normalizedMinPlayers . ' connected player(s).',
		);
	}

	private function buildQueuedMapRows(array $mapOrder, array $sessionSnapshot, $includeMapUid = true) {
		$includeMapUid = (bool) $includeMapUid;

		if (empty($mapOrder)) {
			return array();
		}

		$sessionMapPool = array();
		if (isset($sessionSnapshot['map_pool']) && is_array($sessionSnapshot['map_pool'])) {
			$sessionMapPool = array_merge($sessionMapPool, $sessionSnapshot['map_pool']);
		}
		if (isset($sessionSnapshot['series_map_order']) && is_array($sessionSnapshot['series_map_order'])) {
			$sessionMapPool = array_merge($sessionMapPool, $sessionSnapshot['series_map_order']);
		}
		if (isset($sessionSnapshot['winner_map']) && is_array($sessionSnapshot['winner_map'])) {
			$sessionMapPool[] = $sessionSnapshot['winner_map'];
		}

		$runtimeMapPool = array();
		if ($this->vetoDraftMapPoolService && $this->maniaControl) {
			$runtimeMapPool = $this->vetoDraftMapPoolService->buildMapPool($this->maniaControl->getMapManager());
		}

		$orderedMapIdentities = array();
		foreach ($mapOrder as $mapUid) {
			$mapUid = trim((string) $mapUid);
			if ($mapUid === '') {
				continue;
			}

			$mapIdentity = null;
			if ($this->vetoDraftMapPoolService) {
				$mapIdentity = $this->vetoDraftMapPoolService->findMapIdentityByUid($sessionMapPool, $mapUid);
				if ($mapIdentity === null && !empty($runtimeMapPool)) {
					$mapIdentity = $this->vetoDraftMapPoolService->findMapIdentityByUid($runtimeMapPool, $mapUid);
				}
			}

			if (!is_array($mapIdentity)) {
				$mapIdentity = array(
					'uid' => $mapUid,
					'name' => $mapUid,
				);
			}

			$orderedMapIdentities[] = $mapIdentity;
		}

		if ($this->vetoDraftMapPoolService) {
			return $this->vetoDraftMapPoolService->buildMapListRows($orderedMapIdentities, $includeMapUid);
		}

		$fallbackRows = array();
		foreach ($orderedMapIdentities as $index => $mapIdentity) {
			$mapUid = isset($mapIdentity['uid']) ? (string) $mapIdentity['uid'] : '';
			$fallbackRows[] = '#' . ($index + 1) . ' '
				. (isset($mapIdentity['name']) ? (string) $mapIdentity['name'] : 'Unknown Map')
				. (($includeMapUid && $mapUid !== '') ? ' [' . $mapUid . ']' : '');
		}

		return $fallbackRows;
	}

	private function broadcastVetoDraftRoleAwareMapRows($publicHeader, $adminHeader, array $mapPool) {
		if (empty($mapPool) || !$this->vetoDraftMapPoolService) {
			return;
		}

		$publicRows = $this->vetoDraftMapPoolService->buildMapListRows($mapPool, false);
		$adminRows = $this->vetoDraftMapPoolService->buildMapListRows($mapPool, true);

		if (!empty($publicRows)) {
			$this->broadcastVetoDraftAudienceLine($publicHeader, 'players');
			foreach ($publicRows as $publicRow) {
				$this->broadcastVetoDraftAudienceLine('[PixelControl] ' . $publicRow, 'players');
			}
		}

		if (!empty($adminRows)) {
			$this->broadcastVetoDraftAudienceLine($adminHeader, 'admins');
			foreach ($adminRows as $adminRow) {
				$this->broadcastVetoDraftAudienceLine('[PixelControl] ' . $adminRow, 'admins');
			}
		}
	}

	private function broadcastVetoDraftAudienceLine($line, $audience) {
		if (!$this->maniaControl) {
			return;
		}

		$message = trim((string) $line);
		if ($message === '') {
			return;
		}

		$normalizedAudience = strtolower(trim((string) $audience));
		if ($normalizedAudience === '' || $normalizedAudience === 'all') {
			$this->maniaControl->getChat()->sendInformation($message, null);
			return;
		}

		$playerManager = $this->maniaControl->getPlayerManager();
		if (!$playerManager) {
			return;
		}

		$players = $playerManager->getPlayers(false);
		if (!is_array($players) || empty($players)) {
			return;
		}

		foreach ($players as $candidatePlayer) {
			if (!$candidatePlayer instanceof Player) {
				continue;
			}

			$isControlAdmin = $this->hasVetoControlPermission($candidatePlayer);
			if ($normalizedAudience === 'admins' && !$isControlAdmin) {
				continue;
			}

			if ($normalizedAudience === 'players' && $isControlAdmin) {
				continue;
			}

			$this->maniaControl->getChat()->sendInformation($message, $candidatePlayer);
		}
	}

	private function buildPublicVetoResultStatusLines(array $statusSnapshot) {
		$mode = isset($statusSnapshot['mode']) ? trim((string) $statusSnapshot['mode']) : '';
		$session = isset($statusSnapshot['session']) && is_array($statusSnapshot['session']) ? $statusSnapshot['session'] : array();
		$sessionStatus = isset($session['status']) ? trim((string) $session['status']) : VetoDraftCatalog::STATUS_IDLE;

		if ($sessionStatus === '' || $sessionStatus === VetoDraftCatalog::STATUS_IDLE || $mode === '') {
			return array('[PixelControl] Veto result: status=unavailable.');
		}

		$statusLine = '[PixelControl] Veto result: status=' . $sessionStatus . ', mode=' . $mode;
		$resolutionReason = isset($session['resolution_reason']) ? trim((string) $session['resolution_reason']) : '';
		if ($resolutionReason !== '' && $sessionStatus !== VetoDraftCatalog::STATUS_RUNNING) {
			$statusLine .= ', reason=' . $resolutionReason;
		}
		$statusLine .= '.';

		$statusLines = array($statusLine);
		if ($sessionStatus !== VetoDraftCatalog::STATUS_COMPLETED) {
			return $statusLines;
		}

		if ($mode === VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
			$winnerMapName = $this->buildDisplayMapName(isset($session['winner_map']) ? $session['winner_map'] : null);
			if ($winnerMapName !== '') {
				$statusLines[] = '[PixelControl] Final map: ' . $winnerMapName . '.';
			}

			return $statusLines;
		}

		if ($mode === VetoDraftCatalog::MODE_TOURNAMENT_DRAFT) {
			$seriesMapOrder = isset($session['series_map_order']) && is_array($session['series_map_order'])
				? $session['series_map_order']
				: array();
			$seriesMapNames = $this->buildMapNameSequenceFromIdentities($seriesMapOrder);
			if (!empty($seriesMapNames)) {
				$statusLines[] = '[PixelControl] Series result: ' . implode(' -> ', $seriesMapNames) . '.';
			}
		}

		return $statusLines;
	}

	private function buildMapNameSequenceFromIdentities(array $mapIdentities) {
		$mapNames = array();
		foreach ($mapIdentities as $mapIdentity) {
			$mapName = $this->buildDisplayMapName($mapIdentity);
			if ($mapName === '') {
				continue;
			}

			$mapNames[] = $mapName;
		}

		return $mapNames;
	}

	private function buildDisplayMapName($mapIdentity) {
		if (!is_array($mapIdentity)) {
			return '';
		}

		$mapName = isset($mapIdentity['name']) ? trim((string) $mapIdentity['name']) : '';
		if ($mapName !== '') {
			return $mapName;
		}

		return 'Unknown Map';
	}

	private function resolveVetoOverrideFlag(array $parameters, Player $player) {
		if (empty($parameters['force']) && empty($parameters['override']) && empty($parameters['allow_override'])) {
			return false;
		}

		return $this->maniaControl->getAuthenticationManager()->checkPluginPermission($this, $player, VetoDraftCatalog::RIGHT_OVERRIDE);
	}

	private function hasVetoControlPermission(Player $player) {
		if (!$this->maniaControl) {
			return false;
		}

		return $this->maniaControl->getAuthenticationManager()->checkPluginPermission($this, $player, VetoDraftCatalog::RIGHT_CONTROL);
	}

	private function extractSelectionFromRequest(array $parameters, array $positionals, $positionIndex) {
		if (isset($parameters['map_uid']) && trim((string) $parameters['map_uid']) !== '') {
			return trim((string) $parameters['map_uid']);
		}

		if (isset($parameters['map']) && trim((string) $parameters['map']) !== '') {
			return trim((string) $parameters['map']);
		}

		if (isset($parameters['selection']) && trim((string) $parameters['selection']) !== '') {
			return trim((string) $parameters['selection']);
		}

		if (isset($positionals[$positionIndex])) {
			return trim((string) $positionals[$positionIndex]);
		}

		return '';
	}

	private function extractMapOrderFromSession(array $sessionSnapshot) {
		$mapOrder = array();

		if (isset($sessionSnapshot['winner_map_uid']) && trim((string) $sessionSnapshot['winner_map_uid']) !== '') {
			$mapOrder[] = trim((string) $sessionSnapshot['winner_map_uid']);
			return $mapOrder;
		}

		if (isset($sessionSnapshot['series_map_order']) && is_array($sessionSnapshot['series_map_order'])) {
			foreach ($sessionSnapshot['series_map_order'] as $mapIdentity) {
				if (!is_array($mapIdentity) || !isset($mapIdentity['uid'])) {
					continue;
				}

				$mapUid = trim((string) $mapIdentity['uid']);
				if ($mapUid === '') {
					continue;
				}

				$mapOrder[] = $mapUid;
			}
		}

		return $mapOrder;
	}
}
