<?php

namespace PixelControl\SeriesControl;

class SeriesControlState implements SeriesControlStateInterface {
	/** @var int $bestOf */
	private $bestOf = SeriesControlCatalog::DEFAULT_BEST_OF;
	/** @var int $teamAMapsScore */
	private $teamAMapsScore = SeriesControlCatalog::DEFAULT_TEAM_A_MAPS_SCORE;
	/** @var int $teamBMapsScore */
	private $teamBMapsScore = SeriesControlCatalog::DEFAULT_TEAM_B_MAPS_SCORE;
	/** @var int $teamACurrentMapScore */
	private $teamACurrentMapScore = SeriesControlCatalog::DEFAULT_TEAM_A_CURRENT_MAP_SCORE;
	/** @var int $teamBCurrentMapScore */
	private $teamBCurrentMapScore = SeriesControlCatalog::DEFAULT_TEAM_B_CURRENT_MAP_SCORE;
	/** @var int $updatedAt */
	private $updatedAt = 0;
	/** @var string $updatedBy */
	private $updatedBy = 'system';
	/** @var string $updateSource */
	private $updateSource = SeriesControlCatalog::UPDATE_SOURCE_SETTING;

	public function bootstrap(array $defaults, $updateSource, $updatedBy) {
		$bestOf = SeriesControlCatalog::sanitizeBestOf(
			$this->readValue($defaults, array(SeriesControlCatalog::PARAM_BEST_OF), SeriesControlCatalog::DEFAULT_BEST_OF),
			SeriesControlCatalog::DEFAULT_BEST_OF
		);
		$mapsScore = $this->readValue($defaults, array(SeriesControlCatalog::PARAM_MAPS_SCORE), array());
		if (!is_array($mapsScore)) {
			$mapsScore = array();
		}

		$currentMapScore = $this->readValue($defaults, array('current_map_score'), array());
		if (!is_array($currentMapScore)) {
			$currentMapScore = array();
		}

		$teamAMapsScore = SeriesControlCatalog::sanitizeMapsScore(
			$this->readValue($mapsScore, array(SeriesControlCatalog::TEAM_A), SeriesControlCatalog::DEFAULT_TEAM_A_MAPS_SCORE),
			SeriesControlCatalog::DEFAULT_TEAM_A_MAPS_SCORE
		);
		$teamBMapsScore = SeriesControlCatalog::sanitizeMapsScore(
			$this->readValue($mapsScore, array(SeriesControlCatalog::TEAM_B), SeriesControlCatalog::DEFAULT_TEAM_B_MAPS_SCORE),
			SeriesControlCatalog::DEFAULT_TEAM_B_MAPS_SCORE
		);
		$teamACurrentMapScore = SeriesControlCatalog::sanitizeCurrentMapScore(
			$this->readValue($currentMapScore, array(SeriesControlCatalog::TEAM_A), SeriesControlCatalog::DEFAULT_TEAM_A_CURRENT_MAP_SCORE),
			SeriesControlCatalog::DEFAULT_TEAM_A_CURRENT_MAP_SCORE
		);
		$teamBCurrentMapScore = SeriesControlCatalog::sanitizeCurrentMapScore(
			$this->readValue($currentMapScore, array(SeriesControlCatalog::TEAM_B), SeriesControlCatalog::DEFAULT_TEAM_B_CURRENT_MAP_SCORE),
			SeriesControlCatalog::DEFAULT_TEAM_B_CURRENT_MAP_SCORE
		);

		$this->bestOf = $bestOf;
		$this->teamAMapsScore = $teamAMapsScore;
		$this->teamBMapsScore = $teamBMapsScore;
		$this->teamACurrentMapScore = $teamACurrentMapScore;
		$this->teamBCurrentMapScore = $teamBCurrentMapScore;
		$this->markUpdated($updateSource, $updatedBy);

		return $this->buildSuccess('series_bootstrap_applied', 'Series control defaults initialized.', array('changed_fields' => array('best_of', 'maps_score', 'current_map_score')));
	}

	public function reset() {
		$this->bestOf = SeriesControlCatalog::DEFAULT_BEST_OF;
		$this->teamAMapsScore = SeriesControlCatalog::DEFAULT_TEAM_A_MAPS_SCORE;
		$this->teamBMapsScore = SeriesControlCatalog::DEFAULT_TEAM_B_MAPS_SCORE;
		$this->teamACurrentMapScore = SeriesControlCatalog::DEFAULT_TEAM_A_CURRENT_MAP_SCORE;
		$this->teamBCurrentMapScore = SeriesControlCatalog::DEFAULT_TEAM_B_CURRENT_MAP_SCORE;
		$this->updatedAt = 0;
		$this->updatedBy = 'system';
		$this->updateSource = SeriesControlCatalog::UPDATE_SOURCE_SETTING;
	}

