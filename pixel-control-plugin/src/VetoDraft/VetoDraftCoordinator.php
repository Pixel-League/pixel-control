<?php

namespace PixelControl\VetoDraft;

class VetoDraftCoordinator {
	/** @var MapPoolService $mapPoolService */
	private $mapPoolService;
	/** @var TournamentSequenceBuilder $sequenceBuilder */
	private $sequenceBuilder;

	/** @var MatchmakingVoteSession|null $matchmakingSession */
	private $matchmakingSession = null;
	/** @var TournamentDraftSession|null $tournamentSession */
	private $tournamentSession = null;

	/** @var array|null $latestSessionSnapshot */
	private $latestSessionSnapshot = null;
	/** @var string $latestMode */
	private $latestMode = '';
	/** @var array $matchmakingCountdownAnnouncements */
	private $matchmakingCountdownAnnouncements = array();
	/** @var array $matchmakingCountdownLastRemaining */
	private $matchmakingCountdownLastRemaining = array();

	public function __construct(MapPoolService $mapPoolService, TournamentSequenceBuilder $sequenceBuilder) {
		$this->mapPoolService = $mapPoolService;
		$this->sequenceBuilder = $sequenceBuilder;
	}

	public function reset() {
		$this->matchmakingSession = null;
		$this->tournamentSession = null;
		$this->latestSessionSnapshot = null;
		$this->latestMode = '';
		$this->matchmakingCountdownAnnouncements = array();
		$this->matchmakingCountdownLastRemaining = array();
	}

	public function hasActiveSession() {
		return ($this->matchmakingSession && $this->matchmakingSession->isRunning())
			|| ($this->tournamentSession && $this->tournamentSession->isRunning());
	}

	public function startMatchmaking(array $mapPool, $durationSeconds, $timestamp) {
		if ($this->hasActiveSession()) {
			return $this->failure('session_active', 'A draft/veto session is already running.');
		}

		$timestamp = max(0, (int) $timestamp);
		$durationSeconds = VetoDraftCatalog::sanitizePositiveInt(
			$durationSeconds,
			VetoDraftCatalog::DEFAULT_MATCHMAKING_DURATION_SECONDS,
			10
		);

		if (count($mapPool) < 2) {
			return $this->failure('map_pool_too_small', 'At least two maps are required to start matchmaking vote.');
		}

		$sessionId = $this->buildSessionId(VetoDraftCatalog::MODE_MATCHMAKING_VOTE, $timestamp);
		$this->matchmakingSession = new MatchmakingVoteSession($sessionId, $mapPool, $durationSeconds, $timestamp);
		$this->matchmakingSession->resetVoteCounters();
		$this->tournamentSession = null;
		$this->matchmakingCountdownAnnouncements[$sessionId] = array();
		$this->matchmakingCountdownLastRemaining[$sessionId] = $durationSeconds;
		$sessionSnapshot = $this->matchmakingSession->toArray();
		if (!$this->allVoteTotalsAreZero($sessionSnapshot)) {
			$this->matchmakingSession->resetVoteCounters();
			$sessionSnapshot = $this->matchmakingSession->toArray();
		}

		return $this->success(
			'matchmaking_started',
			'Matchmaking vote has started.',
			array(
				'session' => $sessionSnapshot,
				'vote_totals_reset' => $this->allVoteTotalsAreZero($sessionSnapshot),
			)
		);
	}

