<?php
declare(strict_types=1);

namespace PixelControl\Tests\Support;

class FakeSettingManager {
	public $values = array();
	public $writes = array();
	public $failOnSet = array();

	public function __construct(array $initialValues = array(), array $failOnSet = array()) {
		$this->values = $initialValues;
		$this->failOnSet = $failOnSet;
	}

	public function initSetting($plugin, $settingName, $defaultValue) {
		if (!array_key_exists($settingName, $this->values)) {
			$this->values[$settingName] = $defaultValue;
		}

		return true;
	}

	public function getSettingValue($plugin, $settingName) {
		if (!array_key_exists($settingName, $this->values)) {
			return '';
		}

		return $this->values[$settingName];
	}

	public function setSetting($plugin, $settingName, $settingValue) {
		if (!empty($this->failOnSet[$settingName])) {
			return false;
		}

		$this->values[$settingName] = $settingValue;
		$this->writes[] = array(
			'name' => (string) $settingName,
			'value' => $settingValue,
		);

		return true;
	}
}

class FakeAuthenticationManager {
	private $permissionByRight = array();
	public $notAllowedCount = 0;

	public function setPermission($permissionName, $allowed) {
		$this->permissionByRight[(string) $permissionName] = (bool) $allowed;
	}

	public function checkPluginPermission($plugin, $player, $permissionName) {
		$permissionName = (string) $permissionName;
		if (!array_key_exists($permissionName, $this->permissionByRight)) {
			return false;
		}

		return (bool) $this->permissionByRight[$permissionName];
	}

	public function sendNotAllowed($player) {
		$this->notAllowedCount++;
		return null;
	}
}

class FakeChat {
	public $messages = array();

	public function sendInformation($message, $player = null) {
		$this->messages[] = array('type' => 'info', 'message' => (string) $message);
	}

	public function sendError($message, $player = null) {
		$this->messages[] = array('type' => 'error', 'message' => (string) $message);
	}

	public function sendSuccess($message, $player = null) {
		$this->messages[] = array('type' => 'success', 'message' => (string) $message);
	}
}

class FakeMapManager {
	public $currentMap = null;

	public function getCurrentMap() {
		return $this->currentMap;
	}
}

class FakeClient {
	public $kickCalls = array();
	public $kickFailures = array();
	public $kickExceptions = array();
	public $guestList = array();
	public $guestListCleanCount = 0;
	public $guestListSaveCalls = array();
	public $saveGuestListException = null;
	public $callVoteTimeout = 120000;

	public function kick($login, $reason = '') {
		$normalizedLogin = strtolower(trim((string) $login));
		$this->kickCalls[] = array(
			'login' => $normalizedLogin,
			'reason' => (string) $reason,
		);

		if (isset($this->kickExceptions[$normalizedLogin]) && $this->kickExceptions[$normalizedLogin] instanceof \Throwable) {
			throw $this->kickExceptions[$normalizedLogin];
		}

		if (isset($this->kickFailures[$normalizedLogin])) {
			return false;
		}

		return true;
	}

	public function cleanGuestList() {
		$this->guestList = array();
		$this->guestListCleanCount++;
		return true;
	}

	public function addGuest($login) {
		$this->guestList[] = strtolower(trim((string) $login));
		return true;
	}

	public function saveGuestList($filename = '') {
		$this->guestListSaveCalls[] = (string) $filename;
		if ($this->saveGuestListException instanceof \Throwable) {
			throw $this->saveGuestListException;
		}

		return true;
	}

	public function cancelVote() {
		return true;
	}

	public function setCallVoteTimeOut($value) {
		$this->callVoteTimeout = max(0, (int) $value);
		return true;
	}

	public function getCallVoteTimeOut() {
		return $this->callVoteTimeout;
	}
}

class FakeLinkApiClient {
	public $baseUrl = '';
	public $authMode = 'none';
	public $authValue = '';

	public function setBaseUrl($baseUrl) {
		$this->baseUrl = (string) $baseUrl;
	}

	public function setAuthMode($authMode) {
		$this->authMode = (string) $authMode;
	}