	public function getSnapshot() {
		return array(
			SeriesControlCatalog::PARAM_BEST_OF => $this->bestOf,
			SeriesControlCatalog::PARAM_MAPS_SCORE => array(
				SeriesControlCatalog::TEAM_A => $this->teamAMapsScore,
				SeriesControlCatalog::TEAM_B => $this->teamBMapsScore,
			),
			'current_map_score' => array(
				SeriesControlCatalog::TEAM_A => $this->teamACurrentMapScore,
				SeriesControlCatalog::TEAM_B => $this->teamBCurrentMapScore,
			),
			'updated_at' => $this->updatedAt,
			'updated_by' => $this->updatedBy,
			'update_source' => $this->updateSource,
			'policy' => array(
				'best_of' => array(
					'min' => SeriesControlCatalog::MIN_BEST_OF,
					'max' => SeriesControlCatalog::MAX_BEST_OF,
					'odd_required' => true,
				),
				'maps_score' => array(
					'min' => SeriesControlCatalog::MIN_MAPS_SCORE,
					'max' => SeriesControlCatalog::MAX_MAPS_SCORE,
				),
				'current_map_score' => array(
					'min' => SeriesControlCatalog::MIN_CURRENT_MAP_SCORE,
					'max' => SeriesControlCatalog::MAX_CURRENT_MAP_SCORE,
				),
			),
		);
	}

	public function setBestOf($bestOf, $updateSource, $updatedBy, array $context = array()) {
		if (!SeriesControlCatalog::isIntegerLike($bestOf)) {
			return $this->buildFailure('invalid_parameters', 'best_of must be an integer value.', array('field' => SeriesControlCatalog::PARAM_BEST_OF));
		}

		$requestedBestOf = (int) $bestOf;
		$normalizedBestOf = SeriesControlCatalog::sanitizeBestOf($requestedBestOf, $this->bestOf);

		$changedFields = array();
		if ($normalizedBestOf !== $this->bestOf) {
			$changedFields[] = SeriesControlCatalog::PARAM_BEST_OF;
		}

		$this->bestOf = $normalizedBestOf;
		$this->markUpdated($updateSource, $updatedBy);

		$warnings = array();
		if ($requestedBestOf !== $normalizedBestOf) {
			$warnings[] = 'best_of_normalized';
		}

		$applyScope = !empty($context['active_session']) ? 'next_session' : 'immediate';
		$message = 'Best-of default updated.';
		if (empty($changedFields)) {
			$message = 'Best-of default unchanged.';
		}
		if ($applyScope === 'next_session') {
			$message .= ' Active draft session keeps current sequence; updated policy applies to next start.';
		}

		return $this->buildSuccess('best_of_updated', $message, array(
			'changed_fields' => $changedFields,
			'warnings' => $warnings,
			'apply_scope' => $applyScope,
			'requested_best_of' => $requestedBestOf,
			'normalized_best_of' => $normalizedBestOf,
		));
	}

	public function setMatchMapsScore($targetTeam, $mapsScore, $updateSource, $updatedBy, array $context = array()) {
		$teamKey = SeriesControlCatalog::normalizeTargetTeam($targetTeam);
		if ($teamKey === '') {
			return $this->buildFailure('invalid_parameters', 'target_team must be one of: 0, 1, red, blue.', array('field' => SeriesControlCatalog::PARAM_TARGET_TEAM));
		}

		if (!SeriesControlCatalog::isIntegerLike($mapsScore)) {
			return $this->buildFailure('invalid_parameters', 'maps_score must be an integer value.', array('field' => SeriesControlCatalog::PARAM_MAPS_SCORE));
		}

		$requestedMapsScore = (int) $mapsScore;
		if ($requestedMapsScore < SeriesControlCatalog::MIN_MAPS_SCORE) {
			return $this->buildFailure('invalid_parameters', 'maps_score must be greater than or equal to 0.', array('field' => SeriesControlCatalog::PARAM_MAPS_SCORE));
		}

		$currentMapsScore = $this->getTeamMapsScore($teamKey);
		$normalizedMapsScore = SeriesControlCatalog::sanitizeMapsScore($requestedMapsScore, $currentMapsScore);
		$changedFields = array();
		if ($normalizedMapsScore !== $currentMapsScore) {
			$changedFields[] = SeriesControlCatalog::PARAM_MAPS_SCORE . '.' . $teamKey;
		}

		$this->setTeamMapsScore($teamKey, $normalizedMapsScore);
		$this->markUpdated($updateSource, $updatedBy);

		$warnings = array();
		if ($requestedMapsScore !== $normalizedMapsScore) {
			$warnings[] = 'maps_score_normalized';
		}

		$message = 'Match maps score updated.';
		if (empty($changedFields)) {
			$message = 'Match maps score unchanged.';
		}

		return $this->buildSuccess('maps_score_updated', $message, array(
			'changed_fields' => $changedFields,
			'warnings' => $warnings,
			'target_team' => $teamKey,
			'requested_maps_score' => $requestedMapsScore,
			'normalized_maps_score' => $normalizedMapsScore,
		));
	}