	public function startTournament(array $mapPool, $captainATeamLogin, $captainBTeamLogin, $bestOf, $banStarterPolicy, $actionTimeoutSeconds, $timestamp) {
		if ($this->hasActiveSession()) {
			return $this->failure('session_active', 'A draft/veto session is already running.');
		}

		$captainATeamLogin = strtolower(trim((string) $captainATeamLogin));
		$captainBTeamLogin = strtolower(trim((string) $captainBTeamLogin));
		if ($captainATeamLogin === '' || $captainBTeamLogin === '') {
			return $this->failure('captain_missing', 'Both team captains are required for tournament draft.');
		}

		if ($captainATeamLogin === $captainBTeamLogin) {
			return $this->failure('captain_conflict', 'Team A and Team B captains must be different players.');
		}

		$bestOf = VetoDraftCatalog::sanitizeBestOf($bestOf, VetoDraftCatalog::DEFAULT_BEST_OF);
		if (count($mapPool) < $bestOf) {
			return $this->failure('map_pool_too_small_for_bo', 'Map pool is too small for requested best-of.', array('best_of' => $bestOf, 'map_pool_size' => count($mapPool)));
		}

		$timestamp = max(0, (int) $timestamp);
		$banStarterTeam = VetoDraftCatalog::normalizeStarterTeam($banStarterPolicy, VetoDraftCatalog::TEAM_A);
		$pickStarterTeam = VetoDraftCatalog::oppositeTeam($banStarterTeam);

		$sequenceDefinition = $this->sequenceBuilder->buildSequence(count($mapPool), $bestOf, $banStarterTeam, $pickStarterTeam);
		if (empty($sequenceDefinition['valid'])) {
			return $this->failure(
				'sequence_invalid',
				'Cannot generate tournament draft sequence.',
				array('sequence' => $sequenceDefinition)
			);
		}

		$captains = array(
			VetoDraftCatalog::TEAM_A => $captainATeamLogin,
			VetoDraftCatalog::TEAM_B => $captainBTeamLogin,
		);

		$this->tournamentSession = new TournamentDraftSession(
			$this->buildSessionId(VetoDraftCatalog::MODE_TOURNAMENT_DRAFT, $timestamp),
			$mapPool,
			$captains,
			$sequenceDefinition,
			$actionTimeoutSeconds,
			$timestamp
		);
		$this->matchmakingSession = null;
		$this->matchmakingCountdownAnnouncements = array();
		$this->matchmakingCountdownLastRemaining = array();

		$sessionSnapshot = $this->tournamentSession->toArray();
		if (isset($sessionSnapshot['status']) && $sessionSnapshot['status'] !== VetoDraftCatalog::STATUS_RUNNING) {
			$this->latestSessionSnapshot = $sessionSnapshot;
			$this->latestMode = VetoDraftCatalog::MODE_TOURNAMENT_DRAFT;
			$this->tournamentSession = null;
			return $this->failure('session_initialization_failed', 'Failed to initialize tournament draft session.', array('session' => $sessionSnapshot));
		}

		return $this->success('tournament_started', 'Tournament draft has started.', array('session' => $sessionSnapshot));
	}

	public function castMatchmakingVote($playerLogin, $mapSelection, $timestamp) {
		if (!$this->matchmakingSession || !$this->matchmakingSession->isRunning()) {
			return $this->failure('matchmaking_not_running', 'No active matchmaking vote session.');
		}

		$mapUid = $this->mapPoolService->resolveMapUidFromSelection($this->matchmakingSession->getMapPool(), $mapSelection);
		if ($mapUid === '') {
			return $this->failure('map_unknown', 'Unable to resolve map selection.');
		}

		$voteResult = $this->matchmakingSession->castVote($playerLogin, $mapUid, $timestamp);
		if (empty($voteResult['success'])) {
			return $this->failure(
				isset($voteResult['code']) ? (string) $voteResult['code'] : 'vote_failed',
				isset($voteResult['message']) ? (string) $voteResult['message'] : 'Failed to record vote.',
				$voteResult
			);
		}

		$details = $voteResult;
		$details['session'] = $this->matchmakingSession->toArray();
		return $this->success('vote_recorded', isset($voteResult['message']) ? (string) $voteResult['message'] : 'Vote recorded.', $details);
	}

	public function applyTournamentAction($playerLogin, $mapSelection, $timestamp, $source, $allowOverride) {
		if (!$this->tournamentSession || !$this->tournamentSession->isRunning()) {
			return $this->failure('tournament_not_running', 'No active tournament draft session.');
		}

		$sessionSnapshot = $this->tournamentSession->toArray();
		$availableMaps = isset($sessionSnapshot['available_maps']) && is_array($sessionSnapshot['available_maps'])
			? $sessionSnapshot['available_maps']
			: array();
		$mapUid = $this->mapPoolService->resolveMapUidFromSelection($availableMaps, $mapSelection);
		if ($mapUid === '') {
			return $this->failure('map_unknown', 'Unable to resolve map selection.');
		}

		$actionResult = $this->tournamentSession->applyAction($playerLogin, $mapUid, $timestamp, $source, $allowOverride);
		if (empty($actionResult['success'])) {
			return $this->failure(
				isset($actionResult['code']) ? (string) $actionResult['code'] : 'action_failed',
				isset($actionResult['message']) ? (string) $actionResult['message'] : 'Tournament action failed.',
				$actionResult
			);
		}

		$this->promoteLatestSnapshotIfFinalized();

		return $this->success(
			'tournament_action_applied',
			isset($actionResult['message']) ? (string) $actionResult['message'] : 'Tournament action applied.',
			$actionResult
		);
	}

