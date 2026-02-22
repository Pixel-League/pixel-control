<?php

namespace PixelControl\VetoDraft;

class MatchmakingVoteSession {
	/** @var string $sessionId */
	private $sessionId;
	/** @var string $status */
	private $status = VetoDraftCatalog::STATUS_IDLE;
	/** @var int $startedAt */
	private $startedAt = 0;
	/** @var int $endsAt */
	private $endsAt = 0;
	/** @var int $resolvedAt */
	private $resolvedAt = 0;
	/** @var array[] $mapPool */
	private $mapPool = array();
	/** @var array $mapPoolByUid */
	private $mapPoolByUid = array();
	/** @var array $votesByLogin */
	private $votesByLogin = array();
	/** @var array $voteCounts */
	private $voteCounts = array();
	/** @var string $winnerMapUid */
	private $winnerMapUid = '';
	/** @var bool $tieBreakApplied */
	private $tieBreakApplied = false;
	/** @var array $tieBreakCandidateUids */
	private $tieBreakCandidateUids = array();
	/** @var string $resolutionReason */
	private $resolutionReason = 'pending';
	/** @var array[] $voteEvents */
	private $voteEvents = array();

	public function __construct($sessionId, array $mapPool, $durationSeconds, $startedAt) {
		$this->sessionId = trim((string) $sessionId);
		$this->startedAt = max(0, (int) $startedAt);
		$durationSeconds = VetoDraftCatalog::sanitizePositiveInt($durationSeconds, VetoDraftCatalog::DEFAULT_MATCHMAKING_DURATION_SECONDS, 10);
		$this->endsAt = $this->startedAt + $durationSeconds;

		foreach ($mapPool as $mapIdentity) {
			if (!is_array($mapIdentity) || !isset($mapIdentity['uid'])) {
				continue;
			}

			$mapUid = trim((string) $mapIdentity['uid']);
			if ($mapUid === '') {
				continue;
			}

			$this->mapPool[] = $mapIdentity;
			$this->mapPoolByUid[strtolower($mapUid)] = $mapIdentity;
			$this->voteCounts[strtolower($mapUid)] = 0;
		}

		$this->status = VetoDraftCatalog::STATUS_RUNNING;
	}

	public function isRunning() {
		return $this->status === VetoDraftCatalog::STATUS_RUNNING;
	}

	public function isExpired($timestamp) {
		if (!$this->isRunning()) {
			return false;
		}

		$timestamp = max(0, (int) $timestamp);
		return $timestamp >= $this->endsAt;
	}

	public function castVote($playerLogin, $mapUid, $timestamp) {
		if (!$this->isRunning()) {
			return array(
				'success' => false,
				'code' => 'vote_window_closed',
				'message' => 'Map vote is not running.',
			);
		}

		$playerLogin = strtolower(trim((string) $playerLogin));
		$mapUid = strtolower(trim((string) $mapUid));
		if ($playerLogin === '') {
			return array(
				'success' => false,
				'code' => 'player_login_missing',
				'message' => 'Player login is required to vote.',
			);
		}

		if ($mapUid === '' || !isset($this->mapPoolByUid[$mapUid])) {
			return array(
				'success' => false,
				'code' => 'map_unknown',
				'message' => 'Unknown map selection.',
			);
		}

		$previousMapUid = '';
		if (isset($this->votesByLogin[$playerLogin])) {
			$previousMapUid = (string) $this->votesByLogin[$playerLogin];
			if (isset($this->voteCounts[$previousMapUid])) {
				$this->voteCounts[$previousMapUid] = max(0, ((int) $this->voteCounts[$previousMapUid]) - 1);
			}
		}

		$this->votesByLogin[$playerLogin] = $mapUid;
		if (!isset($this->voteCounts[$mapUid])) {
			$this->voteCounts[$mapUid] = 0;
		}
		$this->voteCounts[$mapUid]++;

		$this->voteEvents[] = array(
			'order_index' => count($this->voteEvents) + 1,
			'player_login' => $playerLogin,
			'map_uid' => $mapUid,
			'previous_map_uid' => $previousMapUid,
			'observed_at' => max(0, (int) $timestamp),
		);

		$mapName = isset($this->mapPoolByUid[$mapUid]['name']) ? (string) $this->mapPoolByUid[$mapUid]['name'] : $mapUid;

		return array(
			'success' => true,
			'code' => 'vote_recorded',
			'message' => 'Vote recorded for ' . $mapName . '.',
			'vote_totals' => $this->buildVoteTotals(),
			'selected_map' => $this->mapPoolByUid[$mapUid],
		);
	}

