<?php

namespace PixelControl\Stats;

class PlayerCombatStatsStore {
	const WEAPON_LASER = 1;
	const WEAPON_ROCKET = 2;

	/** @var array $playerCounters */
	private $playerCounters = array();

	/** @var bool $eliteRoundActive */
	private $eliteRoundActive = false;

	/** @var string|null $eliteRoundAttackerLogin */
	private $eliteRoundAttackerLogin = null;

	/** @var string[] $eliteRoundDefenderLogins */
	private $eliteRoundDefenderLogins = array();

	/** @var array $eliteRoundHits Per-player hit count during current round, keyed by login */
	private $eliteRoundHits = array();

	/** @var array $eliteRoundRocketHits Per-player rocket hit count during current round, keyed by login */
	private $eliteRoundRocketHits = array();

	/** @var array $eliteRoundDeaths Per-player death count during current round, keyed by login */
	private $eliteRoundDeaths = array();

	/** @var int $eliteTurnNumber Monotonically incrementing turn counter, reset on store reset */
	private $eliteTurnNumber = 0;

	/** @var int|null $eliteAttackerTeamId Team ID of the attacker in the current round */
	private $eliteAttackerTeamId = null;

	/** @var array $eliteRoundShots Per-player shot count during current round, keyed by login */
	private $eliteRoundShots = array();

	/** @var array $eliteRoundMisses Per-player miss count during current round, keyed by login */
	private $eliteRoundMisses = array();

	/** @var array $eliteRoundKills Per-player kill count during current round, keyed by login */
	private $eliteRoundKills = array();

	/** @var int $eliteRoundStartedAt Unix timestamp when the current round started */
	private $eliteRoundStartedAt = 0;

	/** @var string[] $eliteRoundAliveDefenders Logins of defenders still alive in the current round */
	private $eliteRoundAliveDefenders = array();

	/**
	 * Reset all tracked counters.
	 */
	public function reset() {
		$this->playerCounters = array();
		$this->eliteRoundActive = false;
		$this->eliteRoundAttackerLogin = null;
		$this->eliteRoundDefenderLogins = array();
		$this->eliteRoundHits = array();
		$this->eliteRoundRocketHits = array();
		$this->eliteRoundDeaths = array();
		$this->eliteTurnNumber = 0;
		$this->eliteAttackerTeamId = null;
		$this->eliteRoundShots = array();
		$this->eliteRoundMisses = array();
		$this->eliteRoundKills = array();
		$this->eliteRoundStartedAt = 0;
		$this->eliteRoundAliveDefenders = array();
	}

	/**
	 * @param string $login
	 * @param int|null $weaponId
	 */
	public function recordShot($login, $weaponId = null) {
		$normalizedLogin = $this->normalizeLogin($login);
		if ($normalizedLogin === null) {
			return;
		}

		$this->ensurePlayer($normalizedLogin);
		$this->playerCounters[$normalizedLogin]['shots']++;

		if ($weaponId === self::WEAPON_LASER) {
			$this->playerCounters[$normalizedLogin]['lasers']++;
		}

		if ($weaponId === self::WEAPON_ROCKET) {
			$this->playerCounters[$normalizedLogin]['rockets']++;
		}

		$this->trackEliteRoundShot($normalizedLogin);
	}

	/**
	 * @param string $login
	 * @param int|null $weaponId
	 */
	public function recordHit($login, $weaponId = null) {
		$normalizedLogin = $this->normalizeLogin($login);
		if ($normalizedLogin === null) {
			return;
		}

		$this->ensurePlayer($normalizedLogin);
		$this->playerCounters[$normalizedLogin]['hits']++;

		if ($weaponId === self::WEAPON_LASER) {
			$this->playerCounters[$normalizedLogin]['hits_laser']++;
		}

		if ($weaponId === self::WEAPON_ROCKET) {
			$this->playerCounters[$normalizedLogin]['hits_rocket']++;
		}

		$this->trackEliteRoundHit($normalizedLogin, $weaponId);
	}

	/**
	 * @param string $login
	 */
	public function recordMiss($login) {
		$normalizedLogin = $this->normalizeLogin($login);
		if ($normalizedLogin === null) {
			return;
		}

		$this->ensurePlayer($normalizedLogin);
		$this->playerCounters[$normalizedLogin]['misses']++;
		$this->trackEliteRoundMiss($normalizedLogin);
	}