	public function tick($timestamp) {
		$timestamp = max(0, (int) $timestamp);
		$events = array();
		$countdownEvents = $this->collectMatchmakingCountdownEvents($timestamp);
		if (!empty($countdownEvents)) {
			$events = array_merge($events, $countdownEvents);
		}

		if ($this->matchmakingSession && $this->matchmakingSession->isRunning() && $this->matchmakingSession->isExpired($timestamp)) {
			$finalSnapshot = $this->matchmakingSession->finalize($timestamp);
			$this->forgetMatchmakingCountdownState(isset($finalSnapshot['session_id']) ? (string) $finalSnapshot['session_id'] : '');
			$events[] = array(
				'type' => 'matchmaking_completed',
				'mode' => VetoDraftCatalog::MODE_MATCHMAKING_VOTE,
				'snapshot' => $finalSnapshot,
				'map_order' => $this->buildMapOrderFromSnapshot($finalSnapshot),
			);
			$this->latestSessionSnapshot = $finalSnapshot;
			$this->latestMode = VetoDraftCatalog::MODE_MATCHMAKING_VOTE;
			$this->matchmakingSession = null;
		}

		if ($this->tournamentSession && $this->tournamentSession->isRunning() && $this->tournamentSession->isTurnTimedOut($timestamp)) {
			$timeoutResult = $this->tournamentSession->applyTimeoutFallback($timestamp);
			$events[] = array(
				'type' => 'tournament_timeout_auto_action',
				'mode' => VetoDraftCatalog::MODE_TOURNAMENT_DRAFT,
				'result' => $timeoutResult,
				'snapshot' => $this->tournamentSession->toArray(),
			);
		}

		$this->promoteLatestSnapshotIfFinalized();

		return array(
			'events' => $events,
			'status' => $this->getStatusSnapshot(),
		);
	}

	public function cancelActiveSession($timestamp, $reason) {
		$timestamp = max(0, (int) $timestamp);
		$reason = trim((string) $reason);

		if ($this->matchmakingSession && $this->matchmakingSession->isRunning()) {
			$sessionSnapshot = $this->matchmakingSession->cancel($timestamp, $reason !== '' ? $reason : 'cancelled_by_operator');
			$this->forgetMatchmakingCountdownState(isset($sessionSnapshot['session_id']) ? (string) $sessionSnapshot['session_id'] : '');
			$this->latestSessionSnapshot = $sessionSnapshot;
			$this->latestMode = VetoDraftCatalog::MODE_MATCHMAKING_VOTE;
			$this->matchmakingSession = null;
			return $this->success('session_cancelled', 'Matchmaking vote cancelled.', array('session' => $sessionSnapshot));
		}

		if ($this->tournamentSession && $this->tournamentSession->isRunning()) {
			$sessionSnapshot = $this->tournamentSession->cancel($timestamp, $reason !== '' ? $reason : 'cancelled_by_operator');
			$this->latestSessionSnapshot = $sessionSnapshot;
			$this->latestMode = VetoDraftCatalog::MODE_TOURNAMENT_DRAFT;
			$this->tournamentSession = null;
			return $this->success('session_cancelled', 'Tournament draft cancelled.', array('session' => $sessionSnapshot));
		}

		return $this->failure('session_not_running', 'No active draft/veto session to cancel.');
	}

	public function getStatusSnapshot() {
		if ($this->matchmakingSession && $this->matchmakingSession->isRunning()) {
			return array(
				'active' => true,
				'mode' => VetoDraftCatalog::MODE_MATCHMAKING_VOTE,
				'session' => $this->matchmakingSession->toArray(),
			);
		}

		if ($this->tournamentSession && $this->tournamentSession->isRunning()) {
			return array(
				'active' => true,
				'mode' => VetoDraftCatalog::MODE_TOURNAMENT_DRAFT,
				'session' => $this->tournamentSession->toArray(),
			);
		}

		if ($this->latestSessionSnapshot) {
			return array(
				'active' => false,
				'mode' => $this->latestMode,
				'session' => $this->latestSessionSnapshot,
			);
		}

		return array(
			'active' => false,
			'mode' => '',
			'session' => array(
				'mode' => '',
				'status' => VetoDraftCatalog::STATUS_IDLE,
			),
		);
	}

