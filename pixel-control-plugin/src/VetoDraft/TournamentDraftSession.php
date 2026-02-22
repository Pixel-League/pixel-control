<?php

namespace PixelControl\VetoDraft;

class TournamentDraftSession {
	/** @var string $sessionId */
	private $sessionId;
	/** @var string $status */
	private $status = VetoDraftCatalog::STATUS_IDLE;
	/** @var int $startedAt */
	private $startedAt = 0;
	/** @var int $resolvedAt */
	private $resolvedAt = 0;
	/** @var int $turnStartedAt */
	private $turnStartedAt = 0;
	/** @var int $actionTimeoutSeconds */
	private $actionTimeoutSeconds = VetoDraftCatalog::DEFAULT_TOURNAMENT_ACTION_TIMEOUT_SECONDS;
	/** @var string $resolutionReason */
	private $resolutionReason = 'pending';

	/** @var array[] $mapPool */
	private $mapPool = array();
	/** @var array $mapPoolByUid */
	private $mapPoolByUid = array();
	/** @var array $availableMapByUid */
	private $availableMapByUid = array();

	/** @var array $captainByTeam */
	private $captainByTeam = array();
	/** @var array $sequenceDefinition */
	private $sequenceDefinition = array();
	/** @var array[] $sequenceSteps */
	private $sequenceSteps = array();
	/** @var int $currentStepIndex */
	private $currentStepIndex = 0;

	/** @var array[] $actions */
	private $actions = array();
	/** @var string[] $pickedMapUids */
	private $pickedMapUids = array();
	/** @var string[] $bannedMapUids */
	private $bannedMapUids = array();

	public function __construct($sessionId, array $mapPool, array $captainByTeam, array $sequenceDefinition, $actionTimeoutSeconds, $startedAt) {
		$this->sessionId = trim((string) $sessionId);
		$this->startedAt = max(0, (int) $startedAt);
		$this->turnStartedAt = $this->startedAt;
		$this->actionTimeoutSeconds = VetoDraftCatalog::sanitizePositiveInt(
			$actionTimeoutSeconds,
			VetoDraftCatalog::DEFAULT_TOURNAMENT_ACTION_TIMEOUT_SECONDS,
			10
		);

		foreach ($mapPool as $mapIdentity) {
			if (!is_array($mapIdentity) || !isset($mapIdentity['uid'])) {
				continue;
			}

			$mapUid = strtolower(trim((string) $mapIdentity['uid']));
			if ($mapUid === '') {
				continue;
			}

			$this->mapPool[] = $mapIdentity;
			$this->mapPoolByUid[$mapUid] = $mapIdentity;
			$this->availableMapByUid[$mapUid] = $mapIdentity;
		}

		$this->captainByTeam[VetoDraftCatalog::TEAM_A] = strtolower(trim(isset($captainByTeam[VetoDraftCatalog::TEAM_A]) ? (string) $captainByTeam[VetoDraftCatalog::TEAM_A] : ''));
		$this->captainByTeam[VetoDraftCatalog::TEAM_B] = strtolower(trim(isset($captainByTeam[VetoDraftCatalog::TEAM_B]) ? (string) $captainByTeam[VetoDraftCatalog::TEAM_B] : ''));

		$this->sequenceDefinition = $sequenceDefinition;
		if (isset($sequenceDefinition['steps']) && is_array($sequenceDefinition['steps'])) {
			$this->sequenceSteps = array_values($sequenceDefinition['steps']);
		}

		if (!empty($this->sequenceSteps) && !empty($this->availableMapByUid)) {
			$this->status = VetoDraftCatalog::STATUS_RUNNING;
		} else {
			$this->status = VetoDraftCatalog::STATUS_CANCELLED;
			$this->resolutionReason = 'session_initialization_failed';
			$this->resolvedAt = $this->startedAt;
		}
	}

	public function isRunning() {
		return $this->status === VetoDraftCatalog::STATUS_RUNNING;
	}

	public function isTurnTimedOut($timestamp) {
		if (!$this->isRunning()) {
			return false;
		}

		$timestamp = max(0, (int) $timestamp);
		return ($timestamp - $this->turnStartedAt) >= $this->actionTimeoutSeconds;
	}

