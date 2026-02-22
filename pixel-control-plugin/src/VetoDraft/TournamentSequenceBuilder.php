<?php

namespace PixelControl\VetoDraft;

class TournamentSequenceBuilder {
	public function buildSequence($mapPoolSize, $bestOf, $banStarterTeam, $pickStarterTeam) {
		$mapPoolSize = max(0, (int) $mapPoolSize);
		$bestOf = VetoDraftCatalog::sanitizeBestOf($bestOf, VetoDraftCatalog::DEFAULT_BEST_OF);

		if ($mapPoolSize < $bestOf || $mapPoolSize <= 0) {
			return array(
				'valid' => false,
				'reason' => 'map_pool_too_small_for_bo',
				'map_pool_size' => $mapPoolSize,
				'best_of' => $bestOf,
				'ban_count' => 0,
				'pick_count' => 0,
				'steps' => array(),
			);
		}

		$banStarterTeam = VetoDraftCatalog::normalizeTeam($banStarterTeam, VetoDraftCatalog::TEAM_A);
		if ($banStarterTeam !== VetoDraftCatalog::TEAM_A && $banStarterTeam !== VetoDraftCatalog::TEAM_B) {
			$banStarterTeam = VetoDraftCatalog::TEAM_A;
		}

		$pickStarterTeam = VetoDraftCatalog::normalizeTeam($pickStarterTeam, VetoDraftCatalog::oppositeTeam($banStarterTeam));
		if ($pickStarterTeam !== VetoDraftCatalog::TEAM_A && $pickStarterTeam !== VetoDraftCatalog::TEAM_B) {
			$pickStarterTeam = VetoDraftCatalog::oppositeTeam($banStarterTeam);
		}

		$banCount = max(0, $mapPoolSize - $bestOf);
		$pickCount = max(0, $bestOf - 1);
		$steps = array();
		$orderIndex = 1;

		$banOrder = VetoDraftCatalog::buildAbbaTurnOrder($banCount, $banStarterTeam);
		foreach ($banOrder as $team) {
			$steps[] = array(
				'order_index' => $orderIndex,
				'phase' => 'ban_phase',
				'action_kind' => VetoDraftCatalog::ACTION_BAN,
				'team' => $team,
			);
			$orderIndex++;
		}

		$pickOrder = VetoDraftCatalog::buildAbbaTurnOrder($pickCount, $pickStarterTeam);
		foreach ($pickOrder as $team) {
			$steps[] = array(
				'order_index' => $orderIndex,
				'phase' => 'pick_phase',
				'action_kind' => VetoDraftCatalog::ACTION_PICK,
				'team' => $team,
			);
			$orderIndex++;
		}

		$steps[] = array(
			'order_index' => $orderIndex,
			'phase' => 'decider_phase',
			'action_kind' => VetoDraftCatalog::ACTION_LOCK,
			'team' => VetoDraftCatalog::TEAM_SYSTEM,
		);

		return array(
			'valid' => true,
			'reason' => 'ok',
			'map_pool_size' => $mapPoolSize,
			'best_of' => $bestOf,
			'ban_count' => $banCount,
			'pick_count' => $pickCount,
			'lock_count' => 1,
			'ban_starter_team' => $banStarterTeam,
			'pick_starter_team' => $pickStarterTeam,
			'steps' => $steps,
		);
	}
}
