<?php

namespace PixelControl\Domain\VetoDraft;

use ManiaControl\Communication\CommunicationAnswer;
use ManiaControl\Logger;
use ManiaControl\Players\Player;
use PixelControl\VetoDraft\ManiaControlMapRuntimeAdapter;
use PixelControl\VetoDraft\MatchmakingLifecycleCatalog;
use PixelControl\VetoDraft\VetoDraftCatalog;

trait VetoDraftIngressTrait {

	public function handleVetoDraftCommand(array $chatCallback, Player $player) {
		if (!$this->vetoDraftEnabled || !$this->vetoDraftCoordinator) {
			$this->sendVetoDraftFeatureDisabledToPlayer($player);
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
				$this->sendVetoDraftDefaultsSummaryToPlayer($player);
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
				$this->sendVetoDraftDefaultsSummaryToPlayer($player);
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
				$this->sendVetoDraftDefaultsSummaryToPlayer($player);
			return;

			case 'ready':
				if (!$this->hasVetoControlPermission($player)) {
					$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
					return;
				}

				$readyResult = $this->armMatchmakingReadyGate('chat_ready');
				if (empty($readyResult['success'])) {
					$this->maniaControl->getChat()->sendError(isset($readyResult['message']) ? (string) $readyResult['message'] : 'Unable to arm matchmaking ready gate.', $player);
					return;
				}

				$this->maniaControl->getChat()->sendSuccess(isset($readyResult['message']) ? (string) $readyResult['message'] : 'Matchmaking ready gate armed.', $player);
				$this->sendVetoDraftDefaultsSummaryToPlayer($player);
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
				$startedMode = '';
				if (isset($startResult['details']) && is_array($startResult['details']) && isset($startResult['details']['session']) && is_array($startResult['details']['session']) && isset($startResult['details']['session']['mode'])) {
					$startedMode = (string) $startResult['details']['session']['mode'];
				}

				if ($startedMode !== VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
					$this->vetoDraftLastAppliedSessionId = '';
					$this->resetMatchmakingLifecycleContext('session_started', 'chat_start', false);
					$this->broadcastVetoDraftSessionOverview();
				}
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
			return $this->buildVetoDraftFeatureDisabledCommunicationAnswer();
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
			$result = $this->startMatchmakingSessionWithReadyGate('communication_start', time(), $durationSeconds, $mapPool);
		}

		$this->syncVetoDraftTelemetryState();

		if (isset($result['success']) && $result['success']) {
			$startedMode = '';
			if (isset($result['details']) && is_array($result['details']) && isset($result['details']['session']) && is_array($result['details']['session']) && isset($result['details']['session']['mode'])) {
				$startedMode = (string) $result['details']['session']['mode'];
			}

			if ($startedMode !== VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
				$this->vetoDraftLastAppliedSessionId = '';
				$this->resetMatchmakingLifecycleContext('session_started', 'communication_start', false);
				$this->broadcastVetoDraftSessionOverview();
			}
		}

		return new CommunicationAnswer($result, empty($result['success']));
	}


	public function handleVetoDraftCommunicationAction($data) {
		if (!$this->vetoDraftEnabled || !$this->vetoDraftCoordinator) {
			return $this->buildVetoDraftFeatureDisabledCommunicationAnswer();
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
			return $this->buildVetoDraftDisabledStatusCommunicationAnswer();
		}

		return new CommunicationAnswer(array(
			'enabled' => true,
			'command' => $this->vetoDraftCommandName,
			'default_mode' => $this->vetoDraftDefaultMode,
			'matchmaking_duration_seconds' => $this->vetoDraftMatchmakingDurationSeconds,
			'matchmaking_autostart_min_players' => $this->vetoDraftMatchmakingAutostartMinPlayers,
			'matchmaking_ready_armed' => $this->vetoDraftMatchmakingReadyArmed,
			'launch_immediately' => $this->vetoDraftLaunchImmediately,
			'series_targets' => $this->getSeriesControlSnapshot(),
			'matchmaking_lifecycle' => $this->buildMatchmakingLifecycleStatusSnapshot(),
			'communication' => array(
				'start' => VetoDraftCatalog::COMMUNICATION_START,
				'action' => VetoDraftCatalog::COMMUNICATION_ACTION,
				'status' => VetoDraftCatalog::COMMUNICATION_STATUS,
				'cancel' => VetoDraftCatalog::COMMUNICATION_CANCEL,
				'ready' => VetoDraftCatalog::COMMUNICATION_READY,
			),
			'status' => $this->vetoDraftCoordinator->getStatusSnapshot(),
		), false);
	}


	public function handleVetoDraftCommunicationCancel($data) {
		if (!$this->vetoDraftEnabled || !$this->vetoDraftCoordinator) {
			return $this->buildVetoDraftFeatureDisabledCommunicationAnswer();
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


	public function handleVetoDraftCommunicationReady($data) {
		if (!$this->vetoDraftEnabled || !$this->vetoDraftCoordinator) {
			return $this->buildVetoDraftFeatureDisabledCommunicationAnswer();
		}

		$result = $this->armMatchmakingReadyGate('communication_ready');
		$this->syncVetoDraftTelemetryState();

		return new CommunicationAnswer($result, empty($result['success']));
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
		return $this->startMatchmakingSessionWithReadyGate('chat_start', time(), $durationSeconds, $mapPool);
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
			$adminHelpLines[] = 'Admin defaults: //' . $this->vetoDraftCommandName . ' mode <matchmaking|tournament> | min_players <int> | ready.';
		} else {
			$adminHelpLines[] = 'Admin matchmaking: //' . $this->vetoDraftCommandName . ' ready, then //' . $this->vetoDraftCommandName . ' start matchmaking [duration=60] [launch=1].';
			$adminHelpLines[] = 'Admin defaults: //' . $this->vetoDraftCommandName . ' mode <matchmaking|tournament> | duration <seconds> | min_players <int> | ready.';
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
			. ', ready_armed=' . ($this->vetoDraftMatchmakingReadyArmed ? 'yes' : 'no')
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
			if ($this->vetoDraftMatchmakingReadyArmed) {
				$this->maniaControl->getChat()->sendInformation(
					'No active session: ready gate armed; threshold auto-start waits for '
					. $this->vetoDraftMatchmakingAutostartMinPlayers
					. ' connected player(s) (currently '
					. $connectedHumanPlayers
					. '). You can also use /'
					. $this->vetoDraftCommandName
					. ' vote <index|uid> to start immediately.',
					$player
				);
			} else {
				$this->maniaControl->getChat()->sendInformation(
					'No active session: matchmaking ready gate is idle. Run //'
					. $this->vetoDraftCommandName
					. ' ready, then threshold/vote flow can auto-start.',
					$player
				);
			}
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
		$this->sendVetoDraftDefaultsSummaryToPlayer($player);
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

}
