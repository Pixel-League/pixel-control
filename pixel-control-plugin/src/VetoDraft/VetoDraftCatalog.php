<?php

namespace PixelControl\VetoDraft;

class VetoDraftCatalog {
	const MODE_MATCHMAKING_VOTE = 'matchmaking_vote';
	const MODE_TOURNAMENT_DRAFT = 'tournament_draft';

	const TEAM_A = 'team_a';
	const TEAM_B = 'team_b';
	const TEAM_SYSTEM = 'system';
	const STARTER_RANDOM = 'random';

	const ACTION_BAN = 'ban';
	const ACTION_PICK = 'pick';
	const ACTION_PASS = 'pass';
	const ACTION_LOCK = 'lock';

	const STATUS_IDLE = 'idle';
	const STATUS_RUNNING = 'running';
	const STATUS_COMPLETED = 'completed';
	const STATUS_CANCELLED = 'cancelled';

	const RIGHT_CONTROL = 'Pixel Control Veto Draft: Control';
	const RIGHT_OVERRIDE = 'Pixel Control Veto Draft: Override';

	const COMMUNICATION_START = 'PixelControl.VetoDraft.Start';
	const COMMUNICATION_ACTION = 'PixelControl.VetoDraft.Action';
	const COMMUNICATION_STATUS = 'PixelControl.VetoDraft.Status';
	const COMMUNICATION_CANCEL = 'PixelControl.VetoDraft.Cancel';

	const DEFAULT_COMMAND = 'pcveto';
	const DEFAULT_MATCHMAKING_DURATION_SECONDS = 60;
	const DEFAULT_MATCHMAKING_AUTOSTART_MIN_PLAYERS = 2;
	const DEFAULT_TOURNAMENT_ACTION_TIMEOUT_SECONDS = 45;
	const DEFAULT_BEST_OF = 3;
	const MAX_BEST_OF = 9;

	public static function normalizeMode($mode, $fallback = self::MODE_MATCHMAKING_VOTE) {
		$normalized = strtolower(trim((string) $mode));
		if ($normalized === '') {
			return $fallback;
		}

		if (in_array($normalized, array('matchmaking', 'vote', 'mm'), true)) {
			return self::MODE_MATCHMAKING_VOTE;
		}

		if (in_array($normalized, array('tournament', 'competitive', 'draft', 'veto', 'bo'), true)) {
			return self::MODE_TOURNAMENT_DRAFT;
		}

		if ($normalized === self::MODE_MATCHMAKING_VOTE || $normalized === self::MODE_TOURNAMENT_DRAFT) {
			return $normalized;
		}

		return $fallback;
	}

	public static function normalizeCommandName($rawCommandName, $fallback = self::DEFAULT_COMMAND) {
		$normalized = strtolower(trim((string) $rawCommandName));
		$normalized = preg_replace('/[^a-z0-9_]+/', '', $normalized);
		if (!is_string($normalized) || $normalized === '') {
			return $fallback;
		}

		if ($normalized === 'admin') {
			return $fallback;
		}

		return $normalized;
	}

	public static function normalizeTeam($team, $fallback = self::TEAM_A) {
		$normalized = strtolower(trim((string) $team));
		if ($normalized === '') {
			return $fallback;
		}

		if (in_array($normalized, array('a', 'teama', 'team_a', 'blue', 'left'), true)) {
			return self::TEAM_A;
		}

		if (in_array($normalized, array('b', 'teamb', 'team_b', 'red', 'right'), true)) {
			return self::TEAM_B;
		}

		if ($normalized === self::STARTER_RANDOM) {
			return self::STARTER_RANDOM;
		}

		if ($normalized === self::TEAM_A || $normalized === self::TEAM_B || $normalized === self::TEAM_SYSTEM) {
			return $normalized;
		}

		return $fallback;
	}

	public static function normalizeStarterTeam($starterTeam, $fallback = self::TEAM_A) {
		$normalizedStarter = self::normalizeTeam($starterTeam, $fallback);
		if ($normalizedStarter !== self::STARTER_RANDOM) {
			return $normalizedStarter;
		}

		return self::pickRandomValue(array(self::TEAM_A, self::TEAM_B), $fallback);
	}

	public static function oppositeTeam($team) {
		$normalizedTeam = self::normalizeTeam($team, self::TEAM_A);
		if ($normalizedTeam === self::TEAM_A) {
			return self::TEAM_B;
		}

		if ($normalizedTeam === self::TEAM_B) {
			return self::TEAM_A;
		}

		return self::TEAM_A;
	}

	public static function sanitizeBestOf($bestOf, $fallback = self::DEFAULT_BEST_OF) {
		$normalized = is_numeric($bestOf) ? (int) $bestOf : (int) $fallback;
		if ($normalized < 1) {
			$normalized = (int) $fallback;
		}

		if ($normalized < 1) {
			$normalized = 1;
		}

		if (($normalized % 2) === 0) {
			$normalized++;
		}

		if ($normalized > self::MAX_BEST_OF) {
			$normalized = self::MAX_BEST_OF;
		}

		if (($normalized % 2) === 0) {
			$normalized--;
		}

		return max(1, $normalized);
	}

	public static function sanitizePositiveInt($value, $fallback, $minimum) {
		$normalized = is_numeric($value) ? (int) $value : (int) $fallback;
		if ($normalized < $minimum) {
			$normalized = (int) $minimum;
		}

		return $normalized;
	}

	public static function buildMatchmakingCountdownSeconds($durationSeconds) {
		$durationSeconds = self::sanitizePositiveInt($durationSeconds, self::DEFAULT_MATCHMAKING_DURATION_SECONDS, 10);

		$countdownSeconds = array();
		$countdownSeconds[] = $durationSeconds;

		$nextTenSecondBoundary = (int) floor(($durationSeconds - 1) / 10) * 10;
		for ($remainingSeconds = $nextTenSecondBoundary; $remainingSeconds >= 10; $remainingSeconds -= 10) {
			if (!in_array($remainingSeconds, $countdownSeconds, true)) {
				$countdownSeconds[] = $remainingSeconds;
			}
		}

		for ($remainingSeconds = 5; $remainingSeconds >= 1; $remainingSeconds--) {
			if (!in_array($remainingSeconds, $countdownSeconds, true)) {
				$countdownSeconds[] = $remainingSeconds;
			}
		}

		return $countdownSeconds;
	}

	public static function buildAbbaTurnOrder($turnCount, $starterTeam) {
		$turnCount = max(0, (int) $turnCount);
		if ($turnCount === 0) {
			return array();
		}

		$starterTeam = self::normalizeTeam($starterTeam, self::TEAM_A);
		if ($starterTeam !== self::TEAM_A && $starterTeam !== self::TEAM_B) {
			$starterTeam = self::TEAM_A;
		}

		$otherTeam = self::oppositeTeam($starterTeam);
		$pattern = array($starterTeam, $otherTeam, $otherTeam, $starterTeam);
		$order = array();

		for ($index = 0; $index < $turnCount; $index++) {
			$order[] = $pattern[$index % 4];
		}

		return $order;
	}

	public static function pickRandomValue(array $values, $fallback = null) {
		if (empty($values)) {
			return $fallback;
		}

		$lastIndex = count($values) - 1;
		if ($lastIndex <= 0) {
			return $values[0];
		}

		try {
			$randomIndex = random_int(0, $lastIndex);
		} catch (\Exception $exception) {
			$randomIndex = mt_rand(0, $lastIndex);
		}

		if (!isset($values[$randomIndex])) {
			return $values[0];
		}

		return $values[$randomIndex];
	}
}
