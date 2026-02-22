<?php

namespace PixelControl\Admin;

use ManiaControl\Admin\AuthenticationManager;

class AdminActionCatalog {
	const ACTION_MAP_SKIP = 'map.skip';
	const ACTION_MAP_RESTART = 'map.restart';
	const ACTION_MAP_JUMP = 'map.jump';
	const ACTION_MAP_QUEUE = 'map.queue';
	const ACTION_MAP_ADD = 'map.add';
	const ACTION_MAP_REMOVE = 'map.remove';
	const ACTION_WARMUP_EXTEND = 'warmup.extend';
	const ACTION_WARMUP_END = 'warmup.end';
	const ACTION_PAUSE_START = 'pause.start';
	const ACTION_PAUSE_END = 'pause.end';
	const ACTION_VOTE_CANCEL = 'vote.cancel';
	const ACTION_VOTE_SET_RATIO = 'vote.set_ratio';
	const ACTION_PLAYER_FORCE_TEAM = 'player.force_team';
	const ACTION_PLAYER_FORCE_PLAY = 'player.force_play';
	const ACTION_PLAYER_FORCE_SPEC = 'player.force_spec';
	const ACTION_AUTH_GRANT = 'auth.grant';
	const ACTION_AUTH_REVOKE = 'auth.revoke';
	const ACTION_VOTE_CUSTOM_START = 'vote.custom_start';
	const ACTION_MATCH_BO_SET = 'match.bo.set';
	const ACTION_MATCH_BO_GET = 'match.bo.get';
	const ACTION_MATCH_MAPS_SET = 'match.maps.set';
	const ACTION_MATCH_MAPS_GET = 'match.maps.get';
	const ACTION_MATCH_SCORE_SET = 'match.score.set';
	const ACTION_MATCH_SCORE_GET = 'match.score.get';

	const RIGHT_MAP_SKIP = 'Pixel Control Admin: Map Skip';
	const RIGHT_MAP_RESTART = 'Pixel Control Admin: Map Restart';
	const RIGHT_MAP_JUMP = 'Pixel Control Admin: Map Jump';
	const RIGHT_MAP_QUEUE = 'Pixel Control Admin: Map Queue';
	const RIGHT_MAP_ADD = 'Pixel Control Admin: Map Add';
	const RIGHT_MAP_REMOVE = 'Pixel Control Admin: Map Remove';
	const RIGHT_WARMUP_EXTEND = 'Pixel Control Admin: Warmup Extend';
	const RIGHT_WARMUP_END = 'Pixel Control Admin: Warmup End';
	const RIGHT_PAUSE_START = 'Pixel Control Admin: Pause Start';
	const RIGHT_PAUSE_END = 'Pixel Control Admin: Pause End';
	const RIGHT_VOTE_CANCEL = 'Pixel Control Admin: Vote Cancel';
	const RIGHT_VOTE_SET_RATIO = 'Pixel Control Admin: Vote Ratio';
	const RIGHT_PLAYER_FORCE_TEAM = 'Pixel Control Admin: Force Team';
	const RIGHT_PLAYER_FORCE_PLAY = 'Pixel Control Admin: Force Play';
	const RIGHT_PLAYER_FORCE_SPEC = 'Pixel Control Admin: Force Spec';
	const RIGHT_AUTH_GRANT = 'Pixel Control Admin: Grant Auth';
	const RIGHT_AUTH_REVOKE = 'Pixel Control Admin: Revoke Auth';
	const RIGHT_VOTE_CUSTOM_START = 'Pixel Control Admin: Custom Vote Start';
	const RIGHT_MATCH_BO_SET = 'Pixel Control Admin: Match BO Set';
	const RIGHT_MATCH_BO_GET = 'Pixel Control Admin: Match BO Get';
	const RIGHT_MATCH_MAPS_SET = 'Pixel Control Admin: Match Maps Set';
	const RIGHT_MATCH_MAPS_GET = 'Pixel Control Admin: Match Maps Get';
	const RIGHT_MATCH_SCORE_SET = 'Pixel Control Admin: Match Score Set';
	const RIGHT_MATCH_SCORE_GET = 'Pixel Control Admin: Match Score Get';

	const COMMUNICATION_EXECUTE_ACTION = 'PixelControl.Admin.ExecuteAction';
	const COMMUNICATION_LIST_ACTIONS = 'PixelControl.Admin.ListActions';

