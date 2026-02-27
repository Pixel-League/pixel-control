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
		const AUTH_LEVEL_MODERATOR = 1;
		const AUTH_LEVEL_ADMIN = 2;
		const AUTH_LEVEL_SUPERADMIN = 3;
		const AUTH_LEVEL_MASTERADMIN = 4;
		const AUTH_NAME_PLAYER = 'Player';
		const AUTH_NAME_MODERATOR = 'Moderator';
		const AUTH_NAME_ADMIN = 'Admin';
		const AUTH_NAME_SUPERADMIN = 'SuperAdmin';
		const AUTH_NAME_MASTERADMIN = 'MasterAdmin';

		public static function checkRight($player, $neededAuthLevel) {
			return isset($player->authLevel) && ((int) $player->authLevel >= (int) $neededAuthLevel);
		}

		public static function getAuthLevelName($authLevel) {
			switch ((int) $authLevel) {
				case self::AUTH_LEVEL_MASTERADMIN:
					return self::AUTH_NAME_MASTERADMIN;
				case self::AUTH_LEVEL_SUPERADMIN:
					return self::AUTH_NAME_SUPERADMIN;
				case self::AUTH_LEVEL_ADMIN:
					return self::AUTH_NAME_ADMIN;
				case self::AUTH_LEVEL_MODERATOR:
					return self::AUTH_NAME_MODERATOR;
				case self::AUTH_LEVEL_PLAYER:
					return self::AUTH_NAME_PLAYER;
				default:
					return '-';
			}
		}

		public static function getAuthLevel($authLevelName) {
			switch ((string) $authLevelName) {
				case self::AUTH_NAME_MASTERADMIN:
					return self::AUTH_LEVEL_MASTERADMIN;
				case self::AUTH_NAME_SUPERADMIN:
					return self::AUTH_LEVEL_SUPERADMIN;
				case self::AUTH_NAME_ADMIN:
					return self::AUTH_LEVEL_ADMIN;
				case self::AUTH_NAME_MODERATOR:
					return self::AUTH_LEVEL_MODERATOR;
				case self::AUTH_NAME_PLAYER:
					return self::AUTH_LEVEL_PLAYER;
				default:
					return -1;
			}
		}

		public function sendNotAllowed($player) {
			return null;
		}

		public function checkPluginPermission($plugin, $player, $permissionName) {
			return false;
		}
	}
}
