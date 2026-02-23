<?php

namespace PixelControl\Domain\SeriesControl;

use ManiaControl\Logger;
use PixelControl\SeriesControl\SeriesControlCatalog;
use PixelControl\SeriesControl\SeriesControlState;

trait SeriesControlDomainTrait {
	private function initializeSeriesControlSettings() {
		if (!$this->maniaControl) {
			return;
		}

		$settingManager = $this->maniaControl->getSettingManager();
		$settingManager->initSetting(
			$this,
			self::SETTING_VETO_DRAFT_DEFAULT_BEST_OF,
			SeriesControlCatalog::sanitizeBestOf(
				$this->resolveRuntimeIntSetting(
					self::SETTING_VETO_DRAFT_DEFAULT_BEST_OF,
					'PIXEL_CONTROL_VETO_DRAFT_DEFAULT_BEST_OF',
					SeriesControlCatalog::DEFAULT_BEST_OF,
					SeriesControlCatalog::MIN_BEST_OF
				),
				SeriesControlCatalog::DEFAULT_BEST_OF
			)
		);
		$settingManager->initSetting($this, self::SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_A, SeriesControlCatalog::DEFAULT_TEAM_A_MAPS_SCORE);
		$settingManager->initSetting($this, self::SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_B, SeriesControlCatalog::DEFAULT_TEAM_B_MAPS_SCORE);
		$settingManager->initSetting($this, self::SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_A, SeriesControlCatalog::DEFAULT_TEAM_A_CURRENT_MAP_SCORE);
		$settingManager->initSetting($this, self::SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_B, SeriesControlCatalog::DEFAULT_TEAM_B_CURRENT_MAP_SCORE);
	}

	private function initializeSeriesControlState() {
		if (!$this->maniaControl) {
			return;
		}

		$this->seriesControlState = new SeriesControlState();

		$bestOf = SeriesControlCatalog::sanitizeBestOf(
			$this->resolveRuntimeIntSetting(
				self::SETTING_VETO_DRAFT_DEFAULT_BEST_OF,
				'PIXEL_CONTROL_VETO_DRAFT_DEFAULT_BEST_OF',
				SeriesControlCatalog::DEFAULT_BEST_OF,
				SeriesControlCatalog::MIN_BEST_OF
			),
			SeriesControlCatalog::DEFAULT_BEST_OF
		);
		$teamAMapsScore = SeriesControlCatalog::sanitizeMapsScore(
			$this->resolveSeriesControlSettingInt(
				self::SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_A,
				SeriesControlCatalog::DEFAULT_TEAM_A_MAPS_SCORE,
				SeriesControlCatalog::MIN_MAPS_SCORE
			),
			SeriesControlCatalog::DEFAULT_TEAM_A_MAPS_SCORE
		);
		$teamBMapsScore = SeriesControlCatalog::sanitizeMapsScore(
			$this->resolveSeriesControlSettingInt(
				self::SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_B,
				SeriesControlCatalog::DEFAULT_TEAM_B_MAPS_SCORE,
				SeriesControlCatalog::MIN_MAPS_SCORE
			),
			SeriesControlCatalog::DEFAULT_TEAM_B_MAPS_SCORE
		);
		$teamACurrentMapScore = SeriesControlCatalog::sanitizeCurrentMapScore(
			$this->resolveSeriesControlSettingInt(
				self::SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_A,
				SeriesControlCatalog::DEFAULT_TEAM_A_CURRENT_MAP_SCORE,
				SeriesControlCatalog::MIN_CURRENT_MAP_SCORE
			),
			SeriesControlCatalog::DEFAULT_TEAM_A_CURRENT_MAP_SCORE
		);
		$teamBCurrentMapScore = SeriesControlCatalog::sanitizeCurrentMapScore(
			$this->resolveSeriesControlSettingInt(
				self::SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_B,
				SeriesControlCatalog::DEFAULT_TEAM_B_CURRENT_MAP_SCORE,
				SeriesControlCatalog::MIN_CURRENT_MAP_SCORE
			),
			SeriesControlCatalog::DEFAULT_TEAM_B_CURRENT_MAP_SCORE
		);

		$this->seriesControlState->bootstrap(
			array(
				SeriesControlCatalog::PARAM_BEST_OF => $bestOf,
				SeriesControlCatalog::PARAM_MAPS_SCORE => array(
					SeriesControlCatalog::TEAM_A => $teamAMapsScore,
					SeriesControlCatalog::TEAM_B => $teamBMapsScore,
				),
				'current_map_score' => array(
					SeriesControlCatalog::TEAM_A => $teamACurrentMapScore,
					SeriesControlCatalog::TEAM_B => $teamBCurrentMapScore,
				),
			),
			$this->resolveSeriesControlBootstrapSource(),
			'plugin_bootstrap'
		);

		$seriesSnapshot = $this->getSeriesControlSnapshot();
		$this->applySeriesControlSnapshotToRuntime($seriesSnapshot);

		Logger::log(
			'[PixelControl][series][bootstrap] best_of=' . (isset($seriesSnapshot['best_of']) ? (int) $seriesSnapshot['best_of'] : 0)
			. ', maps_score_a=' . (isset($seriesSnapshot['maps_score'][SeriesControlCatalog::TEAM_A]) ? (int) $seriesSnapshot['maps_score'][SeriesControlCatalog::TEAM_A] : 0)
			. ', maps_score_b=' . (isset($seriesSnapshot['maps_score'][SeriesControlCatalog::TEAM_B]) ? (int) $seriesSnapshot['maps_score'][SeriesControlCatalog::TEAM_B] : 0)
			. ', round_score_a=' . (isset($seriesSnapshot['current_map_score'][SeriesControlCatalog::TEAM_A]) ? (int) $seriesSnapshot['current_map_score'][SeriesControlCatalog::TEAM_A] : 0)
			. ', round_score_b=' . (isset($seriesSnapshot['current_map_score'][SeriesControlCatalog::TEAM_B]) ? (int) $seriesSnapshot['current_map_score'][SeriesControlCatalog::TEAM_B] : 0)
			. ', source=' . (isset($seriesSnapshot['update_source']) ? (string) $seriesSnapshot['update_source'] : 'setting')
			. '.'
		);
	}

