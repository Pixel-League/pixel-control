<?php

namespace PixelControl\Domain\Combat;

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
trait CombatDomainTrait {
	private function buildCombatPayload($sourceCallback, array $callbackArguments) {
		$dimensionsBundle = $this->extractCombatDimensions($callbackArguments);
		$dimensions = $dimensionsBundle['dimensions'];
		$fieldAvailability = $dimensionsBundle['field_availability'];
		$callbackObject = $this->extractPrimaryCallbackObject($callbackArguments);
		$eventKind = $this->resolveCombatEventKind($sourceCallback);
		$this->logCombatAction($sourceCallback, $dimensions, $callbackObject);

		$this->updateCombatStatsCounters($sourceCallback, $dimensions);
		$trackedLogins = $this->collectCombatSnapshotLogins($dimensions, $callbackObject);

		$playerCounters = array();
		if ($this->playerCombatStatsStore) {
			if ($eventKind === 'shootmania_event_scores') {
				$playerCounters = $this->playerCombatStatsStore->snapshotAll();
			} else {
				$playerCounters = $this->playerCombatStatsStore->snapshotForPlayers($trackedLogins);
			}
		}

		$missingDimensions = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingDimensions[] = $field;
		}

		$payload = array(
			'event_kind' => $eventKind,
			'counter_scope' => 'runtime_session',
			'player_counters' => $playerCounters,
			'tracked_player_count' => $this->playerCombatStatsStore ? $this->playerCombatStatsStore->getTrackedPlayerCount() : 0,
			'dimensions' => $dimensions,
			'field_availability' => $fieldAvailability,
			'missing_dimensions' => $missingDimensions,
			'raw_callback_summary' => $this->buildPayloadSummary($callbackArguments),
		);

		if ($callbackObject instanceof OnCaptureStructure) {
			$payload['capture_players'] = array_values($callbackObject->getLoginArray());
		}

		if ($callbackObject instanceof OnScoresStructure) {
			$scoresSnapshot = $this->buildScoresContextSnapshot($callbackObject);
			$this->latestScoresSnapshot = $scoresSnapshot;
			$payload['scores_section'] = isset($scoresSnapshot['section']) ? $scoresSnapshot['section'] : $callbackObject->getSection();
			$payload['scores_snapshot'] = $scoresSnapshot;
			$payload['scores_result'] = $this->buildWinContextSnapshot('score_update');
		}