	public function getCurrentStep() {
		if (!$this->isRunning()) {
			return null;
		}

		if (!isset($this->sequenceSteps[$this->currentStepIndex])) {
			return null;
		}

		return $this->sequenceSteps[$this->currentStepIndex];
	}

	public function applyAction($actorLogin, $mapUid, $timestamp, $source, $allowOverride) {
		if (!$this->isRunning()) {
			return $this->failure('session_not_running', 'No active tournament draft session.', array('session' => $this->toArray()));
		}

		$step = $this->getCurrentStep();
		if (!$step) {
			$this->status = VetoDraftCatalog::STATUS_COMPLETED;
			$this->resolutionReason = 'sequence_exhausted';
			$this->resolvedAt = max(0, (int) $timestamp);
			return $this->failure('sequence_exhausted', 'Tournament draft sequence is already complete.', array('session' => $this->toArray()));
		}

		$actionKind = isset($step['action_kind']) ? (string) $step['action_kind'] : '';
		if ($actionKind === VetoDraftCatalog::ACTION_LOCK) {
			return $this->applySystemLock($timestamp, 'manual_lock_trigger', true);
		}

		$timestamp = max(0, (int) $timestamp);
		$actorLogin = strtolower(trim((string) $actorLogin));
		$normalizedMapUid = strtolower(trim((string) $mapUid));

		if ($normalizedMapUid === '') {
			return $this->failure('map_required', 'A map selection is required for this action.');
		}

		if (!isset($this->availableMapByUid[$normalizedMapUid])) {
			return $this->failure('map_unavailable', 'Selected map is not available in current pool.', array('map_uid' => $normalizedMapUid));
		}

		$expectedTeam = isset($step['team']) ? (string) $step['team'] : '';
		if (!$allowOverride && !$this->isActorAllowedForStep($actorLogin, $expectedTeam)) {
			return $this->failure(
				'actor_not_allowed',
				'Only the designated captain can play this turn.',
				array(
					'expected_team' => $expectedTeam,
					'expected_captain' => $this->getCaptainLoginForTeam($expectedTeam),
				)
			);
		}

		$result = $this->commitAction($step, $normalizedMapUid, $actorLogin, $timestamp, $source, false, '');
		if (!$result['success']) {
			return $result;
		}

		$autoLockResult = $this->autoLockIfNeeded($timestamp);
		if ($autoLockResult !== null) {
			$result['auto_lock'] = $autoLockResult;
			$result['session'] = $this->toArray();
		}

		return $result;
	}

	public function applyTimeoutFallback($timestamp) {
		if (!$this->isRunning()) {
			return $this->failure('session_not_running', 'No active tournament draft session.');
		}

		$step = $this->getCurrentStep();
		if (!$step) {
			return $this->failure('sequence_exhausted', 'Tournament draft sequence is already complete.');
		}

		$timestamp = max(0, (int) $timestamp);
		$actionKind = isset($step['action_kind']) ? (string) $step['action_kind'] : '';
		if ($actionKind === VetoDraftCatalog::ACTION_LOCK) {
			return $this->applySystemLock($timestamp, 'timeout_auto_lock', true);
		}

		$availableMapUids = array_keys($this->availableMapByUid);
		$selectedMapUid = strtolower((string) VetoDraftCatalog::pickRandomValue($availableMapUids, ''));
		if ($selectedMapUid === '') {
			$this->status = VetoDraftCatalog::STATUS_CANCELLED;
			$this->resolutionReason = 'no_available_map_for_timeout';
			$this->resolvedAt = $timestamp;
			return $this->failure('no_available_map', 'No available map for timeout fallback.', array('session' => $this->toArray()));
		}

		$expectedTeam = isset($step['team']) ? (string) $step['team'] : '';
		$actorLogin = $this->getCaptainLoginForTeam($expectedTeam);
		if ($actorLogin === '') {
			$actorLogin = VetoDraftCatalog::TEAM_SYSTEM;
		}

		$result = $this->commitAction($step, $selectedMapUid, $actorLogin, $timestamp, 'timeout_auto', true, 'turn_timeout');
		if (!$result['success']) {
			return $result;
		}

		$autoLockResult = $this->autoLockIfNeeded($timestamp);
		if ($autoLockResult !== null) {
			$result['auto_lock'] = $autoLockResult;
			$result['session'] = $this->toArray();
		}

		return $result;
	}