	public function resetVoteCounters() {
		$this->votesByLogin = array();
		$this->voteEvents = array();
		$this->voteCounts = array();

		foreach ($this->mapPoolByUid as $mapUid => $_mapIdentity) {
			$this->voteCounts[(string) $mapUid] = 0;
		}
	}

	public function finalize($timestamp) {
		if ($this->status === VetoDraftCatalog::STATUS_COMPLETED) {
			return $this->toArray();
		}

		if ($this->status === VetoDraftCatalog::STATUS_CANCELLED) {
			return $this->toArray();
		}

		$timestamp = max(0, (int) $timestamp);
		$this->resolvedAt = $timestamp;

		$voteTotals = $this->buildVoteTotals();
		if (empty($voteTotals)) {
			$this->winnerMapUid = '';
			$this->status = VetoDraftCatalog::STATUS_CANCELLED;
			$this->resolutionReason = 'map_pool_empty';
			return $this->toArray();
		}

		$highestVoteCount = -1;
		$topCandidates = array();
		foreach ($voteTotals as $voteTotal) {
			$mapUid = isset($voteTotal['map_uid']) ? strtolower((string) $voteTotal['map_uid']) : '';
			$voteCount = isset($voteTotal['vote_count']) ? (int) $voteTotal['vote_count'] : 0;
			if ($mapUid === '') {
				continue;
			}

			if ($voteCount > $highestVoteCount) {
				$highestVoteCount = $voteCount;
				$topCandidates = array($mapUid);
				continue;
			}

			if ($voteCount === $highestVoteCount) {
				$topCandidates[] = $mapUid;
			}
		}

		if (empty($topCandidates)) {
			$this->winnerMapUid = '';
			$this->status = VetoDraftCatalog::STATUS_CANCELLED;
			$this->resolutionReason = 'map_pool_empty';
			return $this->toArray();
		}

		if ($highestVoteCount <= 0) {
			$allMapUids = array_keys($this->mapPoolByUid);
			$this->winnerMapUid = strtolower((string) VetoDraftCatalog::pickRandomValue($allMapUids, ''));
			$this->tieBreakApplied = false;
			$this->tieBreakCandidateUids = array();
			$this->resolutionReason = 'no_votes_random';
		} else {
			$this->tieBreakCandidateUids = $topCandidates;
			$this->tieBreakApplied = count($topCandidates) > 1;
			$this->winnerMapUid = strtolower((string) VetoDraftCatalog::pickRandomValue($topCandidates, ''));
			$this->resolutionReason = $this->tieBreakApplied ? 'top_vote_tiebreak_random' : 'top_vote_winner';
		}

		$this->status = VetoDraftCatalog::STATUS_COMPLETED;
		return $this->toArray();
	}

	public function cancel($timestamp, $reason) {
		if ($this->status === VetoDraftCatalog::STATUS_COMPLETED || $this->status === VetoDraftCatalog::STATUS_CANCELLED) {
			return $this->toArray();
		}

		$this->status = VetoDraftCatalog::STATUS_CANCELLED;
		$this->resolvedAt = max(0, (int) $timestamp);
		$normalizedReason = trim((string) $reason);
		$this->resolutionReason = ($normalizedReason !== '') ? $normalizedReason : 'cancelled';

		return $this->toArray();
	}

	public function getMapPool() {
		return $this->mapPool;
	}

	public function getStatus() {
		return $this->status;
	}

	public function getWinnerMapUid() {
		return $this->winnerMapUid;
	}