	private function getSeriesControlSnapshot() {
		if ($this->seriesControlState) {
			return $this->seriesControlState->getSnapshot();
		}

		return $this->buildDefaultSeriesControlSnapshot();
	}

	private function buildDefaultSeriesControlSnapshot() {
		return array(
			SeriesControlCatalog::PARAM_BEST_OF => SeriesControlCatalog::sanitizeBestOf($this->vetoDraftDefaultBestOf, SeriesControlCatalog::DEFAULT_BEST_OF),
			SeriesControlCatalog::PARAM_MAPS_SCORE => array(
				SeriesControlCatalog::TEAM_A => SeriesControlCatalog::DEFAULT_TEAM_A_MAPS_SCORE,
				SeriesControlCatalog::TEAM_B => SeriesControlCatalog::DEFAULT_TEAM_B_MAPS_SCORE,
			),
			'current_map_score' => array(
				SeriesControlCatalog::TEAM_A => SeriesControlCatalog::DEFAULT_TEAM_A_CURRENT_MAP_SCORE,
				SeriesControlCatalog::TEAM_B => SeriesControlCatalog::DEFAULT_TEAM_B_CURRENT_MAP_SCORE,
			),
			'updated_at' => 0,
			'updated_by' => 'system',
			'update_source' => SeriesControlCatalog::UPDATE_SOURCE_SETTING,
		);
	}

	private function resolveSeriesControlBestOfDefault() {
		$seriesSnapshot = $this->getSeriesControlSnapshot();
		if (isset($seriesSnapshot['best_of'])) {
			return SeriesControlCatalog::sanitizeBestOf($seriesSnapshot['best_of'], $this->vetoDraftDefaultBestOf);
		}

		return SeriesControlCatalog::sanitizeBestOf($this->vetoDraftDefaultBestOf, SeriesControlCatalog::DEFAULT_BEST_OF);
	}

	private function resolveSeriesControlSettingInt($settingName, $fallback, $minimum) {
		if (!$this->maniaControl) {
			return max((int) $minimum, (int) $fallback);
		}

		$settingValue = $this->maniaControl->getSettingManager()->getSettingValue($this, $settingName);
		if (is_numeric($settingValue)) {
			return max((int) $minimum, (int) $settingValue);
		}

		return max((int) $minimum, (int) $fallback);
	}