	public static function normalizeActionName($actionName) {
		$normalizedActionName = strtolower(trim((string) $actionName));
		if ($normalizedActionName === '') {
			return '';
		}

		$aliases = array(
			'map.next' => self::ACTION_MAP_SKIP,
			'map.skip_current' => self::ACTION_MAP_SKIP,
			'map.res' => self::ACTION_MAP_RESTART,
			'map.add_queue' => self::ACTION_MAP_QUEUE,
			'map.add_mx' => self::ACTION_MAP_ADD,
			'map.add_from_mx' => self::ACTION_MAP_ADD,
			'map.delete' => self::ACTION_MAP_REMOVE,
			'map.rm' => self::ACTION_MAP_REMOVE,
			'warmup.stop' => self::ACTION_WARMUP_END,
			'pause.resume' => self::ACTION_PAUSE_END,
			'vote.cancel_current' => self::ACTION_VOTE_CANCEL,
			'vote.set_ratio_for_command' => self::ACTION_VOTE_SET_RATIO,
			'player.force_spectator' => self::ACTION_PLAYER_FORCE_SPEC,
			'player.force_to_team' => self::ACTION_PLAYER_FORCE_TEAM,
			'player.force_to_play' => self::ACTION_PLAYER_FORCE_PLAY,
			'auth.grant_level' => self::ACTION_AUTH_GRANT,
			'auth.revoke_level' => self::ACTION_AUTH_REVOKE,
			'custom_vote.start' => self::ACTION_VOTE_CUSTOM_START,
			'bo.set' => self::ACTION_MATCH_BO_SET,
			'bo.get' => self::ACTION_MATCH_BO_GET,
			'match.bo' => self::ACTION_MATCH_BO_GET,
			'maps.set' => self::ACTION_MATCH_MAPS_SET,
			'match.maps' => self::ACTION_MATCH_MAPS_GET,
			'maps.get' => self::ACTION_MATCH_MAPS_GET,
			'score.set' => self::ACTION_MATCH_SCORE_SET,
			'match.score' => self::ACTION_MATCH_SCORE_GET,
			'score.get' => self::ACTION_MATCH_SCORE_GET,
		);

		if (array_key_exists($normalizedActionName, $aliases)) {
			return $aliases[$normalizedActionName];
		}

		return $normalizedActionName;
	}

	public static function getActionDefinition($actionName) {
		$normalizedActionName = self::normalizeActionName($actionName);
		$definitions = self::getActionDefinitions();
		if (!array_key_exists($normalizedActionName, $definitions)) {
			return null;
		}

		return $definitions[$normalizedActionName];
	}

