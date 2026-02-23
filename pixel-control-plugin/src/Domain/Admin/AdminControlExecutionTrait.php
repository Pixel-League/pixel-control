<?php

namespace PixelControl\Domain\Admin;

use ManiaControl\Logger;
use PixelControl\Admin\AdminActionCatalog;
use PixelControl\Admin\AdminActionResult;

trait AdminControlExecutionTrait {

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

		$whitelistSnapshotBeforeAction = array();
		if ($this->shouldPersistWhitelistAfterAdminAction($normalizedActionName)) {
			$whitelistSnapshotBeforeAction = $this->getWhitelistSnapshot();
		}

		$votePolicySnapshotBeforeAction = array();
		if ($this->shouldPersistVotePolicyAfterAdminAction($normalizedActionName)) {
			$votePolicySnapshotBeforeAction = $this->getVotePolicySnapshot();
		}

		$teamRosterSnapshotBeforeAction = array();
		if ($this->shouldPersistTeamRosterAfterAdminAction($normalizedActionName)) {
			$teamRosterSnapshotBeforeAction = $this->getTeamRosterSnapshot();
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
			$whitelistPersistenceResult = $this->persistWhitelistAfterAdminAction(
				$normalizedActionName,
				$result,
				$whitelistSnapshotBeforeAction
			);
			if (empty($whitelistPersistenceResult['success'])) {
				Logger::logWarning(
					'[PixelControl][admin][action_persistence_failed] action=' . $normalizedActionName
					. ', source=' . $requestSource
					. ', actor=' . $logActor
					. ', code=' . (isset($whitelistPersistenceResult['code']) ? (string) $whitelistPersistenceResult['code'] : 'setting_write_failed')
					. ', scope=whitelist.'
				);

				$failureDetails = $result->getDetails();
				$failureDetails['whitelist'] = $this->buildWhitelistCapabilitySnapshot();
				$failureDetails['persistence'] = isset($whitelistPersistenceResult['details']) && is_array($whitelistPersistenceResult['details'])
					? $whitelistPersistenceResult['details']
					: array();

				return AdminActionResult::failure(
					$normalizedActionName,
					isset($whitelistPersistenceResult['code']) ? (string) $whitelistPersistenceResult['code'] : 'setting_write_failed',
					isset($whitelistPersistenceResult['message']) ? (string) $whitelistPersistenceResult['message'] : 'Unable to persist whitelist settings after admin action.',
					$failureDetails
				);
			}

			$votePolicyPersistenceResult = $this->persistVotePolicyAfterAdminAction(
				$normalizedActionName,
				$result,
				$votePolicySnapshotBeforeAction
			);
			if (empty($votePolicyPersistenceResult['success'])) {
				Logger::logWarning(
					'[PixelControl][admin][action_persistence_failed] action=' . $normalizedActionName
					. ', source=' . $requestSource
					. ', actor=' . $logActor
					. ', code=' . (isset($votePolicyPersistenceResult['code']) ? (string) $votePolicyPersistenceResult['code'] : 'setting_write_failed')
					. ', scope=vote_policy.'
				);

				$failureDetails = $result->getDetails();
				$failureDetails['vote_policy'] = $this->getVotePolicySnapshot();
				$failureDetails['persistence'] = isset($votePolicyPersistenceResult['details']) && is_array($votePolicyPersistenceResult['details'])
					? $votePolicyPersistenceResult['details']
					: array();

				return AdminActionResult::failure(
					$normalizedActionName,
					isset($votePolicyPersistenceResult['code']) ? (string) $votePolicyPersistenceResult['code'] : 'setting_write_failed',
					isset($votePolicyPersistenceResult['message']) ? (string) $votePolicyPersistenceResult['message'] : 'Unable to persist vote policy settings after admin action.',
					$failureDetails
				);
			}

			$teamRosterPersistenceResult = $this->persistTeamRosterAfterAdminAction(
				$normalizedActionName,
				$result,
				$teamRosterSnapshotBeforeAction
			);
			if (empty($teamRosterPersistenceResult['success'])) {
				Logger::logWarning(
					'[PixelControl][admin][action_persistence_failed] action=' . $normalizedActionName
					. ', source=' . $requestSource
					. ', actor=' . $logActor
					. ', code=' . (isset($teamRosterPersistenceResult['code']) ? (string) $teamRosterPersistenceResult['code'] : 'setting_write_failed')
					. ', scope=team_roster.'
				);

				$failureDetails = $result->getDetails();
				$failureDetails['team_roster'] = $this->buildTeamControlCapabilitySnapshot();
				$failureDetails['persistence'] = isset($teamRosterPersistenceResult['details']) && is_array($teamRosterPersistenceResult['details'])
					? $teamRosterPersistenceResult['details']
					: array();

				return AdminActionResult::failure(
					$normalizedActionName,
					isset($teamRosterPersistenceResult['code']) ? (string) $teamRosterPersistenceResult['code'] : 'setting_write_failed',
					isset($teamRosterPersistenceResult['message']) ? (string) $teamRosterPersistenceResult['message'] : 'Unable to persist team-roster settings after admin action.',
					$failureDetails
				);
			}

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
			case AdminActionCatalog::ACTION_WHITELIST_ADD:
			case AdminActionCatalog::ACTION_WHITELIST_REMOVE:
			case AdminActionCatalog::ACTION_TEAM_ROSTER_ASSIGN:
			case AdminActionCatalog::ACTION_TEAM_ROSTER_UNASSIGN:
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
			case AdminActionCatalog::ACTION_WHITELIST_ENABLE:
			case AdminActionCatalog::ACTION_WHITELIST_DISABLE:
			case AdminActionCatalog::ACTION_WHITELIST_LIST:
			case AdminActionCatalog::ACTION_WHITELIST_CLEAN:
			case AdminActionCatalog::ACTION_WHITELIST_SYNC:
			case AdminActionCatalog::ACTION_VOTE_POLICY_GET:
			case AdminActionCatalog::ACTION_VOTE_POLICY_SET:
			case AdminActionCatalog::ACTION_TEAM_POLICY_GET:
			case AdminActionCatalog::ACTION_TEAM_POLICY_SET:
			case AdminActionCatalog::ACTION_TEAM_ROSTER_LIST:
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
			case AdminActionCatalog::ACTION_WHITELIST_ADD:
			case AdminActionCatalog::ACTION_WHITELIST_REMOVE:
			case AdminActionCatalog::ACTION_TEAM_ROSTER_ASSIGN:
			case AdminActionCatalog::ACTION_TEAM_ROSTER_UNASSIGN:
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
			case AdminActionCatalog::ACTION_WHITELIST_ENABLE:
			case AdminActionCatalog::ACTION_WHITELIST_DISABLE:
			case AdminActionCatalog::ACTION_WHITELIST_LIST:
			case AdminActionCatalog::ACTION_WHITELIST_CLEAN:
			case AdminActionCatalog::ACTION_WHITELIST_SYNC:
				return 'whitelist_registry';
			case AdminActionCatalog::ACTION_VOTE_POLICY_GET:
			case AdminActionCatalog::ACTION_VOTE_POLICY_SET:
				return 'vote_policy';
			case AdminActionCatalog::ACTION_TEAM_POLICY_GET:
			case AdminActionCatalog::ACTION_TEAM_POLICY_SET:
				return 'team_policy';
			case AdminActionCatalog::ACTION_TEAM_ROSTER_LIST:
				return 'team_roster';
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

}
