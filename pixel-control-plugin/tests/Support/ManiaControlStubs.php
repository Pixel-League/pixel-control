<?php
declare(strict_types=1);

namespace ManiaControl;

if (!class_exists('ManiaControl\\Logger', false)) {
	class Logger {
		public static function log($message) {
			return null;
		}

		public static function logWarning($message) {
			return null;
		}

		public static function logError($message) {
			return null;
		}
	}
}

namespace ManiaControl\Communication;

if (!class_exists('ManiaControl\\Communication\\CommunicationAnswer', false)) {
	class CommunicationAnswer {
		public $data;
		public $error;

		public function __construct($data = array(), $error = false) {
			$this->data = $data;
			$this->error = (bool) $error;
		}
	}
}

namespace ManiaControl\Players;

if (!class_exists('ManiaControl\\Players\\Player', false)) {
	class Player {
		public $login;
		public $authLevel = 0;
		public $isServer = false;
		private $fakePlayer = false;

		public function __construct($login = '', $authLevel = 0, $isServer = false, $fakePlayer = false) {
			$this->login = (string) $login;
			$this->authLevel = (int) $authLevel;
			$this->isServer = (bool) $isServer;
			$this->fakePlayer = (bool) $fakePlayer;
		}

		public function isFakePlayer() {
			return $this->fakePlayer;
		}
	}
}

namespace ManiaControl\Admin;

if (!class_exists('ManiaControl\\Admin\\AuthenticationManager', false)) {
	class AuthenticationManager {
		const AUTH_LEVEL_PLAYER = 0;
		const AUTH_LEVEL_MODERATOR = 2;

		public function sendNotAllowed($player) {
			return null;
		}

		public function checkPluginPermission($plugin, $player, $permissionName) {
			return false;
		}
	}
}