	public function setAuthValue($authValue) {
		$this->authValue = (string) $authValue;
	}
}

class FakeServer {
	public $login;
	public $titleId;
	public $port;
	public $p2pPort;

	public function __construct($login = 'server-local') {
		$this->login = (string) $login;
		$this->titleId = 'SMStorm@nadeolabs';
		$this->port = 2350;
		$this->p2pPort = 3450;
	}
}

class FakePlayerManager {
	private $players = array();

	public function registerPlayer($player) {
		if (!is_object($player) || !isset($player->login)) {
			return;
		}

		$this->players[strtolower((string) $player->login)] = $player;
	}

	public function getPlayer($login, $fetchOnline = false) {
		$normalizedLogin = strtolower(trim((string) $login));
		if ($normalizedLogin === '') {
			return null;
		}

		if (!array_key_exists($normalizedLogin, $this->players)) {
			return null;
		}

		return $this->players[$normalizedLogin];
	}

	public function getPlayers($includeSpectators = false) {
		return array_values($this->players);
	}

	public function getPlayerCount($activeOnly = false, $includeSpectators = true) {
		return count($this->players);
	}

	public function getSpectatorCount() {
		return 0;
	}
}

class FakeManiaControl {
	private $settingManager;
	private $authenticationManager;
	private $mapManager;
	private $chat;
	private $server;
	private $playerManager;
	private $client;

	public function __construct(
		$settingManager = null,
		$authenticationManager = null,
		$mapManager = null,
		$chat = null,
		$server = null,
		$playerManager = null,
		$client = null
	) {
		$this->settingManager = $settingManager ?: new FakeSettingManager();
		$this->authenticationManager = $authenticationManager ?: new FakeAuthenticationManager();
		$this->mapManager = $mapManager ?: new FakeMapManager();
		$this->chat = $chat ?: new FakeChat();
		$this->server = $server ?: new FakeServer();
		$this->playerManager = $playerManager ?: new FakePlayerManager();
		$this->client = $client ?: new FakeClient();
	}

	public function getSettingManager() {
		return $this->settingManager;
	}

	public function getAuthenticationManager() {
		return $this->authenticationManager;
	}

	public function getMapManager() {
		return $this->mapManager;
	}

	public function getChat() {
		return $this->chat;
	}

	public function getServer() {
		return $this->server;
	}

	public function getPlayerManager() {
		return $this->playerManager;
	}

	public function getClient() {
		return $this->client;
	}
}

class FakeMapPoolService {
	private $mapPool;

	public function __construct(array $mapPool) {
		$this->mapPool = $mapPool;
	}

	public function buildMapPool($mapManager) {
		return $this->mapPool;
	}
}

class FakeVetoCoordinator {
	public $active = false;
	public $startCalls = 0;
	public $lastStartPayload = array();
	public $nextStartResult = null;

	public function setActive($active) {
		$this->active = (bool) $active;
	}

	public function getStatusSnapshot() {
		return array(
			'active' => $this->active,
			'mode' => ($this->active ? 'matchmaking_vote' : ''),
			'session' => array(
				'session_id' => ($this->active ? 'fake-active-session' : ''),
			),
		);
	}

	public function hasActiveSession() {
		return $this->active;
	}

	public function startMatchmaking(array $mapPool, $durationSeconds, $timestamp) {
		$this->startCalls++;
		$this->lastStartPayload = array(
			'map_pool' => $mapPool,
			'duration_seconds' => (int) $durationSeconds,
			'timestamp' => (int) $timestamp,
		);

		if (is_array($this->nextStartResult)) {
			if (!empty($this->nextStartResult['success'])) {
				$this->active = true;
			}
			return $this->nextStartResult;
		}

		$this->active = true;

		return array(
			'success' => true,
			'code' => 'matchmaking_started',
			'message' => 'Matchmaking started (fake coordinator).',
			'details' => array(
				'session' => array(
					'session_id' => 'fake-matchmaking-session',
					'mode' => 'matchmaking_vote',
				),
			),
		);
	}
}
