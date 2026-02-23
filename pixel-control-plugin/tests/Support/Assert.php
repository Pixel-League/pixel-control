<?php
declare(strict_types=1);

namespace PixelControl\Tests\Support;

use RuntimeException;

class Assert {
	public static function true($condition, $message = 'Expected condition to be true.') {
		if (!$condition) {
			throw new RuntimeException((string) $message);
		}
	}

	public static function false($condition, $message = 'Expected condition to be false.') {
		if ($condition) {
			throw new RuntimeException((string) $message);
		}
	}

	public static function same($expected, $actual, $message = '') {
		if ($expected !== $actual) {
			$prefix = ($message !== '') ? ((string) $message . ' ') : '';
			throw new RuntimeException(
				$prefix
				. 'Expected `' . self::export($expected) . '` but received `' . self::export($actual) . '`.'
			);
		}
	}

	public static function notSame($expected, $actual, $message = '') {
		if ($expected === $actual) {
			$prefix = ($message !== '') ? ((string) $message . ' ') : '';
			throw new RuntimeException(
				$prefix
				. 'Did not expect `' . self::export($actual) . '`.'
			);
		}
	}

	public static function arrayHasKey($key, array $array, $message = '') {
		if (!array_key_exists($key, $array)) {
			$prefix = ($message !== '') ? ((string) $message . ' ') : '';
			throw new RuntimeException($prefix . 'Missing array key `' . (string) $key . '`.');
		}
	}

	public static function count($expectedCount, array $array, $message = '') {
		$actualCount = count($array);
		if ((int) $expectedCount !== $actualCount) {
			$prefix = ($message !== '') ? ((string) $message . ' ') : '';
			throw new RuntimeException(
				$prefix
				. 'Expected count `' . (int) $expectedCount . '` but received `' . $actualCount . '`.'
			);
		}
	}

	public static function inArray($needle, array $haystack, $message = '') {
		if (!in_array($needle, $haystack, true)) {
			$prefix = ($message !== '') ? ((string) $message . ' ') : '';
			throw new RuntimeException(
				$prefix
				. 'Expected value `' . self::export($needle) . '` to be present in array.'
			);
		}
	}

	private static function export($value) {
		$encoded = json_encode($value);
		if (is_string($encoded) && $encoded !== '') {
			return $encoded;
		}

		return var_export($value, true);
	}
}