	public function setCurrentMapScore($targetTeam, $score, $updateSource, $updatedBy, array $context = array()) {
		$teamKey = SeriesControlCatalog::normalizeTargetTeam($targetTeam);
		if ($teamKey === '') {
			return $this->buildFailure('invalid_parameters', 'target_team must be one of: 0, 1, red, blue.', array('field' => SeriesControlCatalog::PARAM_TARGET_TEAM));
		}

		if (!SeriesControlCatalog::isIntegerLike($score)) {
			return $this->buildFailure('invalid_parameters', 'score must be an integer value.', array('field' => SeriesControlCatalog::PARAM_SCORE));
		}

		$requestedScore = (int) $score;
		if ($requestedScore < SeriesControlCatalog::MIN_CURRENT_MAP_SCORE) {
			return $this->buildFailure('invalid_parameters', 'score must be greater than or equal to 0.', array('field' => SeriesControlCatalog::PARAM_SCORE));
		}

		$currentScore = $this->getTeamCurrentMapScore($teamKey);
		$normalizedScore = SeriesControlCatalog::sanitizeCurrentMapScore($requestedScore, $currentScore);
		$changedFields = array();
		if ($normalizedScore !== $currentScore) {
			$changedFields[] = 'current_map_score.' . $teamKey;
		}

		$this->setTeamCurrentMapScore($teamKey, $normalizedScore);
		$this->markUpdated($updateSource, $updatedBy);

		$warnings = array();
		if ($requestedScore !== $normalizedScore) {
			$warnings[] = 'current_map_score_normalized';
		}

		$message = 'Current map score updated.';
		if (empty($changedFields)) {
			$message = 'Current map score unchanged.';
		}

		return $this->buildSuccess('current_map_score_updated', $message, array(
			'changed_fields' => $changedFields,
			'warnings' => $warnings,
			'target_team' => $teamKey,
			'requested_score' => $requestedScore,
			'normalized_score' => $normalizedScore,
		));
	}

	private function getTeamMapsScore($teamKey) {
		if ($teamKey === SeriesControlCatalog::TEAM_B) {
			return $this->teamBMapsScore;
		}

		return $this->teamAMapsScore;
	}

	private function setTeamMapsScore($teamKey, $mapsScore) {
		if ($teamKey === SeriesControlCatalog::TEAM_B) {
			$this->teamBMapsScore = (int) $mapsScore;
			return;
		}

		$this->teamAMapsScore = (int) $mapsScore;
	}

	private function getTeamCurrentMapScore($teamKey) {
		if ($teamKey === SeriesControlCatalog::TEAM_B) {
			return $this->teamBCurrentMapScore;
		}

		return $this->teamACurrentMapScore;
	}

	private function setTeamCurrentMapScore($teamKey, $score) {
		if ($teamKey === SeriesControlCatalog::TEAM_B) {
			$this->teamBCurrentMapScore = (int) $score;
			return;
		}

		$this->teamACurrentMapScore = (int) $score;
	}

	private function readValue(array $values, array $keys, $fallback) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $values)) {
				continue;
			}

			return $values[$key];
		}

		return $fallback;
	}

	private function markUpdated($updateSource, $updatedBy) {
		$this->updatedAt = time();
		$this->updateSource = SeriesControlCatalog::normalizeUpdateSource($updateSource, $this->updateSource);
		$this->updatedBy = SeriesControlCatalog::normalizeUpdatedBy($updatedBy, $this->updatedBy);
	}

	private function buildSuccess($code, $message, array $details = array()) {
		return array(
			'success' => true,
			'code' => trim((string) $code),
			'message' => trim((string) $message),
			'snapshot' => $this->getSnapshot(),
			'details' => $details,
		);
	}

	private function buildFailure($code, $message, array $details = array()) {
		return array(
			'success' => false,
			'code' => trim((string) $code),
			'message' => trim((string) $message),
			'snapshot' => $this->getSnapshot(),
			'details' => $details,
		);
	}
}
