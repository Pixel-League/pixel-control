<?php

namespace PixelControl\SeriesControl;

class SeriesControlCatalog {
	const PARAM_BEST_OF = 'best_of';
	const PARAM_TARGET_TEAM = 'target_team';
	const PARAM_MAPS_SCORE = 'maps_score';
	const PARAM_SCORE = 'score';

	const ALIAS_BEST_OF = 'bo';
	const ALIAS_TARGET_TEAM = 'team';
	const ALIAS_TARGET_TEAM_ALT = 'target';

	const TEAM_A = 'team_a';
	const TEAM_B = 'team_b';

	const UPDATE_SOURCE_ENV = 'env';
	const UPDATE_SOURCE_SETTING = 'setting';
	const UPDATE_SOURCE_CHAT = 'chat';
	const UPDATE_SOURCE_COMMUNICATION = 'communication';

	const DEFAULT_BEST_OF = 3;
	const MIN_BEST_OF = 1;
	const MAX_BEST_OF = 9;

	const DEFAULT_TEAM_A_MAPS_SCORE = 0;
	const DEFAULT_TEAM_B_MAPS_SCORE = 0;
	const MIN_MAPS_SCORE = 0;
	const MAX_MAPS_SCORE = 99;

	const DEFAULT_TEAM_A_CURRENT_MAP_SCORE = 0;
	const DEFAULT_TEAM_B_CURRENT_MAP_SCORE = 0;
	const MIN_CURRENT_MAP_SCORE = 0;
	const MAX_CURRENT_MAP_SCORE = 999;

	public static function isIntegerLike($value) {
		if (is_int($value)) {
			return true;
		}

		if (!is_string($value)) {
			return false;
		}

		$trimmed = trim($value);
		if ($trimmed === '') {
			return false;
		}

		return (bool) preg_match('/^-?[0-9]+$/', $trimmed);
	}

	public static function sanitizeBestOf($bestOf, $fallback = self::DEFAULT_BEST_OF) {
		$normalizedFallback = self::isIntegerLike($fallback) ? (int) $fallback : self::DEFAULT_BEST_OF;
		$normalized = self::isIntegerLike($bestOf) ? (int) $bestOf : $normalizedFallback;

		if ($normalized < self::MIN_BEST_OF) {
			$normalized = $normalizedFallback;
		}

		if ($normalized < self::MIN_BEST_OF) {
			$normalized = self::MIN_BEST_OF;
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

		return max(self::MIN_BEST_OF, $normalized);
	}

	public static function normalizeUpdateSource($updateSource, $fallback = self::UPDATE_SOURCE_CHAT) {
		$normalized = strtolower(trim((string) $updateSource));
		if ($normalized === self::UPDATE_SOURCE_ENV || $normalized === self::UPDATE_SOURCE_SETTING || $normalized === self::UPDATE_SOURCE_CHAT || $normalized === self::UPDATE_SOURCE_COMMUNICATION) {
			return $normalized;
		}

		$fallback = strtolower(trim((string) $fallback));
		if ($fallback === self::UPDATE_SOURCE_ENV || $fallback === self::UPDATE_SOURCE_SETTING || $fallback === self::UPDATE_SOURCE_CHAT || $fallback === self::UPDATE_SOURCE_COMMUNICATION) {
			return $fallback;
		}

		return self::UPDATE_SOURCE_CHAT;
	}

	public static function normalizeUpdatedBy($updatedBy, $fallback = 'system') {
		$normalized = trim((string) $updatedBy);
		if ($normalized !== '') {
			return $normalized;
		}

		$fallback = trim((string) $fallback);
		if ($fallback !== '') {
			return $fallback;
		}

		return 'system';
	}

	public static function normalizeTargetTeam($targetTeam, $fallback = '') {
		$normalized = strtolower(trim((string) $targetTeam));
		$normalized = trim($normalized, "\"'");

		if ($normalized === '') {
			$normalized = strtolower(trim((string) $fallback));
			$normalized = trim($normalized, "\"'");
		}

		if (in_array($normalized, array('0', 'blue', 'teama', 'team_a', 'a'), true)) {
			return self::TEAM_A;
		}

		if (in_array($normalized, array('1', 'red', 'teamb', 'team_b', 'b'), true)) {
			return self::TEAM_B;
		}

		if ($normalized === self::TEAM_A || $normalized === self::TEAM_B) {
			return $normalized;
		}

		return '';
	}

	public static function sanitizeMapsScore($mapsScore, $fallback = self::DEFAULT_TEAM_A_MAPS_SCORE) {
		$normalizedFallback = self::isIntegerLike($fallback) ? (int) $fallback : self::DEFAULT_TEAM_A_MAPS_SCORE;
		$normalized = self::isIntegerLike($mapsScore) ? (int) $mapsScore : $normalizedFallback;

		if ($normalized < self::MIN_MAPS_SCORE) {
			$normalized = $normalizedFallback;
		}

		$normalized = max(self::MIN_MAPS_SCORE, $normalized);
		$normalized = min(self::MAX_MAPS_SCORE, $normalized);

		return $normalized;
	}

	public static function sanitizeCurrentMapScore($score, $fallback = self::DEFAULT_TEAM_A_CURRENT_MAP_SCORE) {
		$normalizedFallback = self::isIntegerLike($fallback) ? (int) $fallback : self::DEFAULT_TEAM_A_CURRENT_MAP_SCORE;
		$normalized = self::isIntegerLike($score) ? (int) $score : $normalizedFallback;

		if ($normalized < self::MIN_CURRENT_MAP_SCORE) {
			$normalized = $normalizedFallback;
		}

		$normalized = max(self::MIN_CURRENT_MAP_SCORE, $normalized);
		$normalized = min(self::MAX_CURRENT_MAP_SCORE, $normalized);

		return $normalized;
	}
}