	public function cancel($timestamp, $reason) {
		if ($this->status === VetoDraftCatalog::STATUS_COMPLETED || $this->status === VetoDraftCatalog::STATUS_CANCELLED) {
			return $this->toArray();
		}

		$this->status = VetoDraftCatalog::STATUS_CANCELLED;
		$this->resolvedAt = max(0, (int) $timestamp);
		$normalizedReason = trim((string) $reason);
		$this->resolutionReason = ($normalizedReason !== '') ? $normalizedReason : 'cancelled';

		return $this->toArray();
	}

	public function buildTelemetryActions() {
		return $this->actions;
	}

	public function toArray() {
		return array(
			'session_id' => $this->sessionId,
			'mode' => VetoDraftCatalog::MODE_TOURNAMENT_DRAFT,
			'status' => $this->status,
			'started_at' => $this->startedAt,
			'resolved_at' => $this->resolvedAt,
			'turn_started_at' => $this->turnStartedAt,
			'action_timeout_seconds' => $this->actionTimeoutSeconds,
			'resolution_reason' => $this->resolutionReason,
			'captains' => $this->captainByTeam,
			'sequence' => $this->sequenceDefinition,
			'current_step_index' => $this->currentStepIndex,
			'current_step' => $this->getCurrentStep(),
			'map_pool_size' => count($this->mapPool),
			'available_maps' => array_values($this->availableMapByUid),
			'banned_maps' => $this->buildMapIdentityListFromUids($this->bannedMapUids),
			'picked_maps' => $this->buildMapIdentityListFromUids($this->pickedMapUids),
			'actions' => $this->actions,
			'series_map_order' => $this->buildMapIdentityListFromUids($this->pickedMapUids),
			'decider_map' => $this->resolveDeciderMapIdentity(),
		);
	}

	private function autoLockIfNeeded($timestamp) {
		$nextStep = $this->getCurrentStep();
		if (!$nextStep) {
			return null;
		}

		$nextActionKind = isset($nextStep['action_kind']) ? (string) $nextStep['action_kind'] : '';
		if ($nextActionKind !== VetoDraftCatalog::ACTION_LOCK) {
			return null;
		}

		return $this->applySystemLock($timestamp, 'system_auto_lock', true);
	}

	private function applySystemLock($timestamp, $source, $autoAction) {
		$step = $this->getCurrentStep();
		if (!$step) {
			return $this->failure('sequence_exhausted', 'Tournament draft sequence is already complete.');
		}

		$availableMapUids = array_keys($this->availableMapByUid);
		if (empty($availableMapUids)) {
			$this->status = VetoDraftCatalog::STATUS_CANCELLED;
			$this->resolvedAt = max(0, (int) $timestamp);
			$this->resolutionReason = 'decider_unavailable';
			return $this->failure('decider_unavailable', 'Cannot lock decider map because pool is empty.', array('session' => $this->toArray()));
		}

		$selectedMapUid = '';
		if (count($availableMapUids) === 1) {
			$selectedMapUid = strtolower((string) $availableMapUids[0]);
		} else {
			$selectedMapUid = strtolower((string) VetoDraftCatalog::pickRandomValue($availableMapUids, ''));
		}

		if ($selectedMapUid === '') {
			$this->status = VetoDraftCatalog::STATUS_CANCELLED;
			$this->resolvedAt = max(0, (int) $timestamp);
			$this->resolutionReason = 'decider_selection_failed';
			return $this->failure('decider_selection_failed', 'Cannot select decider map.', array('session' => $this->toArray()));
		}

		$autoReason = (count($availableMapUids) === 1) ? 'single_map_remaining' : 'multiple_maps_remaining_random_lock';
		return $this->commitAction($step, $selectedMapUid, VetoDraftCatalog::TEAM_SYSTEM, max(0, (int) $timestamp), $source, (bool) $autoAction, $autoReason);
	}

