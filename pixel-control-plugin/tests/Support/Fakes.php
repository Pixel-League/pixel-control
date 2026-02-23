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
}

class FakeManiaControl {
	private $settingManager;
	private $authenticationManager;
	private $mapManager;
	private $chat;

	public function __construct(
		$settingManager = null,
		$authenticationManager = null,
		$mapManager = null,
		$chat = null
	) {
		$this->settingManager = $settingManager ?: new FakeSettingManager();
		$this->authenticationManager = $authenticationManager ?: new FakeAuthenticationManager();
		$this->mapManager = $mapManager ?: new FakeMapManager();
		$this->chat = $chat ?: new FakeChat();
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
