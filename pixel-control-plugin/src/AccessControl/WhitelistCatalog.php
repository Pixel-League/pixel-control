<?php

namespace PixelControl\AccessControl;

class WhitelistCatalog {
	const UPDATE_SOURCE_ENV = 'env';
	const UPDATE_SOURCE_SETTING = 'setting';
	const UPDATE_SOURCE_CHAT = 'chat';
	const UPDATE_SOURCE_COMMUNICATION = 'communication';

	const DEFAULT_ENABLED = false;

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

	public static function normalizeEnabled($enabled, $fallback = self::DEFAULT_ENABLED) {
		if (is_bool($enabled)) {
			return $enabled;
		}

		if (is_numeric($enabled)) {
			return ((int) $enabled) !== 0;
		}

		if (is_string($enabled)) {
			$normalized = strtolower(trim($enabled));
			if (in_array($normalized, array('1', 'true', 'yes', 'on', 'enabled'), true)) {
				return true;
			}

			if (in_array($normalized, array('0', 'false', 'no', 'off', 'disabled'), true)) {
				return false;
			}
		}

		return (bool) $fallback;
	}

	public static function normalizeLogin($login) {
		$normalized = strtolower(trim((string) $login));
		$normalized = trim($normalized, "\"'");

		return $normalized;
	}

	public static function normalizeLogins(array $logins) {
		$normalizedLogins = array();
		foreach ($logins as $login) {
			$normalizedLogin = self::normalizeLogin($login);
			if ($normalizedLogin === '') {
				continue;
			}

			$normalizedLogins[$normalizedLogin] = true;
		}

		$normalizedLogins = array_keys($normalizedLogins);
		sort($normalizedLogins);

		return $normalizedLogins;
	}

	public static function parseLogins($rawValue) {
		if (is_array($rawValue)) {
			return self::normalizeLogins($rawValue);
		}

		$rawValue = trim((string) $rawValue);
		if ($rawValue === '') {
			return array();
		}

		$decoded = json_decode($rawValue, true);
		if (is_array($decoded)) {
			return self::normalizeLogins($decoded);
		}

		$parts = preg_split('/[\s,;|]+/', $rawValue);
		if (!is_array($parts)) {
			return array();
		}

		return self::normalizeLogins($parts);
	}

	public static function encodeLogins(array $logins) {
		$normalizedLogins = self::normalizeLogins($logins);
		$json = json_encode($normalizedLogins);
		if (!is_string($json) || $json === '') {
			return '[]';
		}

		return $json;
	}
}