	public function getWinnerMapIdentity() {
		if ($this->winnerMapUid === '') {
			return null;
		}

		if (!isset($this->mapPoolByUid[$this->winnerMapUid])) {
			return null;
		}

		return $this->mapPoolByUid[$this->winnerMapUid];
	}

	public function buildTelemetryActions() {
		$winnerMapIdentity = $this->getWinnerMapIdentity();
		if (!$winnerMapIdentity) {
			return array();
		}

		return array(
			array(
				'order_index' => 1,
				'action_kind' => VetoDraftCatalog::ACTION_PICK,
				'action_status' => 'explicit',
				'action_source' => 'matchmaking_vote_result',
				'raw_action_value' => 'vote_winner',
				'source_callback' => 'PixelControl.VetoDraft.Matchmaking.Result',
				'source_channel' => 'feature.veto_draft.matchmaking',
				'observed_at' => $this->resolvedAt,
				'actor' => array(
					'login' => 'all_players',
					'team' => 'crowd',
				),
				'map' => array(
					'uid' => isset($winnerMapIdentity['uid']) ? (string) $winnerMapIdentity['uid'] : '',
					'name' => isset($winnerMapIdentity['name']) ? (string) $winnerMapIdentity['name'] : '',
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

	public function toArray() {
		$winnerMapIdentity = $this->getWinnerMapIdentity();

		return array(
			'session_id' => $this->sessionId,
			'mode' => VetoDraftCatalog::MODE_MATCHMAKING_VOTE,
			'status' => $this->status,
			'started_at' => $this->startedAt,
			'ends_at' => $this->endsAt,
			'resolved_at' => $this->resolvedAt,
			'resolution_reason' => $this->resolutionReason,
			'winner_map_uid' => ($winnerMapIdentity && isset($winnerMapIdentity['uid'])) ? (string) $winnerMapIdentity['uid'] : '',
			'winner_map' => $winnerMapIdentity,
			'tie_break_applied' => $this->tieBreakApplied,
			'tie_break_candidates' => $this->buildMapIdentityListFromUids($this->tieBreakCandidateUids),
			'vote_totals' => $this->buildVoteTotals(),
			'vote_count' => count($this->votesByLogin),
			'map_pool' => $this->mapPool,
		);
	}

	private function buildVoteTotals() {
		$voteTotals = array();
		foreach ($this->mapPool as $mapIdentity) {
			$mapUid = isset($mapIdentity['uid']) ? strtolower((string) $mapIdentity['uid']) : '';
			if ($mapUid === '') {
				continue;
			}

			$voteTotals[] = array(
				'map_uid' => isset($mapIdentity['uid']) ? (string) $mapIdentity['uid'] : '',
				'map_name' => isset($mapIdentity['name']) ? (string) $mapIdentity['name'] : '',
				'vote_count' => isset($this->voteCounts[$mapUid]) ? (int) $this->voteCounts[$mapUid] : 0,
			);
		}

		usort($voteTotals, function ($left, $right) {
			$leftVoteCount = isset($left['vote_count']) ? (int) $left['vote_count'] : 0;
			$rightVoteCount = isset($right['vote_count']) ? (int) $right['vote_count'] : 0;
			if ($leftVoteCount !== $rightVoteCount) {
				return $rightVoteCount - $leftVoteCount;
			}

			$leftName = isset($left['map_name']) ? strtolower((string) $left['map_name']) : '';
			$rightName = isset($right['map_name']) ? strtolower((string) $right['map_name']) : '';
			return strcmp($leftName, $rightName);
		});

		return $voteTotals;
	}

	private function buildMapIdentityListFromUids(array $mapUids) {
		$maps = array();
		foreach ($mapUids as $mapUid) {
			$normalizedMapUid = strtolower(trim((string) $mapUid));
			if ($normalizedMapUid === '') {
				continue;
			}

			if (!isset($this->mapPoolByUid[$normalizedMapUid])) {
				continue;
			}

			$maps[] = $this->mapPoolByUid[$normalizedMapUid];
		}

		return $maps;
	}
}
