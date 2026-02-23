<?php

namespace PixelControl\Domain\VetoDraft;

use ManiaControl\Logger;
use PixelControl\VetoDraft\VetoDraftCatalog;

trait VetoDraftAutostartTrait {

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

		$startResult = $this->startMatchmakingSessionWithReadyGate(
			$source,
			$timestamp,
			$this->vetoDraftMatchmakingDurationSeconds
		);

		if (empty($startResult['success'])) {
			return $startResult;
		}

		return array(
			'success' => true,
			'code' => 'matchmaking_started',
			'message' => 'Configured matchmaking veto started.',
			'started' => true,
			'details' => $startResult,
			'source' => trim((string) $source),
		);
	}


	private function armMatchmakingReadyGate($source) {
		if (!$this->vetoDraftCoordinator || !$this->maniaControl) {
			return array(
				'success' => false,
				'code' => 'capability_unavailable',
				'message' => 'Map draft/veto capability is unavailable.',
			);
		}

		$statusSnapshot = $this->vetoDraftCoordinator->getStatusSnapshot();
		if (!empty($statusSnapshot['active'])) {
			return array(
				'success' => false,
				'code' => 'session_active',
				'message' => 'A draft/veto session is currently active. Arm matchmaking ready after the active session closes.',
			);
		}

		if ($this->vetoDraftMatchmakingReadyArmed) {
			return array(
				'success' => true,
				'code' => 'matchmaking_ready_already_armed',
				'message' => 'Matchmaking ready gate is already armed.',
				'armed' => true,
			);
		}

		$this->vetoDraftMatchmakingReadyArmed = true;
		$this->vetoDraftMatchmakingAutostartArmed = true;
		$this->vetoDraftMatchmakingAutostartSuppressed = false;

		Logger::log(
			'[PixelControl][veto][ready_gate][armed] source=' . trim((string) $source)
			. ', threshold=' . $this->vetoDraftMatchmakingAutostartMinPlayers
			. ', mode=' . $this->vetoDraftDefaultMode
			. '.'
		);

		return array(
			'success' => true,
			'code' => 'matchmaking_ready_armed',
			'message' => 'Matchmaking ready gate armed. Next matchmaking cycle can start automatically once normal conditions are met.',
			'armed' => true,
		);
	}


	private function startMatchmakingSessionWithReadyGate($source, $timestamp, $durationSeconds, array $mapPool = array()) {
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
				'success' => false,
				'code' => 'session_active',
				'message' => 'A draft/veto session is already running.',
			);
		}

		if (!$this->vetoDraftMatchmakingReadyArmed) {
			return array(
				'success' => false,
				'code' => 'matchmaking_ready_required',
				'message' => 'Matchmaking start requires explicit arming. Run //' . $this->vetoDraftCommandName . ' ready first.',
				'ready_armed' => false,
			);
		}

		$timestamp = max(0, (int) $timestamp);
		if (empty($mapPool)) {
			$mapPool = $this->vetoDraftMapPoolService->buildMapPool($this->maniaControl->getMapManager());
		}

		$startResult = $this->vetoDraftCoordinator->startMatchmaking(
			$mapPool,
			$durationSeconds,
			$timestamp
		);
		$this->syncVetoDraftTelemetryState();

		if (empty($startResult['success'])) {
			return $startResult;
		}

		$this->vetoDraftLastAppliedSessionId = '';
		$this->vetoDraftMatchmakingReadyArmed = false;
		$this->vetoDraftMatchmakingAutostartArmed = false;
		$this->vetoDraftMatchmakingAutostartSuppressed = false;
		$this->resetMatchmakingAutostartPendingWindow('session_started');
		$this->resetMatchmakingLifecycleContext('session_started', trim((string) $source), false);
		$this->broadcastVetoDraftSessionOverview();

		Logger::log(
			'[PixelControl][veto][ready_gate][consumed] source=' . trim((string) $source)
			. ', session=' . (isset($startResult['details']['session']['session_id']) ? (string) $startResult['details']['session']['session_id'] : 'unknown')
			. '.'
		);

		return $startResult;
	}


	private function evaluateMatchmakingAutostartThreshold($timestamp) {
		if (!$this->vetoDraftCoordinator || !$this->vetoDraftEnabled || !$this->maniaControl) {
			return;
		}

		$timestamp = max(0, (int) $timestamp);
		$guardState = $this->buildMatchmakingAutostartGuardState();
		$guardReason = isset($guardState['reason']) ? (string) $guardState['reason'] : '';

		if ($guardReason === 'mode_not_matchmaking') {
			$this->cancelMatchmakingAutostartPendingWindow('mode_not_matchmaking', $guardState, false);
			$this->vetoDraftMatchmakingAutostartArmed = true;
			$this->vetoDraftMatchmakingAutostartSuppressed = false;
			return;
		}

		if ($this->evaluatePendingMatchmakingAutostartWindow($timestamp, $guardState)) {
			return;
		}

		$connectedHumanPlayers = isset($guardState['connected_human_players'])
			? max(0, (int) $guardState['connected_human_players'])
			: 0;
		$threshold = isset($guardState['threshold'])
			? max(1, (int) $guardState['threshold'])
			: max(1, (int) $this->vetoDraftMatchmakingAutostartMinPlayers);

		if ($guardReason === 'session_active') {
			$this->vetoDraftMatchmakingAutostartArmed = false;
			$this->vetoDraftMatchmakingAutostartSuppressed = true;
			return;
		}

		if ($guardReason === 'below_threshold') {
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

		if ($guardReason === 'ready_gate_unarmed') {
			if ($this->vetoDraftMatchmakingAutostartArmed) {
				if (!$this->vetoDraftMatchmakingAutostartSuppressed) {
					$this->vetoDraftMatchmakingAutostartSuppressed = true;
					Logger::log(
						'[PixelControl][veto][autostart][ready_gate_waiting] connected=' . $connectedHumanPlayers
						. ', threshold=' . $threshold
						. ', command=//' . $this->vetoDraftCommandName . ' ready'
						. '.'
					);
				}

				$this->vetoDraftMatchmakingAutostartArmed = false;
				return;
			}

			if (!$this->vetoDraftMatchmakingAutostartSuppressed) {
				$this->vetoDraftMatchmakingAutostartSuppressed = true;
				Logger::log(
					'[PixelControl][veto][autostart][suppressed] connected=' . $connectedHumanPlayers
					. ', threshold=' . $threshold
					. ', reason=ready_gate_waiting.'
				);
			}
			return;
		}

		if (empty($guardState['eligible'])) {
			return;
		}

		if ($this->vetoDraftMatchmakingAutostartArmed) {
			$this->armMatchmakingAutostartPendingWindow($timestamp, 'timer_threshold', $connectedHumanPlayers, $threshold);
			$this->vetoDraftMatchmakingAutostartArmed = false;
			$this->vetoDraftMatchmakingAutostartSuppressed = true;
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


	private function buildMatchmakingAutostartGuardState() {
		$threshold = max(1, (int) $this->vetoDraftMatchmakingAutostartMinPlayers);
		$state = array(
			'eligible' => false,
			'reason' => 'conditions_unknown',
			'connected_human_players' => 0,
			'threshold' => $threshold,
			'ready_armed' => (bool) $this->vetoDraftMatchmakingReadyArmed,
		);

		if ($this->vetoDraftDefaultMode !== VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
			$state['reason'] = 'mode_not_matchmaking';
			return $state;
		}

		if ($this->vetoDraftCoordinator->hasActiveSession()) {
			$state['reason'] = 'session_active';
			return $state;
		}

		$connectedHumanPlayers = $this->countConnectedHumanPlayersForVetoAutoStart();
		$state['connected_human_players'] = $connectedHumanPlayers;

		if ($connectedHumanPlayers < $threshold) {
			$state['reason'] = 'below_threshold';
			return $state;
		}

		if (!$this->vetoDraftMatchmakingReadyArmed) {
			$state['reason'] = 'ready_gate_unarmed';
			return $state;
		}

		$state['eligible'] = true;
		$state['reason'] = 'eligible';

		return $state;
	}


	private function evaluatePendingMatchmakingAutostartWindow($timestamp, array $guardState) {
		if (!is_array($this->vetoDraftMatchmakingAutostartPending)) {
			return false;
		}

		$guardReason = isset($guardState['reason']) ? trim((string) $guardState['reason']) : '';
		if (empty($guardState['eligible'])) {
			$reason = ($guardReason !== '') ? $guardReason : 'conditions_invalid';
			$announceCancellation = ($reason === 'below_threshold' || $reason === 'ready_gate_unarmed');
			$this->cancelMatchmakingAutostartPendingWindow($reason, $guardState, $announceCancellation);
			return false;
		}

		$deadlineAt = isset($this->vetoDraftMatchmakingAutostartPending['deadline_at'])
			? max(0, (int) $this->vetoDraftMatchmakingAutostartPending['deadline_at'])
			: 0;
		if ($deadlineAt > 0 && $timestamp < $deadlineAt) {
			return true;
		}

		$connectedHumanPlayers = isset($guardState['connected_human_players']) ? max(0, (int) $guardState['connected_human_players']) : 0;
		$threshold = isset($guardState['threshold']) ? max(1, (int) $guardState['threshold']) : max(1, (int) $this->vetoDraftMatchmakingAutostartMinPlayers);
		$armedAt = isset($this->vetoDraftMatchmakingAutostartPending['armed_at'])
			? max(0, (int) $this->vetoDraftMatchmakingAutostartPending['armed_at'])
			: $timestamp;

		$startResult = $this->startConfiguredMatchmakingSession('timer_threshold', $timestamp);
		if (!empty($startResult['success'])) {
			$waitedSeconds = max(0, $timestamp - $armedAt);
			Logger::log(
				'[PixelControl][veto][autostart][triggered] connected=' . $connectedHumanPlayers
				. ', threshold=' . $threshold
				. ', prestart_delay=' . VetoDraftCatalog::MATCHMAKING_AUTOSTART_PRESTART_SECONDS
				. ', waited=' . $waitedSeconds
				. ', duration=' . $this->vetoDraftMatchmakingDurationSeconds
				. '.'
			);
			return true;
		}

		$this->cancelMatchmakingAutostartPendingWindow('launch_failed', $guardState, false);
		$this->vetoDraftMatchmakingAutostartArmed = false;
		$this->vetoDraftMatchmakingAutostartSuppressed = true;

		Logger::logWarning(
			'[PixelControl][veto][autostart][trigger_failed] connected=' . $connectedHumanPlayers
			. ', threshold=' . $threshold
			. ', code=' . (isset($startResult['code']) ? (string) $startResult['code'] : 'unknown')
			. ', message=' . (isset($startResult['message']) ? (string) $startResult['message'] : 'failed to start configured matchmaking veto')
			. ', phase=prestart_launch.'
		);

		return true;
	}


	private function armMatchmakingAutostartPendingWindow($timestamp, $source, $connectedHumanPlayers, $threshold) {
		$timestamp = max(0, (int) $timestamp);
		$source = trim((string) $source);
		$connectedHumanPlayers = max(0, (int) $connectedHumanPlayers);
		$threshold = max(1, (int) $threshold);
		$prestartSeconds = max(1, (int) VetoDraftCatalog::MATCHMAKING_AUTOSTART_PRESTART_SECONDS);
		$deadlineAt = $timestamp + $prestartSeconds;

		$this->vetoDraftMatchmakingAutostartPending = array(
			'source' => ($source !== '' ? $source : 'timer_threshold'),
			'armed_at' => $timestamp,
			'deadline_at' => $deadlineAt,
			'notice_announced' => false,
			'cancelled' => false,
			'cancel_reason' => '',
		);

		$this->maniaControl->getChat()->sendInformation('[PixelControl] Matchmaking veto starts in ' . $prestartSeconds . 's.', null);
		$this->vetoDraftMatchmakingAutostartPending['notice_announced'] = true;

		Logger::log(
			'[PixelControl][veto][autostart][prestart_armed] connected=' . $connectedHumanPlayers
			. ', threshold=' . $threshold
			. ', armed_at=' . $timestamp
			. ', deadline=' . $deadlineAt
			. ', delay=' . $prestartSeconds
			. '.'
		);
	}


	private function cancelMatchmakingAutostartPendingWindow($reason, array $guardState = array(), $announceInChat = true) {
		if (!is_array($this->vetoDraftMatchmakingAutostartPending)) {
			return;
		}

		$reason = trim((string) $reason);
		if ($reason === '') {
			$reason = 'conditions_invalid';
		}

		$pendingSnapshot = $this->vetoDraftMatchmakingAutostartPending;
		$connectedHumanPlayers = isset($guardState['connected_human_players'])
			? max(0, (int) $guardState['connected_human_players'])
			: $this->countConnectedHumanPlayersForVetoAutoStart();
		$threshold = isset($guardState['threshold'])
			? max(1, (int) $guardState['threshold'])
			: max(1, (int) $this->vetoDraftMatchmakingAutostartMinPlayers);

		$pendingSnapshot['cancelled'] = true;
		$pendingSnapshot['cancel_reason'] = $reason;
		$this->vetoDraftMatchmakingAutostartPending = null;
		$this->vetoDraftMatchmakingAutostartLastCancellation = $reason;

		Logger::log(
			'[PixelControl][veto][autostart][prestart_cancelled] reason=' . $reason
			. ', connected=' . $connectedHumanPlayers
			. ', threshold=' . $threshold
			. ', armed_at=' . (isset($pendingSnapshot['armed_at']) ? (int) $pendingSnapshot['armed_at'] : 0)
			. ', deadline=' . (isset($pendingSnapshot['deadline_at']) ? (int) $pendingSnapshot['deadline_at'] : 0)
			. '.'
		);

		if ($announceInChat) {
			$this->maniaControl->getChat()->sendInformation('[PixelControl] Matchmaking veto auto-start cancelled (conditions changed).', null);
		}
	}


	private function resetMatchmakingAutostartPendingWindow($reason = '') {
		if (!is_array($this->vetoDraftMatchmakingAutostartPending)) {
			return;
		}

		$this->vetoDraftMatchmakingAutostartPending = null;
		if (trim((string) $reason) === 'session_started') {
			$this->vetoDraftMatchmakingAutostartLastCancellation = '';
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

}
