<?php

namespace PixelControl\TeamControl;

class TeamRosterState implements TeamRosterStateInterface {
	/** @var bool $policyEnabled */
	private $policyEnabled = TeamRosterCatalog::DEFAULT_POLICY_ENABLED;
	/** @var bool $switchLockEnabled */
	private $switchLockEnabled = TeamRosterCatalog::DEFAULT_SWITCH_LOCK;
	/** @var array $assignments */
	private $assignments = array();
	/** @var int $updatedAt */
	private $updatedAt = 0;
	/** @var string $updatedBy */
	private $updatedBy = 'system';
	/** @var string $updateSource */
	private $updateSource = TeamRosterCatalog::UPDATE_SOURCE_SETTING;

	public function bootstrap(array $defaults, $updateSource, $updatedBy) {
		$this->policyEnabled = TeamRosterCatalog::normalizeBool(
			$this->readValue($defaults, array('policy_enabled', 'enabled'), TeamRosterCatalog::DEFAULT_POLICY_ENABLED),
			TeamRosterCatalog::DEFAULT_POLICY_ENABLED
		);
		$this->switchLockEnabled = TeamRosterCatalog::normalizeBool(
			$this->readValue($defaults, array('switch_lock_enabled', 'switch_lock'), TeamRosterCatalog::DEFAULT_SWITCH_LOCK),
			TeamRosterCatalog::DEFAULT_SWITCH_LOCK
		);
		$this->assignments = TeamRosterCatalog::normalizeAssignments(
			$this->readValue($defaults, array('assignments'), array())
		);
		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			'team_roster_bootstrap_applied',
			'Team roster defaults initialized.',
			array('changed_fields' => array('policy_enabled', 'switch_lock_enabled', 'assignments'))
		);
	}

	public function reset() {
		$this->policyEnabled = TeamRosterCatalog::DEFAULT_POLICY_ENABLED;
		$this->switchLockEnabled = TeamRosterCatalog::DEFAULT_SWITCH_LOCK;
		$this->assignments = array();
		$this->updatedAt = 0;
		$this->updatedBy = 'system';
		$this->updateSource = TeamRosterCatalog::UPDATE_SOURCE_SETTING;
	}

	public function getSnapshot() {
		$entries = array();
		foreach ($this->assignments as $login => $teamKey) {
			$entries[] = array(
				'login' => $login,
				'team_key' => $teamKey,
				'team_id' => TeamRosterCatalog::resolveTeamId($teamKey),
			);
		}

		return array(
			'policy_enabled' => $this->policyEnabled,
			'switch_lock_enabled' => $this->switchLockEnabled,
			'assignments' => $this->assignments,
			'assignment_entries' => $entries,
			'assignment_count' => count($this->assignments),
			'updated_at' => $this->updatedAt,
			'updated_by' => $this->updatedBy,
			'update_source' => $this->updateSource,
			'policy' => array(
				'teams' => array('0|blue|team_a', '1|red|team_b'),
				'mode_scope' => 'team_mode_only',
			),
		);
	}

	public function setPolicy($enabled, $switchLock, $updateSource, $updatedBy, array $context = array()) {
		$normalizedEnabled = TeamRosterCatalog::normalizeNullableBool($enabled);
		$normalizedSwitchLock = TeamRosterCatalog::normalizeNullableBool($switchLock);
		if ($normalizedEnabled === null && $normalizedSwitchLock === null) {
			return $this->buildFailure(
				'missing_parameters',
				'Action requires enabled and/or switch_lock parameter.',
				array('fields' => array('enabled', 'switch_lock'))
			);
		}

		$changedFields = array();
		if ($normalizedEnabled !== null && $normalizedEnabled !== $this->policyEnabled) {
			$this->policyEnabled = $normalizedEnabled;
			$changedFields[] = 'policy_enabled';
		}

		if ($normalizedSwitchLock !== null && $normalizedSwitchLock !== $this->switchLockEnabled) {
			$this->switchLockEnabled = $normalizedSwitchLock;
			$changedFields[] = 'switch_lock_enabled';
		}

		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			'team_policy_updated',
			(empty($changedFields) ? 'Team policy unchanged.' : 'Team policy updated.'),
			array(
				'changed_fields' => $changedFields,
				'policy_enabled' => $this->policyEnabled,
				'switch_lock_enabled' => $this->switchLockEnabled,
			)
		);
	}

	public function assign($login, $team, $updateSource, $updatedBy, array $context = array()) {
		$normalizedLogin = TeamRosterCatalog::normalizeLogin($login);
		if ($normalizedLogin === '') {
			return $this->buildFailure('invalid_parameters', 'target_login is required.', array('field' => 'target_login'));
		}

		$normalizedTeam = TeamRosterCatalog::normalizeTeamKey($team);
		if ($normalizedTeam === '') {
			return $this->buildFailure('invalid_parameters', 'team must be one of: 0, 1, red, blue.', array('field' => 'team'));
		}

		$changed = !isset($this->assignments[$normalizedLogin]) || $this->assignments[$normalizedLogin] !== $normalizedTeam;
		$this->assignments[$normalizedLogin] = $normalizedTeam;
		ksort($this->assignments);
		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			($changed ? 'team_assignment_updated' : 'team_assignment_unchanged'),
			($changed ? 'Team assignment stored.' : 'Team assignment unchanged.'),
			array(
				'target_login' => $normalizedLogin,
				'team_key' => $normalizedTeam,
				'team_id' => TeamRosterCatalog::resolveTeamId($normalizedTeam),
				'changed_fields' => ($changed ? array('assignments') : array()),
			)
		);
	}

	public function unassign($login, $updateSource, $updatedBy, array $context = array()) {
		$normalizedLogin = TeamRosterCatalog::normalizeLogin($login);
		if ($normalizedLogin === '') {
			return $this->buildFailure('invalid_parameters', 'target_login is required.', array('field' => 'target_login'));
		}

		$present = isset($this->assignments[$normalizedLogin]);
		if ($present) {
			unset($this->assignments[$normalizedLogin]);
		}

		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			($present ? 'team_assignment_removed' : 'team_assignment_missing'),
			($present ? 'Team assignment removed.' : 'No assignment found for target login.'),
			array(
				'target_login' => $normalizedLogin,
				'changed_fields' => ($present ? array('assignments') : array()),
			)
		);
	}

	public function replaceAssignments(array $assignments, $updateSource, $updatedBy, array $context = array()) {
		$normalizedAssignments = TeamRosterCatalog::normalizeAssignments($assignments);
		$changed = $normalizedAssignments !== $this->assignments;
		$this->assignments = $normalizedAssignments;
		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess(
			'team_assignments_replaced',
			($changed ? 'Team assignments replaced.' : 'Team assignments unchanged.'),
			array('changed_fields' => ($changed ? array('assignments') : array()))
		);
	}

	public function isPolicyEnabled() {
		return $this->policyEnabled;
	}

	public function isSwitchLockEnabled() {
		return $this->switchLockEnabled;
	}

	public function hasAssignment($login) {
		$normalizedLogin = TeamRosterCatalog::normalizeLogin($login);
		if ($normalizedLogin === '') {
			return false;
		}

		return isset($this->assignments[$normalizedLogin]);
	}

	public function getAssignedTeam($login) {
		$normalizedLogin = TeamRosterCatalog::normalizeLogin($login);
		if ($normalizedLogin === '' || !isset($this->assignments[$normalizedLogin])) {
			return '';
		}

		return $this->assignments[$normalizedLogin];
	}

	public function getAssignedTeamId($login) {
		$teamKey = $this->getAssignedTeam($login);
		if ($teamKey === '') {
			return null;
		}

		return TeamRosterCatalog::resolveTeamId($teamKey);
	}

	public function getAssignments() {
		return $this->assignments;
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
		$this->updateSource = TeamRosterCatalog::normalizeUpdateSource($updateSource, $this->updateSource);
		$this->updatedBy = TeamRosterCatalog::normalizeUpdatedBy($updatedBy, $this->updatedBy);
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
