<?php

namespace PixelControl\Domain\Admin;

use ManiaControl\Communication\CommunicationAnswer;
use ManiaControl\Logger;

/**
 * Minimal admin command communication listener for Pixel Control P3 endpoints.
 *
 * Registers a CommunicationManager listener for 'PixelControl.Admin.ExecuteAction'.
 * Validates link-auth credentials on every call.
 * Dispatches only the P3 action subset:
 *   map.skip, map.restart, map.jump, map.queue, map.add, map.remove,
 *   warmup.extend, warmup.end, pause.start, pause.end,
 *   match.bo.get, match.bo.set, match.maps.get, match.maps.set,
 *   match.score.get, match.score.set
 *
 * This trait does NOT restore any of the removed subsystems (VetoDraft, Admin Control,
 * Access Control, Series Control, Team Control). It is a minimal, focused re-implementation.
 */
trait AdminCommandTrait {
	/** @var int $currentBestOf */
	private $currentBestOf = 3;

	/** @var array $teamMapsScore */
	private $teamMapsScore = array('team_a' => 0, 'team_b' => 0);

	/** @var array $teamRoundScore */
	private $teamRoundScore = array('team_a' => 0, 'team_b' => 0);

	/**
	 * Registers the PixelControl.Admin.ExecuteAction communication listener.
	 * Call from load().
	 */
	private function registerAdminCommandListener() {
		if (!$this->maniaControl || !$this->maniaControl->getCommunicationManager()) {
			Logger::logError('[PixelControl] AdminCommandTrait: CommunicationManager not available.');
			return;
		}
		$this->maniaControl->getCommunicationManager()->registerCommunicationListener(
			'PixelControl.Admin.ExecuteAction',
			$this,
			'handleAdminExecuteAction'
		);
		Logger::log('[PixelControl] Admin command listener registered.');
	}

	/**
	 * Handles an incoming PixelControl.Admin.ExecuteAction communication request.
	 * Called by ManiaControl CommunicationManager.
	 *
	 * @param mixed $data
	 * @return CommunicationAnswer
	 */
	public function handleAdminExecuteAction($data) {
		// Validate link-auth.
		$authError = $this->validateLinkAuth($data);
		if ($authError !== null) {
			return new CommunicationAnswer($authError, false);
		}

		$action = isset($data->action) ? (string) $data->action : '';
		$parameters = isset($data->parameters) ? $data->parameters : null;

		$actionMap = array(
			'map.skip'        => 'handleMapSkip',
			'map.restart'     => 'handleMapRestart',
			'map.jump'        => 'handleMapJump',
			'map.queue'       => 'handleMapQueue',
			'map.add'         => 'handleMapAdd',
			'map.remove'      => 'handleMapRemove',
			'warmup.extend'   => 'handleWarmupExtend',
			'warmup.end'      => 'handleWarmupEnd',
			'pause.start'     => 'handlePauseStart',
			'pause.end'       => 'handlePauseEnd',
			'match.bo.get'    => 'handleMatchBestOfGet',
			'match.bo.set'    => 'handleMatchBestOfSet',
			'match.maps.get'  => 'handleMatchMapsGet',
			'match.maps.set'  => 'handleMatchMapsSet',
			'match.score.get' => 'handleMatchScoreGet',
			'match.score.set' => 'handleMatchScoreSet',
		);

		if (!isset($actionMap[$action])) {
			return new CommunicationAnswer(array(
				'action_name' => $action,
				'success'     => false,
				'code'        => 'action_not_found',
				'message'     => "Unknown action: {$action}",
			), false);
		}

		$handlerMethod = $actionMap[$action];
		try {
			$result = $this->$handlerMethod($parameters);
			return new CommunicationAnswer($result, false);
		} catch (\Exception $e) {
			Logger::logError("[PixelControl] Admin action '{$action}' threw: " . $e->getMessage());
			return new CommunicationAnswer(array(
				'action_name' => $action,
				'success'     => false,
				'code'        => 'action_exception',
				'message'     => $e->getMessage(),
			), false);
		}
	}

