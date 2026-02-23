<?php

namespace PixelControl\AccessControl;

class WhitelistState implements WhitelistStateInterface {
	/** @var bool $enabled */
	private $enabled = WhitelistCatalog::DEFAULT_ENABLED;
	/** @var string[] $logins */
	private $logins = array();
	/** @var int $updatedAt */
	private $updatedAt = 0;
	/** @var string $updatedBy */
	private $updatedBy = 'system';
	/** @var string $updateSource */
	private $updateSource = WhitelistCatalog::UPDATE_SOURCE_SETTING;

	public function bootstrap(array $defaults, $updateSource, $updatedBy) {
		$this->enabled = WhitelistCatalog::normalizeEnabled(
			$this->readValue($defaults, array('enabled'), WhitelistCatalog::DEFAULT_ENABLED),
			WhitelistCatalog::DEFAULT_ENABLED
		);
		$this->logins = WhitelistCatalog::parseLogins(
			$this->readValue($defaults, array('logins'), array())
		);
		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			'whitelist_bootstrap_applied',
			'Whitelist defaults initialized.',
			array('changed_fields' => array('enabled', 'logins'))
		);
	}

	public function reset() {
		$this->enabled = WhitelistCatalog::DEFAULT_ENABLED;
		$this->logins = array();
		$this->updatedAt = 0;
		$this->updatedBy = 'system';
		$this->updateSource = WhitelistCatalog::UPDATE_SOURCE_SETTING;
	}

	public function getSnapshot() {
		return array(
			'enabled' => $this->enabled,
			'logins' => $this->logins,
			'count' => count($this->logins),
			'updated_at' => $this->updatedAt,
			'updated_by' => $this->updatedBy,
			'update_source' => $this->updateSource,
			'policy' => array(
				'login_normalization' => 'lowercase_trimmed',
				'deduplication' => true,
			),
		);
	}

	public function setEnabled($enabled, $updateSource, $updatedBy, array $context = array()) {
		$normalizedEnabled = WhitelistCatalog::normalizeEnabled($enabled, $this->enabled);
		$changedFields = array();
		if ($normalizedEnabled !== $this->enabled) {
			$changedFields[] = 'enabled';
		}

		$this->enabled = $normalizedEnabled;
		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			'whitelist_enabled_updated',
			($normalizedEnabled ? 'Whitelist enabled.' : 'Whitelist disabled.'),
			array(
				'changed_fields' => $changedFields,
				'enabled' => $normalizedEnabled,
			)
		);
	}

	public function addLogin($login, $updateSource, $updatedBy, array $context = array()) {
		$normalizedLogin = WhitelistCatalog::normalizeLogin($login);
		if ($normalizedLogin === '') {
			return $this->buildFailure('invalid_parameters', 'target_login is required.', array('field' => 'target_login'));
		}

		$alreadyPresent = in_array($normalizedLogin, $this->logins, true);
		if (!$alreadyPresent) {
			$this->logins[] = $normalizedLogin;
			sort($this->logins);
		}

		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			($alreadyPresent ? 'whitelist_login_present' : 'whitelist_login_added'),
			($alreadyPresent ? 'Login already present in whitelist.' : 'Login added to whitelist.'),
			array(
				'target_login' => $normalizedLogin,
				'changed_fields' => ($alreadyPresent ? array() : array('logins')),
			)
		);
	}

	public function removeLogin($login, $updateSource, $updatedBy, array $context = array()) {
		$normalizedLogin = WhitelistCatalog::normalizeLogin($login);
		if ($normalizedLogin === '') {
			return $this->buildFailure('invalid_parameters', 'target_login is required.', array('field' => 'target_login'));
		}

		$present = in_array($normalizedLogin, $this->logins, true);
		if ($present) {
			$this->logins = array_values(array_filter(
				$this->logins,
				function ($entry) use ($normalizedLogin) {
					return $entry !== $normalizedLogin;
				}
			));
		}

		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			($present ? 'whitelist_login_removed' : 'whitelist_login_missing'),
			($present ? 'Login removed from whitelist.' : 'Login was not present in whitelist.'),
			array(
				'target_login' => $normalizedLogin,
				'changed_fields' => ($present ? array('logins') : array()),
			)
		);
	}

	public function clean($updateSource, $updatedBy, array $context = array()) {
		$hadEntries = !empty($this->logins);
		$this->logins = array();
		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			($hadEntries ? 'whitelist_cleaned' : 'whitelist_already_empty'),
			($hadEntries ? 'Whitelist logins removed.' : 'Whitelist already empty.'),
			array('changed_fields' => ($hadEntries ? array('logins') : array()))
		);
	}

	public function replaceLogins(array $logins, $updateSource, $updatedBy, array $context = array()) {
		$normalizedLogins = WhitelistCatalog::normalizeLogins($logins);
		$changed = $normalizedLogins !== $this->logins;
		$this->logins = $normalizedLogins;
		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			'registry_replaced',
			($changed ? 'Whitelist logins replaced.' : 'Whitelist logins unchanged.'),
			array('changed_fields' => ($changed ? array('logins') : array()))
		);
	}

	public function isEnabled() {
		return $this->enabled;
	}

	public function hasLogin($login) {
		$normalizedLogin = WhitelistCatalog::normalizeLogin($login);
		if ($normalizedLogin === '') {
			return false;
		}

		return in_array($normalizedLogin, $this->logins, true);
	}

	public function getLogins() {
		return $this->logins;
	}

	private function readValue(array $values, array $keys, $fallback) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $values)) {
				continue;
			}

			return $values[$key];
		}

		return $fallback;
	}

	private function markUpdated($updateSource, $updatedBy) {
		$this->updatedAt = time();
		$this->updateSource = WhitelistCatalog::normalizeUpdateSource($updateSource, $this->updateSource);
		$this->updatedBy = WhitelistCatalog::normalizeUpdatedBy($updatedBy, $this->updatedBy);
	}

	private function buildSuccess($code, $message, array $details = array()) {
		return array(
			'success' => true,
			'code' => trim((string) $code),
			'message' => trim((string) $message),
			'snapshot' => $this->getSnapshot(),
			'details' => $details,
		);
	}

	private function buildFailure($code, $message, array $details = array()) {
		return array(
			'success' => false,
			'code' => trim((string) $code),
			'message' => trim((string) $message),
			'snapshot' => $this->getSnapshot(),
			'details' => $details,
		);
	}
}