	private function commitAction(array $step, $mapUid, $actorLogin, $timestamp, $source, $autoAction, $autoReason) {
		$mapUid = strtolower(trim((string) $mapUid));
		if ($mapUid === '' || !isset($this->availableMapByUid[$mapUid])) {
			return $this->failure('map_unavailable', 'Selected map is not available in current pool.', array('map_uid' => $mapUid));
		}

		$timestamp = max(0, (int) $timestamp);
		$actionKind = isset($step['action_kind']) ? (string) $step['action_kind'] : VetoDraftCatalog::ACTION_PICK;
		$phase = isset($step['phase']) ? (string) $step['phase'] : 'unknown_phase';
		$team = isset($step['team']) ? (string) $step['team'] : VetoDraftCatalog::TEAM_SYSTEM;

		$mapIdentity = $this->availableMapByUid[$mapUid];
		unset($this->availableMapByUid[$mapUid]);

		if ($actionKind === VetoDraftCatalog::ACTION_BAN) {
			$this->bannedMapUids[] = $mapUid;
		}

		if ($actionKind === VetoDraftCatalog::ACTION_PICK || $actionKind === VetoDraftCatalog::ACTION_LOCK) {
			$this->pickedMapUids[] = $mapUid;
		}

		$actionStatus = $autoAction ? 'inferred' : 'explicit';
		$action = array(
			'order_index' => count($this->actions) + 1,
			'phase' => $phase,
			'action_kind' => $actionKind,
			'action_status' => $actionStatus,
			'action_source' => trim((string) $source),
			'raw_action_value' => $actionKind,
			'source_callback' => 'PixelControl.VetoDraft.Tournament.Action',
			'source_channel' => 'feature.veto_draft.tournament',
			'observed_at' => $timestamp,
			'actor' => array(
				'login' => trim((string) $actorLogin),
				'team' => $team,
			),
			'map' => array(
				'uid' => isset($mapIdentity['uid']) ? (string) $mapIdentity['uid'] : '',
				'name' => isset($mapIdentity['name']) ? (string) $mapIdentity['name'] : '',
			),
			'auto_action' => (bool) $autoAction,
			'auto_reason' => trim((string) $autoReason),
			'field_availability' => array(
				'map_uid' => true,
				'map_name' => true,
				'actor_login' => true,
				'actor_team' => true,
				'action_kind' => true,
			),
			'missing_fields' => array(),
		);

		$this->actions[] = $action;
		$this->currentStepIndex++;
		$this->turnStartedAt = $timestamp;

		if (!isset($this->sequenceSteps[$this->currentStepIndex])) {
			$this->status = VetoDraftCatalog::STATUS_COMPLETED;
			$this->resolvedAt = $timestamp;
			$this->resolutionReason = 'sequence_completed';
		}

		return array(
			'success' => true,
			'code' => 'action_applied',
			'message' => ucfirst($actionKind) . ' action applied.',
			'action' => $action,
			'session' => $this->toArray(),
		);
	}

	private function isActorAllowedForStep($actorLogin, $team) {
		$normalizedActorLogin = strtolower(trim((string) $actorLogin));
		if ($normalizedActorLogin === '') {
			return false;
		}

		$expectedCaptainLogin = $this->getCaptainLoginForTeam($team);
		if ($expectedCaptainLogin === '') {
			return false;
		}

		return $expectedCaptainLogin === $normalizedActorLogin;
	}

	private function getCaptainLoginForTeam($team) {
		$normalizedTeam = VetoDraftCatalog::normalizeTeam($team, VetoDraftCatalog::TEAM_A);
		if (!isset($this->captainByTeam[$normalizedTeam])) {
			return '';
		}

		return (string) $this->captainByTeam[$normalizedTeam];
	}

	private function buildMapIdentityListFromUids(array $mapUids) {
		$mapIdentityList = array();
		foreach ($mapUids as $mapUid) {
			$normalizedMapUid = strtolower(trim((string) $mapUid));
			if ($normalizedMapUid === '') {
				continue;
			}

			if (!isset($this->mapPoolByUid[$normalizedMapUid])) {
				continue;
			}

			$mapIdentityList[] = $this->mapPoolByUid[$normalizedMapUid];
		}

		return $mapIdentityList;
	}

	private function resolveDeciderMapIdentity() {
		if (empty($this->pickedMapUids)) {
			return null;
		}

		$deciderMapUid = strtolower(trim((string) $this->pickedMapUids[count($this->pickedMapUids) - 1]));
		if ($deciderMapUid === '' || !isset($this->mapPoolByUid[$deciderMapUid])) {
			return null;
		}

		return $this->mapPoolByUid[$deciderMapUid];
	}

	private function failure($code, $message, array $details = array()) {
		return array(
			'success' => false,
			'code' => trim((string) $code),
			'message' => trim((string) $message),
			'details' => $details,
		);
	}
}
