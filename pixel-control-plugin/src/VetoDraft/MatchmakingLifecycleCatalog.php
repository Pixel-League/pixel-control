<?php

namespace PixelControl\VetoDraft;

class MatchmakingLifecycleCatalog {
	const STAGE_IDLE = 'idle';
	const STAGE_VETO_COMPLETED = 'veto_completed';
	const STAGE_SELECTED_MAP_LOADED = 'selected_map_loaded';
	const STAGE_MATCH_STARTED = 'match_started';
	const STAGE_SELECTED_MAP_FINISHED = 'selected_map_finished';
	const STAGE_PLAYERS_REMOVED = 'players_removed';
	const STAGE_MAP_CHANGED = 'map_changed';
	const STAGE_MATCH_ENDED = 'match_ended';
	const STAGE_READY_FOR_NEXT_PLAYERS = 'ready_for_next_players';

	public static function stageOrder() {
		return array(
			self::STAGE_VETO_COMPLETED,
			self::STAGE_SELECTED_MAP_LOADED,
			self::STAGE_MATCH_STARTED,
			self::STAGE_SELECTED_MAP_FINISHED,
			self::STAGE_PLAYERS_REMOVED,
			self::STAGE_MAP_CHANGED,
			self::STAGE_MATCH_ENDED,
			self::STAGE_READY_FOR_NEXT_PLAYERS,
		);
	}

	public static function stageIndex($stage) {
		$normalizedStage = trim((string) $stage);
		if ($normalizedStage === '') {
			return -1;
		}

		$stageOrder = self::stageOrder();
		$stageIndex = array_search($normalizedStage, $stageOrder, true);
		if ($stageIndex === false) {
			return -1;
		}

		return (int) $stageIndex;
	}
}