	// ─── Link-auth validation ─────────────────────────────────────────────────────

	/**
	 * Validates link-auth fields in the communication request data.
	 *
	 * @param mixed $data
	 * @return array|null Error response array on failure, null on success.
	 */
	private function validateLinkAuth($data) {
		if (!isset($data->server_login) || !isset($data->auth)) {
			return array(
				'action_name' => '',
				'success'     => false,
				'code'        => 'link_auth_missing',
				'message'     => 'Missing server_login or auth fields.',
			);
		}

		$requestedServerLogin = (string) $data->server_login;
		$localServerLogin = $this->getLocalServerLogin();

		if ($requestedServerLogin !== $localServerLogin) {
			return array(
				'action_name' => '',
				'success'     => false,
				'code'        => 'link_server_mismatch',
				'message'     => "Server login mismatch: expected {$localServerLogin}, got {$requestedServerLogin}.",
			);
		}

		$auth = $data->auth;
		$authMode = isset($auth->mode) ? (string) $auth->mode : '';
		$authToken = isset($auth->token) ? (string) $auth->token : '';

		if ($authMode !== 'link_bearer') {
			return array(
				'action_name' => '',
				'success'     => false,
				'code'        => 'link_auth_invalid',
				'message'     => "Unsupported auth mode: {$authMode}. Expected link_bearer.",
			);
		}

		$storedToken = $this->getStoredLinkToken();
		if ($authToken === '' || $storedToken === '' || $authToken !== $storedToken) {
			return array(
				'action_name' => '',
				'success'     => false,
				'code'        => 'link_auth_invalid',
				'message'     => 'Link bearer token is invalid or missing.',
			);
		}

		return null;
	}

	/**
	 * Returns the current server login from ManiaControl.
	 *
	 * @return string
	 */
	private function getLocalServerLogin() {
		if (!$this->maniaControl || !$this->maniaControl->getServer()) {
			return '';
		}
		$login = $this->maniaControl->getServer()->login;
		return is_string($login) ? trim($login) : '';
	}

