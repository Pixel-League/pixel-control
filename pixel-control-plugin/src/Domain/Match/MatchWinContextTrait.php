<?php

namespace PixelControl\Domain\Match;

use ManiaControl\Callbacks\Structures\ShootMania\OnScoresStructure;
use ManiaControl\Players\Player;

trait MatchWinContextTrait {

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
		foreach ($scoresStructure->getPlayerScores() as $playerScore) {
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

}