	private function buildSeriesControlSettingValueMap(array $seriesSnapshot) {
		$bestOf = isset($seriesSnapshot[SeriesControlCatalog::PARAM_BEST_OF])
			? SeriesControlCatalog::sanitizeBestOf($seriesSnapshot[SeriesControlCatalog::PARAM_BEST_OF], SeriesControlCatalog::DEFAULT_BEST_OF)
			: SeriesControlCatalog::DEFAULT_BEST_OF;

		$mapsScore = (isset($seriesSnapshot[SeriesControlCatalog::PARAM_MAPS_SCORE]) && is_array($seriesSnapshot[SeriesControlCatalog::PARAM_MAPS_SCORE]))
			? $seriesSnapshot[SeriesControlCatalog::PARAM_MAPS_SCORE]
			: array();
		$currentMapScore = (isset($seriesSnapshot['current_map_score']) && is_array($seriesSnapshot['current_map_score']))
			? $seriesSnapshot['current_map_score']
			: array();

		$teamAMapsScore = SeriesControlCatalog::sanitizeMapsScore(
			isset($mapsScore[SeriesControlCatalog::TEAM_A]) ? $mapsScore[SeriesControlCatalog::TEAM_A] : SeriesControlCatalog::DEFAULT_TEAM_A_MAPS_SCORE,
			SeriesControlCatalog::DEFAULT_TEAM_A_MAPS_SCORE
		);
		$teamBMapsScore = SeriesControlCatalog::sanitizeMapsScore(
			isset($mapsScore[SeriesControlCatalog::TEAM_B]) ? $mapsScore[SeriesControlCatalog::TEAM_B] : SeriesControlCatalog::DEFAULT_TEAM_B_MAPS_SCORE,
			SeriesControlCatalog::DEFAULT_TEAM_B_MAPS_SCORE
		);
		$teamACurrentMapScore = SeriesControlCatalog::sanitizeCurrentMapScore(
			isset($currentMapScore[SeriesControlCatalog::TEAM_A]) ? $currentMapScore[SeriesControlCatalog::TEAM_A] : SeriesControlCatalog::DEFAULT_TEAM_A_CURRENT_MAP_SCORE,
			SeriesControlCatalog::DEFAULT_TEAM_A_CURRENT_MAP_SCORE
		);
		$teamBCurrentMapScore = SeriesControlCatalog::sanitizeCurrentMapScore(
			isset($currentMapScore[SeriesControlCatalog::TEAM_B]) ? $currentMapScore[SeriesControlCatalog::TEAM_B] : SeriesControlCatalog::DEFAULT_TEAM_B_CURRENT_MAP_SCORE,
			SeriesControlCatalog::DEFAULT_TEAM_B_CURRENT_MAP_SCORE
		);

		return array(
			self::SETTING_VETO_DRAFT_DEFAULT_BEST_OF => $bestOf,
			self::SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_A => $teamAMapsScore,
			self::SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_B => $teamBMapsScore,
			self::SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_A => $teamACurrentMapScore,
			self::SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_B => $teamBCurrentMapScore,
		);
	}

	private function persistSeriesControlSnapshot(array $seriesSnapshot, array $previousSnapshot = array()) {
		if (!$this->maniaControl) {
			return array(
				'success' => false,
				'code' => 'capability_unavailable',
				'message' => 'Series settings persistence is unavailable.',
				'snapshot' => $seriesSnapshot,
				'details' => array(),
			);
		}

		$settingManager = $this->maniaControl->getSettingManager();
		$settingValues = $this->buildSeriesControlSettingValueMap($seriesSnapshot);
		$writtenSettings = array();
		$failedSettings = array();

		foreach ($settingValues as $settingName => $settingValue) {
			$settingSaved = $settingManager->setSetting($this, $settingName, $settingValue);
			if ($settingSaved) {
				$writtenSettings[] = $settingName;
				continue;
			}

			$failedSettings[] = $settingName;
		}

		if (empty($failedSettings)) {
			return array(
				'success' => true,
				'code' => 'settings_persisted',
				'message' => 'Series settings persisted.',
				'snapshot' => $seriesSnapshot,
				'details' => array(
					'written_settings' => $writtenSettings,
				),
			);
		}

		$rollbackFailedSettings = array();
		$rollbackAttempted = false;
		if (!empty($writtenSettings) && !empty($previousSnapshot)) {
			$rollbackAttempted = true;
			$rollbackValues = $this->buildSeriesControlSettingValueMap($previousSnapshot);
			foreach ($writtenSettings as $writtenSettingName) {
				if (!array_key_exists($writtenSettingName, $rollbackValues)) {
					continue;
				}

				$rollbackSaved = $settingManager->setSetting($this, $writtenSettingName, $rollbackValues[$writtenSettingName]);
				if (!$rollbackSaved) {
					$rollbackFailedSettings[] = $writtenSettingName;
				}
			}
		}

		return array(
			'success' => false,
			'code' => 'setting_write_failed',
			'message' => 'Unable to persist series settings snapshot.',
			'snapshot' => $seriesSnapshot,
			'details' => array(
				'failed_settings' => $failedSettings,
				'written_settings' => $writtenSettings,
				'rollback_attempted' => $rollbackAttempted,
				'rollback_failed_settings' => $rollbackFailedSettings,
			),
		);
	}

