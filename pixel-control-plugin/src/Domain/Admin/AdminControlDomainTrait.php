<?php

namespace PixelControl\Domain\Admin;

use ManiaControl\Communication\CommunicationAnswer;
use ManiaControl\Logger;
use ManiaControl\Players\Player;
use PixelControl\Admin\AdminActionCatalog;
use PixelControl\Admin\AdminActionResult;
use PixelControl\Admin\NativeAdminGateway;

trait AdminControlDomainTrait {
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

		$this->nativeAdminGateway = new NativeAdminGateway($this->maniaControl);
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
		foreach (AdminActionCatalog::getActionDefinitions() as $actionName => $definition) {
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

	public function handleAdminControlCommand(array $chatCallback, Player $player) {
		if (!$this->adminControlEnabled) {
			$this->maniaControl->getChat()->sendError('Pixel admin control surface is disabled.', $player);
			return;
		}

		$commandRequest = $this->parseAdminControlCommandRequest($chatCallback);
		$actionName = $commandRequest['action_name'];
		$parameters = $commandRequest['parameters'];

		if ($actionName === '' || $actionName === 'help' || $actionName === 'list') {
			$this->sendAdminControlHelp($player);
			return;
		}

		$result = $this->executeDelegatedAdminAction(
			$actionName,
			$parameters,
			(isset($player->login) ? (string) $player->login : ''),
			'chat_command',
			$player
		);

		if ($result->isSuccess()) {
			$this->maniaControl->getChat()->sendSuccess($result->getMessage(), $player);
			return;
		}

		if ($result->getCode() === 'permission_denied') {
			$this->maniaControl->getAuthenticationManager()->sendNotAllowed($player);
			return;
		}

		$this->maniaControl->getChat()->sendError($result->getMessage(), $player);
	}

	public function handleAdminControlCommunicationExecute($data) {
		if (!$this->adminControlEnabled) {
			$result = AdminActionResult::failure('unknown', 'feature_disabled', 'Pixel admin control surface is disabled.');
			return new CommunicationAnswer($result->toArray(), true);
		}

		$requestPayload = $this->normalizeCommunicationPayload($data);
		$actionName = isset($requestPayload['action']) ? (string) $requestPayload['action'] : '';
		if ($actionName === '') {
			$actionName = isset($requestPayload['action_name']) ? (string) $requestPayload['action_name'] : '';
		}

		$parameters = array();
		if (isset($requestPayload['parameters']) && is_array($requestPayload['parameters'])) {
			$parameters = $requestPayload['parameters'];
		}

		$actorLogin = '';
		if (isset($requestPayload['actor_login'])) {
			$actorLogin = trim((string) $requestPayload['actor_login']);
		}

		$result = $this->executeDelegatedAdminAction($actionName, $parameters, $actorLogin, 'communication', null);

		return new CommunicationAnswer($result->toArray(), !$result->isSuccess());
	}

	public function handleAdminControlCommunicationList($data) {
		$payload = array(
			'enabled' => $this->adminControlEnabled,
			'command' => $this->adminControlCommandName,
			'communication' => array(
				'exec' => AdminActionCatalog::COMMUNICATION_EXECUTE_ACTION,
				'list' => AdminActionCatalog::COMMUNICATION_LIST_ACTIONS,
			),
			'actions' => AdminActionCatalog::getActionDefinitions(),
		);

		return new CommunicationAnswer($payload, false);
	}

	private function executeDelegatedAdminAction($actionName, array $parameters, $actorLogin, $requestSource, $requestActor = null) {
		$normalizedActionName = AdminActionCatalog::normalizeActionName($actionName);
		$actionDefinition = AdminActionCatalog::getActionDefinition($normalizedActionName);
		if ($actionDefinition === null) {
			return AdminActionResult::failure($normalizedActionName, 'action_unknown', 'Unknown admin action. Use //'.$this->adminControlCommandName.' help to list actions.');
		}

		$resolvedActor = $this->resolveActionActor($actorLogin, $requestActor);
		if (!$resolvedActor) {
			return AdminActionResult::failure($normalizedActionName, 'actor_not_found', 'Delegated admin action requires a connected actor player.');
		}

		$permissionSetting = isset($actionDefinition['permission_setting']) ? (string) $actionDefinition['permission_setting'] : '';
		if ($permissionSetting !== '') {
			$hasPermission = $this->maniaControl->getAuthenticationManager()->checkPluginPermission($this, $resolvedActor, $permissionSetting);
			if (!$hasPermission) {
				return AdminActionResult::failure($normalizedActionName, 'permission_denied', 'Permission denied for delegated admin action.');
			}
		}

		$normalizedParameters = $this->normalizeActionParameters($normalizedActionName, $parameters);

		Logger::log(
			'[PixelControl][admin][action_requested] action=' . $normalizedActionName
			. ', source=' . $requestSource
			. ', actor=' . (isset($resolvedActor->login) ? (string) $resolvedActor->login : 'unknown')
			. ', parameters=' . json_encode($normalizedParameters)
			. '.'
		);

		$result = $this->nativeAdminGateway
			? $this->nativeAdminGateway->execute($normalizedActionName, $normalizedParameters, isset($resolvedActor->login) ? (string) $resolvedActor->login : '')
			: AdminActionResult::failure($normalizedActionName, 'gateway_unavailable', 'Native admin gateway is unavailable.');

		if ($result->isSuccess()) {
			$this->rememberPauseStateAfterAction($normalizedActionName, $normalizedParameters);
			Logger::log(
				'[PixelControl][admin][action_success] action=' . $normalizedActionName
				. ', source=' . $requestSource
				. ', actor=' . (isset($resolvedActor->login) ? (string) $resolvedActor->login : 'unknown')
				. ', code=' . $result->getCode()
				. '.'
			);
			return $result;
		}

		Logger::logWarning(
			'[PixelControl][admin][action_failed] action=' . $normalizedActionName
			. ', source=' . $requestSource
			. ', actor=' . (isset($resolvedActor->login) ? (string) $resolvedActor->login : 'unknown')
			. ', code=' . $result->getCode()
			. ', message=' . $result->getMessage()
			. '.'
		);

		return $result;
	}

	private function parseAdminControlCommandRequest(array $chatCallback) {
		$commandText = '';
		if (isset($chatCallback[1]) && is_array($chatCallback[1]) && isset($chatCallback[1][2])) {
			$commandText = trim((string) $chatCallback[1][2]);
		}

		if ($commandText === '') {
			return array('action_name' => '', 'parameters' => array());
		}

		$tokens = preg_split('/\s+/', $commandText);
		if (empty($tokens)) {
			return array('action_name' => '', 'parameters' => array());
		}

		array_shift($tokens);
		if (empty($tokens)) {
			return array('action_name' => '', 'parameters' => array());
		}

		$actionName = AdminActionCatalog::normalizeActionName(array_shift($tokens));
		$parameters = $this->parseArgumentTokens($tokens);

		return array(
			'action_name' => $actionName,
			'parameters' => $parameters,
		);
	}

	private function parseArgumentTokens(array $tokens) {
		$arguments = array();
		$positionals = array();

		foreach ($tokens as $token) {
			$trimmedToken = trim((string) $token);
			if ($trimmedToken === '') {
				continue;
			}

			if (strpos($trimmedToken, '=') === false) {
				$positionals[] = $trimmedToken;
				continue;
			}

			$parts = explode('=', $trimmedToken, 2);
			$key = $this->normalizeIdentifier($parts[0], '');
			if ($key === '') {
				continue;
			}

			$arguments[$key] = isset($parts[1]) ? trim((string) $parts[1]) : '';
		}

		if (!empty($positionals)) {
			$arguments['_positionals'] = $positionals;
		}

		return $arguments;
	}

	private function normalizeActionParameters($actionName, array $parameters) {
		$normalizedParameters = $parameters;
		$positionals = array();
		if (isset($normalizedParameters['_positionals']) && is_array($normalizedParameters['_positionals'])) {
			$positionals = array_values($normalizedParameters['_positionals']);
		}
		unset($normalizedParameters['_positionals']);

		switch ($actionName) {
			case AdminActionCatalog::ACTION_MAP_JUMP:
			case AdminActionCatalog::ACTION_MAP_QUEUE:
				if (!isset($normalizedParameters['map_uid']) && !empty($positionals)) {
					$normalizedParameters['map_uid'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_WARMUP_EXTEND:
				if (!isset($normalizedParameters['seconds']) && !empty($positionals)) {
					$normalizedParameters['seconds'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_VOTE_SET_RATIO:
				if (!isset($normalizedParameters['command']) && !empty($positionals)) {
					$normalizedParameters['command'] = $positionals[0];
				}
				if (!isset($normalizedParameters['ratio']) && count($positionals) > 1) {
					$normalizedParameters['ratio'] = $positionals[1];
				}
			break;
			case AdminActionCatalog::ACTION_PLAYER_FORCE_TEAM:
				if (!isset($normalizedParameters['target_login']) && !empty($positionals)) {
					$normalizedParameters['target_login'] = $positionals[0];
				}
				if (!isset($normalizedParameters['team']) && count($positionals) > 1) {
					$normalizedParameters['team'] = $positionals[1];
				}
			break;
			case AdminActionCatalog::ACTION_PLAYER_FORCE_PLAY:
			case AdminActionCatalog::ACTION_PLAYER_FORCE_SPEC:
			case AdminActionCatalog::ACTION_AUTH_REVOKE:
				if (!isset($normalizedParameters['target_login']) && !empty($positionals)) {
					$normalizedParameters['target_login'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_AUTH_GRANT:
				if (!isset($normalizedParameters['target_login']) && !empty($positionals)) {
					$normalizedParameters['target_login'] = $positionals[0];
				}
				if (!isset($normalizedParameters['auth_level']) && count($positionals) > 1) {
					$normalizedParameters['auth_level'] = $positionals[1];
				}
			break;
			case AdminActionCatalog::ACTION_VOTE_CUSTOM_START:
				if (!isset($normalizedParameters['vote_index']) && !empty($positionals)) {
					$normalizedParameters['vote_index'] = $positionals[0];
				}
			break;
		}

		if ($actionName === AdminActionCatalog::ACTION_PAUSE_TOGGLE && !array_key_exists('pause_active', $normalizedParameters)) {
			$knownPauseState = $this->resolveKnownPauseStateForToggle();
			if ($knownPauseState !== null) {
				$normalizedParameters['pause_active'] = $knownPauseState;
			}
		}

		return $normalizedParameters;
	}

	private function resolveActionActor($actorLogin, $requestActor = null) {
		if ($requestActor instanceof Player) {
			return $requestActor;
		}

		$normalizedActorLogin = trim((string) $actorLogin);
		if ($normalizedActorLogin === '') {
			return null;
		}

		return $this->maniaControl->getPlayerManager()->getPlayer($normalizedActorLogin);
	}

	private function normalizeCommunicationPayload($data) {
		if (is_array($data)) {
			return $data;
		}

		if (is_object($data)) {
			$encoded = json_encode($data);
			if (is_string($encoded)) {
				$decoded = json_decode($encoded, true);
				if (is_array($decoded)) {
					return $decoded;
				}
			}
		}

		return array();
	}

	private function sendAdminControlHelp(Player $player) {
		$actionNames = array_keys(AdminActionCatalog::getActionDefinitions());
		sort($actionNames);

		$this->maniaControl->getChat()->sendInformation(
			'Pixel delegated admin actions: ' . implode(', ', $actionNames),
			$player
		);
		$this->maniaControl->getChat()->sendInformation(
			'Usage: //' . $this->adminControlCommandName . ' <action> key=value ...',
			$player
		);
	}

	private function resolveRuntimeBoolSetting($settingName, $environmentVariableName, $fallback) {
		$environmentValue = $this->readEnvString($environmentVariableName, '');
		if ($environmentValue !== '') {
			$normalizedEnvironmentValue = strtolower(trim($environmentValue));
			return in_array($normalizedEnvironmentValue, array('1', 'true', 'yes', 'on'), true);
		}

		$settingValue = $this->maniaControl->getSettingManager()->getSettingValue($this, $settingName);
		if (is_bool($settingValue)) {
			return $settingValue;
		}

		if (is_numeric($settingValue)) {
			return ((int) $settingValue) !== 0;
		}

		if (is_string($settingValue)) {
			$normalizedSettingValue = strtolower(trim($settingValue));
			if ($normalizedSettingValue !== '') {
				return in_array($normalizedSettingValue, array('1', 'true', 'yes', 'on'), true);
			}
		}

		return (bool) $fallback;
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

	private function observePauseStateFromLifecycle($variant, array $callbackArguments) {
		if ($variant === 'pause.start') {
			$this->adminControlPauseActive = true;
			$this->adminControlPauseObservedAt = time();
			return;
		}

		if ($variant === 'pause.end') {
			$this->adminControlPauseActive = false;
			$this->adminControlPauseObservedAt = time();
			return;
		}

		if ($variant !== 'pause.status') {
			return;
		}

		$scriptPayload = $this->extractScriptCallbackPayload($callbackArguments);
		$active = $this->extractBooleanPayloadValue($scriptPayload, array('active'));
		if ($active === null) {
			return;
		}

		$this->adminControlPauseActive = $active;
		$this->adminControlPauseObservedAt = time();
	}

	private function resolveKnownPauseStateForToggle() {
		if (!is_bool($this->adminControlPauseActive)) {
			return null;
		}

		if ($this->adminControlPauseObservedAt <= 0) {
			return null;
		}

		$ageSeconds = max(0, time() - (int) $this->adminControlPauseObservedAt);
		if ($ageSeconds > $this->adminControlPauseStateMaxAgeSeconds) {
			return null;
		}

		return $this->adminControlPauseActive;
	}

	private function rememberPauseStateAfterAction($actionName, array $parameters) {
		if ($actionName === AdminActionCatalog::ACTION_PAUSE_START) {
			$this->adminControlPauseActive = true;
			$this->adminControlPauseObservedAt = time();
			return;
		}

		if ($actionName === AdminActionCatalog::ACTION_PAUSE_END) {
			$this->adminControlPauseActive = false;
			$this->adminControlPauseObservedAt = time();
			return;
		}

		if ($actionName !== AdminActionCatalog::ACTION_PAUSE_TOGGLE) {
			return;
		}

		if (!array_key_exists('pause_active', $parameters)) {
			return;
		}

		$pauseWasActive = $parameters['pause_active'];
		if (is_bool($pauseWasActive)) {
			$this->adminControlPauseActive = !$pauseWasActive;
			$this->adminControlPauseObservedAt = time();
		}
	}

	private function buildAdminControlCapabilitiesPayload() {
		$actionDefinitions = AdminActionCatalog::getActionDefinitions();
		$actionNames = array_keys($actionDefinitions);
		sort($actionNames);

		return array(
			'available' => true,
			'enabled' => $this->adminControlEnabled,
			'command' => $this->adminControlCommandName,
			'pause_state_ttl_seconds' => $this->adminControlPauseStateMaxAgeSeconds,
			'communication' => array(
				'execute_action' => AdminActionCatalog::COMMUNICATION_EXECUTE_ACTION,
				'list_actions' => AdminActionCatalog::COMMUNICATION_LIST_ACTIONS,
			),
			'actions' => $actionNames,
			'ownership_boundary' => array(
				'telemetry_transport' => 'pixel_plugin',
				'admin_execution' => 'native_maniacontrol',
			),
		);
	}
}