	/**
	 * Returns the stored link token from plugin settings.
	 *
	 * @return string
	 */
	private function getStoredLinkToken() {
		if (!$this->maniaControl) {
			return '';
		}
		$token = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_LINK_TOKEN);
		return is_string($token) ? trim($token) : '';
	}

	// ─── Map management handlers (P3.1--P3.6) ────────────────────────────────────

	private function handleMapSkip($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('map.skip', 'ManiaControl not available.');
		}
		$this->maniaControl->getMapManager()->getMapActions()->skipMap();
		return array(
			'action_name' => 'map.skip',
			'success'     => true,
			'code'        => 'map_skipped',
			'message'     => 'Skipped to the next map.',
		);
	}

	private function handleMapRestart($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('map.restart', 'ManiaControl not available.');
		}
		$this->maniaControl->getMapManager()->getMapActions()->restartMap();
		return array(
			'action_name' => 'map.restart',
			'success'     => true,
			'code'        => 'map_restarted',
			'message'     => 'Current map restarted.',
		);
	}

	private function handleMapJump($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('map.jump', 'ManiaControl not available.');
		}
		$mapUid = $this->requireStringParam($parameters, 'map_uid');
		if ($mapUid === null) {
			return array(
				'action_name' => 'map.jump',
				'success'     => false,
				'code'        => 'invalid_map_uid',
				'message'     => 'Missing or invalid map_uid parameter.',
			);
		}
		$map = $this->maniaControl->getMapManager()->getMapByUid($mapUid);
		if (!$map) {
			return array(
				'action_name' => 'map.jump',
				'success'     => false,
				'code'        => 'map_not_found',
				'message'     => "Map with UID '{$mapUid}' not found in the server map pool.",
			);
		}
		$this->maniaControl->getMapManager()->getMapActions()->skipToMap($map);
		return array(
			'action_name' => 'map.jump',
			'success'     => true,
			'code'        => 'map_jumped',
			'message'     => "Jumped to map '{$mapUid}'.",
			'details'     => array('map_uid' => $mapUid),
		);
	}

	private function handleMapQueue($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('map.queue', 'ManiaControl not available.');
		}
		$mapUid = $this->requireStringParam($parameters, 'map_uid');
		if ($mapUid === null) {
			return array(
				'action_name' => 'map.queue',
				'success'     => false,
				'code'        => 'invalid_map_uid',
				'message'     => 'Missing or invalid map_uid parameter.',
			);
		}
		$map = $this->maniaControl->getMapManager()->getMapByUid($mapUid);
		if (!$map) {
			return array(
				'action_name' => 'map.queue',
				'success'     => false,
				'code'        => 'map_not_found',
				'message'     => "Map with UID '{$mapUid}' not found in the server map pool.",
			);
		}
		$this->maniaControl->getMapManager()->addMapToQueue($map);
		return array(
			'action_name' => 'map.queue',
			'success'     => true,
			'code'        => 'map_queued',
			'message'     => "Queued map '{$mapUid}' as next.",
			'details'     => array('map_uid' => $mapUid),
		);
	}

	private function handleMapAdd($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('map.add', 'ManiaControl not available.');
		}
		$mxId = $this->requireStringParam($parameters, 'mx_id');
		if ($mxId === null) {
			return array(
				'action_name' => 'map.add',
				'success'     => false,
				'code'        => 'invalid_mx_id',
				'message'     => 'Missing or invalid mx_id parameter.',
			);
		}
		$this->maniaControl->getMapManager()->addMapFromMx((int) $mxId);
		return array(
			'action_name' => 'map.add',
			'success'     => true,
			'code'        => 'map_added',
			'message'     => "Map with MX ID '{$mxId}' is being downloaded and added.",
			'details'     => array('mx_id' => $mxId),
		);
	}

	private function handleMapRemove($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('map.remove', 'ManiaControl not available.');
		}
		$mapUid = $this->requireStringParam($parameters, 'map_uid');
		if ($mapUid === null) {
			return array(
				'action_name' => 'map.remove',
				'success'     => false,
				'code'        => 'invalid_map_uid',
				'message'     => 'Missing or invalid map_uid parameter.',
			);
		}
		$map = $this->maniaControl->getMapManager()->getMapByUid($mapUid);
		if (!$map) {
			return array(
				'action_name' => 'map.remove',
				'success'     => false,
				'code'        => 'map_not_found',
				'message'     => "Map with UID '{$mapUid}' not found in the server map pool.",
			);
		}
		$this->maniaControl->getMapManager()->removeMap(null, $map->fileName, false, true);
		return array(
			'action_name' => 'map.remove',
			'success'     => true,
			'code'        => 'map_removed',
			'message'     => "Map '{$mapUid}' removed from map pool.",
			'details'     => array('map_uid' => $mapUid),
		);
	}

	// ─── Warmup and pause handlers (P3.7--P3.10) ─────────────────────────────────

	private function handleWarmupExtend($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('warmup.extend', 'ManiaControl not available.');
		}
		$seconds = $this->requirePositiveIntParam($parameters, 'seconds');
		if ($seconds === null) {
			return array(
				'action_name' => 'warmup.extend',
				'success'     => false,
				'code'        => 'invalid_seconds',
				'message'     => 'Missing or invalid seconds parameter (must be a positive integer).',
			);
		}
		$this->maniaControl->getClient()->triggerModeScriptEventArray(
			'Warmup_Extend',
			array((string) ($seconds * 1000))
		);
		return array(
			'action_name' => 'warmup.extend',
			'success'     => true,
			'code'        => 'warmup_extended',
			'message'     => "Warmup extended by {$seconds} second(s).",
			'details'     => array('seconds' => $seconds),
		);
	}

	private function handleWarmupEnd($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('warmup.end', 'ManiaControl not available.');
		}
		$this->maniaControl->getClient()->triggerModeScriptEventArray('Warmup_Stop', array());
		return array(
			'action_name' => 'warmup.end',
			'success'     => true,
			'code'        => 'warmup_ended',
			'message'     => 'Warmup phase ended.',
		);
	}

	private function handlePauseStart($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('pause.start', 'ManiaControl not available.');
		}
		$this->maniaControl->getClient()->triggerModeScriptEventArray('Pause_SetActive', array('true'));
		return array(
			'action_name' => 'pause.start',
			'success'     => true,
			'code'        => 'pause_started',
			'message'     => 'Match paused.',
		);
	}

	private function handlePauseEnd($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('pause.end', 'ManiaControl not available.');
		}
		$this->maniaControl->getClient()->triggerModeScriptEventArray('Pause_SetActive', array('false'));
		return array(
			'action_name' => 'pause.end',
			'success'     => true,
			'code'        => 'pause_ended',
			'message'     => 'Match resumed from pause.',
		);
	}

	// ─── Match configuration handlers (P3.11--P3.16) ─────────────────────────────

	private function handleMatchBestOfGet($parameters) {
		return array(
			'action_name' => 'match.bo.get',
			'success'     => true,
			'code'        => 'match_bo_retrieved',
			'message'     => 'Best-of configuration retrieved.',
			'details'     => array('best_of' => $this->currentBestOf),
		);
	}

	private function handleMatchBestOfSet($parameters) {
		$bestOf = $this->requirePositiveIntParam($parameters, 'best_of');
		if ($bestOf === null || $bestOf < 1) {
			return array(
				'action_name' => 'match.bo.set',
				'success'     => false,
				'code'        => 'invalid_best_of',
				'message'     => 'Missing or invalid best_of parameter (must be a positive integer).',
			);
		}
		$this->currentBestOf = $bestOf;
		return array(
			'action_name' => 'match.bo.set',
			'success'     => true,
			'code'        => 'match_bo_set',
			'message'     => "Best-of set to {$bestOf}.",
			'details'     => array('best_of' => $bestOf),
		);
	}

	private function handleMatchMapsGet($parameters) {
		return array(
			'action_name' => 'match.maps.get',
			'success'     => true,
			'code'        => 'match_maps_retrieved',
			'message'     => 'Maps score retrieved.',
			'details'     => array(
				'team_a_maps' => $this->teamMapsScore['team_a'],
				'team_b_maps' => $this->teamMapsScore['team_b'],
			),
		);
	}

	private function handleMatchMapsSet($parameters) {
		$team = $this->requireStringParam($parameters, 'target_team');
		$mapsScore = $this->requireNonNegativeIntParam($parameters, 'maps_score');

		if ($team === null || !in_array($team, array('team_a', 'team_b'), true)) {
			return array(
				'action_name' => 'match.maps.set',
				'success'     => false,
				'code'        => 'invalid_team',
				'message'     => 'Invalid target_team. Must be "team_a" or "team_b".',
			);
		}
		if ($mapsScore === null) {
			return array(
				'action_name' => 'match.maps.set',
				'success'     => false,
				'code'        => 'invalid_score',
				'message'     => 'Missing or invalid maps_score parameter (must be a non-negative integer).',
			);
		}
		$this->teamMapsScore[$team] = $mapsScore;
		return array(
			'action_name' => 'match.maps.set',
			'success'     => true,
			'code'        => 'match_maps_set',
			'message'     => "Maps score for {$team} set to {$mapsScore}.",
			'details'     => array(
				'target_team'  => $team,
				'maps_score'   => $mapsScore,
				'team_a_maps'  => $this->teamMapsScore['team_a'],
				'team_b_maps'  => $this->teamMapsScore['team_b'],
			),
		);
	}

	private function handleMatchScoreGet($parameters) {
		return array(
			'action_name' => 'match.score.get',
			'success'     => true,
			'code'        => 'match_score_retrieved',
			'message'     => 'Round score retrieved.',
			'details'     => array(
				'team_a_score' => $this->teamRoundScore['team_a'],
				'team_b_score' => $this->teamRoundScore['team_b'],
			),
		);
	}

	private function handleMatchScoreSet($parameters) {
		$team = $this->requireStringParam($parameters, 'target_team');
		$score = $this->requireNonNegativeIntParam($parameters, 'score');

		if ($team === null || !in_array($team, array('team_a', 'team_b'), true)) {
			return array(
				'action_name' => 'match.score.set',
				'success'     => false,
				'code'        => 'invalid_team',
				'message'     => 'Invalid target_team. Must be "team_a" or "team_b".',
			);
		}
		if ($score === null) {
			return array(
				'action_name' => 'match.score.set',
				'success'     => false,
				'code'        => 'invalid_score',
				'message'     => 'Missing or invalid score parameter (must be a non-negative integer).',
			);
		}
		$this->teamRoundScore[$team] = $score;
		return array(
			'action_name' => 'match.score.set',
			'success'     => true,
			'code'        => 'match_score_set',
			'message'     => "Round score for {$team} set to {$score}.",
			'details'     => array(
				'target_team'   => $team,
				'score'         => $score,
				'team_a_score'  => $this->teamRoundScore['team_a'],
				'team_b_score'  => $this->teamRoundScore['team_b'],
			),
		);
	}

	// ─── Shared parameter helpers ─────────────────────────────────────────────────

	/**
	 * Extracts a non-empty string parameter by key from the $parameters object/array.
	 *
	 * @param mixed $parameters
	 * @param string $key
	 * @return string|null
	 */
	private function requireStringParam($parameters, $key) {
		if ($parameters === null) {
			return null;
		}
		if (is_object($parameters) && isset($parameters->$key)) {
			$val = (string) $parameters->$key;
			return $val !== '' ? $val : null;
		}
		if (is_array($parameters) && isset($parameters[$key])) {
			$val = (string) $parameters[$key];
			return $val !== '' ? $val : null;
		}
		return null;
	}

	/**
	 * Extracts a positive integer (>= 1) parameter by key.
	 *
	 * @param mixed $parameters
	 * @param string $key
	 * @return int|null
	 */
	private function requirePositiveIntParam($parameters, $key) {
		if ($parameters === null) {
			return null;
		}
		$raw = null;
		if (is_object($parameters) && isset($parameters->$key)) {
			$raw = $parameters->$key;
		} elseif (is_array($parameters) && isset($parameters[$key])) {
			$raw = $parameters[$key];
		}
		if ($raw === null) {
			return null;
		}
		$val = (int) $raw;
		return $val >= 1 ? $val : null;
	}

	/**
	 * Extracts a non-negative integer (>= 0) parameter by key.
	 *
	 * @param mixed $parameters
	 * @param string $key
	 * @return int|null
	 */
	private function requireNonNegativeIntParam($parameters, $key) {
		if ($parameters === null) {
			return null;
		}
		$raw = null;
		if (is_object($parameters) && isset($parameters->$key)) {
			$raw = $parameters->$key;
		} elseif (is_array($parameters) && isset($parameters[$key])) {
			$raw = $parameters[$key];
		}
		if ($raw === null && !isset($parameters->$key) && !(is_array($parameters) && array_key_exists($key, $parameters))) {
			return null;
		}
		if ($raw === null) {
			return null;
		}
		$val = (int) $raw;
		return $val >= 0 ? $val : null;
	}

	/**
	 * Builds a generic error response array.
	 *
	 * @param string $actionName
	 * @param string $message
	 * @return array
	 */
	private function errorResponse($actionName, $message) {
		return array(
			'action_name' => $actionName,
			'success'     => false,
			'code'        => 'internal_error',
			'message'     => $message,
		);
	}
}
