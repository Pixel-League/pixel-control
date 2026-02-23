<?php

namespace PixelControl\VoteControl;

class VotePolicyCatalog {
	const MODE_CANCEL_NON_ADMIN = 'cancel_non_admin_vote_on_callback';
	const MODE_DISABLE_CALLVOTES = 'disable_callvotes_and_use_admin_actions';

	const UPDATE_SOURCE_ENV = 'env';
	const UPDATE_SOURCE_SETTING = 'setting';
	const UPDATE_SOURCE_CHAT = 'chat';
	const UPDATE_SOURCE_COMMUNICATION = 'communication';

	const DEFAULT_MODE = self::MODE_CANCEL_NON_ADMIN;

	public static function isSupportedMode($mode) {
		return self::resolveModeOrEmpty($mode) !== '';
	}

	public static function normalizeMode($mode, $fallback = self::DEFAULT_MODE) {
		$resolvedMode = self::resolveModeOrEmpty($mode);
		if ($resolvedMode !== '') {
			return $resolvedMode;
		}

		$resolvedFallback = self::resolveModeOrEmpty($fallback);
		if ($resolvedFallback !== '') {
			return $resolvedFallback;
		}

		return self::DEFAULT_MODE;
	}

	public static function isStrictMode($mode) {
		return self::normalizeMode($mode, self::DEFAULT_MODE) === self::MODE_DISABLE_CALLVOTES;
	}

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

	private static function resolveModeOrEmpty($mode) {
		$normalized = strtolower(trim((string) $mode));
		if ($normalized === self::MODE_CANCEL_NON_ADMIN || $normalized === self::MODE_DISABLE_CALLVOTES) {
			return $normalized;
		}

		$aliases = array(
			'cancel_non_admin' => self::MODE_CANCEL_NON_ADMIN,
			'cancel_non_admin_vote' => self::MODE_CANCEL_NON_ADMIN,
			'disable_all_non_admin' => self::MODE_DISABLE_CALLVOTES,
			'disable_callvotes' => self::MODE_DISABLE_CALLVOTES,
			'strict' => self::MODE_DISABLE_CALLVOTES,
		);
		if (array_key_exists($normalized, $aliases)) {
			return $aliases[$normalized];
		}

		return '';
	}
}
