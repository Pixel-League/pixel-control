<?php

namespace PixelControl\Domain\Combat;

use ManiaControl\Callbacks\Structures\ShootMania\OnEliteStartTurnStructure;
use ManiaControl\Callbacks\Structures\ShootMania\OnEliteEndTurnStructure;
use ManiaControl\Logger;
use ManiaControl\Players\Player;

trait EliteRoundTrackingTrait {
	/**
	 * Process an Elite mode callback for round tracking.
	 * Called from handleModeCallback before the event is queued.
	 *
	 * @param array $callbackArguments
	 */
	private function processEliteRoundTracking(array $callbackArguments) {
		if (!$this->playerCombatStatsStore) {
			return;
		}

		$callbackObject = $this->extractEliteCallbackObject($callbackArguments);
		if ($callbackObject === null) {
			return;
		}

		if ($callbackObject instanceof OnEliteStartTurnStructure) {
			$this->handleEliteStartTurn($callbackObject);
			return;
		}

		if ($callbackObject instanceof OnEliteEndTurnStructure) {
			$this->handleEliteEndTurn($callbackObject);
			return;
		}
	}

	/**
	 * @param OnEliteStartTurnStructure $structure
	 */
	private function handleEliteStartTurn(OnEliteStartTurnStructure $structure) {
		$attacker = $structure->getAttacker();
		$attackerLogin = '';
		$attackerTeamId = null;
		if ($attacker instanceof Player && isset($attacker->login)) {
			$attackerLogin = trim((string) $attacker->login);
			if (isset($attacker->teamId)) {
				$attackerTeamId = (int) $attacker->teamId;
			}
		}

		$defenderLogins = array();
		$rawDefenders = $structure->getDefenderLogins();
		if (is_array($rawDefenders)) {
			foreach ($rawDefenders as $login) {
				$normalized = trim((string) $login);
				if ($normalized !== '') {
					$defenderLogins[] = $normalized;
				}
			}
		}

		$this->playerCombatStatsStore->openEliteRound($attackerLogin, $defenderLogins, $attackerTeamId);

		Logger::log(
			'[PixelControl][elite][round_opened] attacker=' . ($attackerLogin !== '' ? $attackerLogin : 'unknown')
			. ', defenders=' . implode(',', $defenderLogins)
			. ', defender_count=' . count($defenderLogins)
			. '.'
		);
	}

	/**
	 * @param OnEliteEndTurnStructure $structure
	 */
	private function handleEliteEndTurn(OnEliteEndTurnStructure $structure) {
		$victoryType = (int) $structure->getVictoryType();

		// Build and dispatch the turn summary BEFORE closing the round so we can still read store state
		$summaryPayload = $this->buildEliteTurnSummaryPayload($victoryType);
		$summaryMetadata = array(
			'elite_turn_number' => $summaryPayload['turn_number'],
			'elite_outcome' => $summaryPayload['outcome'],
			'elite_defense_success' => $summaryPayload['defense_success'] ? 'true' : 'false',
		);
		$this->enqueueEnvelope('combat', 'elite_turn_summary', $summaryPayload, $summaryMetadata);

		$this->playerCombatStatsStore->closeEliteRound($victoryType);

		$victoryLabel = $this->resolveEliteVictoryLabel($victoryType);
		Logger::log(
			'[PixelControl][elite][round_closed] victory_type=' . $victoryType
			. ' (' . $victoryLabel . ').'
		);
	}

	/**
	 * Build the elite_turn_summary event payload.
	 *
	 * @param int $victoryType
	 * @return array
	 */
	private function buildEliteTurnSummaryPayload($victoryType) {
		$victoryType = (int) $victoryType;
		$outcome = $this->resolveEliteVictoryLabel($victoryType);

		// defense_success: true when time_limit (1) or attacker_eliminated (3)
		$defenseSuccess = ($victoryType === 1 || $victoryType === 3);

		$roundStats = $this->playerCombatStatsStore->snapshotEliteRoundStats();

		$startedAt = $this->playerCombatStatsStore->getEliteRoundStartedAt();
		$durationSeconds = ($startedAt > 0) ? max(0, time() - $startedAt) : 0;

		// Map context
		$mapUid = '';
		$mapName = '';
		if ($this->maniaControl) {
			$currentMap = $this->maniaControl->getMapManager()->getCurrentMap();
			if ($currentMap) {
				$mapUid = isset($currentMap->uid) ? (string) $currentMap->uid : '';
				$mapName = isset($currentMap->name) ? (string) $currentMap->name : '';
			}
		}

		// Clutch detection
		$aliveCount = $this->playerCombatStatsStore->getAliveDefenderCount();
		$totalDefenders = count(isset($roundStats['defender_logins']) ? $roundStats['defender_logins'] : array());
		$isClutch = $defenseSuccess && $aliveCount === 1 && $totalDefenders > 1;
		$clutchPlayerLogin = null;
		if ($isClutch) {
			$aliveLogins = $this->playerCombatStatsStore->getAliveDefenderLogins();
			$clutchPlayerLogin = !empty($aliveLogins) ? $aliveLogins[0] : null;
		}

		return array(
			'event_kind' => 'elite_turn_summary',
			'turn_number' => isset($roundStats['turn_number']) ? (int) $roundStats['turn_number'] : 0,
			'attacker_login' => isset($roundStats['attacker_login']) ? $roundStats['attacker_login'] : null,
			'defender_logins' => isset($roundStats['defender_logins']) ? $roundStats['defender_logins'] : array(),
			'attacker_team_id' => isset($roundStats['attacker_team_id']) ? $roundStats['attacker_team_id'] : null,
			'outcome' => $outcome,
			'duration_seconds' => $durationSeconds,
			'defense_success' => $defenseSuccess,
			'per_player_stats' => isset($roundStats['per_player']) ? $roundStats['per_player'] : array(),
			'map_uid' => $mapUid,
			'map_name' => $mapName,
			'clutch' => array(
				'is_clutch' => $isClutch,
				'clutch_player_login' => $clutchPlayerLogin,
				'alive_defenders_at_end' => $aliveCount,
				'total_defenders' => $totalDefenders,
			),
		);
	}

	/**
	 * @param array $callbackArguments
	 * @return OnEliteStartTurnStructure|OnEliteEndTurnStructure|null
	 */
	private function extractEliteCallbackObject(array $callbackArguments) {
		if (empty($callbackArguments)) {
			return null;
		}

		$firstArgument = $callbackArguments[0];
		if ($firstArgument instanceof OnEliteStartTurnStructure) {
			return $firstArgument;
		}

		if ($firstArgument instanceof OnEliteEndTurnStructure) {
			return $firstArgument;
		}

		return null;
	}

	/**
	 * @param int $victoryType
	 * @return string
	 */
	private function resolveEliteVictoryLabel($victoryType) {
		switch ((int) $victoryType) {
			case 1: return 'time_limit';
			case 2: return 'capture';
			case 3: return 'attacker_eliminated';
			case 4: return 'defenders_eliminated';
			default: return 'unknown_' . (int) $victoryType;
		}
	}
}
