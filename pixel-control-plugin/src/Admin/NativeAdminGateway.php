<?php

namespace PixelControl\Admin;

use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\ManiaControl;
use ManiaControl\Players\PlayerActions;
use Maniaplanet\DedicatedServer\Structures\VoteRatio;
use PixelControl\AccessControl\WhitelistCatalog;
use PixelControl\AccessControl\WhitelistStateInterface;
use PixelControl\SeriesControl\SeriesControlCatalog;
use PixelControl\SeriesControl\SeriesControlStateInterface;
use PixelControl\TeamControl\TeamRosterCatalog;
use PixelControl\TeamControl\TeamRosterStateInterface;
use PixelControl\VoteControl\VotePolicyCatalog;
use PixelControl\VoteControl\VotePolicyStateInterface;

class NativeAdminGateway {
	/** @var ManiaControl $maniaControl */
	private $maniaControl;
	/** @var SeriesControlStateInterface|null $seriesControlState */
	private $seriesControlState;
	/** @var WhitelistStateInterface|null $whitelistState */
	private $whitelistState;
	/** @var VotePolicyStateInterface|null $votePolicyState */
	private $votePolicyState;
	/** @var TeamRosterStateInterface|null $teamRosterState */
	private $teamRosterState;

	public function __construct(
		ManiaControl $maniaControl,
		?SeriesControlStateInterface $seriesControlState = null,
		?WhitelistStateInterface $whitelistState = null,
		?VotePolicyStateInterface $votePolicyState = null,
		?TeamRosterStateInterface $teamRosterState = null
	) {
		$this->maniaControl = $maniaControl;
		$this->seriesControlState = $seriesControlState;
		$this->whitelistState = $whitelistState;
		$this->votePolicyState = $votePolicyState;
		$this->teamRosterState = $teamRosterState;
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
				case AdminActionCatalog::ACTION_WHITELIST_ENABLE:
					return $this->executeWhitelistEnable($normalizedActionName, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_WHITELIST_DISABLE:
					return $this->executeWhitelistDisable($normalizedActionName, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_WHITELIST_ADD:
					return $this->executeWhitelistAdd($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_WHITELIST_REMOVE:
					return $this->executeWhitelistRemove($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_WHITELIST_LIST:
					return $this->executeWhitelistList($normalizedActionName);
				case AdminActionCatalog::ACTION_WHITELIST_CLEAN:
					return $this->executeWhitelistClean($normalizedActionName, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_WHITELIST_SYNC:
					return $this->executeWhitelistSync($normalizedActionName);
				case AdminActionCatalog::ACTION_VOTE_POLICY_GET:
					return $this->executeVotePolicyGet($normalizedActionName);
				case AdminActionCatalog::ACTION_VOTE_POLICY_SET:
					return $this->executeVotePolicySet($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_TEAM_POLICY_GET:
					return $this->executeTeamPolicyGet($normalizedActionName);
				case AdminActionCatalog::ACTION_TEAM_POLICY_SET:
					return $this->executeTeamPolicySet($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_TEAM_ROSTER_ASSIGN:
					return $this->executeTeamRosterAssign($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_TEAM_ROSTER_UNASSIGN:
					return $this->executeTeamRosterUnassign($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_TEAM_ROSTER_LIST:
					return $this->executeTeamRosterList($normalizedActionName);
				case AdminActionCatalog::ACTION_MATCH_BO_SET:
					return $this->executeMatchBoSet($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_MATCH_BO_GET:
					return $this->executeMatchBoGet($normalizedActionName);
				case AdminActionCatalog::ACTION_MATCH_MAPS_SET:
					return $this->executeMatchMapsSet($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_MATCH_MAPS_GET:
					return $this->executeMatchMapsGet($normalizedActionName);
				case AdminActionCatalog::ACTION_MATCH_SCORE_SET:
					return $this->executeMatchScoreSet($normalizedActionName, $parameters, $actorLogin, $executionContext);
				case AdminActionCatalog::ACTION_MATCH_SCORE_GET:
					return $this->executeMatchScoreGet($normalizedActionName);
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
		$this->maniaControl->getModeScriptEventManager()->endPause();
		$this->sendModeScriptCommandsSafely(array('Command_ForceWarmUp' => false));
		$this->sendModeScriptCommandsSafely(array('Command_ForceEndRound' => false));

		try {
			$this->maniaControl->getModeScriptEventManager()->stopManiaPlanetWarmup();
		} catch (\Exception $exception) {
			// Keep delegated pause flows resilient across script implementations.
		}
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

	private function executeWhitelistEnable($actionName, $actorLogin, array $executionContext = array()) {
		if (!$this->whitelistState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Whitelist state is unavailable in current runtime.');
		}

		$result = $this->whitelistState->setEnabled(
			true,
			$this->resolveStateUpdateSource($executionContext, WhitelistCatalog::UPDATE_SOURCE_CHAT, WhitelistCatalog::UPDATE_SOURCE_COMMUNICATION),
			$this->resolveStateUpdatedBy($actorLogin, $executionContext)
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'whitelist_update_failed',
				isset($result['message']) ? (string) $result['message'] : 'Whitelist update failed.',
				array(
					'whitelist' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->whitelistState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Whitelist enabled.', array(
			'whitelist' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->whitelistState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'WhitelistState::setEnabled',
		));
	}

	private function executeWhitelistDisable($actionName, $actorLogin, array $executionContext = array()) {
		if (!$this->whitelistState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Whitelist state is unavailable in current runtime.');
		}

		$result = $this->whitelistState->setEnabled(
			false,
			$this->resolveStateUpdateSource($executionContext, WhitelistCatalog::UPDATE_SOURCE_CHAT, WhitelistCatalog::UPDATE_SOURCE_COMMUNICATION),
			$this->resolveStateUpdatedBy($actorLogin, $executionContext)
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'whitelist_update_failed',
				isset($result['message']) ? (string) $result['message'] : 'Whitelist update failed.',
				array(
					'whitelist' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->whitelistState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Whitelist disabled.', array(
			'whitelist' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->whitelistState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'WhitelistState::setEnabled',
		));
	}

	private function executeWhitelistAdd($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		if (!$this->whitelistState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Whitelist state is unavailable in current runtime.');
		}

		$targetLogin = $this->readStringParameter($parameters, array('target_login', 'login'));
		if ($targetLogin === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires target_login.');
		}

		$result = $this->whitelistState->addLogin(
			$targetLogin,
			$this->resolveStateUpdateSource($executionContext, WhitelistCatalog::UPDATE_SOURCE_CHAT, WhitelistCatalog::UPDATE_SOURCE_COMMUNICATION),
			$this->resolveStateUpdatedBy($actorLogin, $executionContext)
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'whitelist_update_failed',
				isset($result['message']) ? (string) $result['message'] : 'Whitelist update failed.',
				array(
					'whitelist' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->whitelistState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Login added to whitelist.', array(
			'whitelist' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->whitelistState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'WhitelistState::addLogin',
		));
	}

	private function executeWhitelistRemove($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		if (!$this->whitelistState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Whitelist state is unavailable in current runtime.');
		}

		$targetLogin = $this->readStringParameter($parameters, array('target_login', 'login'));
		if ($targetLogin === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires target_login.');
		}

		$result = $this->whitelistState->removeLogin(
			$targetLogin,
			$this->resolveStateUpdateSource($executionContext, WhitelistCatalog::UPDATE_SOURCE_CHAT, WhitelistCatalog::UPDATE_SOURCE_COMMUNICATION),
			$this->resolveStateUpdatedBy($actorLogin, $executionContext)
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'whitelist_update_failed',
				isset($result['message']) ? (string) $result['message'] : 'Whitelist update failed.',
				array(
					'whitelist' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->whitelistState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Login removed from whitelist.', array(
			'whitelist' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->whitelistState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'WhitelistState::removeLogin',
		));
	}

	private function executeWhitelistList($actionName) {
		if (!$this->whitelistState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Whitelist state is unavailable in current runtime.');
		}

		return AdminActionResult::success($actionName, 'Whitelist snapshot resolved.', array(
			'whitelist' => $this->whitelistState->getSnapshot(),
			'native_entrypoint' => 'WhitelistState::getSnapshot',
		));
	}

	private function executeWhitelistClean($actionName, $actorLogin, array $executionContext = array()) {
		if (!$this->whitelistState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Whitelist state is unavailable in current runtime.');
		}

		$result = $this->whitelistState->clean(
			$this->resolveStateUpdateSource($executionContext, WhitelistCatalog::UPDATE_SOURCE_CHAT, WhitelistCatalog::UPDATE_SOURCE_COMMUNICATION),
			$this->resolveStateUpdatedBy($actorLogin, $executionContext)
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'whitelist_update_failed',
				isset($result['message']) ? (string) $result['message'] : 'Whitelist update failed.',
				array(
					'whitelist' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->whitelistState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Whitelist cleaned.', array(
			'whitelist' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->whitelistState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'WhitelistState::clean',
		));
	}

	private function executeWhitelistSync($actionName) {
		if (!$this->whitelistState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Whitelist state is unavailable in current runtime.');
		}

		return AdminActionResult::success($actionName, 'Whitelist sync requested.', array(
			'whitelist' => $this->whitelistState->getSnapshot(),
			'deferred_sync' => true,
			'native_entrypoint' => 'AccessControlDomain::syncWhitelistGuestList',
		));
	}

	private function executeVotePolicyGet($actionName) {
		if (!$this->votePolicyState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Vote policy state is unavailable in current runtime.');
		}

		return AdminActionResult::success($actionName, 'Vote policy snapshot resolved.', array(
			'vote_policy' => $this->votePolicyState->getSnapshot(),
			'native_entrypoint' => 'VotePolicyState::getSnapshot',
		));
	}

	private function executeVotePolicySet($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		if (!$this->votePolicyState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Vote policy state is unavailable in current runtime.');
		}

		$mode = $this->readStringParameter($parameters, array('mode'));
		if ($mode === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires mode.');
		}

		$result = $this->votePolicyState->setMode(
			$mode,
			$this->resolveStateUpdateSource($executionContext, VotePolicyCatalog::UPDATE_SOURCE_CHAT, VotePolicyCatalog::UPDATE_SOURCE_COMMUNICATION),
			$this->resolveStateUpdatedBy($actorLogin, $executionContext)
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'vote_policy_update_failed',
				isset($result['message']) ? (string) $result['message'] : 'Vote policy update failed.',
				array(
					'vote_policy' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->votePolicyState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Vote policy updated.', array(
			'vote_policy' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->votePolicyState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'VotePolicyState::setMode',
		));
	}

	private function executeTeamPolicyGet($actionName) {
		if (!$this->teamRosterState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Team roster state is unavailable in current runtime.');
		}

		return AdminActionResult::success($actionName, 'Team policy snapshot resolved.', array(
			'team_roster' => $this->teamRosterState->getSnapshot(),
			'native_entrypoint' => 'TeamRosterState::getSnapshot',
		));
	}

	private function executeTeamPolicySet($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		if (!$this->teamRosterState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Team roster state is unavailable in current runtime.');
		}

		$enabled = $this->readBooleanParameter($parameters, array('enabled', 'policy_enabled'));
		$switchLock = $this->readBooleanParameter($parameters, array('switch_lock', 'switch_lock_enabled', 'lock'));

		$result = $this->teamRosterState->setPolicy(
			$enabled,
			$switchLock,
			$this->resolveStateUpdateSource($executionContext, TeamRosterCatalog::UPDATE_SOURCE_CHAT, TeamRosterCatalog::UPDATE_SOURCE_COMMUNICATION),
			$this->resolveStateUpdatedBy($actorLogin, $executionContext)
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'team_policy_update_failed',
				isset($result['message']) ? (string) $result['message'] : 'Team policy update failed.',
				array(
					'team_roster' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->teamRosterState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Team policy updated.', array(
			'team_roster' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->teamRosterState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'TeamRosterState::setPolicy',
		));
	}

	private function executeTeamRosterAssign($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		if (!$this->teamRosterState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Team roster state is unavailable in current runtime.');
		}

		$targetLogin = $this->readStringParameter($parameters, array('target_login', 'login'));
		if ($targetLogin === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires target_login.');
		}

		$teamValue = $this->readStringParameter($parameters, array('team', 'target_team', 'team_key', 'team_id'));
		if ($teamValue === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires team.');
		}

		$result = $this->teamRosterState->assign(
			$targetLogin,
			$teamValue,
			$this->resolveStateUpdateSource($executionContext, TeamRosterCatalog::UPDATE_SOURCE_CHAT, TeamRosterCatalog::UPDATE_SOURCE_COMMUNICATION),
			$this->resolveStateUpdatedBy($actorLogin, $executionContext)
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'team_assignment_failed',
				isset($result['message']) ? (string) $result['message'] : 'Team assignment failed.',
				array(
					'team_roster' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->teamRosterState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Team assignment stored.', array(
			'team_roster' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->teamRosterState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'TeamRosterState::assign',
		));
	}

	private function executeTeamRosterUnassign($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		if (!$this->teamRosterState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Team roster state is unavailable in current runtime.');
		}

		$targetLogin = $this->readStringParameter($parameters, array('target_login', 'login'));
		if ($targetLogin === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires target_login.');
		}

		$result = $this->teamRosterState->unassign(
			$targetLogin,
			$this->resolveStateUpdateSource($executionContext, TeamRosterCatalog::UPDATE_SOURCE_CHAT, TeamRosterCatalog::UPDATE_SOURCE_COMMUNICATION),
			$this->resolveStateUpdatedBy($actorLogin, $executionContext)
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'team_assignment_failed',
				isset($result['message']) ? (string) $result['message'] : 'Team unassign failed.',
				array(
					'team_roster' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->teamRosterState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Team assignment removed.', array(
			'team_roster' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->teamRosterState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'TeamRosterState::unassign',
		));
	}

	private function executeTeamRosterList($actionName) {
		if (!$this->teamRosterState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Team roster state is unavailable in current runtime.');
		}

		return AdminActionResult::success($actionName, 'Team roster snapshot resolved.', array(
			'team_roster' => $this->teamRosterState->getSnapshot(),
			'native_entrypoint' => 'TeamRosterState::getSnapshot',
		));
	}

	private function executeMatchBoSet($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		if (!$this->seriesControlState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Series control state is unavailable in current runtime.');
		}

		$normalizedParameters = $this->normalizeSeriesControlParameters($parameters);
		$bestOfRaw = $this->readStringParameter($normalizedParameters, array('best_of'));
		if ($bestOfRaw === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires best_of.');
		}

		$resolvedActorLogin = $this->resolveActorLogin($actorLogin, $normalizedParameters);
		$requestSource = isset($executionContext['request_source']) ? (string) $executionContext['request_source'] : '';
		$allowActorless = $this->allowActorlessExecution($executionContext);
		$activeVetoSession = !empty($executionContext['active_veto_session']);

		$updateSource = ($requestSource === 'communication')
			? SeriesControlCatalog::UPDATE_SOURCE_COMMUNICATION
			: SeriesControlCatalog::UPDATE_SOURCE_CHAT;
		$updatedBy = ($resolvedActorLogin !== '')
			? $resolvedActorLogin
			: ($allowActorless ? 'server_payload' : 'system');

		$result = $this->seriesControlState->setBestOf(
			$bestOfRaw,
			$updateSource,
			$updatedBy,
			array('active_session' => $activeVetoSession)
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'bo_update_failed',
				isset($result['message']) ? (string) $result['message'] : 'Best-of update failed.',
				array(
					'series_targets' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->seriesControlState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Best-of updated.', array(
			'series_targets' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->seriesControlState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'SeriesControlState::setBestOf',
		));
	}

	private function executeMatchBoGet($actionName) {
		if (!$this->seriesControlState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Series control state is unavailable in current runtime.');
		}

		$seriesSnapshot = $this->seriesControlState->getSnapshot();
		$bestOf = isset($seriesSnapshot['best_of']) ? (int) $seriesSnapshot['best_of'] : SeriesControlCatalog::DEFAULT_BEST_OF;

		return AdminActionResult::success($actionName, 'Best-of snapshot resolved.', array(
			'best_of' => $bestOf,
			'series_targets' => $seriesSnapshot,
			'native_entrypoint' => 'SeriesControlState::getSnapshot',
		));
	}

	private function executeMatchMapsSet($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		if (!$this->seriesControlState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Series control state is unavailable in current runtime.');
		}

		$normalizedParameters = $this->normalizeSeriesControlParameters($parameters);
		$targetTeamRaw = $this->readStringParameter($normalizedParameters, array('target_team'));
		if ($targetTeamRaw === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires target_team.');
		}

		$mapsScoreRaw = $this->readStringParameter($normalizedParameters, array('maps_score'));
		if ($mapsScoreRaw === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires maps_score.');
		}

		$resolvedActorLogin = $this->resolveActorLogin($actorLogin, $normalizedParameters);
		$requestSource = isset($executionContext['request_source']) ? (string) $executionContext['request_source'] : '';
		$allowActorless = $this->allowActorlessExecution($executionContext);
		$updateSource = ($requestSource === 'communication')
			? SeriesControlCatalog::UPDATE_SOURCE_COMMUNICATION
			: SeriesControlCatalog::UPDATE_SOURCE_CHAT;
		$updatedBy = ($resolvedActorLogin !== '')
			? $resolvedActorLogin
			: ($allowActorless ? 'server_payload' : 'system');

		$result = $this->seriesControlState->setMatchMapsScore(
			$targetTeamRaw,
			$mapsScoreRaw,
			$updateSource,
			$updatedBy,
			array('active_session' => !empty($executionContext['active_veto_session']))
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'maps_score_update_failed',
				isset($result['message']) ? (string) $result['message'] : 'Match maps score update failed.',
				array(
					'series_targets' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->seriesControlState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Match maps score updated.', array(
			'series_targets' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->seriesControlState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'SeriesControlState::setMatchMapsScore',
		));
	}

	private function executeMatchMapsGet($actionName) {
		if (!$this->seriesControlState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Series control state is unavailable in current runtime.');
		}

		$seriesSnapshot = $this->seriesControlState->getSnapshot();
		$mapsScore = (isset($seriesSnapshot['maps_score']) && is_array($seriesSnapshot['maps_score']))
			? $seriesSnapshot['maps_score']
			: array('team_a' => 0, 'team_b' => 0);

		return AdminActionResult::success($actionName, 'Match maps score snapshot resolved.', array(
			'maps_score' => array(
				'team_a' => isset($mapsScore['team_a']) ? (int) $mapsScore['team_a'] : 0,
				'team_b' => isset($mapsScore['team_b']) ? (int) $mapsScore['team_b'] : 0,
			),
			'series_targets' => $seriesSnapshot,
			'native_entrypoint' => 'SeriesControlState::getSnapshot',
		));
	}

	private function executeMatchScoreSet($actionName, array $parameters, $actorLogin, array $executionContext = array()) {
		if (!$this->seriesControlState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Series control state is unavailable in current runtime.');
		}

		$normalizedParameters = $this->normalizeSeriesControlParameters($parameters);
		$targetTeamRaw = $this->readStringParameter($normalizedParameters, array('target_team'));
		if ($targetTeamRaw === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires target_team.');
		}

		$scoreRaw = $this->readStringParameter($normalizedParameters, array('score'));
		if ($scoreRaw === '') {
			return AdminActionResult::failure($actionName, 'missing_parameters', 'Action requires score.');
		}

		$resolvedActorLogin = $this->resolveActorLogin($actorLogin, $normalizedParameters);
		$requestSource = isset($executionContext['request_source']) ? (string) $executionContext['request_source'] : '';
		$allowActorless = $this->allowActorlessExecution($executionContext);
		$updateSource = ($requestSource === 'communication')
			? SeriesControlCatalog::UPDATE_SOURCE_COMMUNICATION
			: SeriesControlCatalog::UPDATE_SOURCE_CHAT;
		$updatedBy = ($resolvedActorLogin !== '')
			? $resolvedActorLogin
			: ($allowActorless ? 'server_payload' : 'system');

		$result = $this->seriesControlState->setCurrentMapScore(
			$targetTeamRaw,
			$scoreRaw,
			$updateSource,
			$updatedBy,
			array('active_session' => !empty($executionContext['active_veto_session']))
		);

		if (empty($result['success'])) {
			return AdminActionResult::failure(
				$actionName,
				isset($result['code']) ? (string) $result['code'] : 'current_map_score_update_failed',
				isset($result['message']) ? (string) $result['message'] : 'Current map score update failed.',
				array(
					'series_targets' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->seriesControlState->getSnapshot(),
					'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
				)
			);
		}

		return AdminActionResult::success($actionName, isset($result['message']) ? (string) $result['message'] : 'Current map score updated.', array(
			'series_targets' => isset($result['snapshot']) && is_array($result['snapshot']) ? $result['snapshot'] : $this->seriesControlState->getSnapshot(),
			'details' => isset($result['details']) && is_array($result['details']) ? $result['details'] : array(),
			'native_entrypoint' => 'SeriesControlState::setCurrentMapScore',
		));
	}

	private function executeMatchScoreGet($actionName) {
		if (!$this->seriesControlState) {
			return AdminActionResult::failure($actionName, 'capability_unavailable', 'Series control state is unavailable in current runtime.');
		}

		$seriesSnapshot = $this->seriesControlState->getSnapshot();
		$currentMapScore = (isset($seriesSnapshot['current_map_score']) && is_array($seriesSnapshot['current_map_score']))
			? $seriesSnapshot['current_map_score']
			: array('team_a' => 0, 'team_b' => 0);

		return AdminActionResult::success($actionName, 'Current map score snapshot resolved.', array(
			'current_map_score' => array(
				'team_a' => isset($currentMapScore['team_a']) ? (int) $currentMapScore['team_a'] : 0,
				'team_b' => isset($currentMapScore['team_b']) ? (int) $currentMapScore['team_b'] : 0,
			),
			'series_targets' => $seriesSnapshot,
			'native_entrypoint' => 'SeriesControlState::getSnapshot',
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

	private function resolveStateUpdateSource(array $executionContext, $chatSource, $communicationSource) {
		$requestSource = isset($executionContext['request_source'])
			? trim((string) $executionContext['request_source'])
			: '';

		if ($requestSource === 'communication') {
			return trim((string) $communicationSource);
		}

		return trim((string) $chatSource);
	}

	private function resolveStateUpdatedBy($actorLogin, array $executionContext) {
		$normalizedActorLogin = trim((string) $actorLogin);
		if ($normalizedActorLogin !== '') {
			return $normalizedActorLogin;
		}

		if ($this->allowActorlessExecution($executionContext)) {
			return 'server_payload';
		}

		return 'system';
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

	private function normalizeSeriesControlParameters(array $parameters) {
		$normalized = $parameters;

		if (isset($normalized['bo']) && !isset($normalized['best_of'])) {
			$normalized['best_of'] = $normalized['bo'];
		}
		if (isset($normalized['team']) && !isset($normalized['target_team'])) {
			$normalized['target_team'] = $normalized['team'];
		}
		if (isset($normalized['target']) && !isset($normalized['target_team'])) {
			$normalized['target_team'] = $normalized['target'];
		}

		if (isset($normalized['_positionals'])) {
			unset($normalized['_positionals']);
		}

		return $normalized;
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

		$normalizedAuthLevel = strtolower(trim($authLevelRaw));
		switch ($normalizedAuthLevel) {
			case 'player':
				$resolvedAuthLevel = AuthenticationManager::AUTH_LEVEL_PLAYER;
				break;
			case 'moderator':
				$resolvedAuthLevel = AuthenticationManager::AUTH_LEVEL_MODERATOR;
				break;
			case 'admin':
				$resolvedAuthLevel = AuthenticationManager::AUTH_LEVEL_ADMIN;
				break;
			case 'superadmin':
				$resolvedAuthLevel = AuthenticationManager::AUTH_LEVEL_SUPERADMIN;
				break;
			default:
				$resolvedAuthLevel = AuthenticationManager::getAuthLevel($authLevelRaw);
				break;
		}

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
