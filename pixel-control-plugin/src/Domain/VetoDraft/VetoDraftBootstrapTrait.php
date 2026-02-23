<?php

namespace PixelControl\Domain\VetoDraft;

use PixelControl\VetoDraft\VetoDraftQueueApplier;
use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Communication\CommunicationAnswer;
use ManiaControl\Logger;
use ManiaControl\Players\Player;
use PixelControl\VetoDraft\MapPoolService;
use PixelControl\VetoDraft\TournamentSequenceBuilder;
use PixelControl\VetoDraft\VetoDraftCatalog;
use PixelControl\VetoDraft\VetoDraftCoordinator;

trait VetoDraftBootstrapTrait {
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
		$this->vetoDraftMatchmakingAutostartPending = null;
		$this->vetoDraftMatchmakingAutostartLastCancellation = '';
		$this->vetoDraftMatchmakingReadyArmed = false;
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
		$this->maniaControl->getCommunicationManager()->registerCommunicationListener(
			VetoDraftCatalog::COMMUNICATION_READY,
			$this,
			'handleVetoDraftCommunicationReady'
		);
	}


	private function unregisterVetoDraftEntryPoints() {
		if (!$this->maniaControl) {
			return;
		}

		$this->maniaControl->getCommandManager()->unregisterCommandListener($this);
		$this->maniaControl->getCommunicationManager()->unregisterCommunicationListener($this);
	}


	private function sendVetoDraftFeatureDisabledToPlayer(Player $player) {
		if (!$this->maniaControl) {
			return;
		}

		$this->maniaControl->getChat()->sendError('Map draft/veto feature is disabled.', $player);
	}


	private function buildVetoDraftFeatureDisabledCommunicationAnswer() {
		return new CommunicationAnswer(
			array(
				'success' => false,
				'code' => 'feature_disabled',
				'message' => 'Map draft/veto feature is disabled.',
			),
			true
		);
	}


	private function buildVetoDraftDisabledStatusCommunicationAnswer() {
		return new CommunicationAnswer(
			array(
				'enabled' => false,
				'status' => array(
					'active' => false,
					'mode' => '',
					'session' => array('status' => 'disabled'),
				),
			),
			false
		);
	}


	private function buildVetoDraftDefaultsSummaryLine() {
		return 'Draft defaults: mode=' . $this->vetoDraftDefaultMode
			. ', matchmaking_duration=' . $this->vetoDraftMatchmakingDurationSeconds . 's'
			. ', min_players=' . $this->vetoDraftMatchmakingAutostartMinPlayers
			. ', ready_armed=' . ($this->vetoDraftMatchmakingReadyArmed ? 'yes' : 'no')
			. ', launch_immediately=' . ($this->vetoDraftLaunchImmediately ? 'yes' : 'no')
			. '.';
	}


	private function sendVetoDraftDefaultsSummaryToPlayer(Player $player) {
		if (!$this->maniaControl) {
			return;
		}

		$this->maniaControl->getChat()->sendInformation($this->buildVetoDraftDefaultsSummaryLine(), $player);
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

		if ($normalizedMode !== VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
			$this->cancelMatchmakingAutostartPendingWindow('mode_changed', array(), false);
		}
		$this->vetoDraftMatchmakingAutostartArmed = true;
		$this->vetoDraftMatchmakingAutostartSuppressed = false;

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
