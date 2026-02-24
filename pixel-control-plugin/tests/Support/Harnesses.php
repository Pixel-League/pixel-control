<?php
declare(strict_types=1);

namespace PixelControl\Tests\Support;

use ManiaControl\Players\Player;
use PixelControl\AccessControl\WhitelistCatalog;
use PixelControl\AccessControl\WhitelistState;
use PixelControl\Admin\AdminActionResult;
use PixelControl\Domain\AccessControl\AccessControlDomainTrait;
use PixelControl\Domain\Admin\AdminControlExecutionTrait;
use PixelControl\Domain\Admin\AdminControlIngressTrait;
use PixelControl\Domain\Connectivity\ConnectivityDomainTrait;
use PixelControl\Domain\SeriesControl\SeriesControlDomainTrait;
use PixelControl\Domain\VetoDraft\VetoDraftAutostartTrait;
use PixelControl\Domain\VetoDraft\VetoDraftBootstrapTrait;
use PixelControl\Domain\VetoDraft\VetoDraftIngressTrait;
use PixelControl\Domain\VetoDraft\VetoDraftLifecycleTrait;
use PixelControl\VetoDraft\VetoDraftCatalog;
use PixelControl\VoteControl\VotePolicyCatalog;
use PixelControl\VoteControl\VotePolicyState;

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

class AdminLinkAuthHarness {
	use AdminControlIngressTrait;

	const SETTING_LINK_SERVER_URL = 'Pixel Control Link Server URL';
	const SETTING_LINK_TOKEN = 'Pixel Control Link Token';

	public $maniaControl;
	public $adminControlEnabled = true;
	public $adminControlCommandName = 'pcadmin';
	public $apiClient = null;

	public $executeCalls = array();
	public $nextExecuteResult = null;

	public function __construct($maniaControl) {
		$this->maniaControl = $maniaControl;
	}

	public function runCommand(array $chatCallback, Player $player): void {
		$this->handleAdminControlCommand($chatCallback, $player);
	}

	public function runCommunicationExecute($payload) {
		return $this->handleAdminControlCommunicationExecute($payload);
	}

	public function runCommunicationList($payload) {
		return $this->handleAdminControlCommunicationList($payload);
	}

	private function executeDelegatedAdminAction($actionName, array $parameters, $actorLogin, $requestSource, $requestActor = null, array $requestOptions = array()) {
		$this->executeCalls[] = array(
			'action_name' => (string) $actionName,
			'parameters' => $parameters,
			'actor_login' => (string) $actorLogin,
			'request_source' => (string) $requestSource,
			'request_options' => $requestOptions,
		);

		if ($this->nextExecuteResult instanceof AdminActionResult) {
			return $this->nextExecuteResult;
		}

		return AdminActionResult::success((string) $actionName, 'Delegated action accepted by harness.');
	}

	private function buildWhitelistCapabilitySnapshot() {
		return array('enabled' => false);
	}

	private function getVotePolicySnapshot() {
		return array('mode' => 'strict');
	}

	private function buildTeamControlCapabilitySnapshot() {
		return array('enabled' => false, 'switch_lock' => false);
	}

	private function getSeriesControlSnapshot() {
		return array('best_of' => 3, 'maps_score' => array('team_a' => 0, 'team_b' => 0));
	}

	private function resolveRuntimeStringSetting($settingName, $environmentVariableName, $fallback) {
		$settingValue = $this->maniaControl->getSettingManager()->getSettingValue($this, (string) $settingName);
		$trimmedSettingValue = trim((string) $settingValue);
		if ($trimmedSettingValue !== '') {
			return $trimmedSettingValue;
		}

		return (string) $fallback;
	}

	private function hasRuntimeEnvValue($environmentVariableName) {
		return false;
	}

	private function normalizeIdentifier($value, $fallback) {
		$normalized = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $value), '_'));
		if ($normalized === '') {
			return $fallback;
		}

		return $normalized;
	}
}

class AccessControlSourceOfTruthHarness {
	use AccessControlDomainTrait;
	use ConnectivityDomainTrait;
	use AdminControlExecutionTrait;

	const ID = 100001;
	const VERSION = '0.1.0-dev';
	const ENVELOPE_SCHEMA_VERSION = '2026-02-20.1';
	const NAME = 'Pixel Control';
	const SETTING_WHITELIST_ENABLED = 'Pixel Control Whitelist Enabled';
	const SETTING_WHITELIST_LOGINS = 'Pixel Control Whitelist Logins';
	const SETTING_VOTE_POLICY_MODE = 'Pixel Control Vote Policy Mode';

