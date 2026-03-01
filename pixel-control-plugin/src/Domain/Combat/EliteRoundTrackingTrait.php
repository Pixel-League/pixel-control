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

		if (!$this->isEliteModeActive()) {
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
	 * @return bool
	 */
	private function isEliteModeActive() {
		$mode = strtolower($this->readEnvString('PIXEL_SM_MODE', ''));
		return strpos($mode, 'elite') !== false;
	}

	/**
	 * @param OnEliteStartTurnStructure $structure
	 */
	private function handleEliteStartTurn(OnEliteStartTurnStructure $structure) {
		$attacker = $structure->getAttacker();
		$attackerLogin = '';
		if ($attacker instanceof Player && isset($attacker->login)) {
			$attackerLogin = trim((string) $attacker->login);
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

		$this->playerCombatStatsStore->openEliteRound($attackerLogin, $defenderLogins);

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
		$this->playerCombatStatsStore->closeEliteRound($victoryType);

		$victoryLabel = $this->resolveEliteVictoryLabel($victoryType);
		Logger::log(
			'[PixelControl][elite][round_closed] victory_type=' . $victoryType
			. ' (' . $victoryLabel . ').'
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
