<?php

namespace PixelControl\Stats;

class PlayerCombatStatsStore {
	const WEAPON_LASER = 1;
	const WEAPON_ROCKET = 2;

	/** @var array $playerCounters */
	private $playerCounters = array();

	/**
	 * Reset all tracked counters.
	 */
	public function reset() {
		$this->playerCounters = array();
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
		}

		$normalizedVictimLogin = $this->normalizeLogin($victimLogin);
		if ($normalizedVictimLogin !== null) {
			$this->ensurePlayer($normalizedVictimLogin);
			$this->playerCounters[$normalizedVictimLogin]['deaths']++;
		}
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

		return $counters;
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