	/**
	 * @param string $killerLogin
	 * @param string $victimLogin
	 */
	public function recordKill($killerLogin, $victimLogin) {
		$normalizedKillerLogin = $this->normalizeLogin($killerLogin);
		if ($normalizedKillerLogin !== null) {
			$this->ensurePlayer($normalizedKillerLogin);
			$this->playerCounters[$normalizedKillerLogin]['kills']++;
			$this->trackEliteRoundKill($normalizedKillerLogin);
		}

		$normalizedVictimLogin = $this->normalizeLogin($victimLogin);
		if ($normalizedVictimLogin !== null) {
			$this->ensurePlayer($normalizedVictimLogin);
			$this->playerCounters[$normalizedVictimLogin]['deaths']++;
			$this->trackEliteRoundDeath($normalizedVictimLogin);
		}
	}

	/**
	 * Open an Elite round tracking window.
	 *
	 * @param string $attackerLogin
	 * @param string[] $defenderLogins
	 * @param int|null $attackerTeamId
	 */
	public function openEliteRound($attackerLogin, array $defenderLogins, $attackerTeamId = null) {
		$this->eliteTurnNumber++;
		$this->eliteRoundActive = true;
		$this->eliteRoundAttackerLogin = $this->normalizeLogin($attackerLogin);
		$this->eliteAttackerTeamId = ($attackerTeamId !== null) ? (int) $attackerTeamId : null;
		$this->eliteRoundDefenderLogins = array();
		foreach ($defenderLogins as $login) {
			$normalized = $this->normalizeLogin($login);
			if ($normalized !== null) {
				$this->eliteRoundDefenderLogins[] = $normalized;
			}
		}
		$this->eliteRoundHits = array();
		$this->eliteRoundRocketHits = array();
		$this->eliteRoundDeaths = array();
		$this->eliteRoundShots = array();
		$this->eliteRoundMisses = array();
		$this->eliteRoundKills = array();
		$this->eliteRoundStartedAt = time();
		$this->eliteRoundAliveDefenders = $this->eliteRoundDefenderLogins;

		// Ensure all participants are tracked
		if ($this->eliteRoundAttackerLogin !== null) {
			$this->ensurePlayer($this->eliteRoundAttackerLogin);
			$this->playerCounters[$this->eliteRoundAttackerLogin]['attack_rounds_played']++;
		}
		foreach ($this->eliteRoundDefenderLogins as $defenderLogin) {
			$this->ensurePlayer($defenderLogin);
			$this->playerCounters[$defenderLogin]['defense_rounds_played']++;
		}
	}

	/**
	 * Close the current Elite round and evaluate outcomes.
	 *
	 * @param int $victoryType VictoryTypes constant (1=TIME_LIMIT, 2=CAPTURE, 3=ATTACKER_ELIMINATED, 4=DEFENDERS_ELIMINATED)
	 */
	public function closeEliteRound($victoryType) {
		if (!$this->eliteRoundActive) {
			return;
		}

		$this->eliteRoundActive = false;
		$victoryType = (int) $victoryType;

		// Attack wins when: CAPTURE (2) or DEFENDERS_ELIMINATED (4)
		$attackWon = ($victoryType === 2 || $victoryType === 4);

		// Credit attacker win
		if ($attackWon && $this->eliteRoundAttackerLogin !== null) {
			$this->ensurePlayer($this->eliteRoundAttackerLogin);
			$this->playerCounters[$this->eliteRoundAttackerLogin]['attack_rounds_won']++;
		}

		// Evaluate defense success per defender
		foreach ($this->eliteRoundDefenderLogins as $defenderLogin) {
			$this->ensurePlayer($defenderLogin);
			$roundHits = isset($this->eliteRoundHits[$defenderLogin]) ? (int) $this->eliteRoundHits[$defenderLogin] : 0;
			$roundRocketHits = isset($this->eliteRoundRocketHits[$defenderLogin]) ? (int) $this->eliteRoundRocketHits[$defenderLogin] : 0;
			$roundDeaths = isset($this->eliteRoundDeaths[$defenderLogin]) ? (int) $this->eliteRoundDeaths[$defenderLogin] : 0;

			// Rule A: 1+ hit AND 0 deaths
			$ruleA = ($roundHits >= 1 && $roundDeaths === 0);
			// Rule B: 2+ rocket hits (regardless of death)
			$ruleB = ($roundRocketHits >= 2);

			if ($ruleA || $ruleB) {
				$this->playerCounters[$defenderLogin]['defense_rounds_won']++;
			}
		}

		// Reset transient state
		$this->eliteRoundAttackerLogin = null;
		$this->eliteRoundDefenderLogins = array();
		$this->eliteRoundHits = array();
		$this->eliteRoundRocketHits = array();
		$this->eliteRoundDeaths = array();
	}

	/**
	 * @return bool
	 */
	public function isEliteRoundActive() {
		return $this->eliteRoundActive;
	}

