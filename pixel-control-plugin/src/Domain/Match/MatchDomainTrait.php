<?php

namespace PixelControl\Domain\Match;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\Structures\ShootMania\OnCaptureStructure;
use ManiaControl\Callbacks\Structures\ShootMania\OnHitNearMissArmorEmptyBaseStructure;
use ManiaControl\Callbacks\Structures\ShootMania\OnHitStructure;
use ManiaControl\Callbacks\Structures\ShootMania\OnScoresStructure;
use ManiaControl\Callbacks\Structures\ShootMania\Models\Position;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Logger;
use ManiaControl\ManiaControl;
use ManiaControl\Maps\Map;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Plugins\PluginManager;
use ManiaControl\Players\Player;
use PixelControl\Api\AsyncPixelControlApiClient;
use PixelControl\Api\DeliveryError;
use PixelControl\Api\EventEnvelope;
use PixelControl\Api\PixelControlApiClientInterface;
use PixelControl\Callbacks\CallbackRegistry;
use PixelControl\Queue\EventQueueInterface;
use PixelControl\Queue\InMemoryEventQueue;
use PixelControl\Queue\QueueItem;
use PixelControl\Retry\ExponentialBackoffRetryPolicy;
use PixelControl\Retry\RetryPolicyInterface;
use PixelControl\Stats\PlayerCombatStatsStore;
trait MatchDomainTrait {
	private function buildLifecycleAggregateTelemetry($variant, $sourceCallback) {
		if (!$this->playerCombatStatsStore) {
			return null;
		}

		$this->resetCombatStatsWindowIfNeeded($variant, $sourceCallback);
		$currentCounters = $this->playerCombatStatsStore->snapshotAll();
		$observedAt = time();

		if ($variant === 'map.begin') {
			$this->openMapAggregateWindow($currentCounters, $observedAt, $sourceCallback);
			$this->openRoundAggregateWindow($currentCounters, $observedAt, $sourceCallback);
			return null;
		}

		if ($variant === 'round.begin') {
			$this->openRoundAggregateWindow($currentCounters, $observedAt, $sourceCallback);
			return null;
		}

		$scope = null;
		$windowBaseline = null;
		$windowStartedAt = 0;
		$windowStartedBy = 'unknown';

		if ($variant === 'round.end') {
			$scope = 'round';
			$windowBaseline = $this->roundAggregateBaseline;
			$windowStartedAt = $this->roundAggregateStartedAt;
			$windowStartedBy = $this->roundAggregateStartedBy;
		} else if ($variant === 'map.end') {
			$scope = 'map';
			$windowBaseline = $this->mapAggregateBaseline;
			$windowStartedAt = $this->mapAggregateStartedAt;
			$windowStartedBy = $this->mapAggregateStartedBy;
		}

		if ($scope === null) {
			return null;
		}

		$baselineInitialized = is_array($windowBaseline);
		if (!$baselineInitialized) {
			$windowBaseline = array();
		}

		$counterDelta = $this->buildCombatCounterDelta($windowBaseline, $currentCounters);
		$totals = $this->buildCombatCounterTotals($counterDelta);
		$teamCounterBundle = $this->buildTeamCounterDelta($counterDelta);
		$teamCounters = isset($teamCounterBundle['teams']) && is_array($teamCounterBundle['teams']) ? $teamCounterBundle['teams'] : array();
		$teamSourceCounts = isset($teamCounterBundle['assignment_source_counts']) && is_array($teamCounterBundle['assignment_source_counts'])
			? $teamCounterBundle['assignment_source_counts']
			: array('player_manager' => 0, 'scores_snapshot' => 0, 'unknown' => 0);
		$unresolvedTeamPlayers = isset($teamCounterBundle['unresolved_players']) && is_array($teamCounterBundle['unresolved_players'])
			? $teamCounterBundle['unresolved_players']
			: array();
		$winContext = $this->buildWinContextSnapshot($scope);

		$fieldAvailability = array(
			'combat_store' => true,
			'window_baseline' => $baselineInitialized,
			'window_started_at' => $windowStartedAt > 0,
			'scores_context' => is_array($this->latestScoresSnapshot),
			'team_counters_delta' => !empty($teamCounters),
			'win_context_result' => is_array($winContext) && isset($winContext['result_state']),
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		$aggregatePayload = array(
			'scope' => $scope,
			'window_state' => 'closed',
			'counter_scope' => 'combat_delta',
			'counter_keys' => $this->getCombatCounterKeys(),
			'player_counters_delta' => $counterDelta,
			'team_counters_delta' => $teamCounters,
			'team_summary' => array(
				'team_count' => count($teamCounters),
				'assignment_source_counts' => $teamSourceCounts,
				'unresolved_player_logins' => $unresolvedTeamPlayers,
			),
			'totals' => $totals,
			'tracked_player_count' => count($counterDelta),
			'window' => array(
				'started_at' => $windowStartedAt,
				'ended_at' => $observedAt,
				'duration_seconds' => ($windowStartedAt > 0 ? max(0, $observedAt - $windowStartedAt) : null),
				'started_by_callback' => $windowStartedBy,
				'ended_by_callback' => $sourceCallback,
			),
			'source_coverage' => array(
				'combat_callbacks' => array(
					'shootmania_event_onshoot',
					'shootmania_event_onhit',
					'shootmania_event_onnearmiss',
					'shootmania_event_onarmorempty',
				),
				'score_callback' => 'shootmania_event_scores',
				'team_assignment' => array(
					'resolution_order' => array('player_manager', 'scores_snapshot', 'unknown'),
					'source_counts' => $teamSourceCounts,
					'unresolved_player_logins' => $unresolvedTeamPlayers,
				),
				'win_context' => array(
					'source_callback' => 'shootmania_event_scores',
					'section_scope_match' => isset($winContext['scope_matches_boundary']) ? (bool) $winContext['scope_matches_boundary'] : false,
					'result_state' => isset($winContext['result_state']) ? (string) $winContext['result_state'] : 'unavailable',
					'fallback_applied' => isset($winContext['fallback_applied']) ? (bool) $winContext['fallback_applied'] : true,
				),
				'notes' => array(
					'Counters are derived from callback deltas between lifecycle boundaries.',
					'Accuracy is recomputed from delta hits/shots for each player and totals.',
					'Team aggregates are grouped using player-manager team ids with score-snapshot fallback when runtime player rows are missing.',
				),
			),
			'win_context' => $winContext,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);

		if ($scope === 'round') {
			$this->openRoundAggregateWindow($currentCounters, $observedAt, $sourceCallback);
		} else {
			$this->openMapAggregateWindow($currentCounters, $observedAt, $sourceCallback);
			$this->openRoundAggregateWindow($currentCounters, $observedAt, $sourceCallback);
		}

		return $aggregatePayload;
	}

	private function buildCombatCounterDelta(array $baselineCounters, array $currentCounters) {
		$deltaCounters = array();
		$counterKeys = $this->getCombatCounterKeys();
		$numericCounterKeys = array('kills', 'deaths', 'hits', 'shots', 'misses', 'rockets', 'lasers');

		$logins = array_values(array_unique(array_merge(array_keys($baselineCounters), array_keys($currentCounters))));
		sort($logins);

		foreach ($logins as $login) {
			$baselineRow = array_key_exists($login, $baselineCounters) && is_array($baselineCounters[$login]) ? $baselineCounters[$login] : array();
			$currentRow = array_key_exists($login, $currentCounters) && is_array($currentCounters[$login]) ? $currentCounters[$login] : array();

			$deltaRow = array();
			foreach ($numericCounterKeys as $counterKey) {
				$baselineValue = isset($baselineRow[$counterKey]) ? (int) $baselineRow[$counterKey] : 0;
				$currentValue = isset($currentRow[$counterKey]) ? (int) $currentRow[$counterKey] : 0;
				$deltaRow[$counterKey] = max(0, $currentValue - $baselineValue);
			}

			$deltaShots = isset($deltaRow['shots']) ? (int) $deltaRow['shots'] : 0;
			$deltaHits = isset($deltaRow['hits']) ? (int) $deltaRow['hits'] : 0;
			$deltaRow['accuracy'] = ($deltaShots > 0 ? round($deltaHits / $deltaShots, 4) : 0.0);

			$hasNonZeroCounter = false;
			foreach ($counterKeys as $counterKey) {
				if (!isset($deltaRow[$counterKey])) {
					$deltaRow[$counterKey] = 0;
				}

				if ($counterKey === 'accuracy') {
					continue;
				}

				if ((int) $deltaRow[$counterKey] > 0) {
					$hasNonZeroCounter = true;
				}
			}

			if ($hasNonZeroCounter || !empty($currentRow) || !empty($baselineRow)) {
				$deltaCounters[$login] = $deltaRow;
			}
		}

		ksort($deltaCounters);

		return $deltaCounters;
	}

	private function buildCombatCounterTotals(array $counterRows) {
		$totals = $this->buildZeroCounterRow();

		foreach ($counterRows as $counterRow) {
			if (!is_array($counterRow)) {
				continue;
			}

			$totals['kills'] += isset($counterRow['kills']) ? (int) $counterRow['kills'] : 0;
			$totals['deaths'] += isset($counterRow['deaths']) ? (int) $counterRow['deaths'] : 0;
			$totals['hits'] += isset($counterRow['hits']) ? (int) $counterRow['hits'] : 0;
			$totals['shots'] += isset($counterRow['shots']) ? (int) $counterRow['shots'] : 0;
			$totals['misses'] += isset($counterRow['misses']) ? (int) $counterRow['misses'] : 0;
			$totals['rockets'] += isset($counterRow['rockets']) ? (int) $counterRow['rockets'] : 0;
			$totals['lasers'] += isset($counterRow['lasers']) ? (int) $counterRow['lasers'] : 0;
		}

		$totals['accuracy'] = ($totals['shots'] > 0 ? round($totals['hits'] / $totals['shots'], 4) : 0.0);

		return $totals;
	}

	private function buildTeamCounterDelta(array $playerCounterDelta) {
		$teamsByKey = array();
		$assignmentSourceCounts = array(
			'player_manager' => 0,
			'scores_snapshot' => 0,
			'unknown' => 0,
		);
		$unresolvedPlayers = array();

		foreach ($playerCounterDelta as $login => $counterRow) {
			if (!is_array($counterRow)) {
				continue;
			}

			$assignment = $this->resolveTeamAssignmentForLogin($login);
			$teamId = isset($assignment['team_id']) ? $assignment['team_id'] : null;
			$source = isset($assignment['source']) ? (string) $assignment['source'] : 'unknown';

			if (!array_key_exists($source, $assignmentSourceCounts)) {
				$source = 'unknown';
			}
			$assignmentSourceCounts[$source]++;

			if ($source === 'unknown') {
				$unresolvedPlayers[] = (string) $login;
			}

			$teamKey = ($teamId === null ? 'unknown' : (string) ((int) $teamId));
			if (!array_key_exists($teamKey, $teamsByKey)) {
				$teamsByKey[$teamKey] = array(
					'team_id' => ($teamId === null ? null : (int) $teamId),
					'team_side' => ($teamId === null ? 'unknown' : 'team_' . (int) $teamId),
					'team_key' => $teamKey,
					'player_logins' => array(),
					'player_count' => 0,
					'totals' => $this->buildZeroCounterRow(),
					'assignment_sources' => array(),
				);
			}

			$teamsByKey[$teamKey]['player_logins'][] = (string) $login;
			if (!in_array($source, $teamsByKey[$teamKey]['assignment_sources'], true)) {
				$teamsByKey[$teamKey]['assignment_sources'][] = $source;
			}

			$teamsByKey[$teamKey]['totals']['kills'] += isset($counterRow['kills']) ? (int) $counterRow['kills'] : 0;
			$teamsByKey[$teamKey]['totals']['deaths'] += isset($counterRow['deaths']) ? (int) $counterRow['deaths'] : 0;
			$teamsByKey[$teamKey]['totals']['hits'] += isset($counterRow['hits']) ? (int) $counterRow['hits'] : 0;
			$teamsByKey[$teamKey]['totals']['shots'] += isset($counterRow['shots']) ? (int) $counterRow['shots'] : 0;
			$teamsByKey[$teamKey]['totals']['misses'] += isset($counterRow['misses']) ? (int) $counterRow['misses'] : 0;
			$teamsByKey[$teamKey]['totals']['rockets'] += isset($counterRow['rockets']) ? (int) $counterRow['rockets'] : 0;
			$teamsByKey[$teamKey]['totals']['lasers'] += isset($counterRow['lasers']) ? (int) $counterRow['lasers'] : 0;
		}

		foreach ($teamsByKey as &$teamRow) {
			sort($teamRow['player_logins']);
			sort($teamRow['assignment_sources']);
			$teamRow['player_count'] = count($teamRow['player_logins']);

			$shots = isset($teamRow['totals']['shots']) ? (int) $teamRow['totals']['shots'] : 0;
			$hits = isset($teamRow['totals']['hits']) ? (int) $teamRow['totals']['hits'] : 0;
			$teamRow['totals']['accuracy'] = ($shots > 0 ? round($hits / $shots, 4) : 0.0);
		}
		unset($teamRow);

		uksort($teamsByKey, function ($left, $right) {
			if ($left === $right) {
				return 0;
			}

			if ($left === 'unknown') {
				return 1;
			}

			if ($right === 'unknown') {
				return -1;
			}

			if (is_numeric($left) && is_numeric($right)) {
				return ((int) $left) - ((int) $right);
			}

			return strcmp((string) $left, (string) $right);
		});

		$unresolvedPlayers = array_values(array_unique($unresolvedPlayers));
		sort($unresolvedPlayers);

		return array(
			'teams' => array_values($teamsByKey),
			'assignment_source_counts' => $assignmentSourceCounts,
			'unresolved_players' => $unresolvedPlayers,
		);
	}

	private function resolveTeamAssignmentForLogin($login) {
		$normalizedLogin = trim((string) $login);
		if ($normalizedLogin === '') {
			return array('team_id' => null, 'source' => 'unknown');
		}

		if ($this->maniaControl) {
			$player = $this->maniaControl->getPlayerManager()->getPlayer($normalizedLogin);
			if ($player instanceof Player && isset($player->teamId) && $player->teamId !== null && $player->teamId !== '') {
				return array('team_id' => (int) $player->teamId, 'source' => 'player_manager');
			}
		}

		$snapshotTeamId = $this->resolveTeamIdFromScoresSnapshot($normalizedLogin);
		if ($snapshotTeamId !== null) {
			return array('team_id' => (int) $snapshotTeamId, 'source' => 'scores_snapshot');
		}

		return array('team_id' => null, 'source' => 'unknown');
	}

	private function resolveTeamIdFromScoresSnapshot($login) {
		if (!is_array($this->latestScoresSnapshot) || !isset($this->latestScoresSnapshot['player_scores']) || !is_array($this->latestScoresSnapshot['player_scores'])) {
			return null;
		}

		foreach ($this->latestScoresSnapshot['player_scores'] as $playerScoreRow) {
			if (!is_array($playerScoreRow)) {
				continue;
			}

			$scoreLogin = isset($playerScoreRow['login']) ? trim((string) $playerScoreRow['login']) : '';
			if ($scoreLogin === '' || strcasecmp($scoreLogin, (string) $login) !== 0) {
				continue;
			}

			if (isset($playerScoreRow['team_id']) && is_numeric($playerScoreRow['team_id'])) {
				return (int) $playerScoreRow['team_id'];
			}
		}

		return null;
	}

	private function getCombatCounterKeys() {
		return array('kills', 'deaths', 'hits', 'shots', 'misses', 'rockets', 'lasers', 'accuracy');
	}

	private function buildZeroCounterRow() {
		return array(
			'kills' => 0,
			'deaths' => 0,
			'hits' => 0,
			'shots' => 0,
			'misses' => 0,
			'rockets' => 0,
			'lasers' => 0,
			'accuracy' => 0.0,
		);
	}

	private function resetCombatStatsWindowIfNeeded($variant, $sourceCallback) {
		if ($variant !== 'match.begin' && $variant !== 'map.begin') {
			return;
		}

		if (!$this->playerCombatStatsStore) {
			return;
		}

		$this->playerCombatStatsStore->reset();
		$this->latestScoresSnapshot = null;
		$this->roundAggregateBaseline = null;
		$this->roundAggregateStartedAt = 0;
		$this->roundAggregateStartedBy = 'unknown';
		$this->mapAggregateBaseline = null;
		$this->mapAggregateStartedAt = 0;
		$this->mapAggregateStartedBy = 'unknown';

		Logger::log(
			'[PixelControl][combat][window_reset] variant=' . (string) $variant
			. ', source_callback=' . (string) $sourceCallback
			. ', retention=match_map_window.'
		);
	}

	private function openRoundAggregateWindow(array $baselineCounters, $startedAt, $sourceCallback) {
		$this->roundAggregateBaseline = $baselineCounters;
		$this->roundAggregateStartedAt = (int) $startedAt;
		$this->roundAggregateStartedBy = (string) $sourceCallback;
	}

	private function openMapAggregateWindow(array $baselineCounters, $startedAt, $sourceCallback) {
		$this->mapAggregateBaseline = $baselineCounters;
		$this->mapAggregateStartedAt = (int) $startedAt;
		$this->mapAggregateStartedBy = (string) $sourceCallback;
	}

	private function buildWinContextSnapshot($scope) {
		if (!is_array($this->latestScoresSnapshot)) {
			$fieldAvailability = array(
				'scores_snapshot' => false,
				'winning_side' => false,
				'winning_reason' => false,
				'result_state' => false,
				'winner_team_id' => false,
				'winner_player_login' => false,
			);

			return array(
				'available' => false,
				'source_callback' => 'shootmania_event_scores',
				'section' => 'unknown',
				'scope_matches_boundary' => false,
				'score_metric' => 'unknown',
				'result_state' => 'unavailable',
				'winning_side' => 'unknown',
				'winning_side_kind' => 'unknown',
				'winning_reason' => 'scores_callback_not_observed',
				'fallback_applied' => true,
				'is_tie' => false,
				'is_draw' => true,
				'score_gap' => null,
				'reason' => 'scores_callback_not_observed',
				'use_teams' => null,
				'winner_team_id' => null,
				'winner_player_login' => '',
				'winner_player_nickname' => '',
				'team_scores' => array(),
				'player_scores' => array(),
				'captured_at' => 0,
				'field_availability' => $fieldAvailability,
				'missing_fields' => array_keys($fieldAvailability),
			);
		}

		$section = isset($this->latestScoresSnapshot['section']) ? (string) $this->latestScoresSnapshot['section'] : 'unknown';
		$normalizedSection = strtolower($section);
		$scopeMatchesBoundary = false;
		if ($scope === 'round' && $normalizedSection === 'endround') {
			$scopeMatchesBoundary = true;
		} else if ($scope === 'map' && ($normalizedSection === 'endmap' || $normalizedSection === 'endmatch')) {
			$scopeMatchesBoundary = true;
		}

		$useTeams = isset($this->latestScoresSnapshot['use_teams']) ? (bool) $this->latestScoresSnapshot['use_teams'] : false;
		$metricKey = $this->resolveWinContextMetricBySection($section);
		$teamScores = isset($this->latestScoresSnapshot['team_scores']) && is_array($this->latestScoresSnapshot['team_scores'])
			? $this->latestScoresSnapshot['team_scores']
			: array();
		$playerScores = isset($this->latestScoresSnapshot['player_scores']) && is_array($this->latestScoresSnapshot['player_scores'])
			? $this->latestScoresSnapshot['player_scores']
			: array();

		$winnerTeamId = isset($this->latestScoresSnapshot['winner_team_id']) && is_numeric($this->latestScoresSnapshot['winner_team_id'])
			? (int) $this->latestScoresSnapshot['winner_team_id']
			: null;
		if ($winnerTeamId !== null && $winnerTeamId < 0) {
			$winnerTeamId = null;
		}

		$winnerPlayerLogin = isset($this->latestScoresSnapshot['winner_player_login'])
			? trim((string) $this->latestScoresSnapshot['winner_player_login'])
			: '';
		$winnerPlayerNickname = isset($this->latestScoresSnapshot['winner_player_nickname'])
			? (string) $this->latestScoresSnapshot['winner_player_nickname']
			: '';

		$resultState = 'draw';
		$winningSide = 'draw';
		$winningSideKind = 'draw';
		$winningReason = 'winner_not_exposed';
		$fallbackApplied = true;
		$isTie = false;
		$isDraw = false;
		$scoreGap = null;

		if ($useTeams) {
			$teamRanking = $this->buildScoreRankingRows($teamScores, $metricKey, 'team_id');
			$scoreGap = $this->resolveScoreGapFromRanking($teamRanking);

			$topTeams = array();
			if (!empty($teamRanking)) {
				$topScore = $teamRanking[0]['score'];
				foreach ($teamRanking as $rankingRow) {
					if (!isset($rankingRow['score']) || $rankingRow['score'] !== $topScore) {
						break;
					}
					$topTeams[] = $rankingRow;
				}
			}

			if ($winnerTeamId !== null) {
				$resultState = 'team_win';
				$winningSide = 'team_' . $winnerTeamId;
				$winningSideKind = 'team';
				$winningReason = 'winner_team_id';
				$fallbackApplied = false;
			} else if (count($topTeams) === 1 && isset($topTeams[0]['id']) && is_numeric($topTeams[0]['id'])) {
				$winnerTeamId = (int) $topTeams[0]['id'];
				$resultState = 'team_win';
				$winningSide = 'team_' . $winnerTeamId;
				$winningSideKind = 'team';
				$winningReason = 'team_score_fallback';
				$fallbackApplied = true;
			} else if (count($topTeams) > 1) {
				$resultState = 'tie';
				$winningSide = 'tie';
				$winningSideKind = 'tie';
				$winningReason = 'team_score_tie';
				$fallbackApplied = true;
				$isTie = true;
			} else {
				$resultState = 'draw';
				$winningSide = 'draw';
				$winningSideKind = 'draw';
				$winningReason = 'team_winner_unavailable';
				$fallbackApplied = true;
				$isDraw = true;
			}
		} else {
			$playerRanking = $this->buildScoreRankingRows($playerScores, $metricKey, 'login');
			$scoreGap = $this->resolveScoreGapFromRanking($playerRanking);

			$topPlayers = array();
			if (!empty($playerRanking)) {
				$topScore = $playerRanking[0]['score'];
				foreach ($playerRanking as $rankingRow) {
					if (!isset($rankingRow['score']) || $rankingRow['score'] !== $topScore) {
						break;
					}
					$topPlayers[] = $rankingRow;
				}
			}

			if ($winnerPlayerLogin !== '') {
				$resultState = 'player_win';
				$winningSide = 'player:' . $winnerPlayerLogin;
				$winningSideKind = 'player';
				$winningReason = 'winner_player_login';
				$fallbackApplied = false;
			} else if (count($topPlayers) === 1 && isset($topPlayers[0]['id']) && trim((string) $topPlayers[0]['id']) !== '') {
				$winnerPlayerLogin = trim((string) $topPlayers[0]['id']);
				$winnerPlayerNickname = $this->resolvePlayerNicknameFromScoreRows($playerScores, $winnerPlayerLogin);
				$resultState = 'player_win';
				$winningSide = 'player:' . $winnerPlayerLogin;
				$winningSideKind = 'player';
				$winningReason = 'player_score_fallback';
				$fallbackApplied = true;
			} else if (count($topPlayers) > 1) {
				$resultState = 'tie';
				$winningSide = 'tie';
				$winningSideKind = 'tie';
				$winningReason = 'player_score_tie';
				$fallbackApplied = true;
				$isTie = true;
			} else {
				$resultState = 'draw';
				$winningSide = 'draw';
				$winningSideKind = 'draw';
				$winningReason = 'player_winner_unavailable';
				$fallbackApplied = true;
				$isDraw = true;
			}
		}

		if (!$scopeMatchesBoundary) {
			$winningReason .= '_scope_mismatch';
			$fallbackApplied = true;
		}

		$fieldAvailability = array(
			'scores_snapshot' => true,
			'section_scope_match' => $scopeMatchesBoundary,
			'score_metric' => $metricKey !== 'unknown',
			'winning_side' => $winningSide !== 'unknown',
			'winning_reason' => $winningReason !== '',
			'result_state' => $resultState !== '',
			'winner_team_id' => $winnerTeamId !== null,
			'winner_player_login' => $winnerPlayerLogin !== '',
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'available' => true,
			'source_callback' => 'shootmania_event_scores',
			'section' => $section,
			'scope_matches_boundary' => $scopeMatchesBoundary,
			'use_teams' => $useTeams,
			'score_metric' => $metricKey,
			'result_state' => $resultState,
			'winning_side' => $winningSide,
			'winning_side_kind' => $winningSideKind,
			'winning_reason' => $winningReason,
			'fallback_applied' => $fallbackApplied,
			'is_tie' => $isTie,
			'is_draw' => $isDraw,
			'score_gap' => $scoreGap,
			'reason' => $winningReason,
			'winner_team_id' => $winnerTeamId,
			'winner_player_login' => $winnerPlayerLogin,
			'winner_player_nickname' => $winnerPlayerNickname,
			'team_scores' => $teamScores,
			'player_scores' => $playerScores,
			'captured_at' => isset($this->latestScoresSnapshot['captured_at']) ? (int) $this->latestScoresSnapshot['captured_at'] : 0,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	private function resolveWinContextMetricBySection($section) {
		switch (strtolower((string) $section)) {
			case 'endround':
				return 'round_points';
			case 'endmatch':
				return 'match_points';
			case 'endmap':
				return 'map_points';
			default:
				return 'map_points';
		}
	}

	private function buildScoreRankingRows(array $scoreRows, $metricKey, $idField) {
		$rankingRows = array();

		foreach ($scoreRows as $scoreRow) {
			if (!is_array($scoreRow) || !isset($scoreRow[$idField])) {
				continue;
			}

			$identifier = $scoreRow[$idField];
			if (is_string($identifier)) {
				$identifier = trim($identifier);
				if ($identifier === '') {
					continue;
				}
			}

			$scoreValue = 0;
			if (isset($scoreRow[$metricKey]) && is_numeric($scoreRow[$metricKey])) {
				$scoreValue = (int) $scoreRow[$metricKey];
			}

			$rankingRows[] = array(
				'id' => $identifier,
				'score' => $scoreValue,
			);
		}

		usort($rankingRows, function ($left, $right) {
			if ((int) $left['score'] === (int) $right['score']) {
				return strcmp((string) $left['id'], (string) $right['id']);
			}

			return ((int) $right['score']) - ((int) $left['score']);
		});

		return $rankingRows;
	}

	private function resolveScoreGapFromRanking(array $rankingRows) {
		if (count($rankingRows) < 2) {
			return null;
		}

		if (!isset($rankingRows[0]['score']) || !isset($rankingRows[1]['score'])) {
			return null;
		}

		return ((int) $rankingRows[0]['score']) - ((int) $rankingRows[1]['score']);
	}

	private function resolvePlayerNicknameFromScoreRows(array $playerScoreRows, $playerLogin) {
		foreach ($playerScoreRows as $scoreRow) {
			if (!is_array($scoreRow) || !isset($scoreRow['login'])) {
				continue;
			}

			$scoreLogin = trim((string) $scoreRow['login']);
			if ($scoreLogin === '' || strcasecmp($scoreLogin, (string) $playerLogin) !== 0) {
				continue;
			}

			return isset($scoreRow['nickname']) ? (string) $scoreRow['nickname'] : '';
		}

		return '';
	}

	private function buildScoresContextSnapshot(OnScoresStructure $scoresStructure) {
		$teamScores = array();
		foreach ($scoresStructure->getTeamScores() as $teamId => $teamScore) {
			if (!is_object($teamScore) || !method_exists($teamScore, 'getTeamId')) {
				continue;
			}

			$teamKey = is_numeric($teamId) ? (int) $teamId : (int) $teamScore->getTeamId();
			$teamScores[$teamKey] = array(
				'team_id' => (int) $teamScore->getTeamId(),
				'name' => method_exists($teamScore, 'getName') ? (string) $teamScore->getName() : '',
				'round_points' => method_exists($teamScore, 'getRoundPoints') ? (int) $teamScore->getRoundPoints() : 0,
				'map_points' => method_exists($teamScore, 'getMapPoints') ? (int) $teamScore->getMapPoints() : 0,
				'match_points' => method_exists($teamScore, 'getMatchPoints') ? (int) $teamScore->getMatchPoints() : 0,
			);
		}
		ksort($teamScores);

		$playerScores = array();
		foreach ($scoresStructure->getPlayerScores() as $login => $playerScore) {
			if (!is_object($playerScore) || !method_exists($playerScore, 'getPlayer')) {
				continue;
			}

			$player = $playerScore->getPlayer();
			if (!$player instanceof Player || !isset($player->login)) {
				continue;
			}

			$playerLogin = trim((string) $player->login);
			if ($playerLogin === '') {
				continue;
			}

			$playerScores[$playerLogin] = array(
				'login' => $playerLogin,
				'nickname' => isset($player->nickname) ? (string) $player->nickname : '',
				'team_id' => isset($player->teamId) ? (int) $player->teamId : null,
				'rank' => method_exists($playerScore, 'getRank') ? (int) $playerScore->getRank() : 0,
				'round_points' => method_exists($playerScore, 'getRoundPoints') ? (int) $playerScore->getRoundPoints() : 0,
				'map_points' => method_exists($playerScore, 'getMapPoints') ? (int) $playerScore->getMapPoints() : 0,
				'match_points' => method_exists($playerScore, 'getMatchPoints') ? (int) $playerScore->getMatchPoints() : 0,
			);
		}
		ksort($playerScores);

		$winnerPlayer = $scoresStructure->getWinnerPlayer();

		return array(
			'section' => (string) $scoresStructure->getSection(),
			'use_teams' => (bool) $scoresStructure->getUseTeams(),
			'winner_team_id' => $scoresStructure->getWinnerTeamId(),
			'winner_player_login' => ($winnerPlayer instanceof Player && isset($winnerPlayer->login)) ? (string) $winnerPlayer->login : '',
			'winner_player_nickname' => ($winnerPlayer instanceof Player && isset($winnerPlayer->nickname)) ? (string) $winnerPlayer->nickname : '',
			'team_scores' => array_values($teamScores),
			'player_scores' => array_values($playerScores),
			'captured_at' => time(),
		);
	}

	private function resetVetoDraftActions() {
		$this->vetoDraftActions = array();
		$this->vetoDraftActionSequence = 0;
	}

	private function recordVetoDraftActionFromLifecycle($variant, $sourceCallback, array $callbackArguments, $adminAction = null) {
		$scriptPayload = $this->extractScriptCallbackPayload($callbackArguments);
		$rawActionValue = $this->extractFirstScalarPayloadValue(
			$scriptPayload,
			array('veto_action', 'draft_action', 'action_kind', 'action', 'kind', 'type', 'command')
		);
		$actionKind = $this->normalizeVetoActionKind($rawActionValue);
		$actionStatus = 'explicit';
		$actionSource = 'script_payload';

		if ($actionKind === 'unknown' && is_array($adminAction)) {
			$actionType = isset($adminAction['action_type']) ? (string) $adminAction['action_type'] : 'unknown';
			if ($actionType === 'map_loading' || $actionType === 'map_unloading') {
				$actionKind = 'lock';
				$actionStatus = 'inferred';
				$actionSource = 'admin_action';
			}
		}

		if ($actionKind === 'unknown' && $variant === 'map.begin') {
			$actionKind = 'lock';
			$actionStatus = 'inferred';
			$actionSource = 'map_boundary';
		}

		if ($actionKind === 'unknown') {
			return;
		}

		$currentMapSnapshot = $this->buildCurrentMapSnapshot();
		$mapUid = $this->extractFirstScalarPayloadValue($scriptPayload, array('map_uid', 'mapid', 'map_id', 'uid'));
		$mapName = $this->extractFirstScalarPayloadValue($scriptPayload, array('map_name', 'map'));
		if ($mapUid === '') {
			$mapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';
		}
		if ($mapName === '') {
			$mapName = isset($currentMapSnapshot['name']) ? (string) $currentMapSnapshot['name'] : '';
		}

		$actor = is_array($adminAction) && isset($adminAction['actor']) && is_array($adminAction['actor'])
			? $adminAction['actor']
			: $this->extractActorSnapshotFromPayload($scriptPayload);

		$this->vetoDraftActionSequence++;
		$orderIndex = $this->vetoDraftActionSequence;

		$fieldAvailability = array(
			'action_kind' => $actionKind !== 'unknown',
			'actor' => is_array($actor) && isset($actor['type']) && $actor['type'] !== 'unknown',
			'map_uid' => $mapUid !== '',
			'source_callback' => trim((string) $sourceCallback) !== '',
			'observed_at' => true,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		$this->vetoDraftActions[] = array(
			'order_index' => $orderIndex,
			'action_kind' => $actionKind,
			'action_status' => $actionStatus,
			'action_source' => $actionSource,
			'raw_action_value' => ($rawActionValue !== '' ? $rawActionValue : null),
			'source_callback' => $sourceCallback,
			'source_channel' => ($this->isScriptLifecycleCallback($sourceCallback) ? 'script' : 'maniaplanet'),
			'observed_at' => time(),
			'actor' => $actor,
			'map' => array(
				'uid' => $mapUid,
				'name' => $mapName,
			),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);

		if (count($this->vetoDraftActions) > $this->vetoDraftActionLimit) {
			$this->vetoDraftActions = array_slice($this->vetoDraftActions, -1 * $this->vetoDraftActionLimit);
		}
	}

	private function normalizeVetoActionKind($rawActionValue) {
		$normalizedValue = $this->normalizeIdentifier($rawActionValue, 'unknown');

		switch ($normalizedValue) {
			case 'ban':
			case 'pick':
			case 'pass':
			case 'lock':
				return $normalizedValue;
			case 'map_loading':
			case 'map_lock':
				return 'lock';
			default:
				return 'unknown';
		}
	}

	private function buildVetoDraftActionSnapshot(array $currentMapSnapshot) {
		$actions = $this->vetoDraftActions;
		$available = !empty($actions);
		$fieldAvailability = array(
			'actions' => $available,
			'current_map_uid' => isset($currentMapSnapshot['uid']) && trim((string) $currentMapSnapshot['uid']) !== '',
			'supported_action_kinds' => true,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $availableField) {
			if ($availableField) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'available' => $available,
			'status' => ($available ? 'partial' : 'unavailable'),
			'reason' => ($available
				? 'veto_actions_inferred_from_available_callbacks'
				: 'veto_callbacks_not_exposed_in_current_runtime'),
			'action_count' => count($actions),
			'supported_action_kinds' => array('ban', 'pick', 'pass', 'lock'),
			'actions' => $actions,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	private function buildVetoResultSnapshot(array $vetoDraftActions, array $currentMapSnapshot, array $mapPool, $variant) {
		$actions = isset($vetoDraftActions['actions']) && is_array($vetoDraftActions['actions'])
			? $vetoDraftActions['actions']
			: array();
		$actionCount = count($actions);
		$currentMapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';

		if ($actionCount === 0) {
			return array(
				'available' => false,
				'status' => 'unavailable',
				'reason' => 'veto_callbacks_not_exposed_in_current_runtime',
				'supported_fields' => array('actor', 'action', 'order', 'timestamp'),
			);
		}

		$lastAction = $actions[$actionCount - 1];
		$lastActionKind = isset($lastAction['action_kind']) ? (string) $lastAction['action_kind'] : 'unknown';

		$fieldAvailability = array(
			'action_count' => true,
			'current_map_uid' => $currentMapUid !== '',
			'last_action_kind' => $lastActionKind !== 'unknown',
			'played_map_order' => !empty($this->playedMapHistory),
			'map_pool' => !empty($mapPool),
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $availableField) {
			if ($availableField) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'available' => true,
			'status' => 'partial',
			'reason' => 'final_selection_inferred_from_partial_veto_actions',
			'variant' => $variant,
			'action_count' => $actionCount,
			'last_action_kind' => $lastActionKind,
			'selection_basis' => array(
				'current_map_uid' => $currentMapUid,
				'played_map_order' => $this->playedMapHistory,
				'map_pool_size' => count($mapPool),
			),
			'final_map' => $currentMapSnapshot,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	private function buildLifecycleMapRotationTelemetry($variant, $sourceCallback) {
		if ($variant !== 'map.begin' && $variant !== 'map.end') {
			return null;
		}

		$currentMapSnapshot = $this->buildCurrentMapSnapshot();
		if ($variant === 'map.begin') {
			$this->recordPlayedMapOrderEntry($currentMapSnapshot, $sourceCallback);
		}

		$mapPool = $this->buildMapPoolSnapshot();
		$currentMapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';
		$currentMapIndex = null;
		if ($currentMapUid !== '') {
			foreach ($mapPool as $index => $mapIdentity) {
				if (!isset($mapIdentity['uid'])) {
					continue;
				}

				if ((string) $mapIdentity['uid'] === $currentMapUid) {
					$currentMapIndex = (int) $index;
					break;
				}
			}
		}

		$nextMaps = array();
		$mapPoolSize = count($mapPool);
		if ($currentMapIndex !== null && $mapPoolSize > 0) {
			for ($step = 1; $step <= min(3, $mapPoolSize); $step++) {
				$nextIndex = ($currentMapIndex + $step) % $mapPoolSize;
				if (!isset($mapPool[$nextIndex])) {
					continue;
				}

				$nextMaps[] = $mapPool[$nextIndex];
			}
		}

		$vetoDraftActions = $this->buildVetoDraftActionSnapshot($currentMapSnapshot);
		$vetoResult = $this->buildVetoResultSnapshot($vetoDraftActions, $currentMapSnapshot, $mapPool, $variant);

		$fieldAvailability = array(
			'map_pool' => !empty($mapPool),
			'current_map' => $currentMapUid !== '',
			'current_map_index' => $currentMapIndex !== null,
			'next_maps' => !empty($nextMaps),
			'played_map_order' => !empty($this->playedMapHistory),
			'veto_draft_actions' => isset($vetoDraftActions['available']) ? (bool) $vetoDraftActions['available'] : false,
			'veto_result' => is_array($vetoResult) && isset($vetoResult['status']),
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'variant' => $variant,
			'map_pool_size' => $mapPoolSize,
			'current_map' => $currentMapSnapshot,
			'current_map_index' => $currentMapIndex,
			'next_maps' => $nextMaps,
			'map_pool' => $mapPool,
			'played_map_count' => count($this->playedMapHistory),
			'played_map_order' => $this->playedMapHistory,
			'veto_draft_actions' => $vetoDraftActions,
			'veto_result' => $vetoResult,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	private function buildMapPoolSnapshot() {
		if (!$this->maniaControl) {
			return array();
		}

		$mapPool = array();
		$maps = $this->maniaControl->getMapManager()->getMaps();
		if (!is_array($maps)) {
			return $mapPool;
		}

		foreach (array_values($maps) as $index => $map) {
			if (!$map instanceof Map) {
				continue;
			}

			$mapPool[] = $this->buildMapIdentityFromObject($map, $index);
		}

		return $mapPool;
	}

	private function buildMapIdentityFromObject(Map $map, $rotationIndex) {
		$identity = array(
			'uid' => isset($map->uid) ? (string) $map->uid : '',
			'name' => isset($map->name) ? (string) $map->name : '',
			'file' => isset($map->fileName) ? (string) $map->fileName : '',
			'environment' => isset($map->environment) ? (string) $map->environment : '',
			'map_type' => isset($map->mapType) ? (string) $map->mapType : '',
			'rotation_index' => (int) $rotationIndex,
			'external_ids' => array(),
		);

		if (isset($map->mx) && is_object($map->mx) && isset($map->mx->id) && is_numeric($map->mx->id)) {
			$identity['external_ids']['mx_id'] = (int) $map->mx->id;
		}

		if (empty($identity['external_ids'])) {
			$identity['external_ids'] = null;
		}

		return $identity;
	}

	private function recordPlayedMapOrderEntry(array $currentMapSnapshot, $sourceCallback) {
		$mapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';
		if ($mapUid === '') {
			return;
		}

		$isRepeat = false;
		if (!empty($this->playedMapHistory)) {
			$lastPlayedMap = $this->playedMapHistory[count($this->playedMapHistory) - 1];
			$lastUid = (isset($lastPlayedMap['uid']) ? (string) $lastPlayedMap['uid'] : '');
			$isRepeat = ($lastUid !== '' && $lastUid === $mapUid);
		}

		$this->playedMapHistory[] = array(
			'order' => count($this->playedMapHistory) + 1,
			'uid' => $mapUid,
			'name' => isset($currentMapSnapshot['name']) ? (string) $currentMapSnapshot['name'] : '',
			'file' => isset($currentMapSnapshot['file']) ? (string) $currentMapSnapshot['file'] : '',
			'environment' => isset($currentMapSnapshot['environment']) ? (string) $currentMapSnapshot['environment'] : '',
			'is_repeat' => $isRepeat,
			'observed_at' => time(),
			'source_callback' => (string) $sourceCallback,
		);

		if (count($this->playedMapHistory) > $this->playedMapHistoryLimit) {
			$this->playedMapHistory = array_slice($this->playedMapHistory, -1 * $this->playedMapHistoryLimit);
		}
	}
}
