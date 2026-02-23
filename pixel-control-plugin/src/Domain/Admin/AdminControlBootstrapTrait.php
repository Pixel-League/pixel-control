<?php

namespace PixelControl\Domain\Admin;

use PixelControl\Admin\NativeAdminGateway;
use ManiaControl\Logger;
use PixelControl\Admin\AdminActionCatalog;

trait AdminControlBootstrapTrait {
	private function initializeAdminControlSettings() {
		$settingManager = $this->maniaControl->getSettingManager();

		$adminControlEnabledByEnv = $this->readEnvString('PIXEL_CONTROL_ADMIN_CONTROL_ENABLED', '0');
		$settingManager->initSetting($this, self::SETTING_ADMIN_CONTROL_ENABLED, ($adminControlEnabledByEnv === '1'));

		$commandNameFromEnv = $this->readEnvString('PIXEL_CONTROL_ADMIN_COMMAND', 'pcadmin');
		$settingManager->initSetting($this, self::SETTING_ADMIN_CONTROL_COMMAND, $commandNameFromEnv);

		$pauseStateMaxAgeByEnv = $this->resolveRuntimeIntSetting(
			self::SETTING_ADMIN_CONTROL_PAUSE_STATE_MAX_AGE_SECONDS,
			'PIXEL_CONTROL_ADMIN_PAUSE_STATE_MAX_AGE_SECONDS',
			120,
			10
		);
		$settingManager->initSetting($this, self::SETTING_ADMIN_CONTROL_PAUSE_STATE_MAX_AGE_SECONDS, $pauseStateMaxAgeByEnv);
	}


	private function initializeAdminDelegationLayer() {
		if (!$this->maniaControl) {
			return;
		}

		$this->nativeAdminGateway = new NativeAdminGateway(
			$this->maniaControl,
			$this->seriesControlState,
			$this->whitelistState,
			$this->votePolicyState,
			$this->teamRosterState
		);
		$this->defineAdminControlPermissions();

		$this->adminControlEnabled = $this->resolveRuntimeBoolSetting(
			self::SETTING_ADMIN_CONTROL_ENABLED,
			'PIXEL_CONTROL_ADMIN_CONTROL_ENABLED',
			false
		);
		$this->adminControlCommandName = $this->resolveAdminControlCommandName();
		$this->adminControlPauseStateMaxAgeSeconds = $this->resolveRuntimeIntSetting(
			self::SETTING_ADMIN_CONTROL_PAUSE_STATE_MAX_AGE_SECONDS,
			'PIXEL_CONTROL_ADMIN_PAUSE_STATE_MAX_AGE_SECONDS',
			120,
			10
		);

		$this->registerAdminControlEntryPoints();

		Logger::log(
			'[PixelControl][admin][bootstrap] enabled=' . ($this->adminControlEnabled ? 'yes' : 'no')
			. ', command=' . $this->adminControlCommandName
			. ', pause_state_ttl=' . $this->adminControlPauseStateMaxAgeSeconds
			. ', actions=' . count(AdminActionCatalog::getActionDefinitions())
			. '.'
		);
	}


	private function defineAdminControlPermissions() {
		if (!$this->maniaControl) {
			return;
		}

		$authenticationManager = $this->maniaControl->getAuthenticationManager();
		foreach (AdminActionCatalog::getActionDefinitions() as $definition) {
			if (!isset($definition['permission_setting']) || trim((string) $definition['permission_setting']) === '') {
				continue;
			}

			if (!isset($definition['minimum_auth_level']) || !is_numeric($definition['minimum_auth_level'])) {
				continue;
			}

			$authenticationManager->definePluginPermissionLevel(
				$this,
				$definition['permission_setting'],
				(int) $definition['minimum_auth_level']
			);
		}
	}


	private function registerAdminControlEntryPoints() {
		if (!$this->maniaControl || !$this->adminControlEnabled) {
			return;
		}

		$commandNames = array($this->adminControlCommandName);
		if ($this->adminControlCommandName !== 'pcadmin') {
			$commandNames[] = 'pcadmin';
		}

		$this->maniaControl->getCommandManager()->registerCommandListener(
			$commandNames,
			$this,
			'handleAdminControlCommand',
			true,
			'Delegated Pixel Control admin actions (native ManiaControl execution).'
		);

		$this->maniaControl->getCommunicationManager()->registerCommunicationListener(
			AdminActionCatalog::COMMUNICATION_EXECUTE_ACTION,
			$this,
			'handleAdminControlCommunicationExecute'
		);

		$this->maniaControl->getCommunicationManager()->registerCommunicationListener(
			AdminActionCatalog::COMMUNICATION_LIST_ACTIONS,
			$this,
			'handleAdminControlCommunicationList'
		);
	}


	private function unregisterAdminControlEntryPoints() {
		if (!$this->maniaControl) {
			return;
		}

		$this->maniaControl->getCommandManager()->unregisterCommandListener($this);
		$this->maniaControl->getCommunicationManager()->unregisterCommunicationListener($this);
	}


	private function resolveAdminControlCommandName() {
		$commandName = $this->resolveRuntimeStringSetting(
			self::SETTING_ADMIN_CONTROL_COMMAND,
			'PIXEL_CONTROL_ADMIN_COMMAND',
			'pcadmin'
		);

		$normalizedCommandName = $this->normalizeIdentifier($commandName, 'pcadmin');
		if ($normalizedCommandName === 'admin') {
			return 'pcadmin';
		}

		return $normalizedCommandName;
	}

}