	/**
	 * @return int
	 */
	public function getEliteTurnNumber() {
		return $this->eliteTurnNumber;
	}

	/**
	 * @return string|null
	 */
	public function getEliteAttackerLogin() {
		return $this->eliteRoundAttackerLogin;
	}

	/**
	 * @return string[]
	 */
	public function getEliteDefenderLogins() {
		return $this->eliteRoundDefenderLogins;
	}

	/**
	 * @return int|null
	 */
	public function getEliteAttackerTeamId() {
		return $this->eliteAttackerTeamId;
	}

	/**
	 * @return int
	 */
	public function getEliteRoundStartedAt() {
		return $this->eliteRoundStartedAt;
	}

	/**
	 * @return int
	 */
	public function getAliveDefenderCount() {
		return count($this->eliteRoundAliveDefenders);
	}

	/**
	 * @return string[]
	 */
	public function getAliveDefenderLogins() {
		return array_values($this->eliteRoundAliveDefenders);
	}

	/**
	 * Snapshot all per-turn tracking data for use in a turn summary event.
	 *
	 * @return array
	 */
	public function snapshotEliteRoundStats() {
		$allLogins = array_unique(array_merge(
			$this->eliteRoundAttackerLogin !== null ? array($this->eliteRoundAttackerLogin) : array(),
			$this->eliteRoundDefenderLogins
		));

		$perPlayer = array();
		foreach ($allLogins as $login) {
			$perPlayer[$login] = array(
				'kills' => isset($this->eliteRoundKills[$login]) ? (int) $this->eliteRoundKills[$login] : 0,
				'deaths' => isset($this->eliteRoundDeaths[$login]) ? (int) $this->eliteRoundDeaths[$login] : 0,
				'hits' => isset($this->eliteRoundHits[$login]) ? (int) $this->eliteRoundHits[$login] : 0,
				'shots' => isset($this->eliteRoundShots[$login]) ? (int) $this->eliteRoundShots[$login] : 0,
				'misses' => isset($this->eliteRoundMisses[$login]) ? (int) $this->eliteRoundMisses[$login] : 0,
				'rocket_hits' => isset($this->eliteRoundRocketHits[$login]) ? (int) $this->eliteRoundRocketHits[$login] : 0,
			);
		}

		ksort($perPlayer);

		return array(
			'per_player' => $perPlayer,
			'turn_number' => $this->eliteTurnNumber,
			'attacker_login' => $this->eliteRoundAttackerLogin,
			'defender_logins' => $this->eliteRoundDefenderLogins,
			'attacker_team_id' => $this->eliteAttackerTeamId,
			'started_at' => $this->eliteRoundStartedAt,
		);
	}

	/**
	 * @param string[] $logins
	 * @return array
	 */
	public function snapshotForPlayers(array $logins) {
		$snapshot = array();

		foreach ($logins as $login) {
			$normalizedLogin = $this->normalizeLogin($login);
			if ($normalizedLogin === null) {
				continue;
			}

			$this->ensurePlayer($normalizedLogin);
			$snapshot[$normalizedLogin] = $this->withComputedFields($this->playerCounters[$normalizedLogin]);
		}

		ksort($snapshot);

		return $snapshot;
	}

	/**
	 * @return array
	 */
	public function snapshotAll() {
		$snapshot = array();

		foreach ($this->playerCounters as $login => $counters) {
			$snapshot[$login] = $this->withComputedFields($counters);
		}

		ksort($snapshot);

		return $snapshot;
	}

	/**
	 * @return int
	 */
	public function getTrackedPlayerCount() {
		return count($this->playerCounters);
	}

	/**
	 * @param string $login
	 */
	private function ensurePlayer($login) {
		if (array_key_exists($login, $this->playerCounters)) {
			return;
		}

		$this->playerCounters[$login] = $this->buildDefaultCounterRow();
	}

	/**
	 * @return array
	 */
	private function buildDefaultCounterRow() {
		return array(
			'kills' => 0,
			'deaths' => 0,
			'hits' => 0,
			'shots' => 0,
			'misses' => 0,
			'rockets' => 0,
			'lasers' => 0,
			'hits_rocket' => 0,
			'hits_laser' => 0,
			'attack_rounds_played' => 0,
			'attack_rounds_won' => 0,
			'defense_rounds_played' => 0,
			'defense_rounds_won' => 0,
		);
	}