	public function buildCompatibilitySnapshots($featureEnabled) {
		$statusSnapshot = $this->getStatusSnapshot();
		$sessionSnapshot = isset($statusSnapshot['session']) && is_array($statusSnapshot['session'])
			? $statusSnapshot['session']
			: array();
		$mode = isset($statusSnapshot['mode']) ? (string) $statusSnapshot['mode'] : '';
		$sessionStatus = isset($sessionSnapshot['status']) ? (string) $sessionSnapshot['status'] : VetoDraftCatalog::STATUS_IDLE;

		$actions = array();
		$result = array(
			'status' => 'unavailable',
			'reason' => $featureEnabled ? 'feature_idle' : 'feature_disabled',
		);

		if ($mode === VetoDraftCatalog::MODE_MATCHMAKING_VOTE) {
			$actions = $this->buildMatchmakingActionsFromSnapshot($sessionSnapshot);
			if ($sessionStatus === VetoDraftCatalog::STATUS_RUNNING) {
				$result = array(
					'status' => 'running',
					'mode' => $mode,
					'reason' => 'vote_window_active',
					'vote_totals' => isset($sessionSnapshot['vote_totals']) ? $sessionSnapshot['vote_totals'] : array(),
				);
			} else if ($sessionStatus === VetoDraftCatalog::STATUS_COMPLETED) {
				$result = array(
					'status' => 'completed',
					'mode' => $mode,
					'reason' => isset($sessionSnapshot['resolution_reason']) ? $sessionSnapshot['resolution_reason'] : 'completed',
					'final_map' => isset($sessionSnapshot['winner_map']) ? $sessionSnapshot['winner_map'] : null,
					'vote_totals' => isset($sessionSnapshot['vote_totals']) ? $sessionSnapshot['vote_totals'] : array(),
					'tie_break_applied' => !empty($sessionSnapshot['tie_break_applied']),
				);
			} else if ($sessionStatus === VetoDraftCatalog::STATUS_CANCELLED) {
				$result = array(
					'status' => 'cancelled',
					'mode' => $mode,
					'reason' => isset($sessionSnapshot['resolution_reason']) ? $sessionSnapshot['resolution_reason'] : 'cancelled',
				);
			}
		}

		if ($mode === VetoDraftCatalog::MODE_TOURNAMENT_DRAFT) {
			$actions = isset($sessionSnapshot['actions']) && is_array($sessionSnapshot['actions'])
				? $sessionSnapshot['actions']
				: array();
			if ($sessionStatus === VetoDraftCatalog::STATUS_RUNNING) {
				$result = array(
					'status' => 'running',
					'mode' => $mode,
					'reason' => 'draft_turns_active',
					'current_step' => isset($sessionSnapshot['current_step']) ? $sessionSnapshot['current_step'] : null,
					'series_map_order' => isset($sessionSnapshot['series_map_order']) ? $sessionSnapshot['series_map_order'] : array(),
				);
			} else if ($sessionStatus === VetoDraftCatalog::STATUS_COMPLETED) {
				$result = array(
					'status' => 'completed',
					'mode' => $mode,
					'reason' => isset($sessionSnapshot['resolution_reason']) ? $sessionSnapshot['resolution_reason'] : 'completed',
					'series_map_order' => isset($sessionSnapshot['series_map_order']) ? $sessionSnapshot['series_map_order'] : array(),
					'decider_map' => isset($sessionSnapshot['decider_map']) ? $sessionSnapshot['decider_map'] : null,
				);
			} else if ($sessionStatus === VetoDraftCatalog::STATUS_CANCELLED) {
				$result = array(
					'status' => 'cancelled',
					'mode' => $mode,
					'reason' => isset($sessionSnapshot['resolution_reason']) ? $sessionSnapshot['resolution_reason'] : 'cancelled',
				);
			}
		}

		$actionsSnapshot = array(
			'available' => $featureEnabled,
			'status' => $featureEnabled ? $sessionStatus : 'unavailable',
			'reason' => $featureEnabled
				? (isset($result['reason']) ? $result['reason'] : 'feature_idle')
				: 'feature_disabled',
			'action_count' => count($actions),
			'supported_action_kinds' => array(
				VetoDraftCatalog::ACTION_BAN,
				VetoDraftCatalog::ACTION_PICK,
				VetoDraftCatalog::ACTION_PASS,
				VetoDraftCatalog::ACTION_LOCK,
			),
			'actions' => $actions,
			'field_availability' => array(
				'action_kind' => true,
				'map_uid' => true,
				'map_name' => true,
				'actor_login' => true,
				'actor_team' => true,
			),
			'missing_fields' => array(),
		);

		return array(
			'actions' => $actionsSnapshot,
			'result' => $result,
			'mode' => $mode,
			'status' => $sessionStatus,
			'session' => $sessionSnapshot,
		);
	}