	public $maniaControl;
	public $whitelistState = null;
	public $votePolicyState = null;
	public $whitelistRecentDeniedAt = array();
	public $whitelistDenyCooldownSeconds = 5;
	public $whitelistReconcileIntervalSeconds = 3;
	public $whitelistLastReconcileAt = 0;
	public $whitelistGuestListLastSyncHash = '';
	public $whitelistGuestListLastSyncAt = 0;
	public $votePolicyLastCallVoteTimeoutMs = 0;
	public $votePolicyStrictRuntimeApplied = false;
	public $eventQueue = null;
	public $callbackRegistry = null;
	public $apiClient = null;
	public $queueMaxSize = 2000;
	public $dispatchBatchSize = 3;
	public $queueGrowthLogStep = 100;
	public $heartbeatIntervalSeconds = 120;
	public $playerConstraintPolicyTtlSeconds = 20;
	public $adminControlEnabled = true;
	public $adminControlCommandName = 'pcadmin';
	public $adminControlPauseStateMaxAgeSeconds = 120;
	public $queuedConnectivityEnvelopes = array();

	public function __construct($maniaControl) {
		$this->maniaControl = $maniaControl;
		$this->whitelistState = new WhitelistState();
		$this->whitelistState->bootstrap(
			array(
				'enabled' => false,
				'logins' => array(),
			),
			WhitelistCatalog::UPDATE_SOURCE_CHAT,
			'harness_bootstrap'
		);

		$this->votePolicyState = new VotePolicyState();
		$this->votePolicyState->bootstrap(
			array('mode' => VotePolicyCatalog::DEFAULT_MODE),
			VotePolicyCatalog::UPDATE_SOURCE_CHAT,
			'harness_bootstrap'
		);
	}

	public function bootstrapWhitelist($enabled, array $logins, $updatedBy = 'harness') {
		$this->whitelistState->bootstrap(
			array(
				'enabled' => (bool) $enabled,
				'logins' => $logins,
			),
			WhitelistCatalog::UPDATE_SOURCE_CHAT,
			(string) $updatedBy
		);
	}

	public function runWhitelistSweep($source, $force = false) {
		return $this->reconcileWhitelistForConnectedPlayers($source, $force);
	}

	public function runAccessControlPolicyTick() {
		$this->handleAccessControlPolicyTick();
	}

	public function buildHeartbeatForTest() {
		return $this->buildHeartbeatPayload();
	}

	public function triggerCapabilityRefreshForAction($actionName) {
		$this->queueConnectivityCapabilityRefreshAfterAdminAction((string) $actionName, 'harness', 'operator');
	}

	private function enqueueEnvelope($eventCategory, $eventName, array $payload, array $metadata) {
		$this->queuedConnectivityEnvelopes[] = array(
			'event_category' => (string) $eventCategory,
			'event_name' => (string) $eventName,
			'payload' => $payload,
			'metadata' => $metadata,
		);
	}

	private function buildQueueTelemetrySnapshot() {
		return array();
	}

	private function buildRetryTelemetrySnapshot() {
		return array();
	}

	private function buildOutageTelemetrySnapshot() {
		return array();
	}

	private function countModeCallbackCount(array $modeCallbacks) {
		return 0;
	}

	private function buildAdminControlCapabilitiesPayload() {
		return array(
			'available' => true,
			'enabled' => true,
			'command' => 'pcadmin',
			'whitelist' => $this->buildWhitelistCapabilitySnapshot(),
			'vote_policy' => $this->getVotePolicySnapshot(),
			'team_control' => array(
				'policy_enabled' => false,
				'switch_lock_enabled' => false,
				'assignments' => array(),
				'assignment_count' => 0,
			),
			'series_targets' => array(
				'best_of' => 3,
				'maps_score' => array('team_a' => 0, 'team_b' => 0),
				'current_map_score' => array('team_a' => 0, 'team_b' => 0),
			),
		);
	}

	private function readEnvString($environmentVariableName, $fallback) {
		return (string) $fallback;
	}

	private function resolveRuntimeStringSetting($settingName, $environmentVariableName, $fallback) {
		return (string) $fallback;
	}

	private function resolveRuntimeBoolSetting($settingName, $environmentVariableName, $fallback) {
		return (bool) $fallback;
	}

	private function isRuntimeEnvDefined($environmentVariableName) {
		return false;
	}

	private function hasRuntimeEnvValue($environmentVariableName) {
		return false;
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
