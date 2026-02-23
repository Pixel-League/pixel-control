<?php

namespace PixelControl\Domain\VetoDraft;

use ManiaControl\Logger;
use ManiaControl\Players\Player;
use PixelControl\VetoDraft\ManiaControlMapRuntimeAdapter;
use PixelControl\VetoDraft\MatchmakingLifecycleCatalog;
use PixelControl\VetoDraft\VetoDraftCatalog;

trait VetoDraftLifecycleTrait {

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
			$this->vetoDraftMatchmakingAutostartArmed = false;
			$this->vetoDraftMatchmakingAutostartSuppressed = false;
			$this->vetoDraftMatchmakingReadyArmed = false;
			Logger::log(
				'[PixelControl][veto][matchmaking_lifecycle][ready] session=' . (isset($snapshot['session_id']) ? (string) $snapshot['session_id'] : 'unknown')
				. ', source=' . ($source !== '' ? $source : 'unknown')
				. ', reason=' . ($snapshot['resolution_reason'] !== '' ? (string) $snapshot['resolution_reason'] : 'completed')
				. ', matchmaking_ready_armed=no'
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
				'skipped' => isset($kickResult['skipped_count']) ? (int) $kickResult['skipped_count'] : 0,
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
				'skipped_count' => 0,
				'failed_logins' => array(),
				'skipped_logins' => array(),
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
		$skippedCount = 0;
		$failedLogins = array();
		$skippedLogins = array();
		$attemptedLogins = array();

		foreach ($players as $player) {
			$cleanupEligibility = $this->resolveMatchmakingLifecyclePlayerCleanupEligibility($player);
			$playerLogin = isset($cleanupEligibility['login']) ? trim((string) $cleanupEligibility['login']) : '';
			$eligibilityReason = isset($cleanupEligibility['reason']) ? (string) $cleanupEligibility['reason'] : 'unknown';
			$classification = isset($cleanupEligibility['classification']) ? (string) $cleanupEligibility['classification'] : 'unknown';

			if (empty($cleanupEligibility['eligible'])) {
				$skippedCount++;
				$skippedLogins[] = array(
					'login' => ($playerLogin !== '' ? $playerLogin : 'unknown'),
					'reason' => $eligibilityReason,
					'classification' => $classification,
				);

				Logger::log(
					'[PixelControl][veto][matchmaking_lifecycle][kick_skipped] login=' . ($playerLogin !== '' ? $playerLogin : 'unknown')
					. ', reason=' . $eligibilityReason
					. ', classification=' . $classification
					. '.'
				);
				continue;
			}

			$attemptedCount++;
			$attemptedLogins[] = $playerLogin;

			try {
				$this->maniaControl->getClient()->disconnectFakePlayer($playerLogin);
				$succeededCount++;

				Logger::log(
					'[PixelControl][veto][matchmaking_lifecycle][kick_applied] login=' . $playerLogin
					. ', method=disconnectFakePlayer'
					. ', reason=' . $eligibilityReason
					. ', classification=' . $classification
					. ', success=yes.'
				);
			} catch (\Throwable $throwable) {
				$failedCount++;
				$failedLogins[] = array(
					'login' => $playerLogin,
					'error' => $throwable->getMessage(),
				);

				Logger::logWarning(
					'[PixelControl][veto][matchmaking_lifecycle][kick_applied] login=' . $playerLogin
					. ', method=disconnectFakePlayer'
					. ', reason=' . $eligibilityReason
					. ', classification=' . $classification
					. ', success=no'
					. ', error=' . $throwable->getMessage()
					. '.'
				);
			}
		}

		$success = ($failedCount === 0);

		return array(
			'attempted' => true,
			'success' => $success,
			'code' => $success ? 'kick_all_completed' : 'kick_all_partial_failure',
			'message' => $success
				? 'Lifecycle cleanup routine completed.'
				: 'Lifecycle cleanup routine completed with one or more failures.',
			'attempted_count' => $attemptedCount,
			'succeeded_count' => $succeededCount,
			'failed_count' => $failedCount,
			'skipped_count' => $skippedCount,
			'attempted_logins' => $attemptedLogins,
			'failed_logins' => $failedLogins,
			'skipped_logins' => $skippedLogins,
			'cleanup_policy' => 'fake_players_only',
			'observed_at' => time(),
		);
	}


	private function resolveMatchmakingLifecyclePlayerCleanupEligibility($player) {
		if (!$player instanceof Player) {
			return array(
				'eligible' => false,
				'login' => '',
				'reason' => 'invalid_player_instance',
				'classification' => 'unknown',
			);
		}

		$playerLogin = isset($player->login) ? trim((string) $player->login) : '';
		if ($playerLogin === '') {
			return array(
				'eligible' => false,
				'login' => '',
				'reason' => 'missing_login',
				'classification' => 'unknown',
			);
		}

		$isFakePlayer = null;
		if (method_exists($player, 'isFakePlayer')) {
			try {
				$isFakePlayer = (bool) $player->isFakePlayer();
			} catch (\Throwable $throwable) {
				$isFakePlayer = null;
			}
		}

		if ($isFakePlayer === null && isset($player->pid) && is_numeric($player->pid)) {
			$isFakePlayer = ((int) $player->pid <= 0);
		}

		if ($isFakePlayer === null && strpos($playerLogin, '*') === 0) {
			$isFakePlayer = true;
		}

		if ($isFakePlayer === true) {
			return array(
				'eligible' => true,
				'login' => $playerLogin,
				'reason' => 'safe_fake_player',
				'classification' => 'fake_player',
			);
		}

		if ($isFakePlayer === false) {
			return array(
				'eligible' => false,
				'login' => $playerLogin,
				'reason' => 'human_player_protected',
				'classification' => 'human_player',
			);
		}

		return array(
			'eligible' => false,
			'login' => $playerLogin,
			'reason' => 'player_identity_unclassified',
			'classification' => 'unknown',
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

}