	private function restoreSeriesControlSnapshot(array $seriesSnapshot, $updateSource, $updatedBy) {
		if (!$this->seriesControlState) {
			return array(
				'success' => false,
				'code' => 'capability_unavailable',
				'message' => 'Series control state is unavailable for rollback.',
				'details' => array(),
			);
		}

		$bootstrapResult = $this->seriesControlState->bootstrap(
			array(
				SeriesControlCatalog::PARAM_BEST_OF => isset($seriesSnapshot[SeriesControlCatalog::PARAM_BEST_OF]) ? $seriesSnapshot[SeriesControlCatalog::PARAM_BEST_OF] : SeriesControlCatalog::DEFAULT_BEST_OF,
				SeriesControlCatalog::PARAM_MAPS_SCORE => isset($seriesSnapshot[SeriesControlCatalog::PARAM_MAPS_SCORE]) && is_array($seriesSnapshot[SeriesControlCatalog::PARAM_MAPS_SCORE]) ? $seriesSnapshot[SeriesControlCatalog::PARAM_MAPS_SCORE] : array(),
				'current_map_score' => isset($seriesSnapshot['current_map_score']) && is_array($seriesSnapshot['current_map_score']) ? $seriesSnapshot['current_map_score'] : array(),
			),
			SeriesControlCatalog::normalizeUpdateSource($updateSource, SeriesControlCatalog::UPDATE_SOURCE_SETTING),
			SeriesControlCatalog::normalizeUpdatedBy($updatedBy, 'system')
		);

		$currentSnapshot = $this->getSeriesControlSnapshot();
		$this->applySeriesControlSnapshotToRuntime($currentSnapshot);

		return array(
			'success' => !empty($bootstrapResult['success']),
			'code' => !empty($bootstrapResult['success']) ? 'rollback_applied' : 'rollback_failed',
			'message' => !empty($bootstrapResult['success']) ? 'Series snapshot rollback applied.' : 'Series snapshot rollback failed.',
			'details' => array(
				'snapshot' => $currentSnapshot,
			),
		);
	}

	private function applySeriesControlSnapshotToRuntime(array $seriesSnapshot) {
		if (isset($seriesSnapshot['best_of'])) {
			$this->vetoDraftDefaultBestOf = SeriesControlCatalog::sanitizeBestOf(
				$seriesSnapshot['best_of'],
				$this->vetoDraftDefaultBestOf
			);
		}
	}

	private function buildSeriesControlSnapshotSummaryLine() {
		$snapshot = $this->getSeriesControlSnapshot();
		$bestOf = isset($snapshot['best_of']) ? (int) $snapshot['best_of'] : 0;
		$mapsScore = (isset($snapshot['maps_score']) && is_array($snapshot['maps_score']))
			? $snapshot['maps_score']
			: array();
		$currentMapScore = (isset($snapshot['current_map_score']) && is_array($snapshot['current_map_score']))
			? $snapshot['current_map_score']
			: array();
		$teamAMapsScore = isset($mapsScore[SeriesControlCatalog::TEAM_A]) ? (int) $mapsScore[SeriesControlCatalog::TEAM_A] : 0;
		$teamBMapsScore = isset($mapsScore[SeriesControlCatalog::TEAM_B]) ? (int) $mapsScore[SeriesControlCatalog::TEAM_B] : 0;
		$teamACurrentMapScore = isset($currentMapScore[SeriesControlCatalog::TEAM_A]) ? (int) $currentMapScore[SeriesControlCatalog::TEAM_A] : 0;
		$teamBCurrentMapScore = isset($currentMapScore[SeriesControlCatalog::TEAM_B]) ? (int) $currentMapScore[SeriesControlCatalog::TEAM_B] : 0;
		$updateSource = isset($snapshot['update_source']) ? (string) $snapshot['update_source'] : 'setting';
		$updatedBy = isset($snapshot['updated_by']) ? (string) $snapshot['updated_by'] : 'system';

		return 'Series config: bo=' . $bestOf
			. ', maps_score_a=' . $teamAMapsScore
			. ', maps_score_b=' . $teamBMapsScore
			. ', round_score_a=' . $teamACurrentMapScore
			. ', round_score_b=' . $teamBCurrentMapScore
			. ', source=' . $updateSource
			. ', updated_by=' . $updatedBy
			. '.';
	}

	private function resolveSeriesControlBootstrapSource() {
		$envKeys = array(
			'PIXEL_CONTROL_VETO_DRAFT_DEFAULT_BEST_OF',
		);

		foreach ($envKeys as $envKey) {
			if ($this->hasRuntimeEnvValue($envKey)) {
				return SeriesControlCatalog::UPDATE_SOURCE_ENV;
			}
		}

		return SeriesControlCatalog::UPDATE_SOURCE_SETTING;
	}
}
