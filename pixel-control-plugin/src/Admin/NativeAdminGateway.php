<?php

namespace PixelControl\Admin;

use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\ManiaControl;
use ManiaControl\Players\PlayerActions;
use Maniaplanet\DedicatedServer\Structures\VoteRatio;

class NativeAdminGateway {
	/** @var ManiaControl $maniaControl */
	private $maniaControl;

	public function __construct(ManiaControl $maniaControl) {
		$this->maniaControl = $maniaControl;
	}

	public function execute($actionName, array $parameters = array(), $actorLogin = '', array $executionContext = array()) {
		$normalizedActionName = AdminActionCatalog::normalizeActionName($actionName);
		if ($normalizedActionName === '') {
			return AdminActionResult::failure('unknown', 'action_missing', 'Action name is required.');
		}

		try {
			switch ($normalizedActionName) {
				case AdminActionCatalog::ACTION_MAP_SKIP:
					return $this->executeMapSkip($normalizedActionName);
				case AdminActionCatalog::ACTION_MAP_RESTART:
					return $this->executeMapRestart($normalizedActionName);
				case AdminActionCatalog::ACTION_MAP_JUMP:
					return $this->executeMapJump($normalizedActionName, $parameters);
				case AdminActionCatalog::ACTION_MAP_QUEUE:
					return $this->executeMapQueue($normalizedActionName, $parameters);
				case AdminActionCatalog::ACTION_MAP_ADD:
					return $this->executeMapAdd($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_MAP_REMOVE:
					return $this->executeMapRemove($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_WARMUP_EXTEND:
					return $this->executeWarmupExtend($normalizedActionName, $parameters);
				case AdminActionCatalog::ACTION_WARMUP_END:
					return $this->executeWarmupEnd($normalizedActionName);
				case AdminActionCatalog::ACTION_PAUSE_START:
					return $this->executePauseStart($normalizedActionName);
				case AdminActionCatalog::ACTION_PAUSE_END:
					return $this->executePauseEnd($normalizedActionName);
				case AdminActionCatalog::ACTION_PAUSE_TOGGLE:
					return $this->executePauseToggle($normalizedActionName, $parameters);
				case AdminActionCatalog::ACTION_VOTE_CANCEL:
					return $this->executeVoteCancel($normalizedActionName);
				case AdminActionCatalog::ACTION_VOTE_SET_RATIO:
					return $this->executeVoteSetRatio($normalizedActionName, $parameters);
				case AdminActionCatalog::ACTION_PLAYER_FORCE_TEAM:
					return $this->executeForceTeam($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_PLAYER_FORCE_PLAY:
					return $this->executeForcePlay($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_PLAYER_FORCE_SPEC:
					return $this->executeForceSpec($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_AUTH_GRANT:
					return $this->executeAuthGrant($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_AUTH_REVOKE:
					return $this->executeAuthRevoke($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_VOTE_CUSTOM_START:
					return $this->executeCustomVoteStart($normalizedActionName, $parameters, $actorLogin, $executionContext);
				default:
					return AdminActionResult::failure($normalizedActionName, 'action_unknown', 'Unknown admin action.');
			}
		} catch (\Exception $exception) {
			return AdminActionResult::failure(
				$normalizedActionName,
				'native_exception',
				$exception->getMessage(),
				array('exception_class' => get_class($exception))
			);
		}
	}

	private function executeMapSkip($actionName) {
		$success = $this->maniaControl->getMapManager()->getMapActions()->skipMap();
		if (!$success) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Map skip failed in native MapActions.');
		}

		return AdminActionResult::success($actionName, 'Map skip delegated to native MapActions.', array(
			'native_entrypoint' => 'MapActions::skipMap',
		));
	}

	private function executeMapRestart($actionName) {
		$success = $this->maniaControl->getMapManager()->getMapActions()->restartMap();
		if (!$success) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Map restart failed in native MapActions.');
		}

		return AdminActionResult::success($actionName, 'Map restart delegated to native MapActions.', array(
			'native_entrypoint' => 'MapActions::restartMap',
		));
	}

	private function executeMapJump($actionName, array $parameters) {
		$mapUid = $this->readStringParameter($parameters, array('map_uid', 'uid'));
		if ($mapUid !== '') {
			$success = $this->maniaControl->getMapManager()->getMapActions()->skipToMapByUid($mapUid);
			if (!$success) {
				return AdminActionResult::failure($actionName, 'native_rejected', 'Map jump failed for provided map_uid.', array('map_uid' => $mapUid));
			}

			return AdminActionResult::success($actionName, 'Map jump delegated to native MapActions.', array(
				'map_uid' => $mapUid,
				'native_entrypoint' => 'MapActions::skipToMapByUid',
			));
		}

		$mxId = $this->readIntParameter($parameters, array('mx_id'));
		if ($mxId === null) {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires map_uid or mx_id.');
		}

		$success = $this->maniaControl->getMapManager()->getMapActions()->skipToMapByMxId($mxId);
		if (!$success) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Map jump failed for provided mx_id.', array('mx_id' => $mxId));
		}

		return AdminActionResult::success($actionName, 'Map jump delegated to native MapActions.', array(
			'mx_id' => $mxId,
			'native_entrypoint' => 'MapActions::skipToMapByMxId',
		));
	}

	private function executeMapQueue($actionName, array $parameters) {
		$mapUid = $this->readStringParameter($parameters, array('map_uid', 'uid'));
		if ($mapUid === '') {
			$mxId = $this->readIntParameter($parameters, array('mx_id'));
			if ($mxId !== null) {
				$map = $this->maniaControl->getMapManager()->getMapByMxId($mxId);
				if ($map && isset($map->uid)) {
					$mapUid = (string) $map->uid;
				}
			}
		}

		if ($mapUid === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires map_uid or mx_id that resolves to a map UID.');
		}

		$success = $this->maniaControl->getMapManager()->getMapQueue()->serverAddMapToMapQueue($mapUid);
		if (!$success) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Map queue update failed in native MapQueue.', array('map_uid' => $mapUid));
		}

		return AdminActionResult::success($actionName, 'Map queue delegated to native MapQueue.', array(
			'map_uid' => $mapUid,
			'native_entrypoint' => 'MapQueue::serverAddMapToMapQueue',
		));
	}

	private function executeMapAdd($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		$mxId = $this->readIntParameter($parameters, array('mx_id', 'mxid'));
		if ($mxId === null || $mxId <= 0) {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires valid mx_id.');
		}

		$resolvedActorLogin = $this->resolveActorLogin($actorLogin, $parameters);
		$allowActorless = $this->allowActorlessExecution($executionContext);
		$nativeLogin = $resolvedActorLogin !== '' ? $resolvedActorLogin : null;

		if ($nativeLogin === null && !$allowActorless) {
			return AdminActionResult::failure($actionName, 'actor_missing', 'Actor login is required for this request source.');
		}

		$this->maniaControl->getMapManager()->addMapFromMx($mxId, $nativeLogin, false);

		return AdminActionResult::success($actionName, 'Map add submitted to native MapManager (async ManiaExchange import).', array(
			'mx_id' => $mxId,
			'actor_login' => ($nativeLogin !== null) ? $nativeLogin : '',
			'async_submission' => true,
			'native_entrypoint' => 'MapManager::addMapFromMx',
		));
	}

	private function executeMapRemove($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		$mapUid = $this->readStringParameter($parameters, array('map_uid', 'uid'));
		$mxId = null;

		if ($mapUid === '') {
			$mxId = $this->readIntParameter($parameters, array('mx_id', 'mxid'));
			if ($mxId !== null) {
				$mxMap = $this->maniaControl->getMapManager()->getMapByMxId($mxId);
				if ($mxMap && isset($mxMap->uid)) {
					$mapUid = (string) $mxMap->uid;
				}
			}
		}

		if ($mapUid === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires map_uid or mx_id that resolves to a map UID.');
		}

		$eraseMapFile = $this->readBooleanParameter($parameters, array('erase_map_file', 'erase_file', 'erase_map'));
		$showChatMessage = $this->readBooleanParameter($parameters, array('show_chat_message', 'show_message', 'display_message'));
		if ($eraseMapFile === null) {
			$eraseMapFile = false;
		}
		if ($showChatMessage === null) {
			$showChatMessage = true;
		}

		$resolvedActorLogin = $this->resolveActorLogin($actorLogin, $parameters);
		$allowActorless = $this->allowActorlessExecution($executionContext);
		$adminPlayer = null;

		if ($resolvedActorLogin !== '') {
			$adminPlayer = $this->maniaControl->getPlayerManager()->getPlayer($resolvedActorLogin);
		}

		if ($adminPlayer === null && $resolvedActorLogin !== '' && !$allowActorless) {
			return AdminActionResult::failure($actionName, 'actor_not_found', 'Actor player was not found in player manager.');
		}

		$success = $this->maniaControl->getMapManager()->removeMap($adminPlayer, $mapUid, $eraseMapFile, $showChatMessage);
		if (!$success) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Map remove failed in native MapManager.', array(
				'map_uid' => $mapUid,
				'mx_id' => $mxId,
				'erase_map_file' => $eraseMapFile,
			));
		}

		return AdminActionResult::success($actionName, 'Map remove delegated to native MapManager.', array(
			'map_uid' => $mapUid,
			'mx_id' => $mxId,
			'erase_map_file' => $eraseMapFile,
			'show_chat_message' => $showChatMessage,
			'native_entrypoint' => 'MapManager::removeMap',
		));
	}

