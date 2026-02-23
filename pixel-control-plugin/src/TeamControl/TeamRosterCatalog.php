<?php

namespace PixelControl\TeamControl;

class TeamRosterCatalog {
	const TEAM_A = 'team_a';
	const TEAM_B = 'team_b';

	const UPDATE_SOURCE_ENV = 'env';
	const UPDATE_SOURCE_SETTING = 'setting';
	const UPDATE_SOURCE_CHAT = 'chat';
	const UPDATE_SOURCE_COMMUNICATION = 'communication';

	const DEFAULT_POLICY_ENABLED = false;
	const DEFAULT_SWITCH_LOCK = true;

	public static function normalizeUpdateSource($updateSource, $fallback = self::UPDATE_SOURCE_SETTING) {
		$normalized = strtolower(trim((string) $updateSource));
		if (
			$normalized === self::UPDATE_SOURCE_ENV
			|| $normalized === self::UPDATE_SOURCE_SETTING
			|| $normalized === self::UPDATE_SOURCE_CHAT
			|| $normalized === self::UPDATE_SOURCE_COMMUNICATION
		) {
			return $normalized;
		}

		$fallback = strtolower(trim((string) $fallback));
		if (
			$fallback === self::UPDATE_SOURCE_ENV
			|| $fallback === self::UPDATE_SOURCE_SETTING
			|| $fallback === self::UPDATE_SOURCE_CHAT
			|| $fallback === self::UPDATE_SOURCE_COMMUNICATION
		) {
			return $fallback;
		}

		return self::UPDATE_SOURCE_SETTING;
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

	public static function normalizeBool($value, $fallback = false) {
		if (is_bool($value)) {
			return $value;
		}

		if (is_numeric($value)) {
			return ((int) $value) !== 0;
		}

		if (is_string($value)) {
			$normalized = strtolower(trim($value));
			if (in_array($normalized, array('1', 'true', 'yes', 'on', 'enabled'), true)) {
				return true;
			}

			if (in_array($normalized, array('0', 'false', 'no', 'off', 'disabled'), true)) {
				return false;
			}
		}

		return (bool) $fallback;
	}

	public static function normalizeNullableBool($value) {
		if ($value === null || $value === '') {
			return null;
		}

		return self::normalizeBool($value, false);
	}

	public static function normalizeLogin($login) {
		$normalized = strtolower(trim((string) $login));
		$normalized = trim($normalized, "\"'");

		return $normalized;
	}

	public static function normalizeTeamKey($team, $fallback = '') {
		$normalized = strtolower(trim((string) $team));
		$normalized = trim($normalized, "\"'");

		if ($normalized === '') {
			$normalized = strtolower(trim((string) $fallback));
			$normalized = trim($normalized, "\"'");
		}

		if (in_array($normalized, array('0', 'blue', 'team_a', 'teama', 'a'), true)) {
			return self::TEAM_A;
		}

		if (in_array($normalized, array('1', 'red', 'team_b', 'teamb', 'b'), true)) {
			return self::TEAM_B;
		}

		if ($normalized === self::TEAM_A || $normalized === self::TEAM_B) {
			return $normalized;
		}

		return '';
	}

	public static function resolveTeamId($teamKey) {
		$teamKey = self::normalizeTeamKey($teamKey);
		if ($teamKey === self::TEAM_A) {
			return 0;
		}

		if ($teamKey === self::TEAM_B) {
			return 1;
		}

		return null;
	}

	public static function normalizeAssignments($assignments) {
		if (!is_array($assignments)) {
			return array();
		}

		$normalizedAssignments = array();
		foreach ($assignments as $rawLogin => $rawTeam) {
			$candidateLogin = is_string($rawLogin) ? $rawLogin : '';
			$candidateTeam = $rawTeam;

			if (is_array($rawTeam)) {
				if ($candidateLogin === '' && isset($rawTeam['login'])) {
					$candidateLogin = (string) $rawTeam['login'];
				}

				if (isset($rawTeam['team'])) {
					$candidateTeam = $rawTeam['team'];
				} else if (isset($rawTeam['team_key'])) {
					$candidateTeam = $rawTeam['team_key'];
				} else if (isset($rawTeam['team_id'])) {
					$candidateTeam = $rawTeam['team_id'];
				}
			}

			$normalizedLogin = self::normalizeLogin($candidateLogin);
			if ($normalizedLogin === '') {
				continue;
			}

			$normalizedTeam = self::normalizeTeamKey($candidateTeam);
			if ($normalizedTeam === '') {
				continue;
			}

			$normalizedAssignments[$normalizedLogin] = $normalizedTeam;
		}

		ksort($normalizedAssignments);

		return $normalizedAssignments;
	}

	public static function parseAssignments($rawValue) {
		if (is_array($rawValue)) {
			return self::normalizeAssignments($rawValue);
		}

		$rawValue = trim((string) $rawValue);
		if ($rawValue === '') {
			return array();
		}

		$decoded = json_decode($rawValue, true);
		if (!is_array($decoded)) {
			return array();
		}

		return self::normalizeAssignments($decoded);
	}

	public static function encodeAssignments(array $assignments) {
		$normalizedAssignments = self::normalizeAssignments($assignments);
		$json = json_encode($normalizedAssignments);
		if (!is_string($json) || $json === '') {
			return '{}';
		}

		return $json;
	}
}
