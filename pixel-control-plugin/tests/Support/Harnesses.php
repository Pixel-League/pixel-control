<?php
declare(strict_types=1);

namespace PixelControl\Tests\Support;

use ManiaControl\Players\Player;
use PixelControl\Domain\Admin\AdminControlIngressTrait;
use PixelControl\Domain\SeriesControl\SeriesControlDomainTrait;
use PixelControl\Domain\VetoDraft\VetoDraftAutostartTrait;
use PixelControl\Domain\VetoDraft\VetoDraftBootstrapTrait;
use PixelControl\Domain\VetoDraft\VetoDraftIngressTrait;
use PixelControl\Domain\VetoDraft\VetoDraftLifecycleTrait;
use PixelControl\VetoDraft\VetoDraftCatalog;

class AdminVetoNormalizationHarness {
	use AdminControlIngressTrait;
	use VetoDraftIngressTrait;

	public function parseAdminCommandRequest(array $chatCallback) {
		return $this->parseAdminControlCommandRequest($chatCallback);
	}

	public function normalizeAdminActionParameters($actionName, array $parameters) {
		return $this->normalizeActionParameters($actionName, $parameters);
	}

	public function parseVetoCommandRequest(array $chatCallback) {
		return $this->parseVetoDraftCommandRequest($chatCallback);
	}

	public function normalizePayload($data) {
		return $this->normalizeCommunicationPayload($data);
	}

	private function normalizeIdentifier($value, $fallback) {
		$normalized = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $value), '_'));
		if ($normalized === '') {
			return $fallback;
		}

		return $normalized;
	}
}

class VetoReadyLifecyclePermissionHarness {
	use VetoDraftAutostartTrait;
	use VetoDraftLifecycleTrait;
	use VetoDraftBootstrapTrait;

	public $maniaControl;
	public $vetoDraftEnabled = true;
	public $vetoDraftCoordinator;
	public $vetoDraftMapPoolService;

	public $vetoDraftCommandName = VetoDraftCatalog::DEFAULT_COMMAND;
	public $vetoDraftDefaultMode = VetoDraftCatalog::MODE_MATCHMAKING_VOTE;
	public $vetoDraftMatchmakingDurationSeconds = VetoDraftCatalog::DEFAULT_MATCHMAKING_DURATION_SECONDS;
	public $vetoDraftTournamentActionTimeoutSeconds = VetoDraftCatalog::DEFAULT_TOURNAMENT_ACTION_TIMEOUT_SECONDS;
	public $vetoDraftMatchmakingAutostartMinPlayers = VetoDraftCatalog::DEFAULT_MATCHMAKING_AUTOSTART_MIN_PLAYERS;
	public $vetoDraftMatchmakingReadyArmed = false;
	public $vetoDraftMatchmakingAutostartArmed = true;
	public $vetoDraftMatchmakingAutostartSuppressed = false;
	public $vetoDraftMatchmakingAutostartPending = null;
	public $vetoDraftMatchmakingAutostartLastCancellation = '';
	public $vetoDraftMatchmakingLifecycleContext = null;
	public $vetoDraftMatchmakingLifecycleLastSnapshot = null;
	public $vetoDraftMatchmakingLifecycleHistoryLimit = 20;
	public $vetoDraftLastAppliedSessionId = '';

	public function __construct($maniaControl, $vetoDraftCoordinator, $vetoDraftMapPoolService) {
		$this->maniaControl = $maniaControl;
		$this->vetoDraftCoordinator = $vetoDraftCoordinator;
		$this->vetoDraftMapPoolService = $vetoDraftMapPoolService;
	}

	public function armReadyGate($source) {
		return $this->armMatchmakingReadyGate($source);
	}

	public function startWithReadyGate($source, $timestamp, $durationSeconds, array $mapPool = array()) {
		return $this->startMatchmakingSessionWithReadyGate($source, $timestamp, $durationSeconds, $mapPool);
	}

	public function isReadyArmed() {
		return (bool) $this->vetoDraftMatchmakingReadyArmed;
	}

	public function getAutostartArmed() {
		return (bool) $this->vetoDraftMatchmakingAutostartArmed;
	}

	public function getAutostartSuppressed() {
		return (bool) $this->vetoDraftMatchmakingAutostartSuppressed;
	}

	public function setLifecycleContext(array $context) {
		$this->vetoDraftMatchmakingLifecycleContext = $context;
	}

	public function getLifecycleContext() {
		return $this->vetoDraftMatchmakingLifecycleContext;
	}

	public function getLifecycleLastSnapshot() {
		return $this->vetoDraftMatchmakingLifecycleLastSnapshot;
	}

	public function completeLifecycle($status, $reason, $source, array $details = array()) {
		$this->completeMatchmakingLifecycleContext($status, $reason, $source, $details);
	}

	public function resolveOverride(array $parameters, Player $player) {
		return $this->resolveVetoOverrideFlag($parameters, $player);
	}

	private function broadcastVetoDraftSessionOverview() {
		return;
	}

	private function syncVetoDraftTelemetryState() {
		return;
	}

	private function countConnectedHumanPlayersForVetoAutoStart() {
		return 0;
	}

	private function extractSourceCallback(array $callbackArguments) {
		return 'unknown';
	}

	private function resolveLifecycleVariant($sourceCallback, array $callbackArguments) {
		return 'unknown';
	}

	private function resolveCurrentLifecycleMapUid() {
		return '';
	}

	private function resolveLifecycleMapUidFromCallbackArguments(array $callbackArguments) {
		return '';
	}

	private function executeMatchmakingLifecycleStartMatchAction($source) {
		return array('success' => true, 'code' => 'stubbed_start_match');
	}

	private function executeMatchmakingLifecycleKickAllPlayersAction($source) {
		return array('success' => true, 'code' => 'stubbed_kick_all');
	}

	private function executeMatchmakingLifecycleMapChangeAction($source) {
		return array('success' => true, 'code' => 'stubbed_map_change');
	}

	private function executeMatchmakingLifecycleMarkMatchEndedAction($source) {
		return array('success' => true, 'code' => 'stubbed_match_end');
	}

	private function invokeMatchmakingLifecycleModeScriptEvent($methodName, array $arguments = array()) {
		return array('success' => true, 'code' => 'stubbed_mode_script_event');
	}

	private function invokeMatchmakingLifecycleModeScriptCommands($commands) {
		return array('success' => true, 'code' => 'stubbed_mode_commands');
	}

	private function invokeMatchmakingLifecycleWarmupStop($source) {
		return array('success' => true, 'code' => 'stubbed_warmup_stop');
	}

	private function logMatchmakingLifecycleAction($actionName, array $actionResult, array $context = array()) {
		return;
	}
}

class SeriesPersistenceHarness {
	use SeriesControlDomainTrait;

	const SETTING_VETO_DRAFT_DEFAULT_BEST_OF = 'Pixel Control Veto Draft Default Best Of';
	const SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_A = 'Pixel Control Series Maps Score Team A';
	const SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_B = 'Pixel Control Series Maps Score Team B';
	const SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_A = 'Pixel Control Series Current Map Score Team A';
	const SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_B = 'Pixel Control Series Current Map Score Team B';

	public $maniaControl;
	public $seriesControlState = null;
	public $vetoDraftDefaultBestOf = VetoDraftCatalog::DEFAULT_BEST_OF;

	public function __construct($maniaControl) {
		$this->maniaControl = $maniaControl;
	}

	public function persistSnapshot(array $seriesSnapshot, array $previousSnapshot = array()) {
		return $this->persistSeriesControlSnapshot($seriesSnapshot, $previousSnapshot);
	}
}