	private function executeWarmupExtend($actionName, array $parameters) {
		$scriptGuardResult = $this->guardScriptMode($actionName);
		if ($scriptGuardResult !== null) {
			return $scriptGuardResult;
		}

		$seconds = $this->readIntParameter($parameters, array('seconds', 'duration_seconds'));
		if ($seconds === null) {
			$seconds = 10;
		}
		$seconds = max(1, $seconds);

		$this->maniaControl->getModeScriptEventManager()->extendManiaPlanetWarmup($seconds);

		return AdminActionResult::success($actionName, 'Warmup extension delegated to native mode script manager.', array(
			'seconds' => $seconds,
			'native_entrypoint' => 'ModeScriptEventManager::extendManiaPlanetWarmup',
		));
	}

	private function executeWarmupEnd($actionName) {
		$scriptGuardResult = $this->guardScriptMode($actionName);
		if ($scriptGuardResult !== null) {
			return $scriptGuardResult;
		}

		$this->maniaControl->getModeScriptEventManager()->stopManiaPlanetWarmup();

		return AdminActionResult::success($actionName, 'Warmup stop delegated to native mode script manager.', array(
			'native_entrypoint' => 'ModeScriptEventManager::stopManiaPlanetWarmup',
		));
	}

	private function executePauseStart($actionName) {
		$pauseGuardResult = $this->guardPauseCapability($actionName);
		if ($pauseGuardResult !== null) {
			return $pauseGuardResult;
		}

		$this->applyPauseState(true);

		return AdminActionResult::success($actionName, 'Pause start delegated to native mode script manager.', array(
			'compatibility_mode' => 'script_event_plus_mode_commands',
			'native_entrypoint' => 'ModeScriptEventManager::startPause',
		));
	}

