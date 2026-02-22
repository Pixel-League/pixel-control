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

		$this->nativeAdminGateway = new NativeAdminGateway($this->maniaControl, $this->seriesControlState);
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

		$result = $this->executeDelegatedAdminAction(
			$actionName,
			$parameters,
			$actorLogin,
			'communication',
			null,
			array(
				'allow_actorless' => true,
				'skip_permission_checks' => true,
				'security_mode' => 'payload_untrusted',
			)
		);

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
			'security' => array(
				'chat_command' => array(
					'actor_login_required' => true,
					'permission_model' => 'maniacontrol_plugin_rights',
				),
				'communication' => array(
					'authentication_mode' => 'none_temporary',
					'actor_login_required' => false,
					'permission_model' => 'trusted_payload_no_actor',
				),
			),
			'series_targets' => $this->getSeriesControlSnapshot(),
			'actions' => AdminActionCatalog::getActionDefinitions(),
		);

		return new CommunicationAnswer($payload, false);
	}

	private function executeDelegatedAdminAction($actionName, array $parameters, $actorLogin, $requestSource, $requestActor = null, array $requestOptions = array()) {
		$normalizedActionName = AdminActionCatalog::normalizeActionName($actionName);
		$actionDefinition = AdminActionCatalog::getActionDefinition($normalizedActionName);
		if ($actionDefinition === null) {
			return AdminActionResult::failure($normalizedActionName, 'action_unknown', 'Unknown admin action. Use //'.$this->adminControlCommandName.' help to list actions.');
		}

		$seriesSnapshotBeforeAction = array();
		if ($this->shouldPersistSeriesControlAfterAdminAction($normalizedActionName)) {
			$seriesSnapshotBeforeAction = $this->getSeriesControlSnapshot();
		}

		$allowActorless = !empty($requestOptions['allow_actorless']);
		$skipPermissionChecks = !empty($requestOptions['skip_permission_checks']);
		$securityMode = isset($requestOptions['security_mode']) ? trim((string) $requestOptions['security_mode']) : 'actor_bound';

		$resolvedActor = $this->resolveActionActor($actorLogin, $requestActor);
		if (!$resolvedActor && !$allowActorless) {
			return AdminActionResult::failure($normalizedActionName, 'actor_not_found', 'Delegated admin action requires a connected actor player.');
		}
		$resolvedActorLogin = ($resolvedActor && isset($resolvedActor->login)) ? (string) $resolvedActor->login : '';
		$logActor = ($resolvedActorLogin !== '') ? $resolvedActorLogin : 'server_payload';

		if ($allowActorless && $resolvedActorLogin === '') {
			Logger::logWarning(
				'[PixelControl][admin][security_mode] action=' . $normalizedActionName
				. ', source=' . (string) $requestSource
				. ', mode=' . ($securityMode !== '' ? $securityMode : 'payload_untrusted')
				. ', actor=none, note=unauthenticated_payload_path.'
			);
		}

		$permissionSetting = isset($actionDefinition['permission_setting']) ? (string) $actionDefinition['permission_setting'] : '';
		if ($permissionSetting !== '' && !$skipPermissionChecks) {
			if (!$resolvedActor) {
				return AdminActionResult::failure($normalizedActionName, 'actor_not_found', 'Delegated admin action requires a connected actor player.');
			}

			$hasPermission = $this->maniaControl->getAuthenticationManager()->checkPluginPermission($this, $resolvedActor, $permissionSetting);
			if (!$hasPermission) {
				return AdminActionResult::failure($normalizedActionName, 'permission_denied', 'Permission denied for delegated admin action.');
			}
		}

		$normalizedParameters = $this->normalizeActionParameters($normalizedActionName, $parameters);

		Logger::log(
			'[PixelControl][admin][action_requested] action=' . $normalizedActionName
			. ', source=' . $requestSource
			. ', actor=' . $logActor
			. ', parameters=' . json_encode($normalizedParameters)
			. '.'
		);

		$result = $this->nativeAdminGateway
			? $this->nativeAdminGateway->execute(
				$normalizedActionName,
				$normalizedParameters,
				$resolvedActorLogin,
				array(
					'request_source' => (string) $requestSource,
					'allow_actorless' => $allowActorless,
					'skip_permission_checks' => $skipPermissionChecks,
					'security_mode' => ($securityMode !== '' ? $securityMode : 'actor_bound'),
					'active_veto_session' => ($this->vetoDraftCoordinator ? $this->vetoDraftCoordinator->hasActiveSession() : false),
				)
			)
			: AdminActionResult::failure($normalizedActionName, 'gateway_unavailable', 'Native admin gateway is unavailable.');

		if ($result->isSuccess()) {
			$seriesPersistenceResult = $this->persistSeriesControlAfterAdminAction(
				$normalizedActionName,
				$result,
				$seriesSnapshotBeforeAction
			);
			if (empty($seriesPersistenceResult['success'])) {
				Logger::logWarning(
					'[PixelControl][admin][action_persistence_failed] action=' . $normalizedActionName
					. ', source=' . $requestSource
					. ', actor=' . $logActor
					. ', code=' . (isset($seriesPersistenceResult['code']) ? (string) $seriesPersistenceResult['code'] : 'setting_write_failed')
					. '.'
				);

				$failureDetails = $result->getDetails();
				$failureDetails['series_targets'] = $this->getSeriesControlSnapshot();
				$failureDetails['persistence'] = isset($seriesPersistenceResult['details']) && is_array($seriesPersistenceResult['details'])
					? $seriesPersistenceResult['details']
					: array();

				return AdminActionResult::failure(
					$normalizedActionName,
					isset($seriesPersistenceResult['code']) ? (string) $seriesPersistenceResult['code'] : 'setting_write_failed',
					isset($seriesPersistenceResult['message']) ? (string) $seriesPersistenceResult['message'] : 'Unable to persist series settings after admin action.',
					$failureDetails
				);
			}

			$this->rememberPauseStateAfterAction($normalizedActionName, $normalizedParameters);
			$this->rememberAdminActionCorrelationContext(
				$normalizedActionName,
				$normalizedParameters,
				$resolvedActorLogin,
				$requestSource,
				$securityMode
			);
			Logger::log(
				'[PixelControl][admin][action_success] action=' . $normalizedActionName
				. ', source=' . $requestSource
				. ', actor=' . $logActor
				. ', code=' . $result->getCode()
				. '.'
			);
			return $result;
		}

		Logger::logWarning(
			'[PixelControl][admin][action_failed] action=' . $normalizedActionName
			. ', source=' . $requestSource
			. ', actor=' . $logActor
			. ', code=' . $result->getCode()
			. ', message=' . $result->getMessage()
			. '.'
		);

		return $result;
	}

	private function shouldPersistSeriesControlAfterAdminAction($actionName) {
		return in_array(
			$actionName,
			array(
				AdminActionCatalog::ACTION_MATCH_BO_SET,
				AdminActionCatalog::ACTION_MATCH_MAPS_SET,
				AdminActionCatalog::ACTION_MATCH_SCORE_SET,
			),
			true
		);
	}

	private function persistSeriesControlAfterAdminAction($actionName, AdminActionResult $actionResult, array $seriesSnapshotBeforeAction) {
		if (!$this->shouldPersistSeriesControlAfterAdminAction($actionName)) {
			return array('success' => true, 'code' => 'not_required', 'message' => 'No persistence required.', 'details' => array());
		}

		$actionDetails = $actionResult->getDetails();
		$seriesSnapshot = (isset($actionDetails['series_targets']) && is_array($actionDetails['series_targets']))
			? $actionDetails['series_targets']
			: $this->getSeriesControlSnapshot();

		$persistenceResult = $this->persistSeriesControlSnapshot($seriesSnapshot, $seriesSnapshotBeforeAction);
		if (!empty($persistenceResult['success'])) {
			return $persistenceResult;
		}

		$rollbackResult = array();
		if (!empty($seriesSnapshotBeforeAction)) {
			$rollbackResult = $this->restoreSeriesControlSnapshot(
				$seriesSnapshotBeforeAction,
				'setting',
				'admin_action_persistence_rollback'
			);
		}

		return array(
			'success' => false,
			'code' => isset($persistenceResult['code']) ? (string) $persistenceResult['code'] : 'setting_write_failed',
			'message' => 'Series settings persistence failed; runtime update rolled back.',
			'details' => array(
				'persistence' => isset($persistenceResult['details']) && is_array($persistenceResult['details']) ? $persistenceResult['details'] : array(),
				'rollback' => isset($rollbackResult['details']) && is_array($rollbackResult['details']) ? $rollbackResult['details'] : array(),
			),
		);
	}

	private function rememberAdminActionCorrelationContext($actionName, array $parameters, $actorLogin, $requestSource, $securityMode) {
		$targetScope = $this->resolveAdminActionCorrelationTargetScope($actionName);
		$targetId = $this->resolveAdminActionCorrelationTargetId($actionName, $parameters, $actorLogin);
		$normalizedActorLogin = trim((string) $actorLogin);
		$observedAt = time();

		$this->recentAdminActionContexts[] = array(
			'event_id' => 'pc-adminctx-' . sha1($actionName . '|' . $targetId . '|' . $observedAt),
			'event_name' => 'pixel_control.admin.execute_action',
			'source_sequence' => 0,
			'source_time' => $observedAt,
			'source_callback' => 'admin.execute_action',
			'action_name' => $actionName,
			'action_type' => $this->resolveAdminActionCorrelationType($actionName),
			'action_phase' => 'execute',
			'target_scope' => $targetScope,
			'target_id' => $targetId,
			'initiator_kind' => $this->resolveAdminActionCorrelationInitiatorKind($normalizedActorLogin, $requestSource, $securityMode),
			'actor_login' => $normalizedActorLogin,
			'observed_at' => $observedAt,
		);

		if (count($this->recentAdminActionContexts) > $this->adminCorrelationHistoryLimit) {
			$this->recentAdminActionContexts = array_slice($this->recentAdminActionContexts, -1 * $this->adminCorrelationHistoryLimit);
		}

		$this->pruneRecentAdminActionContexts();
	}

	private function resolveAdminActionCorrelationTargetScope($actionName) {
			switch ($actionName) {
			case AdminActionCatalog::ACTION_PLAYER_FORCE_TEAM:
			case AdminActionCatalog::ACTION_PLAYER_FORCE_PLAY:
			case AdminActionCatalog::ACTION_PLAYER_FORCE_SPEC:
			case AdminActionCatalog::ACTION_AUTH_GRANT:
			case AdminActionCatalog::ACTION_AUTH_REVOKE:
				return 'player';
			case AdminActionCatalog::ACTION_MAP_SKIP:
			case AdminActionCatalog::ACTION_MAP_RESTART:
			case AdminActionCatalog::ACTION_MAP_JUMP:
			case AdminActionCatalog::ACTION_MAP_QUEUE:
			case AdminActionCatalog::ACTION_MAP_ADD:
			case AdminActionCatalog::ACTION_MAP_REMOVE:
				return 'map';
			case AdminActionCatalog::ACTION_WARMUP_EXTEND:
			case AdminActionCatalog::ACTION_WARMUP_END:
			case AdminActionCatalog::ACTION_PAUSE_START:
			case AdminActionCatalog::ACTION_PAUSE_END:
			case AdminActionCatalog::ACTION_VOTE_CANCEL:
			case AdminActionCatalog::ACTION_VOTE_SET_RATIO:
			case AdminActionCatalog::ACTION_VOTE_CUSTOM_START:
			case AdminActionCatalog::ACTION_MATCH_BO_SET:
			case AdminActionCatalog::ACTION_MATCH_BO_GET:
			case AdminActionCatalog::ACTION_MATCH_MAPS_SET:
			case AdminActionCatalog::ACTION_MATCH_MAPS_GET:
			case AdminActionCatalog::ACTION_MATCH_SCORE_SET:
			case AdminActionCatalog::ACTION_MATCH_SCORE_GET:
				return 'server';
			default:
				return 'unknown';
		}
	}

	private function resolveAdminActionCorrelationTargetId($actionName, array $parameters, $actorLogin) {
			switch ($actionName) {
			case AdminActionCatalog::ACTION_PLAYER_FORCE_TEAM:
			case AdminActionCatalog::ACTION_PLAYER_FORCE_PLAY:
			case AdminActionCatalog::ACTION_PLAYER_FORCE_SPEC:
			case AdminActionCatalog::ACTION_AUTH_GRANT:
			case AdminActionCatalog::ACTION_AUTH_REVOKE:
				if (isset($parameters['target_login']) && trim((string) $parameters['target_login']) !== '') {
					return trim((string) $parameters['target_login']);
				}

				return trim((string) $actorLogin);
			case AdminActionCatalog::ACTION_MAP_JUMP:
			case AdminActionCatalog::ACTION_MAP_QUEUE:
			case AdminActionCatalog::ACTION_MAP_REMOVE:
				if (isset($parameters['map_uid']) && trim((string) $parameters['map_uid']) !== '') {
					return trim((string) $parameters['map_uid']);
				}
				if (isset($parameters['mx_id']) && trim((string) $parameters['mx_id']) !== '') {
					return 'mx:' . trim((string) $parameters['mx_id']);
				}

				return 'unknown';
			case AdminActionCatalog::ACTION_MAP_ADD:
				if (isset($parameters['mx_id']) && trim((string) $parameters['mx_id']) !== '') {
					return 'mx:' . trim((string) $parameters['mx_id']);
				}

				return 'unknown';
			case AdminActionCatalog::ACTION_MATCH_BO_SET:
			case AdminActionCatalog::ACTION_MATCH_BO_GET:
				return 'bo_policy';
			case AdminActionCatalog::ACTION_MATCH_MAPS_GET:
				return 'maps_scoreboard';
			case AdminActionCatalog::ACTION_MATCH_SCORE_GET:
				return 'current_map_scoreboard';
			case AdminActionCatalog::ACTION_MATCH_MAPS_SET:
			case AdminActionCatalog::ACTION_MATCH_SCORE_SET:
				if (isset($parameters['target_team']) && trim((string) $parameters['target_team']) !== '') {
					return 'series_team:' . strtolower(trim((string) $parameters['target_team']));
				}

				return 'series_team';
			default:
				return 'unknown';
		}
	}

	private function resolveAdminActionCorrelationType($actionName) {
		$actionTokens = explode('.', (string) $actionName);
		if (empty($actionTokens)) {
			return 'unknown';
		}

		if (count($actionTokens) < 2) {
			return $actionTokens[0];
		}

		return $actionTokens[0] . '_' . $actionTokens[1];
	}

	private function resolveAdminActionCorrelationInitiatorKind($actorLogin, $requestSource, $securityMode) {
		if (trim((string) $actorLogin) !== '') {
			return 'player';
		}

		if ($requestSource === 'communication') {
			if ($securityMode === 'payload_untrusted') {
				return 'server_payload_untrusted';
			}

			return 'server_payload';
		}

		return 'unknown';
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
			case AdminActionCatalog::ACTION_MAP_ADD:
				if (!isset($normalizedParameters['mx_id']) && !empty($positionals)) {
					$normalizedParameters['mx_id'] = $positionals[0];
				}
			break;
			case AdminActionCatalog::ACTION_MAP_REMOVE:
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
			case AdminActionCatalog::ACTION_MATCH_BO_SET:
				if (!isset($normalizedParameters['best_of']) && !empty($positionals)) {
					$normalizedParameters['best_of'] = $positionals[0];
				}
				if (isset($normalizedParameters['bo']) && !isset($normalizedParameters['best_of'])) {
					$normalizedParameters['best_of'] = $normalizedParameters['bo'];
				}
			break;
			case AdminActionCatalog::ACTION_MATCH_MAPS_SET:
				if (!isset($normalizedParameters['target_team']) && !empty($positionals)) {
					$normalizedParameters['target_team'] = $positionals[0];
				}
				if (!isset($normalizedParameters['maps_score']) && count($positionals) > 1) {
					$normalizedParameters['maps_score'] = $positionals[1];
				}

				if (isset($normalizedParameters['team']) && !isset($normalizedParameters['target_team'])) {
					$normalizedParameters['target_team'] = $normalizedParameters['team'];
				}
				if (isset($normalizedParameters['target']) && !isset($normalizedParameters['target_team'])) {
					$normalizedParameters['target_team'] = $normalizedParameters['target'];
				}
				if (isset($normalizedParameters['score']) && !isset($normalizedParameters['maps_score'])) {
					$normalizedParameters['maps_score'] = $normalizedParameters['score'];
				}
			break;
			case AdminActionCatalog::ACTION_MATCH_SCORE_SET:
				if (!isset($normalizedParameters['target_team']) && !empty($positionals)) {
					$normalizedParameters['target_team'] = $positionals[0];
				}
				if (!isset($normalizedParameters['score']) && count($positionals) > 1) {
					$normalizedParameters['score'] = $positionals[1];
				}

				if (isset($normalizedParameters['team']) && !isset($normalizedParameters['target_team'])) {
					$normalizedParameters['target_team'] = $normalizedParameters['team'];
				}
				if (isset($normalizedParameters['target']) && !isset($normalizedParameters['target_team'])) {
					$normalizedParameters['target_team'] = $normalizedParameters['target'];
				}
			break;
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
		$actionDefinitions = AdminActionCatalog::getActionDefinitions();
		$actionNames = array_keys($actionDefinitions);
		sort($actionNames);

		$this->maniaControl->getChat()->sendInformation(
			'Pixel delegated admin actions (' . count($actionNames) . ').',
			$player
		);
		$this->maniaControl->getChat()->sendInformation(
			'Usage: //' . $this->adminControlCommandName . ' <action> key=value ...',
			$player
		);

		foreach ($actionNames as $actionName) {
			$definition = (isset($actionDefinitions[$actionName]) && is_array($actionDefinitions[$actionName]))
				? $actionDefinitions[$actionName]
				: array();
			$requiredParameters = $this->extractAdminActionHelpParameters($definition, 'required_parameters');
			$optionalParameters = $this->extractAdminActionHelpParameters($definition, 'optional_parameters');

			$this->maniaControl->getChat()->sendInformation(
				$this->formatAdminActionHelpLine($actionName, $requiredParameters, $optionalParameters),
				$player
			);
		}
	}

	private function extractAdminActionHelpParameters(array $actionDefinition, $fieldName) {
		if (!isset($actionDefinition[$fieldName]) || !is_array($actionDefinition[$fieldName])) {
			return array();
		}

		$parameters = array();
		foreach ($actionDefinition[$fieldName] as $parameterName) {
			$normalizedParameterName = trim((string) $parameterName);
			if ($normalizedParameterName === '') {
				continue;
			}

			$parameters[] = $normalizedParameterName;
		}

		return $parameters;
	}

	private function formatAdminActionHelpLine($actionName, array $requiredParameters, array $optionalParameters) {
		$segments = array('- ' . $actionName);
		$requiredPart = $this->formatAdminActionHelpParameterGroup($requiredParameters, 'required');
		$optionalPart = $this->formatAdminActionHelpParameterGroup($optionalParameters, 'optional');
		if ($requiredPart !== '') {
			$segments[] = $requiredPart;
		}
		if ($optionalPart !== '') {
			$segments[] = $optionalPart;
		}

		return implode(' | ', $segments);
	}

	private function formatAdminActionHelpParameterGroup(array $parameters, $label) {
		if (empty($parameters)) {
			return '';
		}

		return $label . ': ' . implode(', ', $parameters);
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
			'security' => array(
				'chat_command' => array(
					'actor_login_required' => true,
					'permission_model' => 'maniacontrol_plugin_rights',
				),
				'communication' => array(
					'authentication_mode' => 'none_temporary',
					'actor_login_required' => false,
					'permission_model' => 'trusted_payload_no_actor',
				),
			),
			'actions' => $actionNames,
			'series_targets' => $this->getSeriesControlSnapshot(),
			'ownership_boundary' => array(
				'telemetry_transport' => 'pixel_plugin',
				'admin_execution' => 'native_maniacontrol',
			),
		);
	}
}