	public static function getActionDefinitions() {
		return array(
			self::ACTION_MAP_SKIP => array(
				'permission_setting' => self::RIGHT_MAP_SKIP,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array(),
				'native_entrypoint' => 'MapActions::skipMap',
				'ownership' => 'delegate',
			),
			self::ACTION_MAP_RESTART => array(
				'permission_setting' => self::RIGHT_MAP_RESTART,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array(),
				'native_entrypoint' => 'MapActions::restartMap',
				'ownership' => 'delegate',
			),
			self::ACTION_MAP_JUMP => array(
				'permission_setting' => self::RIGHT_MAP_JUMP,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array('map_uid|mx_id'),
				'native_entrypoint' => 'MapActions::skipToMapByUid|skipToMapByMxId',
				'ownership' => 'delegate',
			),
			self::ACTION_MAP_QUEUE => array(
				'permission_setting' => self::RIGHT_MAP_QUEUE,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array('map_uid|mx_id'),
				'native_entrypoint' => 'MapQueue::serverAddMapToMapQueue',
				'ownership' => 'delegate',
			),
			self::ACTION_MAP_ADD => array(
				'permission_setting' => self::RIGHT_MAP_ADD,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_ADMIN,
				'required_parameters' => array('mx_id'),
				'native_entrypoint' => 'MapManager::addMapFromMx',
				'ownership' => 'delegate',
			),
			self::ACTION_MAP_REMOVE => array(
				'permission_setting' => self::RIGHT_MAP_REMOVE,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_ADMIN,
				'required_parameters' => array('map_uid|mx_id'),
				'native_entrypoint' => 'MapManager::removeMap',
				'ownership' => 'delegate',
			),
			self::ACTION_WARMUP_EXTEND => array(
				'permission_setting' => self::RIGHT_WARMUP_EXTEND,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array('seconds'),
				'native_entrypoint' => 'ModeScriptEventManager::extendManiaPlanetWarmup',
				'ownership' => 'mixed',
			),
			self::ACTION_WARMUP_END => array(
				'permission_setting' => self::RIGHT_WARMUP_END,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array(),
				'native_entrypoint' => 'ModeScriptEventManager::stopManiaPlanetWarmup',
				'ownership' => 'mixed',
			),
			self::ACTION_PAUSE_START => array(
				'permission_setting' => self::RIGHT_PAUSE_START,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array(),
				'native_entrypoint' => 'ModeScriptEventManager::startPause',
				'ownership' => 'mixed',
			),
			self::ACTION_PAUSE_END => array(
				'permission_setting' => self::RIGHT_PAUSE_END,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array(),
				'native_entrypoint' => 'ModeScriptEventManager::endPause',
				'ownership' => 'mixed',
			),
			self::ACTION_VOTE_CANCEL => array(
				'permission_setting' => self::RIGHT_VOTE_CANCEL,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array(),
				'native_entrypoint' => 'DedicatedClient::cancelVote',
				'ownership' => 'delegate',
			),
			self::ACTION_VOTE_SET_RATIO => array(
				'permission_setting' => self::RIGHT_VOTE_SET_RATIO,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_ADMIN,
				'required_parameters' => array('command', 'ratio'),
				'native_entrypoint' => 'DedicatedClient::setCallVoteRatios',
				'ownership' => 'mixed',
			),
			self::ACTION_PLAYER_FORCE_TEAM => array(
				'permission_setting' => self::RIGHT_PLAYER_FORCE_TEAM,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array('target_login', 'team'),
				'native_entrypoint' => 'PlayerActions::forcePlayerToTeam',
				'ownership' => 'delegate',
			),
			self::ACTION_PLAYER_FORCE_PLAY => array(
				'permission_setting' => self::RIGHT_PLAYER_FORCE_PLAY,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array('target_login'),
				'native_entrypoint' => 'PlayerActions::forcePlayerToPlay',
				'ownership' => 'delegate',
			),
			self::ACTION_PLAYER_FORCE_SPEC => array(
				'permission_setting' => self::RIGHT_PLAYER_FORCE_SPEC,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array('target_login'),
				'native_entrypoint' => 'PlayerActions::forcePlayerToSpectator',
				'ownership' => 'delegate',
			),
			self::ACTION_AUTH_GRANT => array(
				'permission_setting' => self::RIGHT_AUTH_GRANT,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_ADMIN,
				'required_parameters' => array('target_login', 'auth_level'),
				'native_entrypoint' => 'PlayerActions::grantAuthLevel',
				'ownership' => 'delegate',
			),
			self::ACTION_AUTH_REVOKE => array(
				'permission_setting' => self::RIGHT_AUTH_REVOKE,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_ADMIN,
				'required_parameters' => array('target_login'),
				'native_entrypoint' => 'PlayerActions::revokeAuthLevel',
				'ownership' => 'delegate',
			),
			self::ACTION_VOTE_CUSTOM_START => array(
				'permission_setting' => self::RIGHT_VOTE_CUSTOM_START,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array('vote_index'),
				'native_entrypoint' => 'MCTeam\\CustomVotesPlugin::startVote',
				'ownership' => 'mixed',
			),
			self::ACTION_MATCH_BO_SET => array(
				'permission_setting' => self::RIGHT_MATCH_BO_SET,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array('best_of'),
				'native_entrypoint' => 'SeriesControlState::setBestOf',
				'ownership' => 'plugin_state',
			),
			self::ACTION_MATCH_BO_GET => array(
				'permission_setting' => self::RIGHT_MATCH_BO_GET,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array(),
				'native_entrypoint' => 'SeriesControlState::getSnapshot(best_of)',
				'ownership' => 'plugin_state',
			),
			self::ACTION_MATCH_MAPS_SET => array(
				'permission_setting' => self::RIGHT_MATCH_MAPS_SET,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array('target_team', 'maps_score'),
				'native_entrypoint' => 'SeriesControlState::setMatchMapsScore',
				'ownership' => 'plugin_state',
			),
			self::ACTION_MATCH_MAPS_GET => array(
				'permission_setting' => self::RIGHT_MATCH_MAPS_GET,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array(),
				'native_entrypoint' => 'SeriesControlState::getSnapshot(maps_score)',
				'ownership' => 'plugin_state',
			),
			self::ACTION_MATCH_SCORE_SET => array(
				'permission_setting' => self::RIGHT_MATCH_SCORE_SET,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array('target_team', 'score'),
				'native_entrypoint' => 'SeriesControlState::setCurrentMapScore',
				'ownership' => 'plugin_state',
			),
			self::ACTION_MATCH_SCORE_GET => array(
				'permission_setting' => self::RIGHT_MATCH_SCORE_GET,
				'minimum_auth_level' => AuthenticationManager::AUTH_LEVEL_MODERATOR,
				'required_parameters' => array(),
				'native_entrypoint' => 'SeriesControlState::getSnapshot(current_map_score)',
				'ownership' => 'plugin_state',
			),
		);
	}
}