	private function executePauseEnd($actionName) {
		$pauseGuardResult = $this->guardPauseCapability($actionName);
		if ($pauseGuardResult !== null) {
			return $pauseGuardResult;
		}

		$this->applyPauseState(false);

		return AdminActionResult::success($actionName, 'Pause end delegated to native mode script manager.', array(
			'compatibility_mode' => 'script_event_plus_mode_commands',
			'native_entrypoint' => 'ModeScriptEventManager::endPause',
		));
	}

	private function executePauseToggle($actionName, array $parameters) {
		$pauseGuardResult = $this->guardPauseCapability($actionName);
		if ($pauseGuardResult !== null) {
			return $pauseGuardResult;
		}

		$pauseActive = $this->readBooleanParameter($parameters, array('pause_active', 'active'));
		if ($pauseActive === null) {
			return AdminActionResult::failure($actionName, 'pause_state_unknown', 'Pause toggle requires a known pause_active state.');
		}

		if ($pauseActive) {
			$this->applyPauseState(false);
			return AdminActionResult::success($actionName, 'Pause toggle delegated to native mode script manager (end).', array(
				'pause_active_before' => true,
				'pause_active_after' => false,
				'compatibility_mode' => 'script_event_plus_mode_commands',
				'native_entrypoint' => 'ModeScriptEventManager::endPause',
			));
		}

		$this->applyPauseState(true);
		return AdminActionResult::success($actionName, 'Pause toggle delegated to native mode script manager (start).', array(
			'pause_active_before' => false,
			'pause_active_after' => true,
			'compatibility_mode' => 'script_event_plus_mode_commands',
			'native_entrypoint' => 'ModeScriptEventManager::startPause',
		));
	}