	private function promoteLatestSnapshotIfFinalized() {
		if ($this->matchmakingSession) {
			$sessionSnapshot = $this->matchmakingSession->toArray();
			$sessionStatus = isset($sessionSnapshot['status']) ? (string) $sessionSnapshot['status'] : '';
			if ($sessionStatus === VetoDraftCatalog::STATUS_COMPLETED || $sessionStatus === VetoDraftCatalog::STATUS_CANCELLED) {
				$this->forgetMatchmakingCountdownState(isset($sessionSnapshot['session_id']) ? (string) $sessionSnapshot['session_id'] : '');
				$this->latestSessionSnapshot = $sessionSnapshot;
				$this->latestMode = VetoDraftCatalog::MODE_MATCHMAKING_VOTE;
				$this->matchmakingSession = null;
			}
		}

		if ($this->tournamentSession) {
			$sessionSnapshot = $this->tournamentSession->toArray();
			$sessionStatus = isset($sessionSnapshot['status']) ? (string) $sessionSnapshot['status'] : '';
			if ($sessionStatus === VetoDraftCatalog::STATUS_COMPLETED || $sessionStatus === VetoDraftCatalog::STATUS_CANCELLED) {
				$this->latestSessionSnapshot = $sessionSnapshot;
				$this->latestMode = VetoDraftCatalog::MODE_TOURNAMENT_DRAFT;
				$this->tournamentSession = null;
			}
		}
	}

	private function buildMatchmakingActionsFromSnapshot(array $sessionSnapshot) {
		if (!isset($sessionSnapshot['status']) || (string) $sessionSnapshot['status'] !== VetoDraftCatalog::STATUS_COMPLETED) {
			return array();
		}

		$winnerMap = isset($sessionSnapshot['winner_map']) && is_array($sessionSnapshot['winner_map'])
			? $sessionSnapshot['winner_map']
			: null;
		if (!$winnerMap) {
			return array();
		}

		return array(
			array(
				'order_index' => 1,
				'phase' => 'vote_result',
				'action_kind' => VetoDraftCatalog::ACTION_PICK,
				'action_status' => 'explicit',
				'action_source' => 'matchmaking_vote_result',
				'raw_action_value' => 'vote_winner',
				'source_callback' => 'PixelControl.VetoDraft.Matchmaking.Result',
				'source_channel' => 'feature.veto_draft.matchmaking',
				'observed_at' => isset($sessionSnapshot['resolved_at']) ? (int) $sessionSnapshot['resolved_at'] : 0,
				'actor' => array(
					'login' => 'all_players',
					'team' => 'crowd',
				),
				'map' => array(
					'uid' => isset($winnerMap['uid']) ? (string) $winnerMap['uid'] : '',
					'name' => isset($winnerMap['name']) ? (string) $winnerMap['name'] : '',
				),
				'field_availability' => array(
					'map_uid' => true,
					'map_name' => true,
					'actor_login' => true,
					'actor_team' => true,
					'action_kind' => true,
				),
				'missing_fields' => array(),
			),
		);
	}

	private function buildMapOrderFromSnapshot(array $sessionSnapshot) {
		$mapOrder = array();

		if (isset($sessionSnapshot['winner_map_uid']) && trim((string) $sessionSnapshot['winner_map_uid']) !== '') {
			$mapOrder[] = trim((string) $sessionSnapshot['winner_map_uid']);
			return $mapOrder;
		}

		if (!isset($sessionSnapshot['series_map_order']) || !is_array($sessionSnapshot['series_map_order'])) {
			return $mapOrder;
		}

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

		return $mapOrder;
	}