		return $payload;
	}

	private function extractCombatDimensions(array $callbackArguments) {
		$callbackObject = $this->extractPrimaryCallbackObject($callbackArguments);

		$weaponId = null;
		if ($callbackObject && method_exists($callbackObject, 'getWeapon')) {
			$weaponId = (int) $callbackObject->getWeapon();
		}

		$damage = null;
		if ($callbackObject instanceof OnHitStructure) {
			$damage = (int) $callbackObject->getDamage();
		}

		$distance = null;
		if ($callbackObject instanceof OnHitNearMissArmorEmptyBaseStructure) {
			$distance = (float) $callbackObject->getDistance();
		}

		$eventTime = null;
		if ($callbackObject && method_exists($callbackObject, 'getTime')) {
			$eventTime = (int) $callbackObject->getTime();
		}

		$shooter = null;
		if ($callbackObject && method_exists($callbackObject, 'getShooter')) {
			$shooter = $this->buildPlayerIdentity($callbackObject->getShooter());
		}

		$victim = null;
		if ($callbackObject && method_exists($callbackObject, 'getVictim')) {
			$victim = $this->buildPlayerIdentity($callbackObject->getVictim());
		}

		$shooterPosition = null;
		if ($callbackObject && method_exists($callbackObject, 'getShooterPosition')) {
			$shooterPosition = $this->buildPositionSnapshot($callbackObject->getShooterPosition());
		}

		$victimPosition = null;
		if ($callbackObject && method_exists($callbackObject, 'getVictimPosition')) {
			$victimPosition = $this->buildPositionSnapshot($callbackObject->getVictimPosition());
		}

		$dimensions = array(
			'weapon_id' => $weaponId,
			'damage' => $damage,
			'distance' => $distance,
			'event_time' => $eventTime,
			'shooter' => $shooter,
			'victim' => $victim,
			'shooter_position' => $shooterPosition,
			'victim_position' => $victimPosition,
		);

		$fieldAvailability = array(
			'weapon_id' => $weaponId !== null,
			'damage' => $damage !== null,
			'distance' => $distance !== null,
			'event_time' => $eventTime !== null,
			'shooter' => is_array($shooter),
			'victim' => is_array($victim),
			'shooter_position' => is_array($shooterPosition),
			'victim_position' => is_array($victimPosition),
		);

		return array(
			'dimensions' => $dimensions,
			'field_availability' => $fieldAvailability,
		);
	}

	private function updateCombatStatsCounters($sourceCallback, array $dimensions) {
		if (!$this->playerCombatStatsStore) {
			return;
		}

		$normalizedSourceCallback = $this->resolveCombatEventKind($sourceCallback);
		$shooterLogin = '';
		if (isset($dimensions['shooter']) && is_array($dimensions['shooter']) && isset($dimensions['shooter']['login'])) {
			$shooterLogin = (string) $dimensions['shooter']['login'];
		}

		$victimLogin = '';
		if (isset($dimensions['victim']) && is_array($dimensions['victim']) && isset($dimensions['victim']['login'])) {
			$victimLogin = (string) $dimensions['victim']['login'];
		}

		$weaponId = null;
		if (isset($dimensions['weapon_id']) && is_numeric($dimensions['weapon_id'])) {
			$weaponId = (int) $dimensions['weapon_id'];
		}

		switch ($normalizedSourceCallback) {
			case 'shootmania_event_onshoot':
				$this->playerCombatStatsStore->recordShot($shooterLogin, $weaponId);
				break;
			case 'shootmania_event_onhit':
				$this->playerCombatStatsStore->recordHit($shooterLogin);
				break;
			case 'shootmania_event_onnearmiss':
				$this->playerCombatStatsStore->recordMiss($shooterLogin);
				break;
			case 'shootmania_event_onarmorempty':
				$this->playerCombatStatsStore->recordKill($shooterLogin, $victimLogin);
				break;
		}
	}

	private function logCombatAction($sourceCallback, array $dimensions, $callbackObject) {
		$eventKind = $this->resolveCombatEventKind($sourceCallback);
		$weaponId = null;
		if (isset($dimensions['weapon_id']) && is_numeric($dimensions['weapon_id'])) {
			$weaponId = (int) $dimensions['weapon_id'];
		}

		$weaponLabel = $this->resolveCombatWeaponLabel($weaponId);
		$shooterLogin = $this->resolveCombatLoginLabel($dimensions, 'shooter', 'unknown_shooter');
		$victimLogin = $this->resolveCombatLoginLabel($dimensions, 'victim', 'unknown_victim');

		switch ($eventKind) {
			case 'shootmania_event_onshoot':
				Logger::log('[Pixel Plugin] ' . $shooterLogin . ' shooted with ' . $weaponLabel . '.');
				return;

			case 'shootmania_event_onhit':
				Logger::log('[Pixel Plugin] ' . $shooterLogin . ' hit someone with ' . $weaponLabel . '.');
				Logger::log('[Pixel Plugin] ' . $victimLogin . ' got hit.');
				return;

			case 'shootmania_event_onnearmiss':
				Logger::log('[Pixel Plugin] ' . $shooterLogin . ' near missed with ' . $weaponLabel . '.');
				return;

			case 'shootmania_event_onarmorempty':
				Logger::log('[Pixel Plugin] ' . $victimLogin . ' armor got emptied by ' . $shooterLogin . ' with ' . $weaponLabel . '.');
				return;

			case 'shootmania_event_oncapture':
				$captureLogins = array();
				if ($callbackObject instanceof OnCaptureStructure) {
					foreach ($callbackObject->getLoginArray() as $login) {
						$normalizedLogin = trim((string) $login);
						if ($normalizedLogin === '') {
							continue;
						}

						$captureLogins[] = $normalizedLogin;
					}
				}

				if (empty($captureLogins)) {
					Logger::log('[Pixel Plugin] Capture event detected.');
					return;
				}

				Logger::log('[Pixel Plugin] Capture event by ' . implode(', ', $captureLogins) . '.');
				return;

			case 'shootmania_event_scores':
				$scoreSection = 'unknown';
				$scorePlayerCount = 0;
				$winnerTeamLabel = 'unknown';
				$winnerPlayerLogin = 'none';
				$useTeamsLabel = 'unknown';
				if ($callbackObject instanceof OnScoresStructure) {
					$scoreSection = (string) $callbackObject->getSection();
					$scorePlayerCount = count($callbackObject->getPlayerScores());
					$useTeamsLabel = $callbackObject->getUseTeams() ? 'true' : 'false';

					$winnerTeamId = $callbackObject->getWinnerTeamId();
					if (is_numeric($winnerTeamId)) {
						$winnerTeamLabel = (string) ((int) $winnerTeamId);
					}

					$winnerPlayer = $callbackObject->getWinnerPlayer();
					if ($winnerPlayer instanceof Player && isset($winnerPlayer->login)) {
						$winnerPlayerCandidate = trim((string) $winnerPlayer->login);
						if ($winnerPlayerCandidate !== '') {
							$winnerPlayerLogin = $winnerPlayerCandidate;
						}
					}
				}

				Logger::log(
					'[Pixel Plugin] Scores updated (section=' . $scoreSection
					. ', players=' . $scorePlayerCount
					. ', use_teams=' . $useTeamsLabel
					. ', winner_team=' . $winnerTeamLabel
					. ', winner_player=' . $winnerPlayerLogin
					. ').'
				);
				return;
		}

		Logger::log('[Pixel Plugin] Combat callback received: ' . $eventKind . '.');
	}

	private function resolveCombatEventKind($sourceCallback) {
		$normalized = $this->normalizeIdentifier($sourceCallback, 'unknown');

		if (strpos($normalized, 'onnearmiss') !== false) {
			return 'shootmania_event_onnearmiss';
		}

		if (strpos($normalized, 'onarmorempty') !== false) {
			return 'shootmania_event_onarmorempty';
		}

		if (strpos($normalized, 'oncapture') !== false) {
			return 'shootmania_event_oncapture';
		}

		if (strpos($normalized, 'onscores') !== false || strpos($normalized, 'scores') !== false) {
			return 'shootmania_event_scores';
		}

		if (strpos($normalized, 'onhit') !== false) {
			return 'shootmania_event_onhit';
		}

		if (strpos($normalized, 'onshoot') !== false) {
			return 'shootmania_event_onshoot';
		}

		return $normalized;
	}

	private function resolveCombatLoginLabel(array $dimensions, $key, $fallback) {
		if (!isset($dimensions[$key]) || !is_array($dimensions[$key])) {
			return $fallback;
		}

		$login = isset($dimensions[$key]['login']) ? trim((string) $dimensions[$key]['login']) : '';
		if ($login === '') {
			return $fallback;
		}

		return $login;
	}

	private function resolveCombatWeaponLabel($weaponId) {
		if ($weaponId === PlayerCombatStatsStore::WEAPON_LASER) {
			return 'laser';
		}

		if ($weaponId === PlayerCombatStatsStore::WEAPON_ROCKET) {
			return 'rocket';
		}

		switch ((int) $weaponId) {
			case 3:
				return 'nucleus';
			case 4:
				return 'grenade';
			case 5:
				return 'arrow';
			case 6:
				return 'missile';
			default:
				if ($weaponId === null) {
					return 'unknown_weapon';
				}

				return 'weapon_' . (string) $weaponId;
		}
	}

	private function collectCombatSnapshotLogins(array $dimensions, $callbackObject) {
		$logins = array();

		if (isset($dimensions['shooter']) && is_array($dimensions['shooter']) && isset($dimensions['shooter']['login'])) {
			$shooterLogin = trim((string) $dimensions['shooter']['login']);
			if ($shooterLogin !== '') {
				$logins[] = $shooterLogin;
			}
		}

		if (isset($dimensions['victim']) && is_array($dimensions['victim']) && isset($dimensions['victim']['login'])) {
			$victimLogin = trim((string) $dimensions['victim']['login']);
			if ($victimLogin !== '') {
				$logins[] = $victimLogin;
			}
		}

		if ($callbackObject instanceof OnCaptureStructure) {
			foreach ($callbackObject->getLoginArray() as $login) {
				$normalizedLogin = trim((string) $login);
				if ($normalizedLogin === '') {
					continue;
				}

				$logins[] = $normalizedLogin;
			}
		}

		if ($callbackObject instanceof OnScoresStructure) {
			foreach ($callbackObject->getPlayerScores() as $login => $playerScore) {
				if (is_string($login) && trim($login) !== '') {
					$logins[] = trim($login);
					continue;
				}

				if (is_object($playerScore) && method_exists($playerScore, 'getPlayer')) {
					$scorePlayer = $playerScore->getPlayer();
					if ($scorePlayer instanceof Player && isset($scorePlayer->login)) {
						$scoreLogin = trim((string) $scorePlayer->login);
						if ($scoreLogin !== '') {
							$logins[] = $scoreLogin;
						}
					}
				}
			}
		}

		$logins = array_values(array_unique($logins));
		sort($logins);

		return $logins;
	}

	private function extractPrimaryCallbackObject(array $callbackArguments) {
		if (empty($callbackArguments)) {
			return null;
		}

		$firstArgument = $callbackArguments[0];
		if (!is_object($firstArgument)) {
			return null;
		}

		return $firstArgument;
	}

	private function buildPlayerIdentity($player) {
		if (!$player instanceof Player) {
			return null;
		}

		return array(
			'login' => isset($player->login) ? (string) $player->login : '',
			'nickname' => isset($player->nickname) ? (string) $player->nickname : '',
			'team_id' => isset($player->teamId) ? (int) $player->teamId : -1,
			'is_spectator' => isset($player->isSpectator) ? (bool) $player->isSpectator : false,
		);
	}

	private function buildPositionSnapshot($position) {
		if (!$position instanceof Position) {
			return null;
		}

		return array(
			'x' => (float) $position->getX(),
			'y' => (float) $position->getY(),
			'z' => (float) $position->getZ(),
		);
	}
}