	private function applyPauseState($active) {
		$active = (bool) $active;

		if ($active) {
			$this->sendModeScriptCommandsSafely(array('Command_ForceWarmUp' => true));
			$this->sendModeScriptCommandsSafely(array('Command_SetPause' => true));
			$this->sendModeScriptCommandsSafely(array('Command_ForceEndRound' => true));
			$this->maniaControl->getModeScriptEventManager()->startPause();
			return;
		}

		$this->sendModeScriptCommandsSafely(array('Command_SetPause' => false));
		$this->sendModeScriptCommandsSafely(array('Command_ForceWarmUp' => false));
		$this->maniaControl->getModeScriptEventManager()->endPause();
	}

	private function sendModeScriptCommandsSafely(array $commands) {
		try {
			$this->maniaControl->getClient()->sendModeScriptCommands($commands);
		} catch (\Exception $exception) {
			// Keep delegated pause flows resilient across script implementations.
		}
	}

	private function executeVoteCancel($actionName) {
		$success = $this->maniaControl->getClient()->cancelVote();
		if (!$success) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Vote cancel failed because no vote is currently running.');
		}

		return AdminActionResult::success($actionName, 'Vote cancel delegated to dedicated server client.', array(
			'native_entrypoint' => 'Client::cancelVote',
		));
	}

	private function executeVoteSetRatio($actionName, array $parameters) {
		$voteCommand = $this->readStringParameter($parameters, array('command', 'vote_command'));
		if ($voteCommand === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires vote command identifier.');
		}

		$ratio = $this->readFloatParameter($parameters, array('ratio'));
		if ($ratio === null) {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires ratio.');
		}

		if ($ratio < 0.0 || $ratio > 1.0) {
			return AdminActionResult::failure($actionName, 'invalid_parameters', 'Ratio must be between 0.0 and 1.0.');
		}

		$currentVoteRatios = $this->maniaControl->getClient()->getCallVoteRatios();
		$updated = false;

		foreach ($currentVoteRatios as $voteRatio) {
			if (!is_object($voteRatio) || !isset($voteRatio->command)) {
				continue;
			}

			if ((string) $voteRatio->command !== $voteCommand) {
				continue;
			}

			$voteRatio->ratio = $ratio;
			$updated = true;
			break;
		}

		if (!$updated) {
			$voteRatio = new VoteRatio();
			$voteRatio->command = $voteCommand;
			$voteRatio->ratio = $ratio;

			if (method_exists($voteRatio, 'isValid') && !$voteRatio->isValid()) {
				return AdminActionResult::failure($actionName, 'invalid_parameters', 'Provided vote ratio payload is invalid for dedicated server API.');
			}

			$currentVoteRatios[] = $voteRatio;
		}

		$success = $this->maniaControl->getClient()->setCallVoteRatios($currentVoteRatios);
		if (!$success) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Vote ratio update failed in dedicated server API.');
		}

		return AdminActionResult::success($actionName, 'Vote ratio delegated to dedicated server client.', array(
			'command' => $voteCommand,
			'ratio' => $ratio,
			'native_entrypoint' => 'Client::setCallVoteRatios',
		));
	}

	private function executeForceTeam($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		$teamModeGuardResult = $this->guardTeamMode($actionName);
		if ($teamModeGuardResult !== null) {
			return $teamModeGuardResult;
		}

		$resolvedActorLogin = $this->resolveActorLogin($actorLogin, $parameters);
		$calledByAdmin = true;
		if ($resolvedActorLogin === '' && $this->allowActorlessExecution($executionContext)) {
			$calledByAdmin = false;
		}

		if ($resolvedActorLogin === '' && $calledByAdmin) {
			return AdminActionResult::failure($actionName, 'actor_missing', 'Actor login is required for delegated player actions.');
		}

		$targetLogin = $this->readStringParameter($parameters, array('target_login', 'login'));
		if ($targetLogin === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires target_login.');
		}

		$teamId = $this->resolveTeamId($parameters);
		if ($teamId === null) {
			return AdminActionResult::failure($actionName, 'invalid_parameters', 'Action requires team=blue|red or team_id=0|1.');
		}

		$success = $this->maniaControl->getPlayerManager()->getPlayerActions()->forcePlayerToTeam(
			$resolvedActorLogin,
			$targetLogin,
			$teamId,
			$calledByAdmin
		);

		if (!$success) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Force-team request failed in native PlayerActions.', array(
				'target_login' => $targetLogin,
				'team_id' => $teamId,
			));
		}

		return AdminActionResult::success($actionName, 'Force-team delegated to native PlayerActions.', array(
			'target_login' => $targetLogin,
			'team_id' => $teamId,
			'execution_mode' => $calledByAdmin ? 'actor_bound' : 'server_payload_actorless',
			'native_entrypoint' => 'PlayerActions::forcePlayerToTeam',
		));
	}

	private function executeForcePlay($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		$resolvedActorLogin = $this->resolveActorLogin($actorLogin, $parameters);
		$calledByAdmin = true;
		if ($resolvedActorLogin === '' && $this->allowActorlessExecution($executionContext)) {
			$calledByAdmin = false;
		}

		if ($resolvedActorLogin === '' && $calledByAdmin) {
			return AdminActionResult::failure($actionName, 'actor_missing', 'Actor login is required for delegated player actions.');
		}

		$targetLogin = $this->readStringParameter($parameters, array('target_login', 'login'));
		if ($targetLogin === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires target_login.');
		}

		$success = $this->maniaControl->getPlayerManager()->getPlayerActions()->forcePlayerToPlay(
			$resolvedActorLogin,
			$targetLogin,
			true,
			true,
			$calledByAdmin
		);

		if (!$success) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Force-play request failed in native PlayerActions.', array(
				'target_login' => $targetLogin,
			));
		}

		return AdminActionResult::success($actionName, 'Force-play delegated to native PlayerActions.', array(
			'target_login' => $targetLogin,
			'execution_mode' => $calledByAdmin ? 'actor_bound' : 'server_payload_actorless',
			'native_entrypoint' => 'PlayerActions::forcePlayerToPlay',
		));
	}

	private function executeForceSpec($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		$resolvedActorLogin = $this->resolveActorLogin($actorLogin, $parameters);
		$calledByAdmin = true;
		if ($resolvedActorLogin === '' && $this->allowActorlessExecution($executionContext)) {
			$calledByAdmin = false;
		}

		if ($resolvedActorLogin === '' && $calledByAdmin) {
			return AdminActionResult::failure($actionName, 'actor_missing', 'Actor login is required for delegated player actions.');
		}

		$targetLogin = $this->readStringParameter($parameters, array('target_login', 'login'));
		if ($targetLogin === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires target_login.');
		}

		$success = $this->maniaControl->getPlayerManager()->getPlayerActions()->forcePlayerToSpectator(
			$resolvedActorLogin,
			$targetLogin,
			PlayerActions::SPECTATOR_BUT_KEEP_SELECTABLE,
			true,
			$calledByAdmin
		);

		if (!$success) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Force-spectator request failed in native PlayerActions.', array(
				'target_login' => $targetLogin,
			));
		}

		return AdminActionResult::success($actionName, 'Force-spectator delegated to native PlayerActions.', array(
			'target_login' => $targetLogin,
			'execution_mode' => $calledByAdmin ? 'actor_bound' : 'server_payload_actorless',
			'native_entrypoint' => 'PlayerActions::forcePlayerToSpectator',
		));
	}

	private function executeAuthGrant($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		$resolvedActorLogin = $this->resolveActorLogin($actorLogin, $parameters);
		$allowActorless = $this->allowActorlessExecution($executionContext);
		if ($resolvedActorLogin === '' && !$allowActorless) {
			return AdminActionResult::failure($actionName, 'actor_missing', 'Actor login is required for delegated auth actions.');
		}

		$targetLogin = $this->readStringParameter($parameters, array('target_login', 'login'));
		if ($targetLogin === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires target_login.');
		}

		$authLevel = $this->resolveAuthLevel($parameters);
		if ($authLevel === null) {
			return AdminActionResult::failure($actionName, 'invalid_parameters', 'Action requires auth_level (player|moderator|admin|superadmin).');
		}

		$targetBefore = $this->maniaControl->getPlayerManager()->getPlayer($targetLogin);
		if (!$targetBefore) {
			return AdminActionResult::failure($actionName, 'target_not_found', 'Target player was not found in native player manager.');
		}
		$beforeLevel = isset($targetBefore->authLevel) ? (int) $targetBefore->authLevel : null;
		$nativeEntrypoint = 'PlayerActions::grantAuthLevel';
		$executionMode = 'actor_bound';

		if ($resolvedActorLogin !== '') {
			$this->maniaControl->getPlayerManager()->getPlayerActions()->grantAuthLevel($resolvedActorLogin, $targetLogin, $authLevel);
		} else {
			$grantSuccess = $this->maniaControl->getAuthenticationManager()->grantAuthLevel($targetBefore, $authLevel);
			if (!$grantSuccess) {
				return AdminActionResult::failure($actionName, 'native_rejected', 'Grant-auth request failed in native AuthenticationManager.', array(
					'target_login' => $targetLogin,
					'requested_auth_level' => $authLevel,
				));
			}

			$nativeEntrypoint = 'AuthenticationManager::grantAuthLevel';
			$executionMode = 'server_payload_actorless';
		}

		$targetAfter = $this->maniaControl->getPlayerManager()->getPlayer($targetLogin);
		$afterLevel = ($targetAfter && isset($targetAfter->authLevel)) ? (int) $targetAfter->authLevel : null;

		if ($afterLevel !== $authLevel) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Grant-auth request was rejected by native auth rules.', array(
				'target_login' => $targetLogin,
				'before_auth_level' => $beforeLevel,
				'after_auth_level' => $afterLevel,
				'requested_auth_level' => $authLevel,
			));
		}

		return AdminActionResult::success($actionName, 'Grant-auth delegated to native auth service.', array(
			'target_login' => $targetLogin,
			'before_auth_level' => $beforeLevel,
			'after_auth_level' => $afterLevel,
			'execution_mode' => $executionMode,
			'native_entrypoint' => $nativeEntrypoint,
		));
	}

	private function executeAuthRevoke($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		$resolvedActorLogin = $this->resolveActorLogin($actorLogin, $parameters);
		$allowActorless = $this->allowActorlessExecution($executionContext);
		if ($resolvedActorLogin === '' && !$allowActorless) {
			return AdminActionResult::failure($actionName, 'actor_missing', 'Actor login is required for delegated auth actions.');
		}

		$targetLogin = $this->readStringParameter($parameters, array('target_login', 'login'));
		if ($targetLogin === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires target_login.');
		}

		$targetBefore = $this->maniaControl->getPlayerManager()->getPlayer($targetLogin);
		if (!$targetBefore) {
			return AdminActionResult::failure($actionName, 'target_not_found', 'Target player was not found in native player manager.');
		}
		$beforeLevel = isset($targetBefore->authLevel) ? (int) $targetBefore->authLevel : null;
		$nativeEntrypoint = 'PlayerActions::revokeAuthLevel';
		$executionMode = 'actor_bound';

		if ($resolvedActorLogin !== '') {
			$this->maniaControl->getPlayerManager()->getPlayerActions()->revokeAuthLevel($resolvedActorLogin, $targetLogin);
		} else {
			$revokeSuccess = $this->maniaControl->getAuthenticationManager()->grantAuthLevel($targetBefore, AuthenticationManager::AUTH_LEVEL_PLAYER);
			if (!$revokeSuccess) {
				return AdminActionResult::failure($actionName, 'native_rejected', 'Revoke-auth request failed in native AuthenticationManager.', array(
					'target_login' => $targetLogin,
				));
			}

			$nativeEntrypoint = 'AuthenticationManager::grantAuthLevel';
			$executionMode = 'server_payload_actorless';
		}

		$targetAfter = $this->maniaControl->getPlayerManager()->getPlayer($targetLogin);
		$afterLevel = ($targetAfter && isset($targetAfter->authLevel)) ? (int) $targetAfter->authLevel : null;

		if ($afterLevel !== AuthenticationManager::AUTH_LEVEL_PLAYER) {
			return AdminActionResult::failure($actionName, 'native_rejected', 'Revoke-auth request was rejected by native auth rules.', array(
				'target_login' => $targetLogin,
				'before_auth_level' => $beforeLevel,
				'after_auth_level' => $afterLevel,
			));
		}

		return AdminActionResult::success($actionName, 'Revoke-auth delegated to native auth service.', array(
			'target_login' => $targetLogin,
			'before_auth_level' => $beforeLevel,
			'after_auth_level' => $afterLevel,
			'execution_mode' => $executionMode,
			'native_entrypoint' => $nativeEntrypoint,
		));
	}

	private function executeCustomVoteStart($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		$resolvedActorLogin = $this->resolveActorLogin($actorLogin, $parameters);
		$allowActorless = $this->allowActorlessExecution($executionContext);
		if ($resolvedActorLogin === '' && !$allowActorless) {
			return AdminActionResult::failure($actionName, 'actor_missing', 'Actor login is required to start a custom vote.');
		}

		$voteIndex = $this->readStringParameter($parameters, array('vote_index', 'vote'));
		if ($voteIndex === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires vote_index.');
		}

		$customVotesPlugin = $this->maniaControl->getPluginManager()->getPlugin('MCTeam\\CustomVotesPlugin');
		if (!$customVotesPlugin || !method_exists($customVotesPlugin, 'startVote')) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'CustomVotesPlugin is not active on this server.');
		}

		$actorPlayer = null;
		$executionMode = 'actor_bound';
		if ($resolvedActorLogin !== '') {
			$actorPlayer = $this->maniaControl->getPlayerManager()->getPlayer($resolvedActorLogin);
		}

		if (!$actorPlayer && $allowActorless) {
			$actorPlayer = $this->resolveFallbackVoteActor();
			$executionMode = 'server_payload_actorless';
		}

		if (!$actorPlayer) {
			return AdminActionResult::failure($actionName, 'actor_not_found', 'No connected player is available to initiate the custom vote.');
		}

		call_user_func(array($customVotesPlugin, 'startVote'), $actorPlayer, $voteIndex);

		return AdminActionResult::success($actionName, 'Custom vote start delegated to native CustomVotesPlugin.', array(
			'actor_login' => isset($actorPlayer->login) ? (string) $actorPlayer->login : '',
			'execution_mode' => $executionMode,
			'vote_index' => $voteIndex,
			'native_entrypoint' => 'CustomVotesPlugin::startVote',
		));
	}

	private function guardScriptMode($actionName) {
		$scriptManager = $this->maniaControl->getServer()->getScriptManager();
		if (!$scriptManager->isScriptMode()) {
			return AdminActionResult::failure($actionName, 'unsupported_mode', 'Action requires script mode support.');
		}

		return null;
	}

	private function guardPauseCapability($actionName) {
		$scriptGuardResult = $this->guardScriptMode($actionName);
		if ($scriptGuardResult !== null) {
			return $scriptGuardResult;
		}

		$scriptManager = $this->maniaControl->getServer()->getScriptManager();
		if (!$scriptManager->modeUsesPause()) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Pause controls are not available for current mode/script.');
		}

		return null;
	}

	private function guardTeamMode($actionName) {
		$scriptManager = $this->maniaControl->getServer()->getScriptManager();
		if (!$scriptManager->modeIsTeamMode()) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Team-forcing action requires a team-mode script.');
		}

		return null;
	}

	private function allowActorlessExecution(array $executionContext) {
		return !empty($executionContext['allow_actorless']);
	}

	private function resolveFallbackVoteActor() {
		$players = $this->maniaControl->getPlayerManager()->getPlayers(false);
		if (!is_array($players) || empty($players)) {
			return null;
		}

		$fallbackAny = null;
		$fallbackNonSpectator = null;

		foreach ($players as $player) {
			if (!$player) {
				continue;
			}

			if ($fallbackAny === null) {
				$fallbackAny = $player;
			}

			$isSpectator = isset($player->isSpectator) && $player->isSpectator;
			$isMuted = method_exists($player, 'isMuted') && $player->isMuted();

			if (!$isSpectator && !$isMuted) {
				return $player;
			}

			if (!$isSpectator && $fallbackNonSpectator === null) {
				$fallbackNonSpectator = $player;
			}
		}

		if ($fallbackNonSpectator !== null) {
			return $fallbackNonSpectator;
		}

		return $fallbackAny;
	}

	private function resolveActorLogin($actorLogin, array $parameters) {
		$trimmedActorLogin = trim((string) $actorLogin);
		if ($trimmedActorLogin !== '') {
			return $trimmedActorLogin;
		}

		return $this->readStringParameter($parameters, array('actor_login', 'admin_login', 'initiator_login'));
	}

	private function resolveTeamId(array $parameters) {
		$teamId = $this->readIntParameter($parameters, array('team_id'));
		if ($teamId !== null && ($teamId === PlayerActions::TEAM_BLUE || $teamId === PlayerActions::TEAM_RED)) {
			return $teamId;
		}

		$teamName = strtolower($this->readStringParameter($parameters, array('team', 'side')));
		if ($teamName === 'blue') {
			return PlayerActions::TEAM_BLUE;
		}

		if ($teamName === 'red') {
			return PlayerActions::TEAM_RED;
		}

		return null;
	}

	private function resolveAuthLevel(array $parameters) {
		$authLevelRaw = $this->readStringParameter($parameters, array('auth_level', 'level', 'target_auth_level'));
		if ($authLevelRaw === '') {
			return null;
		}

		if (is_numeric($authLevelRaw)) {
			$numericAuthLevel = (int) $authLevelRaw;
			if ($numericAuthLevel >= AuthenticationManager::AUTH_LEVEL_PLAYER && $numericAuthLevel <= AuthenticationManager::AUTH_LEVEL_SUPERADMIN) {
				return $numericAuthLevel;
			}

			return null;
		}

		$resolvedAuthLevel = AuthenticationManager::getAuthLevel($authLevelRaw);
		if ($resolvedAuthLevel >= AuthenticationManager::AUTH_LEVEL_PLAYER && $resolvedAuthLevel <= AuthenticationManager::AUTH_LEVEL_SUPERADMIN) {
			return $resolvedAuthLevel;
		}

		return null;
	}

	private function readStringParameter(array $parameters, array $keys) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $parameters)) {
				continue;
			}

			$value = trim((string) $parameters[$key]);
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}

	private function readIntParameter(array $parameters, array $keys) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $parameters)) {
				continue;
			}

			$value = $parameters[$key];
			if (!is_numeric($value)) {
				continue;
			}

			return (int) $value;
		}

		return null;
	}

	private function readFloatParameter(array $parameters, array $keys) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $parameters)) {
				continue;
			}

			$value = $parameters[$key];
			if (!is_numeric($value)) {
				continue;
			}

			return (float) $value;
		}

		return null;
	}

	private function readBooleanParameter(array $parameters, array $keys) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $parameters)) {
				continue;
			}

			$value = $parameters[$key];
			if (is_bool($value)) {
				return $value;
			}

			if (is_numeric($value)) {
				return ((int) $value) !== 0;
			}

			$normalizedValue = strtolower(trim((string) $value));
			if (in_array($normalizedValue, array('1', 'true', 'yes', 'on', 'active', 'start', 'started'), true)) {
				return true;
			}

			if (in_array($normalizedValue, array('0', 'false', 'no', 'off', 'inactive', 'end', 'ended'), true)) {
				return false;
			}
		}

		return null;
	}
}
