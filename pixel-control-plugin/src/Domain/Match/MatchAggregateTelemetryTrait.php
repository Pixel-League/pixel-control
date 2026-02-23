<?php

namespace PixelControl\Domain\Match;

use ManiaControl\Logger;
use ManiaControl\Players\Player;

trait MatchAggregateTelemetryTrait {
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

}