	/**
	 * @param array $counters
	 * @return array
	 */
	private function withComputedFields(array $counters) {
		$shots = isset($counters['shots']) ? (int) $counters['shots'] : 0;
		$hits = isset($counters['hits']) ? (int) $counters['hits'] : 0;

		$accuracy = 0.0;
		if ($shots > 0) {
			$accuracy = round($hits / $shots, 4);
		}

		$counters['accuracy'] = $accuracy;

		$rockets = isset($counters['rockets']) ? (int) $counters['rockets'] : 0;
		$hitsRocket = isset($counters['hits_rocket']) ? (int) $counters['hits_rocket'] : 0;
		$counters['rocket_accuracy'] = ($rockets > 0) ? round($hitsRocket / $rockets, 4) : 0.0;

		$lasers = isset($counters['lasers']) ? (int) $counters['lasers'] : 0;
		$hitsLaser = isset($counters['hits_laser']) ? (int) $counters['hits_laser'] : 0;
		$counters['laser_accuracy'] = ($lasers > 0) ? round($hitsLaser / $lasers, 4) : 0.0;

		$attackRoundsPlayed = isset($counters['attack_rounds_played']) ? (int) $counters['attack_rounds_played'] : 0;
		$attackRoundsWon = isset($counters['attack_rounds_won']) ? (int) $counters['attack_rounds_won'] : 0;
		$counters['attack_win_rate'] = ($attackRoundsPlayed > 0) ? round($attackRoundsWon / $attackRoundsPlayed, 4) : 0.0;

		$defenseRoundsPlayed = isset($counters['defense_rounds_played']) ? (int) $counters['defense_rounds_played'] : 0;
		$defenseRoundsWon = isset($counters['defense_rounds_won']) ? (int) $counters['defense_rounds_won'] : 0;
		$counters['defense_win_rate'] = ($defenseRoundsPlayed > 0) ? round($defenseRoundsWon / $defenseRoundsPlayed, 4) : 0.0;

		return $counters;
	}

	/**
	 * Track a hit during an active Elite round for per-round evaluation.
	 *
	 * @param string $login Already normalized login
	 * @param int|null $weaponId
	 */
	private function trackEliteRoundHit($login, $weaponId = null) {
		if (!$this->eliteRoundActive) {
			return;
		}

		if (!isset($this->eliteRoundHits[$login])) {
			$this->eliteRoundHits[$login] = 0;
		}
		$this->eliteRoundHits[$login]++;

		if ($weaponId === self::WEAPON_ROCKET) {
			if (!isset($this->eliteRoundRocketHits[$login])) {
				$this->eliteRoundRocketHits[$login] = 0;
			}
			$this->eliteRoundRocketHits[$login]++;
		}
	}

	/**
	 * Track a death during an active Elite round for per-round evaluation.
	 *
	 * @param string $login Already normalized login
	 */
	private function trackEliteRoundDeath($login) {
		if (!$this->eliteRoundActive) {
			return;
		}

		if (!isset($this->eliteRoundDeaths[$login])) {
			$this->eliteRoundDeaths[$login] = 0;
		}
		$this->eliteRoundDeaths[$login]++;

		// Remove dead defenders from the alive list
		$aliveIndex = array_search($login, $this->eliteRoundAliveDefenders, true);
		if ($aliveIndex !== false) {
			array_splice($this->eliteRoundAliveDefenders, $aliveIndex, 1);
		}
	}

	/**
	 * Track a shot during an active Elite round.
	 *
	 * @param string $login Already normalized login
	 */
	private function trackEliteRoundShot($login) {
		if (!$this->eliteRoundActive) {
			return;
		}

		if (!isset($this->eliteRoundShots[$login])) {
			$this->eliteRoundShots[$login] = 0;
		}
		$this->eliteRoundShots[$login]++;
	}

	/**
	 * Track a miss during an active Elite round.
	 *
	 * @param string $login Already normalized login
	 */
	private function trackEliteRoundMiss($login) {
		if (!$this->eliteRoundActive) {
			return;
		}

		if (!isset($this->eliteRoundMisses[$login])) {
			$this->eliteRoundMisses[$login] = 0;
		}
		$this->eliteRoundMisses[$login]++;
	}

	/**
	 * Track a kill during an active Elite round.
	 *
	 * @param string $login Already normalized login (killer)
	 */
	private function trackEliteRoundKill($login) {
		if (!$this->eliteRoundActive) {
			return;
		}

		if (!isset($this->eliteRoundKills[$login])) {
			$this->eliteRoundKills[$login] = 0;
		}
		$this->eliteRoundKills[$login]++;
	}

	/**
	 * @param mixed $login
	 * @return string|null
	 */
	private function normalizeLogin($login) {
		$normalizedLogin = trim((string) $login);
		if ($normalizedLogin === '') {
			return null;
		}

		return $normalizedLogin;
	}
}
