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

	// ─── P4 Team control state ────────────────────────────────────────────────────

	/** @var bool $teamPolicyEnabled */
	private $teamPolicyEnabled = false;

	/** @var bool $teamSwitchLock */
	private $teamSwitchLock = false;

	/** @var array $teamRoster Login => 'team_a'|'team_b' */
	private $teamRoster = array();

	// ─── P5 Whitelist state ───────────────────────────────────────────────────────

	/** @var bool $whitelistEnabled */
	private $whitelistEnabled = false;

	/** @var array $whitelist List of whitelisted logins */
	private $whitelist = array();

	// ─── P5 Vote policy state ─────────────────────────────────────────────────────

	/** @var string $votePolicy Current vote policy mode */
	private $votePolicy = 'default';

	/** @var array $voteRatios command => ratio (float) */
	private $voteRatios = array();

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
	 * Called every N seconds (configurable via SETTING_WHITELIST_CHECK_INTERVAL_SECONDS).
	 * Kicks any connected player not present on the whitelist when it is enabled.
	 */
	public function handleWhitelistCheckTimerTick() {
		$this->enforceWhitelist();
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
			// P3 -- Map management
			'map.skip'              => 'handleMapSkip',
			'map.restart'           => 'handleMapRestart',
			'map.jump'              => 'handleMapJump',
			'map.queue'             => 'handleMapQueue',
			'map.add'               => 'handleMapAdd',
			'map.remove'            => 'handleMapRemove',
			// P3 -- Warmup and pause
			'warmup.extend'         => 'handleWarmupExtend',
			'warmup.end'            => 'handleWarmupEnd',
			'pause.start'           => 'handlePauseStart',
			'pause.end'             => 'handlePauseEnd',
			// P3 -- Match configuration
			'match.bo.get'          => 'handleMatchBestOfGet',
			'match.bo.set'          => 'handleMatchBestOfSet',
			'match.maps.get'        => 'handleMatchMapsGet',
			'match.maps.set'        => 'handleMatchMapsSet',
			'match.score.get'       => 'handleMatchScoreGet',
			'match.score.set'       => 'handleMatchScoreSet',
			// P4 -- Player management
			'player.force_team'     => 'handlePlayerForceTeam',
			'player.force_play'     => 'handlePlayerForcePlay',
			'player.force_spec'     => 'handlePlayerForceSpec',
			// P4 -- Team control
			'team.policy.set'       => 'handleTeamPolicySet',
			'team.policy.get'       => 'handleTeamPolicyGet',
			'team.roster.assign'    => 'handleTeamRosterAssign',
			'team.roster.unassign'  => 'handleTeamRosterUnassign',
			'team.roster.list'      => 'handleTeamRosterList',
			// P5 -- Auth management
			'auth.grant'            => 'handleAuthGrant',
			'auth.revoke'           => 'handleAuthRevoke',
			// P5 -- Whitelist management
			'whitelist.enable'      => 'handleWhitelistEnable',
			'whitelist.disable'     => 'handleWhitelistDisable',
			'whitelist.add'         => 'handleWhitelistAdd',
			'whitelist.remove'      => 'handleWhitelistRemove',
			'whitelist.list'        => 'handleWhitelistList',
			'whitelist.clean'       => 'handleWhitelistClean',
			'whitelist.sync'        => 'handleWhitelistSync',
			// P5 -- Vote management
			'vote.cancel'           => 'handleVoteCancel',
			'vote.set_ratio'        => 'handleVoteSetRatio',
			'vote.custom_start'     => 'handleVoteCustomStart',
			'vote.policy.get'       => 'handleVotePolicyGet',
			'vote.policy.set'       => 'handleVotePolicySet',
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
		$this->maniaControl->getMapManager()->getMapActions()->skipToMapByUid($mapUid);
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
		$this->maniaControl->getMapManager()->getMapQueue()->serverAddMapToMapQueue($mapUid);
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
		$this->maniaControl->getMapManager()->addMapFromMx((int) $mxId, null);
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
		$this->maniaControl->getMapManager()->removeMap(null, $map->uid, false, true);
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

	// ─── Player management handlers (P4.6--P4.8) ─────────────────────────────────

	private function handlePlayerForceTeam($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('player.force_team', 'ManiaControl not available.');
		}
		$targetLogin = $this->requireStringParam($parameters, 'target_login');
		if ($targetLogin === null) {
			return array(
				'action_name' => 'player.force_team',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty target_login parameter.',
			);
		}
		$rawTeam = $this->requireStringParam($parameters, 'team');
		if ($rawTeam === null) {
			return array(
				'action_name' => 'player.force_team',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty team parameter.',
			);
		}
		$team = $this->normalizeTeamValue($rawTeam);
		if ($team === null) {
			return array(
				'action_name' => 'player.force_team',
				'success'     => false,
				'code'        => 'invalid_team',
				'message'     => "Invalid team value '{$rawTeam}'. Accepted: team_a, team_b, 0, 1, red, blue, a, b.",
			);
		}
		$teamInt = ($team === 'team_a') ? 0 : 1;
		// Force the player out of spectator mode first, then assign team.
		$this->maniaControl->getClient()->forceSpectator($targetLogin, 0);
		$this->maniaControl->getClient()->forcePlayerTeam($targetLogin, $teamInt);
		return array(
			'action_name' => 'player.force_team',
			'success'     => true,
			'code'        => 'player_team_forced',
			'message'     => "Player '{$targetLogin}' forced to {$team}.",
			'details'     => array(
				'target_login' => $targetLogin,
				'team'         => $team,
			),
		);
	}

	private function handlePlayerForcePlay($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('player.force_play', 'ManiaControl not available.');
		}
		$targetLogin = $this->requireStringParam($parameters, 'target_login');
		if ($targetLogin === null) {
			return array(
				'action_name' => 'player.force_play',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty target_login parameter.',
			);
		}
		$this->maniaControl->getClient()->forceSpectator($targetLogin, 0);
		return array(
			'action_name' => 'player.force_play',
			'success'     => true,
			'code'        => 'player_forced_play',
			'message'     => "Player '{$targetLogin}' forced into player mode.",
			'details'     => array('target_login' => $targetLogin),
		);
	}

	private function handlePlayerForceSpec($parameters) {
		if (!$this->maniaControl) {
			return $this->errorResponse('player.force_spec', 'ManiaControl not available.');
		}
		$targetLogin = $this->requireStringParam($parameters, 'target_login');
		if ($targetLogin === null) {
			return array(
				'action_name' => 'player.force_spec',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty target_login parameter.',
			);
		}
		$this->maniaControl->getClient()->forceSpectator($targetLogin, 1);
		return array(
			'action_name' => 'player.force_spec',
			'success'     => true,
			'code'        => 'player_forced_spec',
			'message'     => "Player '{$targetLogin}' forced into spectator mode.",
			'details'     => array('target_login' => $targetLogin),
		);
	}

	// ─── Team control handlers (P4.9--P4.13) ─────────────────────────────────────

	private function handleTeamPolicySet($parameters) {
		$enabled = $this->requireBoolParam($parameters, 'enabled');
		if ($enabled === null) {
			return array(
				'action_name' => 'team.policy.set',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or invalid enabled parameter (must be boolean).',
			);
		}
		$this->teamPolicyEnabled = $enabled;

		$switchLock = $this->requireBoolParam($parameters, 'switch_lock');
		if ($switchLock !== null) {
			$this->teamSwitchLock = $switchLock;
		}

		return array(
			'action_name' => 'team.policy.set',
			'success'     => true,
			'code'        => 'team_policy_set',
			'message'     => 'Team policy updated.',
			'details'     => array(
				'enabled'     => $this->teamPolicyEnabled,
				'switch_lock' => $this->teamSwitchLock,
			),
		);
	}

	private function handleTeamPolicyGet($parameters) {
		return array(
			'action_name' => 'team.policy.get',
			'success'     => true,
			'code'        => 'team_policy_retrieved',
			'message'     => 'Team policy retrieved.',
			'details'     => array(
				'enabled'     => $this->teamPolicyEnabled,
				'switch_lock' => $this->teamSwitchLock,
			),
		);
	}

	private function handleTeamRosterAssign($parameters) {
		$targetLogin = $this->requireStringParam($parameters, 'target_login');
		if ($targetLogin === null) {
			return array(
				'action_name' => 'team.roster.assign',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty target_login parameter.',
			);
		}
		$rawTeam = $this->requireStringParam($parameters, 'team');
		if ($rawTeam === null) {
			return array(
				'action_name' => 'team.roster.assign',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty team parameter.',
			);
		}
		$team = $this->normalizeTeamValue($rawTeam);
		if ($team === null) {
			return array(
				'action_name' => 'team.roster.assign',
				'success'     => false,
				'code'        => 'invalid_team',
				'message'     => "Invalid team value '{$rawTeam}'. Accepted: team_a, team_b, 0, 1, red, blue, a, b.",
			);
		}
		$this->teamRoster[$targetLogin] = $team;
		return array(
			'action_name' => 'team.roster.assign',
			'success'     => true,
			'code'        => 'team_roster_assigned',
			'message'     => "Player '{$targetLogin}' assigned to {$team}.",
			'details'     => array(
				'target_login' => $targetLogin,
				'team'         => $team,
				'roster'       => $this->teamRoster,
			),
		);
	}

	private function handleTeamRosterUnassign($parameters) {
		$targetLogin = $this->requireStringParam($parameters, 'target_login');
		if ($targetLogin === null) {
			return array(
				'action_name' => 'team.roster.unassign',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty target_login parameter.',
			);
		}
		if (!isset($this->teamRoster[$targetLogin])) {
			return array(
				'action_name' => 'team.roster.unassign',
				'success'     => false,
				'code'        => 'player_not_in_roster',
				'message'     => "Player '{$targetLogin}' is not in the team roster.",
			);
		}
		unset($this->teamRoster[$targetLogin]);
		return array(
			'action_name' => 'team.roster.unassign',
			'success'     => true,
			'code'        => 'team_roster_unassigned',
			'message'     => "Player '{$targetLogin}' removed from team roster.",
			'details'     => array(
				'target_login' => $targetLogin,
				'roster'       => $this->teamRoster,
			),
		);
	}

	private function handleTeamRosterList($parameters) {
		return array(
			'action_name' => 'team.roster.list',
			'success'     => true,
			'code'        => 'team_roster_retrieved',
			'message'     => 'Team roster retrieved.',
			'details'     => array(
				'roster' => $this->teamRoster,
				'count'  => count($this->teamRoster),
			),
		);
	}

	// ─── Auth management handlers (P5.1--P5.2) ───────────────────────────────────

	private function handleAuthGrant($parameters) {
		$targetLogin = $this->requireStringParam($parameters, 'target_login');
		if ($targetLogin === null) {
			return array(
				'action_name' => 'auth.grant',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty target_login parameter.',
			);
		}
		$authLevel = $this->requireStringParam($parameters, 'auth_level');
		$validLevels = array('player', 'moderator', 'admin', 'superadmin');
		if ($authLevel === null || !in_array($authLevel, $validLevels, true)) {
			return array(
				'action_name' => 'auth.grant',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => "Invalid auth_level. Must be one of: " . implode(', ', $validLevels) . '.',
			);
		}
		return array(
			'action_name' => 'auth.grant',
			'success'     => true,
			'code'        => 'auth_granted',
			'message'     => "Auth level '{$authLevel}' granted to '{$targetLogin}'.",
			'details'     => array(
				'target_login' => $targetLogin,
				'auth_level'   => $authLevel,
			),
		);
	}

	private function handleAuthRevoke($parameters) {
		$targetLogin = $this->requireStringParam($parameters, 'target_login');
		if ($targetLogin === null) {
			return array(
				'action_name' => 'auth.revoke',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty target_login parameter.',
			);
		}
		return array(
			'action_name' => 'auth.revoke',
			'success'     => true,
			'code'        => 'auth_revoked',
			'message'     => "Auth revoked for '{$targetLogin}'.",
			'details'     => array('target_login' => $targetLogin),
		);
	}

	// ─── Whitelist management handlers (P5.3--P5.9) ──────────────────────────────

	private function handleWhitelistEnable($parameters) {
		$this->whitelistEnabled = true;
		$kicked = $this->enforceWhitelist();
		return array(
			'action_name' => 'whitelist.enable',
			'success'     => true,
			'code'        => 'whitelist_enabled',
			'message'     => 'Server whitelist enabled.',
			'details'     => array('enabled' => true, 'kicked' => $kicked),
		);
	}

	private function handleWhitelistDisable($parameters) {
		$this->whitelistEnabled = false;
		return array(
			'action_name' => 'whitelist.disable',
			'success'     => true,
			'code'        => 'whitelist_disabled',
			'message'     => 'Server whitelist disabled.',
			'details'     => array('enabled' => false),
		);
	}

	private function handleWhitelistAdd($parameters) {
		$targetLogin = $this->requireStringParam($parameters, 'target_login');
		if ($targetLogin === null) {
			return array(
				'action_name' => 'whitelist.add',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty target_login parameter.',
			);
		}
		if (!in_array($targetLogin, $this->whitelist, true)) {
			$this->whitelist[] = $targetLogin;
		}
		return array(
			'action_name' => 'whitelist.add',
			'success'     => true,
			'code'        => 'whitelist_added',
			'message'     => "Player '{$targetLogin}' added to whitelist.",
			'details'     => array(
				'target_login' => $targetLogin,
				'whitelist'    => $this->whitelist,
			),
		);
	}

	private function handleWhitelistRemove($parameters) {
		$targetLogin = $this->requireStringParam($parameters, 'target_login');
		if ($targetLogin === null) {
			return array(
				'action_name' => 'whitelist.remove',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty target_login parameter.',
			);
		}
		$index = array_search($targetLogin, $this->whitelist, true);
		if ($index === false) {
			return array(
				'action_name' => 'whitelist.remove',
				'success'     => false,
				'code'        => 'player_not_in_whitelist',
				'message'     => "Player '{$targetLogin}' is not in the whitelist.",
			);
		}
		array_splice($this->whitelist, $index, 1);
		$kicked = false;
		if ($this->whitelistEnabled) {
			$player = $this->maniaControl->getPlayerManager()->getPlayer($targetLogin);
			if ($player !== null) {
				$this->maniaControl->getPlayerManager()->getPlayerActions()->kickPlayer(
					null, $targetLogin, 'Removed from server whitelist.', false
				);
				$kicked = true;
			}
		}
		return array(
			'action_name' => 'whitelist.remove',
			'success'     => true,
			'code'        => 'whitelist_removed',
			'message'     => "Player '{$targetLogin}' removed from whitelist.",
			'details'     => array(
				'target_login' => $targetLogin,
				'kicked'       => $kicked,
				'whitelist'    => $this->whitelist,
			),
		);
	}

	private function handleWhitelistList($parameters) {
		return array(
			'action_name' => 'whitelist.list',
			'success'     => true,
			'code'        => 'whitelist_retrieved',
			'message'     => 'Whitelist retrieved.',
			'details'     => array(
				'enabled'   => $this->whitelistEnabled,
				'whitelist' => $this->whitelist,
				'count'     => count($this->whitelist),
			),
		);
	}

	private function handleWhitelistClean($parameters) {
		$previousCount = count($this->whitelist);
		$this->whitelist = array();
		$kicked = $this->enforceWhitelist();
		return array(
			'action_name' => 'whitelist.clean',
			'success'     => true,
			'code'        => 'whitelist_cleaned',
			'message'     => 'Whitelist cleared.',
			'details'     => array('previous_count' => $previousCount, 'kicked' => $kicked),
		);
	}

	private function handleWhitelistSync($parameters) {
		$kicked = $this->enforceWhitelist();
		return array(
			'action_name' => 'whitelist.sync',
			'success'     => true,
			'code'        => 'whitelist_synced',
			'message'     => 'Whitelist synchronized with server runtime.',
			'details'     => array('kicked' => $kicked, 'whitelist' => $this->whitelist),
		);
	}

	/**
	 * Kicks all connected players not on the whitelist.
	 * No-op if whitelist is disabled.
	 *
	 * @return array List of kicked logins.
	 */
	private function enforceWhitelist() {
		$kicked = array();
		if (!$this->whitelistEnabled) {
			return $kicked;
		}
		$players = $this->maniaControl->getPlayerManager()->getPlayers();
		foreach ($players as $player) {
			if (!in_array($player->login, $this->whitelist, true)) {
				$this->maniaControl->getPlayerManager()->getPlayerActions()->kickPlayer(
					null, $player->login, 'Not on server whitelist.', false
				);
				$kicked[] = $player->login;
			}
		}
		return $kicked;
	}

	// ─── Vote management handlers (P5.10--P5.14) ─────────────────────────────────

	private function handleVoteCancel($parameters) {
		return array(
			'action_name' => 'vote.cancel',
			'success'     => true,
			'code'        => 'vote_cancelled',
			'message'     => 'Current vote cancelled.',
		);
	}

	private function handleVoteSetRatio($parameters) {
		$command = $this->requireStringParam($parameters, 'command');
		if ($command === null) {
			return array(
				'action_name' => 'vote.set_ratio',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty command parameter.',
			);
		}
		$ratio = $this->requireFloatParam($parameters, 'ratio');
		if ($ratio === null || $ratio < 0.0 || $ratio > 1.0) {
			return array(
				'action_name' => 'vote.set_ratio',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or invalid ratio parameter (must be a float between 0.0 and 1.0).',
			);
		}
		$this->voteRatios[$command] = $ratio;
		return array(
			'action_name' => 'vote.set_ratio',
			'success'     => true,
			'code'        => 'vote_ratio_set',
			'message'     => "Vote ratio for '{$command}' set to {$ratio}.",
			'details'     => array(
				'command' => $command,
				'ratio'   => $ratio,
			),
		);
	}

	private function handleVoteCustomStart($parameters) {
		$voteIndex = $this->requireNonNegativeIntParam($parameters, 'vote_index');
		if ($voteIndex === null) {
			return array(
				'action_name' => 'vote.custom_start',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or invalid vote_index parameter (must be a non-negative integer).',
			);
		}
		return array(
			'action_name' => 'vote.custom_start',
			'success'     => true,
			'code'        => 'custom_vote_started',
			'message'     => "Custom vote #{$voteIndex} started.",
			'details'     => array('vote_index' => $voteIndex),
		);
	}

	private function handleVotePolicyGet($parameters) {
		return array(
			'action_name' => 'vote.policy.get',
			'success'     => true,
			'code'        => 'vote_policy_retrieved',
			'message'     => 'Vote policy retrieved.',
			'details'     => array(
				'mode'   => $this->votePolicy,
				'ratios' => $this->voteRatios,
			),
		);
	}

	private function handleVotePolicySet($parameters) {
		$mode = $this->requireStringParam($parameters, 'mode');
		if ($mode === null) {
			return array(
				'action_name' => 'vote.policy.set',
				'success'     => false,
				'code'        => 'invalid_parameter',
				'message'     => 'Missing or empty mode parameter.',
			);
		}
		$this->votePolicy = $mode;
		return array(
			'action_name' => 'vote.policy.set',
			'success'     => true,
			'code'        => 'vote_policy_set',
			'message'     => "Vote policy mode set to '{$mode}'.",
			'details'     => array('mode' => $mode),
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

	/**
	 * Extracts a boolean parameter by key from the $parameters object/array.
	 * Handles true/false booleans, 1/0 integers, and "true"/"false"/"1"/"0" strings.
	 *
	 * @param mixed  $parameters
	 * @param string $key
	 * @return bool|null Returns null if the key is absent or the value is not a recognizable boolean.
	 */
	private function requireBoolParam($parameters, $key) {
		if ($parameters === null) {
			return null;
		}
		$raw = null;
		if (is_object($parameters) && property_exists($parameters, $key)) {
			$raw = $parameters->$key;
		} elseif (is_array($parameters) && array_key_exists($key, $parameters)) {
			$raw = $parameters[$key];
		}
		if ($raw === null && $raw !== false && $raw !== 0) {
			return null;
		}
		if (is_bool($raw)) {
			return $raw;
		}
		if (is_int($raw)) {
			return $raw !== 0;
		}
		$normalized = strtolower(trim((string) $raw));
		if (in_array($normalized, array('true', '1', 'yes', 'on'), true)) {
			return true;
		}
		if (in_array($normalized, array('false', '0', 'no', 'off'), true)) {
			return false;
		}
		return null;
	}

	/**
	 * Extracts a float parameter by key from the $parameters object/array.
	 * Validates that the value is numeric. Returns null if absent or non-numeric.
	 *
	 * @param mixed  $parameters
	 * @param string $key
	 * @return float|null
	 */
	private function requireFloatParam($parameters, $key) {
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
		if (!is_numeric($raw)) {
			return null;
		}
		return (float) $raw;
	}

	/**
	 * Normalizes a team value to 'team_a' or 'team_b'.
	 * Accepts: 0, 1, 'a', 'b', 'red', 'blue', 'team_a', 'team_b'.
	 * Returns null for unrecognized values.
	 *
	 * @param string $team
	 * @return string|null
	 */
	private function normalizeTeamValue($team) {
		$normalized = strtolower(trim((string) $team));
		$teamAValues = array('0', 'a', 'red', 'team_a');
		$teamBValues = array('1', 'b', 'blue', 'team_b');
		if (in_array($normalized, $teamAValues, true)) {
			return 'team_a';
		}
		if (in_array($normalized, $teamBValues, true)) {
			return 'team_b';
		}
		return null;
	}
}