	private function collectMatchmakingCountdownEvents($timestamp) {
		if (!$this->matchmakingSession || !$this->matchmakingSession->isRunning()) {
			return array();
		}

		$sessionSnapshot = $this->matchmakingSession->toArray();
		$sessionId = isset($sessionSnapshot['session_id']) ? trim((string) $sessionSnapshot['session_id']) : '';
		if ($sessionId === '') {
			return array();
		}

		$endsAt = isset($sessionSnapshot['ends_at']) ? (int) $sessionSnapshot['ends_at'] : 0;
		$remainingSeconds = max(0, $endsAt - $timestamp);
		$previousRemainingSeconds = array_key_exists($sessionId, $this->matchmakingCountdownLastRemaining)
			? max(0, (int) $this->matchmakingCountdownLastRemaining[$sessionId])
			: $remainingSeconds;
		$this->matchmakingCountdownLastRemaining[$sessionId] = $remainingSeconds;

		$announcedSeconds = array_key_exists($sessionId, $this->matchmakingCountdownAnnouncements)
			&& is_array($this->matchmakingCountdownAnnouncements[$sessionId])
			? $this->matchmakingCountdownAnnouncements[$sessionId]
			: array();

		$startedAt = isset($sessionSnapshot['started_at']) ? (int) $sessionSnapshot['started_at'] : 0;
		$durationSeconds = max(0, $endsAt - $startedAt);
		$countdownSchedule = VetoDraftCatalog::buildMatchmakingCountdownSeconds(
			$durationSeconds > 0 ? $durationSeconds : VetoDraftCatalog::DEFAULT_MATCHMAKING_DURATION_SECONDS
		);

		$countdownEvents = array();
		foreach ($countdownSchedule as $countdownSecond) {
			$countdownSecond = (int) $countdownSecond;
			if ($countdownSecond <= 0) {
				continue;
			}

			if (in_array($countdownSecond, $announcedSeconds, true)) {
				continue;
			}

			if ($previousRemainingSeconds < $countdownSecond) {
				continue;
			}

			if ($remainingSeconds > $countdownSecond) {
				continue;
			}

			$announcedSeconds[] = $countdownSecond;
			$countdownEvents[] = array(
				'type' => 'matchmaking_countdown',
				'mode' => VetoDraftCatalog::MODE_MATCHMAKING_VOTE,
				'session_id' => $sessionId,
				'remaining_seconds' => $countdownSecond,
				'snapshot' => $sessionSnapshot,
			);
		}

		$this->matchmakingCountdownAnnouncements[$sessionId] = $announcedSeconds;

		return $countdownEvents;
	}

	private function allVoteTotalsAreZero(array $sessionSnapshot) {
		$voteTotals = isset($sessionSnapshot['vote_totals']) && is_array($sessionSnapshot['vote_totals'])
			? $sessionSnapshot['vote_totals']
			: array();
		if (empty($voteTotals)) {
			return false;
		}

		foreach ($voteTotals as $voteTotal) {
			$voteCount = isset($voteTotal['vote_count']) ? (int) $voteTotal['vote_count'] : 0;
			if ($voteCount !== 0) {
				return false;
			}
		}

		return true;
	}

	private function forgetMatchmakingCountdownState($sessionId) {
		$sessionId = trim((string) $sessionId);
		if ($sessionId === '') {
			return;
		}

		if (array_key_exists($sessionId, $this->matchmakingCountdownAnnouncements)) {
			unset($this->matchmakingCountdownAnnouncements[$sessionId]);
		}

		if (array_key_exists($sessionId, $this->matchmakingCountdownLastRemaining)) {
			unset($this->matchmakingCountdownLastRemaining[$sessionId]);
		}
	}

	private function buildSessionId($mode, $timestamp) {
		$mode = strtolower(trim((string) $mode));
		if ($mode === '') {
			$mode = 'veto';
		}

		$timestamp = max(0, (int) $timestamp);
		$randomSuffix = (string) VetoDraftCatalog::pickRandomValue(array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'), 'x');
		return $mode . '-' . $timestamp . '-' . $randomSuffix;
	}

	private function success($code, $message, array $details = array()) {
		return array(
			'success' => true,
			'code' => trim((string) $code),
			'message' => trim((string) $message),
			'details' => $details,
		);
	}

	private function failure($code, $message, array $details = array()) {
		return array(
			'success' => false,
			'code' => trim((string) $code),
			'message' => trim((string) $message),
			'details' => $details,
		);
	}
}
