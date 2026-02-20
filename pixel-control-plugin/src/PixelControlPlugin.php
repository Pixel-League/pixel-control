<?php

namespace PixelControl;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\Structures\ShootMania\OnCaptureStructure;
use ManiaControl\Callbacks\Structures\ShootMania\OnHitNearMissArmorEmptyBaseStructure;
use ManiaControl\Callbacks\Structures\ShootMania\OnHitStructure;
use ManiaControl\Callbacks\Structures\ShootMania\OnScoresStructure;
use ManiaControl\Callbacks\Structures\ShootMania\Models\Position;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Logger;
use ManiaControl\ManiaControl;
use ManiaControl\Maps\Map;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Plugins\PluginManager;
use ManiaControl\Players\Player;
use PixelControl\Api\AsyncPixelControlApiClient;
use PixelControl\Api\DeliveryError;
use PixelControl\Api\EventEnvelope;
use PixelControl\Api\PixelControlApiClientInterface;
use PixelControl\Callbacks\CallbackRegistry;
use PixelControl\Queue\EventQueueInterface;
use PixelControl\Queue\InMemoryEventQueue;
use PixelControl\Queue\QueueItem;
use PixelControl\Retry\ExponentialBackoffRetryPolicy;
use PixelControl\Retry\RetryPolicyInterface;
use PixelControl\Stats\PlayerCombatStatsStore;

class PixelControlPlugin implements CallbackListener, TimerListener, Plugin {
	const ID = 100001;
	const VERSION = '0.1.0-dev';
	const ENVELOPE_SCHEMA_VERSION = '2026-02-20.1';
	const NAME = 'Pixel Control';
	const AUTHOR = 'Pixel Control Team';
	const DESCRIPTION = 'First-party Pixel Control plugin skeleton for ManiaControl.';
	const SETTING_API_BASE_URL = 'Pixel Control API Base URL';
	const SETTING_API_EVENT_PATH = 'Pixel Control API Event Path';
	const SETTING_API_TIMEOUT_SECONDS = 'Pixel Control API Timeout Seconds';
	const SETTING_API_MAX_RETRY_ATTEMPTS = 'Pixel Control API Max Retry Attempts';
	const SETTING_API_RETRY_BACKOFF_MS = 'Pixel Control API Retry Backoff Ms';
	const SETTING_API_AUTH_MODE = 'Pixel Control API Auth Mode';
	const SETTING_API_AUTH_VALUE = 'Pixel Control API Auth Value';
	const SETTING_API_AUTH_HEADER = 'Pixel Control API Auth Header';
	const SETTING_QUEUE_MAX_SIZE = 'Pixel Control Queue Max Size';
	const SETTING_DISPATCH_BATCH_SIZE = 'Pixel Control Dispatch Batch Size';
	const SETTING_HEARTBEAT_INTERVAL_SECONDS = 'Pixel Control Heartbeat Interval Seconds';

	/** @var ManiaControl|null $maniaControl */
	private $maniaControl = null;
	/** @var CallbackRegistry|null $callbackRegistry */
	private $callbackRegistry = null;
	/** @var PixelControlApiClientInterface|null $apiClient */
	private $apiClient = null;
	/** @var EventQueueInterface|null $eventQueue */
	private $eventQueue = null;
	/** @var RetryPolicyInterface|null $retryPolicy */
	private $retryPolicy = null;
	/** @var PlayerCombatStatsStore|null $playerCombatStatsStore */
	private $playerCombatStatsStore = null;
	/** @var array $playerStateCache */
	private $playerStateCache = array();
	/** @var int $sourceSequence */
	private $sourceSequence = 0;
	/** @var int $queueMaxSize */
	private $queueMaxSize = 2000;
	/** @var int $dispatchBatchSize */
	private $dispatchBatchSize = 3;
	/** @var int $heartbeatIntervalSeconds */
	private $heartbeatIntervalSeconds = 15;
	/** @var int $queueHighWatermark */
	private $queueHighWatermark = 0;
	/** @var int $queueDropCount */
	private $queueDropCount = 0;
	/** @var int $queueIdentityDropCount */
	private $queueIdentityDropCount = 0;
	/** @var int $queueGrowthLogStep */
	private $queueGrowthLogStep = 100;
	/** @var bool $outageActive */
	private $outageActive = false;
	/** @var int $outageStartedAt */
	private $outageStartedAt = 0;
	/** @var int $outageFailureCount */
	private $outageFailureCount = 0;
	/** @var string $outageLastErrorCode */
	private $outageLastErrorCode = '';
	/** @var string $outageLastErrorMessage */
	private $outageLastErrorMessage = '';
	/** @var bool $recoveryFlushPending */
	private $recoveryFlushPending = false;
	/** @var int $recoverySuccessCount */
	private $recoverySuccessCount = 0;
	/** @var int $recoveryStartedAt */
	private $recoveryStartedAt = 0;
	/** @var array[] $recentAdminActionContexts */
	private $recentAdminActionContexts = array();
	/** @var int $adminCorrelationWindowSeconds */
	private $adminCorrelationWindowSeconds = 45;
	/** @var int $adminCorrelationHistoryLimit */
	private $adminCorrelationHistoryLimit = 32;
	/** @var array|null $roundAggregateBaseline */
	private $roundAggregateBaseline = null;
	/** @var int $roundAggregateStartedAt */
	private $roundAggregateStartedAt = 0;
	/** @var string $roundAggregateStartedBy */
	private $roundAggregateStartedBy = 'unknown';
	/** @var array|null $mapAggregateBaseline */
	private $mapAggregateBaseline = null;
	/** @var int $mapAggregateStartedAt */
	private $mapAggregateStartedAt = 0;
	/** @var string $mapAggregateStartedBy */
	private $mapAggregateStartedBy = 'unknown';
	/** @var array|null $latestScoresSnapshot */
	private $latestScoresSnapshot = null;
	/** @var array[] $playedMapHistory */
	private $playedMapHistory = array();
	/** @var int $playedMapHistoryLimit */
	private $playedMapHistoryLimit = 20;
	/** @var int $playerTransitionSequence */
	private $playerTransitionSequence = 0;
	/** @var array $playerSessionStateCache */
	private $playerSessionStateCache = array();
	/** @var array|null $playerConstraintPolicyCache */
	private $playerConstraintPolicyCache = null;
	/** @var int $playerConstraintPolicyCapturedAt */
	private $playerConstraintPolicyCapturedAt = 0;
	/** @var int $playerConstraintPolicyTtlSeconds */
	private $playerConstraintPolicyTtlSeconds = 20;
	/** @var int $playerConstraintPolicyErrorLogAt */
	private $playerConstraintPolicyErrorLogAt = 0;
	/** @var int $playerConstraintPolicyErrorCooldownSeconds */
	private $playerConstraintPolicyErrorCooldownSeconds = 30;
	/** @var array[] $vetoDraftActions */
	private $vetoDraftActions = array();
	/** @var int $vetoDraftActionLimit */
	private $vetoDraftActionLimit = 64;
	/** @var int $vetoDraftActionSequence */
	private $vetoDraftActionSequence = 0;

	/**
	 * @see \ManiaControl\Plugins\Plugin::prepare()
	 */
	public static function prepare(ManiaControl $maniaControl) {
		if (getenv('PIXEL_CONTROL_AUTO_ENABLE') !== '1') {
			return;
		}

		$mysqli = $maniaControl->getDatabase()->getMysqli();
		$query = "INSERT INTO `" . PluginManager::TABLE_PLUGINS . "` (`className`, `active`) VALUES (?, 1) ON DUPLICATE KEY UPDATE `active` = 1;";
		$statement = $mysqli->prepare($query);
		if ($mysqli->error || !$statement) {
			Logger::logError('[PixelControl] Failed to auto-enable plugin during prepare: ' . $mysqli->error);
			return;
		}

		$className = __CLASS__;
		$statement->bind_param('s', $className);
		$statement->execute();
		if ($statement->error) {
			Logger::logError('[PixelControl] Failed to persist plugin active state: ' . $statement->error);
			$statement->close();
			return;
		}

		$statement->close();
		Logger::log('[PixelControl] Auto-enable requested via PIXEL_CONTROL_AUTO_ENABLE=1.');
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::load()
	 */
	public function load(ManiaControl $maniaControl) {
		Logger::log('[PixelControl] Loading plugin v' . self::VERSION . '.');

		$this->maniaControl = $maniaControl;
		$this->initializeSettings();
		$this->initializeSourceSequence();
		$this->initializeEventPipeline();
		$this->callbackRegistry = new CallbackRegistry();
		$this->callbackRegistry->register($maniaControl, $this);
		$this->registerPeriodicTimers();
		$this->resolvePlayerConstraintPolicyContext(true);
		$this->queueConnectivityEvent('registration', $this->buildRegistrationPayload());
		$this->dispatchQueuedEvents();

		Logger::log(
			'[PixelControl] Callback groups registered: lifecycle=' . count($this->callbackRegistry->getLifecycleCallbacks())
			. ', lifecycle_script=' . count($this->callbackRegistry->getLifecycleScriptCallbacks())
			. ', player=' . count($this->callbackRegistry->getPlayerCallbacks())
			. ', combat=' . count($this->callbackRegistry->getCombatCallbacks())
			. ', mode=' . $this->countModeCallbackCount($this->callbackRegistry->getModeCallbacks())
		);
		Logger::log('[PixelControl] Plugin loaded.');

		return true;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::unload()
	 */
	public function unload() {
		Logger::log('[PixelControl] Unloading plugin.');

		$this->retryPolicy = null;
		$this->eventQueue = null;
		$this->apiClient = null;
		$this->callbackRegistry = null;
		$this->playerCombatStatsStore = null;
		$this->playerStateCache = array();
		$this->recentAdminActionContexts = array();
		$this->roundAggregateBaseline = null;
		$this->roundAggregateStartedAt = 0;
		$this->roundAggregateStartedBy = 'unknown';
		$this->mapAggregateBaseline = null;
		$this->mapAggregateStartedAt = 0;
		$this->mapAggregateStartedBy = 'unknown';
		$this->latestScoresSnapshot = null;
		$this->playedMapHistory = array();
		$this->playerTransitionSequence = 0;
		$this->playerSessionStateCache = array();
		$this->playerConstraintPolicyCache = null;
		$this->playerConstraintPolicyCapturedAt = 0;
		$this->playerConstraintPolicyErrorLogAt = 0;
		$this->vetoDraftActions = array();
		$this->vetoDraftActionSequence = 0;
		$this->heartbeatIntervalSeconds = 15;
		$this->resetDeliveryTelemetry();
		$this->maniaControl = null;

		Logger::log('[PixelControl] Plugin unloaded.');
	}

	/**
	 * Stub callback handler for match lifecycle events.
	 */
	public function handleLifecycleCallback(...$callbackArguments) {
		$this->queueCallbackEvent('lifecycle', $callbackArguments);
	}

	/**
	 * Stub callback handler for player state events.
	 */
	public function handlePlayerCallback(...$callbackArguments) {
		$this->queueCallbackEvent('player', $callbackArguments);
	}

	/**
	 * Stub callback handler for combat and score events.
	 */
	public function handleCombatCallback(...$callbackArguments) {
		$this->queueCallbackEvent('combat', $callbackArguments);
	}

	/**
	 * Stub callback handler for mode-specific events.
	 */
	public function handleModeCallback(...$callbackArguments) {
		$this->queueCallbackEvent('mode', $callbackArguments);
	}

	/**
	 * Periodic dispatch tick to flush retry-ready envelopes.
	 */
	public function handleDispatchTimerTick() {
		$this->dispatchQueuedEvents();
	}

	/**
	 * Periodic heartbeat for liveness/capability reporting.
	 */
	public function handleHeartbeatTimerTick() {
		$this->resolvePlayerConstraintPolicyContext(true);
		$this->queueConnectivityEvent('heartbeat', $this->buildHeartbeatPayload());
		$this->dispatchQueuedEvents();
	}

	/**
	 * Initialize plugin settings with secure local-first defaults.
	 */
	private function initializeSettings() {
		$settingManager = $this->maniaControl->getSettingManager();
		$settingManager->initSetting($this, self::SETTING_API_BASE_URL, $this->readEnvString('PIXEL_CONTROL_API_BASE_URL', 'http://127.0.0.1:8080'));
		$settingManager->initSetting($this, self::SETTING_API_EVENT_PATH, $this->readEnvString('PIXEL_CONTROL_API_EVENT_PATH', '/plugin/events'));
		$settingManager->initSetting($this, self::SETTING_API_TIMEOUT_SECONDS, $this->resolveRuntimeIntSetting(self::SETTING_API_TIMEOUT_SECONDS, 'PIXEL_CONTROL_API_TIMEOUT_SECONDS', 5, 1));
		$settingManager->initSetting($this, self::SETTING_API_MAX_RETRY_ATTEMPTS, $this->resolveRuntimeIntSetting(self::SETTING_API_MAX_RETRY_ATTEMPTS, 'PIXEL_CONTROL_API_MAX_RETRY_ATTEMPTS', 3, 1));
		$settingManager->initSetting($this, self::SETTING_API_RETRY_BACKOFF_MS, $this->resolveRuntimeIntSetting(self::SETTING_API_RETRY_BACKOFF_MS, 'PIXEL_CONTROL_API_RETRY_BACKOFF_MS', 250, 0));
		$settingManager->initSetting($this, self::SETTING_API_AUTH_MODE, $this->readEnvString('PIXEL_CONTROL_AUTH_MODE', 'none'));
		$settingManager->initSetting($this, self::SETTING_API_AUTH_VALUE, $this->readEnvString('PIXEL_CONTROL_AUTH_VALUE', ''));
		$settingManager->initSetting($this, self::SETTING_API_AUTH_HEADER, $this->readEnvString('PIXEL_CONTROL_AUTH_HEADER', 'X-Pixel-Control-Api-Key'));
		$settingManager->initSetting($this, self::SETTING_QUEUE_MAX_SIZE, $this->resolveRuntimeIntSetting(self::SETTING_QUEUE_MAX_SIZE, 'PIXEL_CONTROL_QUEUE_MAX_SIZE', 2000, 1));
		$settingManager->initSetting($this, self::SETTING_DISPATCH_BATCH_SIZE, $this->resolveRuntimeIntSetting(self::SETTING_DISPATCH_BATCH_SIZE, 'PIXEL_CONTROL_DISPATCH_BATCH_SIZE', 3, 1));
		$settingManager->initSetting($this, self::SETTING_HEARTBEAT_INTERVAL_SECONDS, $this->resolveRuntimeIntSetting(self::SETTING_HEARTBEAT_INTERVAL_SECONDS, 'PIXEL_CONTROL_HEARTBEAT_INTERVAL_SECONDS', 15, 1));
	}

	/**
	 * Build the local queue + async transport shell.
	 */
	private function initializeEventPipeline() {
		$baseUrl = $this->resolveRuntimeStringSetting(self::SETTING_API_BASE_URL, 'PIXEL_CONTROL_API_BASE_URL', 'http://127.0.0.1:8080');
		$eventPath = $this->resolveRuntimeStringSetting(self::SETTING_API_EVENT_PATH, 'PIXEL_CONTROL_API_EVENT_PATH', '/plugin/events');
		$timeoutSeconds = $this->resolveRuntimeIntSetting(self::SETTING_API_TIMEOUT_SECONDS, 'PIXEL_CONTROL_API_TIMEOUT_SECONDS', 5, 1);
		$maxRetryAttempts = $this->resolveRuntimeIntSetting(self::SETTING_API_MAX_RETRY_ATTEMPTS, 'PIXEL_CONTROL_API_MAX_RETRY_ATTEMPTS', 3, 1);
		$retryBackoffMs = $this->resolveRuntimeIntSetting(self::SETTING_API_RETRY_BACKOFF_MS, 'PIXEL_CONTROL_API_RETRY_BACKOFF_MS', 250, 0);
		$authMode = $this->resolveRuntimeStringSetting(self::SETTING_API_AUTH_MODE, 'PIXEL_CONTROL_AUTH_MODE', 'none');
		$authValue = $this->resolveRuntimeStringSetting(self::SETTING_API_AUTH_VALUE, 'PIXEL_CONTROL_AUTH_VALUE', '');
		$authHeader = $this->resolveRuntimeStringSetting(self::SETTING_API_AUTH_HEADER, 'PIXEL_CONTROL_AUTH_HEADER', 'X-Pixel-Control-Api-Key');
		$this->queueMaxSize = $this->resolveRuntimeIntSetting(self::SETTING_QUEUE_MAX_SIZE, 'PIXEL_CONTROL_QUEUE_MAX_SIZE', 2000, 1);
		$this->dispatchBatchSize = $this->resolveRuntimeIntSetting(self::SETTING_DISPATCH_BATCH_SIZE, 'PIXEL_CONTROL_DISPATCH_BATCH_SIZE', 3, 1);
		$this->heartbeatIntervalSeconds = $this->resolveRuntimeIntSetting(self::SETTING_HEARTBEAT_INTERVAL_SECONDS, 'PIXEL_CONTROL_HEARTBEAT_INTERVAL_SECONDS', 15, 1);
		$this->queueGrowthLogStep = max(10, (int) floor($this->queueMaxSize / 10));
		$retryBackoffSeconds = max(0, (int) ceil($retryBackoffMs / 1000));

		$this->apiClient = new AsyncPixelControlApiClient($this->maniaControl, $baseUrl, $eventPath);
		$this->apiClient->setTimeoutSeconds($timeoutSeconds);
		$this->apiClient->setMaxRetryAttempts($maxRetryAttempts);
		$this->apiClient->setRetryBackoffMs($retryBackoffMs);
		$this->apiClient->setAuthMode($authMode);
		$this->apiClient->setAuthValue($authValue);
		$this->apiClient->setAuthHeader($authHeader);

		$this->eventQueue = new InMemoryEventQueue();
		$this->retryPolicy = new ExponentialBackoffRetryPolicy($maxRetryAttempts, $retryBackoffSeconds);
		$this->playerCombatStatsStore = new PlayerCombatStatsStore();
		$this->playerStateCache = array();
		$this->recentAdminActionContexts = array();
		$this->roundAggregateBaseline = null;
		$this->roundAggregateStartedAt = 0;
		$this->roundAggregateStartedBy = 'unknown';
		$this->mapAggregateBaseline = null;
		$this->mapAggregateStartedAt = 0;
		$this->mapAggregateStartedBy = 'unknown';
		$this->latestScoresSnapshot = null;
		$this->playedMapHistory = array();
		$this->playerTransitionSequence = 0;
		$this->playerSessionStateCache = array();
		$this->playerConstraintPolicyCache = null;
		$this->playerConstraintPolicyCapturedAt = 0;
		$this->playerConstraintPolicyErrorLogAt = 0;
		$this->vetoDraftActions = array();
		$this->vetoDraftActionSequence = 0;
		$this->resetDeliveryTelemetry();

		Logger::log(
			'[PixelControl] Runtime config: base_url=' . $baseUrl
			. ', event_path=' . $eventPath
			. ', timeout=' . $timeoutSeconds
			. ', max_retry=' . $maxRetryAttempts
			. ', retry_backoff_ms=' . $retryBackoffMs
			. ', queue_max=' . $this->queueMaxSize
			. ', dispatch_batch=' . $this->dispatchBatchSize
			. ', heartbeat_interval=' . $this->heartbeatIntervalSeconds
			. ', auth_mode=' . $this->apiClient->getAuthMode()
			. ($this->apiClient->getAuthMode() === 'api_key' ? ', auth_header=' . $this->apiClient->getAuthHeader() : '')
			. '.'
		);
	}

	/**
	 * Register periodic timers for queue dispatch + heartbeat signals.
	 */
	private function registerPeriodicTimers() {
		if (!$this->maniaControl) {
			return;
		}

		$timerManager = $this->maniaControl->getTimerManager();
		$timerManager->registerTimerListening($this, 'handleDispatchTimerTick', 1000);
		$timerManager->registerTimerListening($this, 'handleHeartbeatTimerTick', $this->heartbeatIntervalSeconds * 1000);

		Logger::log('[PixelControl] Timers registered: dispatch=1s, heartbeat=' . $this->heartbeatIntervalSeconds . 's.');
	}

	/**
	 * @param string $settingName
	 * @param string $environmentVariableName
	 * @param string $fallback
	 * @return string
	 */
	private function resolveRuntimeStringSetting($settingName, $environmentVariableName, $fallback) {
		$envValue = $this->readEnvString($environmentVariableName, '');
		if ($envValue !== '') {
			return $envValue;
		}

		$settingValue = (string) $this->maniaControl->getSettingManager()->getSettingValue($this, $settingName);
		$settingValue = trim($settingValue);
		if ($settingValue === '') {
			return $fallback;
		}

		return $settingValue;
	}

	/**
	 * @param string $settingName
	 * @param string $environmentVariableName
	 * @param int    $fallback
	 * @param int    $minimum
	 * @return int
	 */
	private function resolveRuntimeIntSetting($settingName, $environmentVariableName, $fallback, $minimum) {
		$envValue = $this->readEnvString($environmentVariableName, '');
		if ($envValue !== '' && is_numeric($envValue)) {
			return max($minimum, (int) $envValue);
		}

		$settingValue = $this->maniaControl->getSettingManager()->getSettingValue($this, $settingName);
		if (is_numeric($settingValue)) {
			return max($minimum, (int) $settingValue);
		}

		return max($minimum, (int) $fallback);
	}

	/**
	 * @param string $environmentVariableName
	 * @param string $fallback
	 * @return string
	 */
	private function readEnvString($environmentVariableName, $fallback) {
		$rawValue = getenv($environmentVariableName);
		if ($rawValue === false) {
			return $fallback;
		}

		$trimmedValue = trim((string) $rawValue);
		if ($trimmedValue === '') {
			return $fallback;
		}

		return $trimmedValue;
	}

	/**
	 * Initialize a monotonic local sequence cursor for outbound envelopes.
	 */
	private function initializeSourceSequence() {
		$seed = (int) floor(microtime(true) * 1000);
		$this->sourceSequence = max(0, $seed);

		Logger::log('[PixelControl] Monotonic source sequence initialized at ' . $this->sourceSequence . '.');
	}

	/**
	 * @return int
	 */
	private function nextSourceSequence() {
		$this->sourceSequence++;
		return $this->sourceSequence;
	}

	/**
	 * @param string $eventName
	 * @param array  $payload
	 */
	private function queueConnectivityEvent($eventName, array $payload) {
		$metadata = array(
			'plugin_version' => self::VERSION,
			'schema_version' => self::ENVELOPE_SCHEMA_VERSION,
			'mode_family' => 'multi-mode',
			'signal_kind' => 'connectivity',
			'context' => $this->buildRuntimeContextSnapshot(),
			'queue' => $this->buildQueueTelemetrySnapshot(),
			'retry' => $this->buildRetryTelemetrySnapshot(),
			'outage' => $this->buildOutageTelemetrySnapshot(),
		);

		$this->enqueueEnvelope('connectivity', 'plugin.' . $eventName, $payload, $metadata);
	}

	/**
	 * @return array
	 */
	private function buildRegistrationPayload() {
		return array(
			'type' => 'plugin_registration',
			'plugin' => array(
				'id' => self::ID,
				'name' => self::NAME,
				'version' => self::VERSION,
			),
			'capabilities' => $this->buildCapabilitiesPayload(),
			'context' => $this->buildRuntimeContextSnapshot(),
			'timestamp' => time(),
		);
	}

	/**
	 * @return array
	 */
	private function buildHeartbeatPayload() {
		return array(
			'type' => 'plugin_heartbeat',
			'queue_depth' => $this->eventQueue ? $this->eventQueue->count() : 0,
			'queue' => $this->buildQueueTelemetrySnapshot(),
			'retry' => $this->buildRetryTelemetrySnapshot(),
			'outage' => $this->buildOutageTelemetrySnapshot(),
			'context' => $this->buildRuntimeContextSnapshot(),
			'timestamp' => time(),
		);
	}

	/**
	 * @return array
	 */
	private function buildCapabilitiesPayload() {
		$capabilities = array(
			'event_envelope' => true,
			'schema_version' => self::ENVELOPE_SCHEMA_VERSION,
			'deterministic_event_identity' => true,
			'idempotency_key_validation' => true,
			'monotonic_source_sequence' => true,
			'async_delivery' => true,
			'local_retry_queue' => true,
			'outage_observability' => true,
			'periodic_heartbeat' => true,
			'player_constraint_policy_signals' => true,
			'player_constraint_policy_cache_ttl_seconds' => $this->playerConstraintPolicyTtlSeconds,
			'callback_groups' => array(
				'lifecycle' => $this->callbackRegistry ? count($this->callbackRegistry->getLifecycleCallbacks()) : 0,
				'lifecycle_script' => $this->callbackRegistry ? count($this->callbackRegistry->getLifecycleScriptCallbacks()) : 0,
				'player' => $this->callbackRegistry ? count($this->callbackRegistry->getPlayerCallbacks()) : 0,
				'combat' => $this->callbackRegistry ? count($this->callbackRegistry->getCombatCallbacks()) : 0,
				'mode' => $this->callbackRegistry ? $this->countModeCallbackCount($this->callbackRegistry->getModeCallbacks()) : 0,
			),
			'transport' => array(
				'timeout_seconds' => $this->apiClient ? $this->apiClient->getTimeoutSeconds() : 0,
				'max_retry_attempts' => $this->apiClient ? $this->apiClient->getMaxRetryAttempts() : 0,
				'retry_backoff_ms' => $this->apiClient ? $this->apiClient->getRetryBackoffMs() : 0,
				'auth_mode' => $this->apiClient ? $this->apiClient->getAuthMode() : 'none',
			),
			'queue' => array(
				'max_size' => $this->queueMaxSize,
				'dispatch_batch_size' => $this->dispatchBatchSize,
				'growth_log_step' => $this->queueGrowthLogStep,
			),
		);

		if ($this->heartbeatIntervalSeconds > 0) {
			$capabilities['heartbeat_interval_seconds'] = $this->heartbeatIntervalSeconds;
		}

		return $capabilities;
	}

	/**
	 * @return array
	 */
	private function buildServerSnapshot() {
		$snapshot = array();

		if (!$this->maniaControl) {
			return $snapshot;
		}

		$server = $this->maniaControl->getServer();
		$snapshot['login'] = isset($server->login) ? (string) $server->login : '';
		$snapshot['title_id'] = isset($server->titleId) ? (string) $server->titleId : '';
		$snapshot['game_port'] = isset($server->port) ? (int) $server->port : 0;
		$snapshot['p2p_port'] = isset($server->p2pPort) ? (int) $server->p2pPort : 0;
		$snapshot['name'] = $this->readEnvString('PIXEL_SM_SERVER_NAME', '');
		$snapshot['game_mode'] = $this->readEnvString('PIXEL_SM_MODE', '');
		$snapshot['configured_mode'] = $this->readEnvString('PIXEL_SM_MODE', '');
		$snapshot['configured_matchsettings'] = $this->readEnvString('PIXEL_SM_MATCHSETTINGS', '');
		$snapshot['configured_title_pack'] = $this->readEnvString('PIXEL_SM_TITLE_PACK', '');

		$snapshot['current_map'] = $this->buildCurrentMapSnapshot();

		return $snapshot;
	}

	/**
	 * @return array
	 */
	private function buildCurrentMapSnapshot() {
		if (!$this->maniaControl) {
			return array();
		}

		$currentMap = $this->maniaControl->getMapManager()->getCurrentMap();
		if (!$currentMap) {
			return array();
		}

		$snapshot = array(
			'uid' => isset($currentMap->uid) ? (string) $currentMap->uid : '',
			'name' => isset($currentMap->name) ? (string) $currentMap->name : '',
			'file' => isset($currentMap->fileName) ? (string) $currentMap->fileName : '',
			'environment' => isset($currentMap->environment) ? (string) $currentMap->environment : '',
			'map_type' => isset($currentMap->mapType) ? (string) $currentMap->mapType : '',
			'external_ids' => null,
		);

		if (isset($currentMap->mx) && is_object($currentMap->mx) && isset($currentMap->mx->id) && is_numeric($currentMap->mx->id)) {
			$snapshot['external_ids'] = array('mx_id' => (int) $currentMap->mx->id);
		}

		return $snapshot;
	}

	/**
	 * @return array
	 */
	private function buildPlayerSnapshot() {
		if (!$this->maniaControl) {
			return array();
		}

		return array(
			'active' => $this->maniaControl->getPlayerManager()->getPlayerCount(true, true),
			'total' => $this->maniaControl->getPlayerManager()->getPlayerCount(false, false),
			'spectators' => $this->maniaControl->getPlayerManager()->getSpectatorCount(),
		);
	}

	/**
	 * @return array
	 */
	private function buildRuntimeContextSnapshot() {
		return array(
			'server' => $this->buildServerSnapshot(),
			'players' => $this->buildPlayerSnapshot(),
		);
	}

	/**
	 * @param string $eventCategory
	 * @param array  $callbackArguments
	 */
	private function queueCallbackEvent($eventCategory, array $callbackArguments) {
		$sourceCallback = $this->extractSourceCallback($callbackArguments);
		$payload = $this->buildCallbackPayload($eventCategory, $sourceCallback, $callbackArguments);
		$metadata = $this->buildEnvelopeMetadata($eventCategory, $sourceCallback, $payload);

		$enqueuedEnvelope = $this->enqueueEnvelope($eventCategory, $sourceCallback, $payload, $metadata);
		if ($eventCategory === 'lifecycle') {
			$this->trackRecentAdminActionContext($sourceCallback, $payload, $enqueuedEnvelope);
		}

		$this->dispatchQueuedEvents();
	}

	/**
	 * @param string $eventCategory
	 * @param string $sourceCallback
	 * @param array  $callbackArguments
	 * @return array
	 */
	private function buildCallbackPayload($eventCategory, $sourceCallback, array $callbackArguments) {
		if ($eventCategory === 'lifecycle') {
			return $this->buildLifecyclePayload($sourceCallback, $callbackArguments);
		}

		if ($eventCategory === 'player') {
			return $this->buildPlayerPayload($sourceCallback, $callbackArguments);
		}

		if ($eventCategory === 'combat') {
			return $this->buildCombatPayload($sourceCallback, $callbackArguments);
		}

		return $this->buildPayloadSummary($callbackArguments);
	}

	/**
	 * @param string $eventCategory
	 * @param string $sourceCallback
	 * @param array  $payload
	 * @return array
	 */
	private function buildEnvelopeMetadata($eventCategory, $sourceCallback, array $payload = array()) {
		$metadata = array(
			'plugin_version' => self::VERSION,
			'schema_version' => self::ENVELOPE_SCHEMA_VERSION,
			'mode_family' => 'multi-mode',
			'signal_kind' => 'callback',
		);

		if ($eventCategory === 'lifecycle') {
			$metadata['lifecycle_variant'] = isset($payload['variant'])
				? (string) $payload['variant']
				: $this->resolveLifecycleVariant($sourceCallback, array());
			$metadata['context'] = $this->buildRuntimeContextSnapshot();

			$adminAction = null;
			if (isset($payload['admin_action']) && is_array($payload['admin_action'])) {
				$adminAction = $payload['admin_action'];
			} else {
				$adminAction = $this->buildAdminActionPayload($sourceCallback, array());
			}

			if ($adminAction !== null) {
				$metadata['admin_action_name'] = $adminAction['action_name'];
				$metadata['admin_action_target'] = $adminAction['target'];
				$metadata['admin_action_domain'] = $adminAction['action_domain'];
				$metadata['admin_action_type'] = $adminAction['action_type'];
				$metadata['admin_action_phase'] = $adminAction['action_phase'];
				$metadata['admin_action_target_scope'] = $adminAction['target_scope'];
				$metadata['admin_action_target_id'] = $adminAction['target_id'];
				$metadata['admin_action_initiator_kind'] = $adminAction['initiator_kind'];
			}

			if (isset($payload['aggregate_stats']) && is_array($payload['aggregate_stats'])) {
				$metadata['stats_aggregate_scope'] = isset($payload['aggregate_stats']['scope']) ? (string) $payload['aggregate_stats']['scope'] : 'unknown';
				$metadata['stats_counter_scope'] = isset($payload['aggregate_stats']['counter_scope']) ? (string) $payload['aggregate_stats']['counter_scope'] : 'unknown';
				if (isset($payload['aggregate_stats']['team_summary']['team_count'])) {
					$metadata['stats_team_count'] = (int) $payload['aggregate_stats']['team_summary']['team_count'];
				}
				if (isset($payload['aggregate_stats']['win_context']['result_state'])) {
					$metadata['stats_win_result'] = (string) $payload['aggregate_stats']['win_context']['result_state'];
				}
			}

			if (isset($payload['map_rotation']) && is_array($payload['map_rotation'])) {
				$metadata['map_rotation_snapshot'] = 'included';
				$metadata['map_pool_size'] = isset($payload['map_rotation']['map_pool_size']) ? (int) $payload['map_rotation']['map_pool_size'] : 0;
				if (isset($payload['map_rotation']['veto_draft_actions']['status'])) {
					$metadata['veto_draft_status'] = (string) $payload['map_rotation']['veto_draft_actions']['status'];
				}
				if (isset($payload['map_rotation']['veto_draft_actions']['action_count'])) {
					$metadata['veto_draft_action_count'] = (int) $payload['map_rotation']['veto_draft_actions']['action_count'];
				}
				if (isset($payload['map_rotation']['veto_result']['status'])) {
					$metadata['veto_result_status'] = (string) $payload['map_rotation']['veto_result']['status'];
				}
			}
		}

		if ($eventCategory === 'player') {
			if (isset($payload['event_kind'])) {
				$metadata['player_event_kind'] = $payload['event_kind'];
			}

			if (isset($payload['transition_kind'])) {
				$metadata['player_transition_kind'] = $payload['transition_kind'];
			}

			if (isset($payload['permission_signals']) && is_array($payload['permission_signals'])) {
				$metadata['player_eligibility_state'] = isset($payload['permission_signals']['eligibility_state']) ? (string) $payload['permission_signals']['eligibility_state'] : 'unknown';
				$metadata['player_readiness_state'] = isset($payload['permission_signals']['readiness_state']) ? (string) $payload['permission_signals']['readiness_state'] : 'unknown';
			}

			if (isset($payload['admin_correlation']) && is_array($payload['admin_correlation'])) {
				$metadata['player_admin_correlation'] = (!empty($payload['admin_correlation']['correlated']) ? 'linked' : 'none');
				if (!empty($payload['admin_correlation']['correlated']) && isset($payload['admin_correlation']['admin_event']['action_name'])) {
					$metadata['player_admin_action_name'] = (string) $payload['admin_correlation']['admin_event']['action_name'];
				}
			}

			if (isset($payload['reconnect_continuity']) && is_array($payload['reconnect_continuity'])) {
				$metadata['player_reconnect_state'] = isset($payload['reconnect_continuity']['transition_state'])
					? (string) $payload['reconnect_continuity']['transition_state']
					: 'unknown';
				$metadata['player_session_id'] = isset($payload['reconnect_continuity']['session_id'])
					? (string) $payload['reconnect_continuity']['session_id']
					: '';
			}

			if (isset($payload['side_change']) && is_array($payload['side_change'])) {
				$metadata['player_side_change'] = !empty($payload['side_change']['detected']) ? 'detected' : 'none';
				$metadata['player_side_change_kind'] = isset($payload['side_change']['transition_kind'])
					? (string) $payload['side_change']['transition_kind']
					: 'unknown';
			}

			if (isset($payload['constraint_signals']) && is_array($payload['constraint_signals'])) {
				if (
					isset($payload['constraint_signals']['forced_team_policy'])
					&& is_array($payload['constraint_signals']['forced_team_policy'])
				) {
					$metadata['player_forced_team_policy_state'] = isset($payload['constraint_signals']['forced_team_policy']['policy_state'])
						? (string) $payload['constraint_signals']['forced_team_policy']['policy_state']
						: 'unknown';
				}

				if (
					isset($payload['constraint_signals']['slot_policy'])
					&& is_array($payload['constraint_signals']['slot_policy'])
				) {
					$metadata['player_slot_policy_state'] = isset($payload['constraint_signals']['slot_policy']['policy_state'])
						? (string) $payload['constraint_signals']['slot_policy']['policy_state']
						: 'unknown';
				}

				if (
					isset($payload['constraint_signals']['policy_context'])
					&& is_array($payload['constraint_signals']['policy_context'])
				) {
					$metadata['player_constraint_policy_available'] = !empty($payload['constraint_signals']['policy_context']['available'])
						? 'yes'
						: 'no';
				}
			}
		}

		if ($eventCategory === 'combat') {
			$metadata['stats_snapshot'] = 'player_combat_runtime';
		}

		return $metadata;
	}

	/**
	 * @param string $sourceCallback
	 * @param array  $callbackArguments
	 * @return array
	 */
	private function buildLifecyclePayload($sourceCallback, array $callbackArguments) {
		$variant = $this->resolveLifecycleVariant($sourceCallback, $callbackArguments);
		$variantParts = explode('.', $variant, 2);
		$isScriptLifecycle = $this->isScriptLifecycleCallback($sourceCallback);

		if ($variant === 'match.begin') {
			$this->resetVetoDraftActions();
		}

		$payload = array(
			'variant' => $variant,
			'phase' => isset($variantParts[0]) ? $variantParts[0] : 'lifecycle',
			'state' => isset($variantParts[1]) ? $variantParts[1] : 'unknown',
			'source_channel' => $isScriptLifecycle ? 'script' : 'maniaplanet',
			'raw_source_callback' => $sourceCallback,
			'raw_callback_summary' => $this->buildPayloadSummary($callbackArguments),
		);

		if ($isScriptLifecycle) {
			$payload['script_callback'] = $this->extractScriptLifecycleSnapshot($callbackArguments);
		}

		$adminAction = $this->buildAdminActionPayload($sourceCallback, $callbackArguments);
		if ($adminAction !== null) {
			$payload['admin_action'] = $adminAction;
		}

		$this->recordVetoDraftActionFromLifecycle($variant, $sourceCallback, $callbackArguments, $adminAction);

		$aggregateStats = $this->buildLifecycleAggregateTelemetry($variant, $sourceCallback);
		if ($aggregateStats !== null) {
			$payload['aggregate_stats'] = $aggregateStats;
		}

		$mapRotation = $this->buildLifecycleMapRotationTelemetry($variant, $sourceCallback);
		if ($mapRotation !== null) {
			$payload['map_rotation'] = $mapRotation;
		}

		return $payload;
	}

	/**
	 * @param string $sourceCallback
	 * @param array  $callbackArguments
	 * @return array|null
	 */
	private function buildAdminActionPayload($sourceCallback, array $callbackArguments = array()) {
		$scriptPayload = $this->extractScriptCallbackPayload($callbackArguments);
		$actionDefinition = $this->resolveAdminActionDefinition($sourceCallback, $scriptPayload);
		if ($actionDefinition === null) {
			return null;
		}

		$actor = $this->extractActorSnapshotFromPayload($scriptPayload);
		$targetIdBundle = $this->resolveAdminActionTargetId($actionDefinition, $scriptPayload);
		$initiatorKind = $this->resolveAdminActionInitiatorKind($sourceCallback, $actor, $scriptPayload);

		$fieldAvailability = array(
			'actor' => isset($actor['type']) && $actor['type'] !== 'unknown',
			'action_domain' => $actionDefinition['action_domain'] !== 'unknown',
			'action_type' => $actionDefinition['action_type'] !== 'unknown',
			'target_scope' => $actionDefinition['target_scope'] !== 'unknown',
			'target_id' => $targetIdBundle['available'],
			'initiator_kind' => $initiatorKind !== 'unknown',
			'response_id' => isset($scriptPayload['responseid']),
			'count' => isset($scriptPayload['count']),
			'time' => isset($scriptPayload['time']),
			'restarted' => isset($scriptPayload['restarted']),
			'map' => isset($scriptPayload['map']),
			'active' => isset($scriptPayload['active']),
			'available' => isset($scriptPayload['available']),
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		$payload = array(
			'action_name' => $actionDefinition['action_name'],
			'action_domain' => $actionDefinition['action_domain'],
			'action_type' => $actionDefinition['action_type'],
			'action_phase' => $actionDefinition['action_phase'],
			'target' => $actionDefinition['target'],
			'target_scope' => $actionDefinition['target_scope'],
			'target_id' => $targetIdBundle['value'],
			'initiator_kind' => $initiatorKind,
			'source_callback' => $sourceCallback,
			'source_channel' => $this->isScriptLifecycleCallback($sourceCallback) ? 'script' : 'maniaplanet',
			'actor' => $actor,
			'context' => $this->buildRuntimeContextSnapshot(),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);

		if (
			isset($actionDefinition['action_type'])
			&& (string) $actionDefinition['action_type'] === 'pause'
		) {
			$payload['context']['pause_request'] = $this->buildPauseRequestContext($scriptPayload, $actor);
		}

		if (!empty($scriptPayload)) {
			$payload['script_payload'] = $scriptPayload;
		}

		return $payload;
	}

	/**
	 * @param string $sourceCallback
	 * @return array|null
	 */
	private function resolveAdminActionDefinition($sourceCallback, array $scriptPayload = array()) {
		if (!$this->isScriptLifecycleCallback($sourceCallback)) {
			return null;
		}

		$normalizedSourceCallback = $this->normalizeIdentifier($sourceCallback, 'unknown');
		if ($normalizedSourceCallback === 'maniaplanet_pause_status') {
			$pauseActive = $this->extractBooleanPayloadValue($scriptPayload, array('active'));
			$actionName = 'pause.status';
			$actionPhase = 'status';

			if ($pauseActive === true) {
				$actionName = 'pause.start';
				$actionPhase = 'start';
			} elseif ($pauseActive === false) {
				$actionName = 'pause.end';
				$actionPhase = 'end';
			}

			return array(
				'action_name' => $actionName,
				'action_domain' => 'match_flow',
				'action_type' => 'pause',
				'action_phase' => $actionPhase,
				'target' => 'pause',
				'target_scope' => 'match',
			);
		}

		$definitions = array(
			'maniaplanet_warmup_start' => array('action_name' => 'warmup.start', 'action_domain' => 'match_flow', 'action_type' => 'warmup', 'action_phase' => 'start', 'target' => 'warmup', 'target_scope' => 'server'),
			'maniaplanet_warmup_end' => array('action_name' => 'warmup.end', 'action_domain' => 'match_flow', 'action_type' => 'warmup', 'action_phase' => 'end', 'target' => 'warmup', 'target_scope' => 'server'),
			'maniaplanet_warmup_status' => array('action_name' => 'warmup.status', 'action_domain' => 'match_flow', 'action_type' => 'warmup', 'action_phase' => 'status', 'target' => 'warmup', 'target_scope' => 'server'),
			'maniaplanet_startmatch_start' => array('action_name' => 'match.start', 'action_domain' => 'match_flow', 'action_type' => 'match_start', 'action_phase' => 'start', 'target' => 'match', 'target_scope' => 'match'),
			'maniaplanet_startmatch_end' => array('action_name' => 'match.start', 'action_domain' => 'match_flow', 'action_type' => 'match_start', 'action_phase' => 'end', 'target' => 'match', 'target_scope' => 'match'),
			'maniaplanet_endmatch_start' => array('action_name' => 'match.end', 'action_domain' => 'match_flow', 'action_type' => 'match_end', 'action_phase' => 'start', 'target' => 'match', 'target_scope' => 'match'),
			'maniaplanet_endmatch_end' => array('action_name' => 'match.end', 'action_domain' => 'match_flow', 'action_type' => 'match_end', 'action_phase' => 'end', 'target' => 'match', 'target_scope' => 'match'),
			'maniaplanet_loadingmap_start' => array('action_name' => 'map.loading.start', 'action_domain' => 'match_flow', 'action_type' => 'map_loading', 'action_phase' => 'start', 'target' => 'map', 'target_scope' => 'map'),
			'maniaplanet_loadingmap_end' => array('action_name' => 'map.loading.end', 'action_domain' => 'match_flow', 'action_type' => 'map_loading', 'action_phase' => 'end', 'target' => 'map', 'target_scope' => 'map'),
			'maniaplanet_unloadingmap_start' => array('action_name' => 'map.unloading.start', 'action_domain' => 'match_flow', 'action_type' => 'map_unloading', 'action_phase' => 'start', 'target' => 'map', 'target_scope' => 'map'),
			'maniaplanet_unloadingmap_end' => array('action_name' => 'map.unloading.end', 'action_domain' => 'match_flow', 'action_type' => 'map_unloading', 'action_phase' => 'end', 'target' => 'map', 'target_scope' => 'map'),
			'maniaplanet_startround_start' => array('action_name' => 'round.start', 'action_domain' => 'match_flow', 'action_type' => 'round_start', 'action_phase' => 'start', 'target' => 'round', 'target_scope' => 'round'),
			'maniaplanet_startround_end' => array('action_name' => 'round.start', 'action_domain' => 'match_flow', 'action_type' => 'round_start', 'action_phase' => 'end', 'target' => 'round', 'target_scope' => 'round'),
			'maniaplanet_endround_start' => array('action_name' => 'round.end', 'action_domain' => 'match_flow', 'action_type' => 'round_end', 'action_phase' => 'start', 'target' => 'round', 'target_scope' => 'round'),
			'maniaplanet_endround_end' => array('action_name' => 'round.end', 'action_domain' => 'match_flow', 'action_type' => 'round_end', 'action_phase' => 'end', 'target' => 'round', 'target_scope' => 'round'),
		);

		if (!array_key_exists($normalizedSourceCallback, $definitions)) {
			return null;
		}

		return $definitions[$normalizedSourceCallback];
	}

	/**
	 * @param array $scriptPayload
	 * @param array $actor
	 * @return array
	 */
	private function buildPauseRequestContext(array $scriptPayload, array $actor) {
		$requesterLogin = $this->extractFirstScalarPayloadValue(
			$scriptPayload,
			array('requested_by_login', 'requester_login', 'requested_by', 'actor_login', 'player_login', 'login', 'player')
		);

		if ($requesterLogin === '' && isset($actor['login']) && trim((string) $actor['login']) !== '') {
			$requesterLogin = trim((string) $actor['login']);
		}

		$teamId = null;
		$teamIdRaw = $this->extractFirstScalarPayloadValue(
			$scriptPayload,
			array('requested_by_team_id', 'requester_team_id', 'actor_team_id', 'team_id', 'team')
		);
		if ($teamIdRaw !== '' && is_numeric($teamIdRaw)) {
			$teamId = (int) $teamIdRaw;
		} elseif (isset($actor['team_id']) && is_int($actor['team_id']) && $actor['team_id'] >= 0) {
			$teamId = (int) $actor['team_id'];
		}

		$teamSide = $this->extractFirstScalarPayloadValue(
			$scriptPayload,
			array('requested_by_team_side', 'requester_team_side', 'team_side', 'side')
		);
		if ($teamSide === '' && $teamId !== null) {
			$teamSide = $this->resolveTeamSideLabelFromTeamId($teamId);
		}

		$active = $this->extractBooleanPayloadValue($scriptPayload, array('active'));
		$available = $this->extractBooleanPayloadValue($scriptPayload, array('available'));

		$fieldAvailability = array(
			'requested_by_login' => $requesterLogin !== '',
			'requested_by_team_id' => $teamId !== null,
			'requested_by_team_side' => $teamSide !== '',
			'active' => $active !== null,
			'available' => $available !== null,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $isAvailable) {
			if ($isAvailable) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'requested_by_login' => $requesterLogin,
			'requested_by_team_id' => $teamId,
			'requested_by_team_side' => $teamSide,
			'active' => $active,
			'available' => $available,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	/**
	 * @param int $teamId
	 * @return string
	 */
	private function resolveTeamSideLabelFromTeamId($teamId) {
		switch ((int) $teamId) {
			case 0:
				return 'red';
			case 1:
				return 'blue';
			default:
				return 'team_' . (int) $teamId;
		}
	}

	/**
	 * @param array    $payload
	 * @param string[] $candidateKeys
	 * @return bool|null
	 */
	private function extractBooleanPayloadValue(array $payload, array $candidateKeys) {
		foreach ($candidateKeys as $candidateKey) {
			if (!array_key_exists($candidateKey, $payload)) {
				continue;
			}

			$candidateValue = $payload[$candidateKey];
			if (is_bool($candidateValue)) {
				return $candidateValue;
			}

			if (is_int($candidateValue) || is_float($candidateValue)) {
				return ((float) $candidateValue) !== 0.0;
			}

			if (!is_string($candidateValue)) {
				continue;
			}

			$normalizedValue = strtolower(trim($candidateValue));
			if ($normalizedValue === '') {
				continue;
			}

			if (in_array($normalizedValue, array('1', 'true', 'yes', 'on', 'active', 'start', 'started'), true)) {
				return true;
			}

			if (in_array($normalizedValue, array('0', 'false', 'no', 'off', 'inactive', 'end', 'ended'), true)) {
				return false;
			}
		}

		return null;
	}

	/**
	 * @param array $callbackArguments
	 * @return array
	 */
	private function extractScriptCallbackPayload(array $callbackArguments) {
		if (empty($callbackArguments) || !is_array($callbackArguments[0])) {
			return array();
		}

		$scriptCallbackData = $callbackArguments[0];
		if (!array_key_exists(1, $scriptCallbackData)) {
			return array();
		}

		$rawPayload = $scriptCallbackData[1];
		if (is_array($rawPayload) && array_key_exists(0, $rawPayload) && is_string($rawPayload[0])) {
			$rawPayload = $rawPayload[0];
		}

		if (is_string($rawPayload)) {
			$decodedPayload = json_decode($rawPayload, true);
			if (is_array($decodedPayload)) {
				return $decodedPayload;
			}

			$trimmedRawPayload = trim($rawPayload);
			if ($trimmedRawPayload !== '') {
				return array('raw' => $trimmedRawPayload);
			}

			return array();
		}

		if (is_array($rawPayload)) {
			return $rawPayload;
		}

		if (is_object($rawPayload)) {
			$encodedPayload = json_encode($rawPayload);
			if (is_string($encodedPayload)) {
				$decodedPayload = json_decode($encodedPayload, true);
				if (is_array($decodedPayload)) {
					return $decodedPayload;
				}
			}
		}

		return array();
	}

	/**
	 * @param array $scriptPayload
	 * @return array
	 */
	private function extractActorSnapshotFromPayload(array $scriptPayload) {
		$actorLogin = '';
		$candidateKeys = array('actor_login', 'player_login', 'login', 'player', 'playerlogin');

		foreach ($candidateKeys as $candidateKey) {
			if (!isset($scriptPayload[$candidateKey])) {
				continue;
			}

			if (is_scalar($scriptPayload[$candidateKey])) {
				$actorLogin = trim((string) $scriptPayload[$candidateKey]);
			}

			if ($actorLogin !== '') {
				break;
			}
		}

		if ($actorLogin === '') {
			return array(
				'type' => 'unknown',
				'login' => '',
				'nickname' => '',
				'team_id' => -1,
			);
		}

		$player = $this->maniaControl ? $this->maniaControl->getPlayerManager()->getPlayer($actorLogin) : null;
		if ($player instanceof Player) {
			return array(
				'type' => 'player',
				'login' => isset($player->login) ? (string) $player->login : $actorLogin,
				'nickname' => isset($player->nickname) ? (string) $player->nickname : '',
				'team_id' => isset($player->teamId) ? (int) $player->teamId : -1,
			);
		}

		return array(
			'type' => 'login',
			'login' => $actorLogin,
			'nickname' => '',
			'team_id' => -1,
		);
	}

	/**
	 * @param string $sourceCallback
	 * @param array  $actor
	 * @param array  $scriptPayload
	 * @return string
	 */
	private function resolveAdminActionInitiatorKind($sourceCallback, array $actor, array $scriptPayload) {
		if (isset($actor['type']) && ($actor['type'] === 'player' || $actor['type'] === 'login')) {
			return 'player';
		}

		$payloadInitiatorKind = $this->extractFirstScalarPayloadValue($scriptPayload, array('initiator_kind', 'actor_kind', 'initiator_type', 'source'));
		if ($payloadInitiatorKind !== '') {
			return $this->normalizeIdentifier($payloadInitiatorKind, 'unknown');
		}

		if ($this->isScriptLifecycleCallback($sourceCallback)) {
			return 'system';
		}

		return 'unknown';
	}

	/**
	 * @param array $actionDefinition
	 * @param array $scriptPayload
	 * @return array
	 */
	private function resolveAdminActionTargetId(array $actionDefinition, array $scriptPayload) {
		$targetScope = isset($actionDefinition['target_scope']) ? (string) $actionDefinition['target_scope'] : 'unknown';
		$candidateKeys = array('target_id', 'target', 'id');

		switch ($targetScope) {
			case 'map':
				$candidateKeys = array_merge($candidateKeys, array('map_uid', 'mapid', 'map_id', 'map', 'map_name', 'uid'));
				break;
			case 'round':
				$candidateKeys = array_merge($candidateKeys, array('round_id', 'round', 'count', 'time'));
				break;
			case 'match':
				$candidateKeys = array_merge($candidateKeys, array('match_id', 'match', 'responseid'));
				break;
			case 'server':
				$candidateKeys = array_merge($candidateKeys, array('responseid', 'active', 'available'));
				break;
		}

		$resolvedTargetId = $this->extractFirstScalarPayloadValue($scriptPayload, $candidateKeys);
		if ($resolvedTargetId !== '') {
			return array(
				'value' => $resolvedTargetId,
				'available' => true,
			);
		}

		if ($targetScope === 'map') {
			$currentMap = $this->buildCurrentMapSnapshot();
			if (isset($currentMap['uid']) && trim((string) $currentMap['uid']) !== '') {
				return array(
					'value' => trim((string) $currentMap['uid']),
					'available' => true,
				);
			}
		}

		return array(
			'value' => 'unknown',
			'available' => false,
		);
	}

	/**
	 * @param array $payload
	 * @param array $candidateKeys
	 * @return string
	 */
	private function extractFirstScalarPayloadValue(array $payload, array $candidateKeys) {
		foreach ($candidateKeys as $candidateKey) {
			if (!array_key_exists($candidateKey, $payload)) {
				continue;
			}

			$candidateValue = $payload[$candidateKey];
			if (!is_scalar($candidateValue)) {
				continue;
			}

			if (is_bool($candidateValue)) {
				return $candidateValue ? 'true' : 'false';
			}

			$normalizedValue = trim((string) $candidateValue);
			if ($normalizedValue !== '') {
				return $normalizedValue;
			}
		}

		return '';
	}

	/**
	 * @param string $sourceCallback
	 * @return array
	 */
	private function resolvePlayerTransitionDefinition($sourceCallback) {
		switch ($this->normalizeIdentifier($sourceCallback, 'unknown')) {
			case 'playermanagercallback_playerconnect':
				return array(
					'event_kind' => 'player.connect',
					'transition_kind' => 'connectivity',
					'forced_connectivity' => 'connected',
				);
			case 'playermanagercallback_playerdisconnect':
				return array(
					'event_kind' => 'player.disconnect',
					'transition_kind' => 'connectivity',
					'forced_connectivity' => 'disconnected',
				);
			case 'playermanagercallback_playerinfochanged':
				return array(
					'event_kind' => 'player.info_changed',
					'transition_kind' => 'state_change',
					'forced_connectivity' => null,
				);
			case 'playermanagercallback_playerinfoschanged':
				return array(
					'event_kind' => 'player.infos_changed',
					'transition_kind' => 'batch_refresh',
					'forced_connectivity' => null,
				);
			default:
				return array(
					'event_kind' => 'player.unknown',
					'transition_kind' => 'unknown',
					'forced_connectivity' => null,
				);
		}
	}

	/**
	 * @param string $sourceCallback
	 * @param array  $callbackArguments
	 * @return array
	 */
	private function buildPlayerPayload($sourceCallback, array $callbackArguments) {
		$transitionDefinition = $this->resolvePlayerTransitionDefinition($sourceCallback);
		$transitionSequence = $this->nextPlayerTransitionSequence();
		$observedAt = time();
		$player = $this->extractPlayerFromCallbackArguments($callbackArguments);
		$currentPlayerSnapshot = $this->buildPlayerTelemetrySnapshot($player);

		if (is_array($currentPlayerSnapshot) && $transitionDefinition['forced_connectivity'] !== null) {
			$currentPlayerSnapshot['is_connected'] = $transitionDefinition['forced_connectivity'] === 'connected';
			$currentPlayerSnapshot['connectivity_state'] = $transitionDefinition['forced_connectivity'];
			$currentPlayerSnapshot['readiness_state'] = $this->resolvePlayerReadinessState($currentPlayerSnapshot);
			$currentPlayerSnapshot['eligibility_state'] = $this->resolvePlayerEligibilityState($currentPlayerSnapshot);
			$currentPlayerSnapshot['can_join_round'] = $this->resolvePlayerCanJoinRound($currentPlayerSnapshot);
		}

		$previousPlayerSnapshot = $this->resolvePreviousPlayerSnapshot($currentPlayerSnapshot);
		$stateDelta = $this->buildPlayerStateDelta($previousPlayerSnapshot, $currentPlayerSnapshot);
		$permissionSignals = $this->buildPlayerPermissionSignals($currentPlayerSnapshot, $stateDelta);
		$rosterState = $this->buildRosterStateTelemetry($currentPlayerSnapshot, $previousPlayerSnapshot);
		$adminCorrelation = $this->buildPlayerAdminCorrelation($currentPlayerSnapshot, $transitionDefinition);
		$reconnectContinuity = $this->buildReconnectContinuityTelemetry(
			$transitionDefinition,
			$currentPlayerSnapshot,
			$previousPlayerSnapshot,
			$sourceCallback,
			$transitionSequence,
			$observedAt
		);
		$sideChange = $this->buildSideChangeTelemetry(
			$transitionDefinition,
			$currentPlayerSnapshot,
			$previousPlayerSnapshot,
			$stateDelta,
			$sourceCallback,
			$transitionSequence,
			$observedAt
		);
		$rosterSnapshot = $this->buildPlayerSnapshot();
		$constraintSignals = $this->buildPlayerConstraintSignals(
			$transitionDefinition,
			$currentPlayerSnapshot,
			$previousPlayerSnapshot,
			$stateDelta,
			$rosterSnapshot,
			$sourceCallback,
			$transitionSequence,
			$observedAt
		);

		$fieldAvailability = array(
			'player' => is_array($currentPlayerSnapshot),
			'player_login' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['login']) && trim((string) $currentPlayerSnapshot['login']) !== '',
			'previous_player' => is_array($previousPlayerSnapshot),
			'team_id' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['team_id']) && $currentPlayerSnapshot['team_id'] !== null,
			'is_spectator' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['is_spectator']) && $currentPlayerSnapshot['is_spectator'] !== null,
			'auth_level' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['auth_level']) && $currentPlayerSnapshot['auth_level'] !== null,
			'is_referee' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['is_referee']) && $currentPlayerSnapshot['is_referee'] !== null,
			'readiness_state' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['readiness_state']) && trim((string) $currentPlayerSnapshot['readiness_state']) !== '',
			'eligibility_state' => is_array($currentPlayerSnapshot) && isset($currentPlayerSnapshot['eligibility_state']) && trim((string) $currentPlayerSnapshot['eligibility_state']) !== '',
			'roster_state' => is_array($rosterState),
			'admin_correlation' => is_array($adminCorrelation),
			'reconnect_continuity' => is_array($reconnectContinuity),
			'side_change' => is_array($sideChange),
			'constraint_signals' => is_array($constraintSignals),
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		$payload = array(
			'event_kind' => $transitionDefinition['event_kind'],
			'transition_kind' => $transitionDefinition['transition_kind'],
			'source_callback' => $sourceCallback,
			'player' => $currentPlayerSnapshot,
			'previous_player' => $previousPlayerSnapshot,
			'state_delta' => $stateDelta,
			'permission_signals' => $permissionSignals,
			'roster_state' => $rosterState,
			'admin_correlation' => $adminCorrelation,
			'reconnect_continuity' => $reconnectContinuity,
			'side_change' => $sideChange,
			'constraint_signals' => $constraintSignals,
			'roster_snapshot' => $rosterSnapshot,
			'tracked_player_cache_size' => count($this->playerStateCache),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
			'raw_callback_summary' => $this->buildPayloadSummary($callbackArguments),
		);

		if ($transitionDefinition['transition_kind'] === 'batch_refresh') {
			$payload['batch_scope'] = 'server_roster_refresh';
		}

		$this->updatePlayerStateCache($transitionDefinition, $currentPlayerSnapshot);

		return $payload;
	}

	/**
	 * @param array $callbackArguments
	 * @return Player|null
	 */
	private function extractPlayerFromCallbackArguments(array $callbackArguments) {
		if (empty($callbackArguments)) {
			return null;
		}

		$firstArgument = $callbackArguments[0];
		if ($firstArgument instanceof Player) {
			return $firstArgument;
		}

		if (is_string($firstArgument) && $this->maniaControl) {
			$resolvedPlayer = $this->maniaControl->getPlayerManager()->getPlayer($firstArgument);
			if ($resolvedPlayer instanceof Player) {
				return $resolvedPlayer;
			}
		}

		return null;
	}

	/**
	 * @param Player|null $player
	 * @return array|null
	 */
	private function buildPlayerTelemetrySnapshot($player) {
		if (!$player instanceof Player) {
			return null;
		}

		$authLevel = isset($player->authLevel) ? (int) $player->authLevel : null;

		$snapshot = array(
			'login' => isset($player->login) ? (string) $player->login : '',
			'nickname' => isset($player->nickname) ? (string) $player->nickname : '',
			'team_id' => isset($player->teamId) ? (int) $player->teamId : null,
			'is_spectator' => isset($player->isSpectator) ? (bool) $player->isSpectator : null,
			'is_temporary_spectator' => isset($player->isTemporarySpectator) ? (bool) $player->isTemporarySpectator : null,
			'is_pure_spectator' => isset($player->isPureSpectator) ? (bool) $player->isPureSpectator : null,
			'is_connected' => isset($player->isConnected) ? (bool) $player->isConnected : null,
			'has_joined_game' => isset($player->hasJoinedGame) ? (bool) $player->hasJoinedGame : null,
			'forced_spectator_state' => isset($player->forcedSpectatorState) ? (int) $player->forcedSpectatorState : null,
			'auth_level' => $authLevel,
			'auth_name' => $this->resolveAuthLevelName($authLevel),
			'auth_role' => $this->resolveAuthLevelRole($authLevel),
			'is_referee' => isset($player->isReferee) ? (bool) $player->isReferee : null,
			'has_player_slot' => isset($player->hasPlayerSlot) ? (bool) $player->hasPlayerSlot : null,
			'is_managed_by_other_server' => isset($player->isManagedByAnOtherServer) ? (bool) $player->isManagedByAnOtherServer : null,
			'is_broadcasting' => isset($player->isBroadcasting) ? (bool) $player->isBroadcasting : null,
			'is_podium_ready' => isset($player->isPodiumReady) ? (bool) $player->isPodiumReady : null,
			'is_official' => isset($player->isOfficial) ? (bool) $player->isOfficial : null,
			'is_server' => isset($player->isServer) ? (bool) $player->isServer : null,
			'is_fake' => method_exists($player, 'isFakePlayer') ? (bool) $player->isFakePlayer() : null,
		);

		$snapshot['connectivity_state'] = $this->resolvePlayerConnectivityState($snapshot);
		$snapshot['readiness_state'] = $this->resolvePlayerReadinessState($snapshot);
		$snapshot['eligibility_state'] = $this->resolvePlayerEligibilityState($snapshot);
		$snapshot['can_join_round'] = $this->resolvePlayerCanJoinRound($snapshot);

		return $snapshot;
	}

	/**
	 * @param array|null $previousPlayerSnapshot
	 * @param array|null $currentPlayerSnapshot
	 * @return array
	 */
	private function buildPlayerStateDelta($previousPlayerSnapshot, $currentPlayerSnapshot) {
		return array(
			'connectivity' => $this->buildPlayerStateDeltaEntry(
				$this->resolvePlayerConnectivityState($previousPlayerSnapshot),
				$this->resolvePlayerConnectivityState($currentPlayerSnapshot)
			),
			'spectator' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'is_spectator'),
				$this->getSnapshotField($currentPlayerSnapshot, 'is_spectator')
			),
			'team_id' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'team_id'),
				$this->getSnapshotField($currentPlayerSnapshot, 'team_id')
			),
			'auth_level' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'auth_level'),
				$this->getSnapshotField($currentPlayerSnapshot, 'auth_level')
			),
			'auth_role' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'auth_role'),
				$this->getSnapshotField($currentPlayerSnapshot, 'auth_role')
			),
			'is_referee' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'is_referee'),
				$this->getSnapshotField($currentPlayerSnapshot, 'is_referee')
			),
			'has_player_slot' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'has_player_slot'),
				$this->getSnapshotField($currentPlayerSnapshot, 'has_player_slot')
			),
			'has_joined_game' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'has_joined_game'),
				$this->getSnapshotField($currentPlayerSnapshot, 'has_joined_game')
			),
			'forced_spectator_state' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'forced_spectator_state'),
				$this->getSnapshotField($currentPlayerSnapshot, 'forced_spectator_state')
			),
			'is_temporary_spectator' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'is_temporary_spectator'),
				$this->getSnapshotField($currentPlayerSnapshot, 'is_temporary_spectator')
			),
			'readiness_state' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'readiness_state'),
				$this->getSnapshotField($currentPlayerSnapshot, 'readiness_state')
			),
			'eligibility_state' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'eligibility_state'),
				$this->getSnapshotField($currentPlayerSnapshot, 'eligibility_state')
			),
			'can_join_round' => $this->buildPlayerStateDeltaEntry(
				$this->getSnapshotField($previousPlayerSnapshot, 'can_join_round'),
				$this->getSnapshotField($currentPlayerSnapshot, 'can_join_round')
			),
		);
	}

	/**
	 * @param mixed $before
	 * @param mixed $after
	 * @return array
	 */
	private function buildPlayerStateDeltaEntry($before, $after) {
		return array(
			'before' => $before,
			'after' => $after,
			'changed' => $before !== $after,
		);
	}

	/**
	 * @return int
	 */
	private function nextPlayerTransitionSequence() {
		$this->playerTransitionSequence++;
		return $this->playerTransitionSequence;
	}

	/**
	 * @param array      $transitionDefinition
	 * @param array|null $currentPlayerSnapshot
	 * @param array|null $previousPlayerSnapshot
	 * @param string     $sourceCallback
	 * @param int        $transitionSequence
	 * @param int        $observedAt
	 * @return array
	 */
	private function buildReconnectContinuityTelemetry(
		array $transitionDefinition,
		$currentPlayerSnapshot,
		$previousPlayerSnapshot,
		$sourceCallback,
		$transitionSequence,
		$observedAt
	) {
		$eventKind = isset($transitionDefinition['event_kind']) ? (string) $transitionDefinition['event_kind'] : 'player.unknown';
		$transitionKind = isset($transitionDefinition['transition_kind']) ? (string) $transitionDefinition['transition_kind'] : 'unknown';
		$playerLogin = $this->resolvePlayerLoginFromSnapshots($currentPlayerSnapshot, $previousPlayerSnapshot);
		$beforeConnectivity = $this->resolvePlayerConnectivityState($previousPlayerSnapshot);
		$afterConnectivity = $this->resolvePlayerConnectivityState($currentPlayerSnapshot);

		if ($playerLogin === '') {
			$fieldAvailability = array(
				'player_login' => false,
				'session_id' => false,
				'session_ordinal' => false,
				'connected_before' => $beforeConnectivity !== 'unknown',
				'connected_after' => $afterConnectivity !== 'unknown',
				'transition_sequence' => true,
			);

			return array(
				'identity_key' => 'unknown',
				'player_login' => '',
				'event_kind' => $eventKind,
				'transition_kind' => $transitionKind,
				'transition_state' => 'unavailable',
				'continuity_state' => 'unknown',
				'session_id' => null,
				'session_ordinal' => null,
				'previous_session_id' => null,
				'reconnect_count' => 0,
				'connected_before' => $beforeConnectivity,
				'connected_after' => $afterConnectivity,
				'last_disconnect_at' => null,
				'seconds_since_last_disconnect' => null,
				'source_callback' => $sourceCallback,
				'observed_at' => (int) $observedAt,
				'ordering' => array(
					'global_transition_sequence' => (int) $transitionSequence,
					'player_transition_sequence' => null,
				),
				'field_availability' => $fieldAvailability,
				'missing_fields' => array('player_login', 'session_id', 'session_ordinal'),
			);
		}

		if (!isset($this->playerSessionStateCache[$playerLogin]) || !is_array($this->playerSessionStateCache[$playerLogin])) {
			$this->playerSessionStateCache[$playerLogin] = $this->buildDefaultPlayerSessionState($playerLogin);
		}

		$sessionState = $this->playerSessionStateCache[$playerLogin];
		$sessionState['player_transition_count'] = isset($sessionState['player_transition_count'])
			? ((int) $sessionState['player_transition_count']) + 1
			: 1;

		$previousSessionId = isset($sessionState['session_id']) ? trim((string) $sessionState['session_id']) : '';
		$transitionState = 'state_update';
		$continuityState = 'continuous';
		$secondsSinceLastDisconnect = null;

		$lastDisconnectAt = isset($sessionState['last_disconnect_at']) && is_numeric($sessionState['last_disconnect_at'])
			? (int) $sessionState['last_disconnect_at']
			: 0;

		$isConnectEvent = ($eventKind === 'player.connect') || ($beforeConnectivity === 'disconnected' && $afterConnectivity === 'connected');
		$isDisconnectEvent = ($eventKind === 'player.disconnect') || $afterConnectivity === 'disconnected';

		if ($isConnectEvent) {
			$sessionState['session_ordinal'] = max(0, (int) $sessionState['session_ordinal']) + 1;
			$sessionState['session_id'] = $this->buildPlayerSessionId($playerLogin, $sessionState['session_ordinal']);
			$sessionState['last_connect_at'] = (int) $observedAt;
			$sessionState['last_connect_transition_sequence'] = (int) $transitionSequence;

			$isReconnect = ($lastDisconnectAt > 0) || (isset($sessionState['last_connectivity_state']) && $sessionState['last_connectivity_state'] === 'disconnected');
			if ($isReconnect) {
				$sessionState['reconnect_count'] = isset($sessionState['reconnect_count']) ? ((int) $sessionState['reconnect_count']) + 1 : 1;
				$transitionState = 'reconnect';
				$continuityState = 'resumed';
				if ($lastDisconnectAt > 0) {
					$secondsSinceLastDisconnect = max(0, (int) $observedAt - $lastDisconnectAt);
				}
			} else if ((int) $sessionState['session_ordinal'] === 1) {
				$transitionState = 'initial_connect';
				$continuityState = 'continuous';
			} else {
				$transitionState = 'connect';
				$continuityState = 'continuous';
			}
		} else if ($isDisconnectEvent) {
			$transitionState = 'disconnect';
			$continuityState = 'disconnected';
			$sessionState['last_disconnect_at'] = (int) $observedAt;
			$sessionState['last_disconnect_transition_sequence'] = (int) $transitionSequence;
		} else if ($transitionKind === 'batch_refresh') {
			$transitionState = 'batch_refresh';
			$continuityState = ($afterConnectivity === 'disconnected') ? 'disconnected' : 'continuous';
		} else if ($transitionKind === 'state_change') {
			$transitionState = 'state_change';
			$continuityState = ($afterConnectivity === 'disconnected') ? 'disconnected' : 'continuous';
		} else {
			$transitionState = 'state_update';
			$continuityState = ($afterConnectivity === 'disconnected') ? 'disconnected' : 'continuous';
		}

		$sessionState['last_connectivity_state'] = $afterConnectivity;
		$sessionState['last_event_kind'] = $eventKind;
		$sessionState['last_seen_at'] = (int) $observedAt;
		$sessionState['last_transition_sequence'] = (int) $transitionSequence;

		if (is_array($currentPlayerSnapshot) && array_key_exists('team_id', $currentPlayerSnapshot)) {
			$sessionState['last_team_id'] = $currentPlayerSnapshot['team_id'];
		}

		$this->playerSessionStateCache[$playerLogin] = $sessionState;

		$resolvedSessionId = isset($sessionState['session_id']) ? trim((string) $sessionState['session_id']) : '';
		$resolvedSessionOrdinal = isset($sessionState['session_ordinal']) ? (int) $sessionState['session_ordinal'] : 0;
		$resolvedReconnectCount = isset($sessionState['reconnect_count']) ? (int) $sessionState['reconnect_count'] : 0;
		$resolvedLastDisconnectAt = isset($sessionState['last_disconnect_at']) && is_numeric($sessionState['last_disconnect_at'])
			? (int) $sessionState['last_disconnect_at']
			: null;

		$fieldAvailability = array(
			'player_login' => $playerLogin !== '',
			'session_id' => $resolvedSessionId !== '',
			'session_ordinal' => $resolvedSessionOrdinal > 0,
			'previous_session_id' => $previousSessionId !== '',
			'connected_before' => $beforeConnectivity !== 'unknown',
			'connected_after' => $afterConnectivity !== 'unknown',
			'last_disconnect_at' => $resolvedLastDisconnectAt !== null,
			'seconds_since_last_disconnect' => $secondsSinceLastDisconnect !== null,
			'transition_sequence' => true,
			'player_transition_sequence' => isset($sessionState['player_transition_count']) && (int) $sessionState['player_transition_count'] > 0,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'identity_key' => 'player_login:' . $playerLogin,
			'player_login' => $playerLogin,
			'event_kind' => $eventKind,
			'transition_kind' => $transitionKind,
			'transition_state' => $transitionState,
			'continuity_state' => $continuityState,
			'session_id' => ($resolvedSessionId !== '' ? $resolvedSessionId : null),
			'session_ordinal' => ($resolvedSessionOrdinal > 0 ? $resolvedSessionOrdinal : null),
			'previous_session_id' => ($previousSessionId !== '' ? $previousSessionId : null),
			'reconnect_count' => $resolvedReconnectCount,
			'connected_before' => $beforeConnectivity,
			'connected_after' => $afterConnectivity,
			'last_disconnect_at' => $resolvedLastDisconnectAt,
			'seconds_since_last_disconnect' => $secondsSinceLastDisconnect,
			'source_callback' => $sourceCallback,
			'observed_at' => (int) $observedAt,
			'ordering' => array(
				'global_transition_sequence' => (int) $transitionSequence,
				'player_transition_sequence' => isset($sessionState['player_transition_count']) ? (int) $sessionState['player_transition_count'] : null,
			),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	/**
	 * @param array      $transitionDefinition
	 * @param array|null $currentPlayerSnapshot
	 * @param array|null $previousPlayerSnapshot
	 * @param array      $stateDelta
	 * @param string     $sourceCallback
	 * @param int        $transitionSequence
	 * @param int        $observedAt
	 * @return array
	 */
	private function buildSideChangeTelemetry(
		array $transitionDefinition,
		$currentPlayerSnapshot,
		$previousPlayerSnapshot,
		array $stateDelta,
		$sourceCallback,
		$transitionSequence,
		$observedAt
	) {
		$playerLogin = $this->resolvePlayerLoginFromSnapshots($currentPlayerSnapshot, $previousPlayerSnapshot);
		$previousTeamId = $this->getSnapshotField($previousPlayerSnapshot, 'team_id');
		$currentTeamId = $this->getSnapshotField($currentPlayerSnapshot, 'team_id');
		$previousSide = $this->buildSideProjectionFromSnapshot($previousPlayerSnapshot);
		$currentSide = $this->buildSideProjectionFromSnapshot($currentPlayerSnapshot);

		$teamChanged = false;
		if (isset($stateDelta['team_id']) && is_array($stateDelta['team_id']) && isset($stateDelta['team_id']['changed'])) {
			$teamChanged = (bool) $stateDelta['team_id']['changed'];
		} else {
			$teamChanged = ($previousTeamId !== $currentTeamId);
		}

		$sideChanged = ($previousSide !== $currentSide) && $previousSide !== 'unknown' && $currentSide !== 'unknown';
		$detected = ($playerLogin !== '') && ($teamChanged || $sideChanged);

		$transitionKind = 'none';
		if ($playerLogin === '') {
			$transitionKind = 'unavailable';
		} else if ($detected && ($previousSide === 'unassigned' || $currentSide === 'unassigned')) {
			$transitionKind = 'assignment_change';
		} else if ($detected && $sideChanged) {
			$transitionKind = 'side_change';
		} else if ($detected && $teamChanged) {
			$transitionKind = 'team_change';
		}

		$dedupeKey = null;
		if ($playerLogin !== '' && $detected) {
			$dedupeKey = 'pc-side-' . sha1(
				$playerLogin . '|'
				. (string) $previousTeamId . '|'
				. (string) $currentTeamId . '|'
				. (string) $transitionSequence . '|'
				. (string) $sourceCallback
			);
		}

		$playerTransitionSequence = null;
		if ($playerLogin !== '' && isset($this->playerSessionStateCache[$playerLogin]['player_transition_count'])) {
			$playerTransitionSequence = (int) $this->playerSessionStateCache[$playerLogin]['player_transition_count'];
		}

		$fieldAvailability = array(
			'player_login' => $playerLogin !== '',
			'previous_team_id' => $previousTeamId !== null,
			'current_team_id' => $currentTeamId !== null,
			'previous_side' => $previousSide !== 'unknown',
			'current_side' => $currentSide !== 'unknown',
			'dedupe_key' => $dedupeKey !== null,
			'transition_sequence' => true,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'detected' => $detected,
			'transition_kind' => $transitionKind,
			'event_kind' => isset($transitionDefinition['event_kind']) ? (string) $transitionDefinition['event_kind'] : 'player.unknown',
			'player_login' => $playerLogin,
			'previous_team_id' => $previousTeamId,
			'current_team_id' => $currentTeamId,
			'previous_side' => $previousSide,
			'current_side' => $currentSide,
			'team_changed' => $teamChanged,
			'side_changed' => $sideChanged,
			'dedupe_key' => $dedupeKey,
			'source_callback' => $sourceCallback,
			'observed_at' => (int) $observedAt,
			'ordering' => array(
				'global_transition_sequence' => (int) $transitionSequence,
				'player_transition_sequence' => $playerTransitionSequence,
			),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	/**
	 * @param array|null $currentPlayerSnapshot
	 * @param array|null $previousPlayerSnapshot
	 * @return string
	 */
	private function resolvePlayerLoginFromSnapshots($currentPlayerSnapshot, $previousPlayerSnapshot) {
		$currentLogin = trim((string) $this->getSnapshotField($currentPlayerSnapshot, 'login'));
		if ($currentLogin !== '') {
			return $currentLogin;
		}

		$previousLogin = trim((string) $this->getSnapshotField($previousPlayerSnapshot, 'login'));
		if ($previousLogin !== '') {
			return $previousLogin;
		}

		return '';
	}

	/**
	 * @param string $playerLogin
	 * @return array
	 */
	private function buildDefaultPlayerSessionState($playerLogin) {
		return array(
			'identity_key' => 'player_login:' . $playerLogin,
			'session_id' => '',
			'session_ordinal' => 0,
			'reconnect_count' => 0,
			'player_transition_count' => 0,
			'last_connectivity_state' => 'unknown',
			'last_disconnect_at' => 0,
			'last_disconnect_transition_sequence' => 0,
			'last_connect_at' => 0,
			'last_connect_transition_sequence' => 0,
			'last_seen_at' => 0,
			'last_transition_sequence' => 0,
			'last_event_kind' => 'player.unknown',
			'last_team_id' => null,
		);
	}

	/**
	 * @param string $playerLogin
	 * @param int    $sessionOrdinal
	 * @return string
	 */
	private function buildPlayerSessionId($playerLogin, $sessionOrdinal) {
		$normalizedLogin = $this->normalizeIdentifier($playerLogin, 'unknown_player');
		$normalizedOrdinal = max(1, (int) $sessionOrdinal);

		return 'pc-session-' . $normalizedLogin . '-' . $normalizedOrdinal;
	}

	/**
	 * @param array|null $snapshot
	 * @return string
	 */
	private function buildSideProjectionFromSnapshot($snapshot) {
		if (!is_array($snapshot)) {
			return 'unknown';
		}

		$isSpectator = $this->getSnapshotField($snapshot, 'is_spectator');
		if ($isSpectator === true) {
			return 'spectator';
		}

		$teamId = $this->getSnapshotField($snapshot, 'team_id');
		if ($teamId === null || $teamId === '') {
			return 'unassigned';
		}

		if (!is_numeric($teamId)) {
			return 'unknown';
		}

		return 'team_' . (int) $teamId;
	}

	/**
	 * @param array|null $snapshot
	 * @return string
	 */
	private function resolvePlayerConnectivityState($snapshot) {
		if (!is_array($snapshot) || !array_key_exists('is_connected', $snapshot) || $snapshot['is_connected'] === null) {
			return 'unknown';
		}

		return $snapshot['is_connected'] ? 'connected' : 'disconnected';
	}

	/**
	 * @param array|null $snapshot
	 * @return string
	 */
	private function resolvePlayerReadinessState($snapshot) {
		if (!is_array($snapshot)) {
			return 'unknown';
		}

		$connectivityState = $this->resolvePlayerConnectivityState($snapshot);
		if ($connectivityState === 'disconnected') {
			return 'disconnected';
		}

		$isSpectator = $this->getSnapshotField($snapshot, 'is_spectator');
		$isTemporarySpectator = $this->getSnapshotField($snapshot, 'is_temporary_spectator');
		$hasPlayerSlot = $this->getSnapshotField($snapshot, 'has_player_slot');
		$hasJoinedGame = $this->getSnapshotField($snapshot, 'has_joined_game');

		if ($isSpectator === true) {
			if ($isTemporarySpectator === true) {
				return 'spectating_temporary';
			}

			return 'spectating';
		}

		if ($hasPlayerSlot === false) {
			return 'waiting_slot';
		}

		if ($hasJoinedGame === false) {
			return 'joining';
		}

		if ($connectivityState === 'connected' && $hasPlayerSlot === true && $hasJoinedGame === true) {
			return 'ready';
		}

		if ($connectivityState === 'connected') {
			return 'connected_idle';
		}

		return 'unknown';
	}

	/**
	 * @param array|null $snapshot
	 * @return string
	 */
	private function resolvePlayerEligibilityState($snapshot) {
		if (!is_array($snapshot)) {
			return 'unknown';
		}

		$connectivityState = $this->resolvePlayerConnectivityState($snapshot);
		if ($connectivityState === 'disconnected') {
			return 'ineligible';
		}

		$isServer = $this->getSnapshotField($snapshot, 'is_server');
		$isManagedByOtherServer = $this->getSnapshotField($snapshot, 'is_managed_by_other_server');
		$hasPlayerSlot = $this->getSnapshotField($snapshot, 'has_player_slot');
		$isSpectator = $this->getSnapshotField($snapshot, 'is_spectator');
		$forcedSpectatorState = $this->getSnapshotField($snapshot, 'forced_spectator_state');

		if ($isServer === true) {
			return 'ineligible';
		}

		if ($isManagedByOtherServer === true) {
			return 'restricted';
		}

		if ($hasPlayerSlot === false || $isSpectator === true) {
			return 'restricted';
		}

		if (is_numeric($forcedSpectatorState) && (int) $forcedSpectatorState > 0) {
			return 'restricted';
		}

		if ($connectivityState === 'connected') {
			return 'eligible';
		}

		return 'unknown';
	}

	/**
	 * @param array|null $snapshot
	 * @return bool|null
	 */
	private function resolvePlayerCanJoinRound($snapshot) {
		if (!is_array($snapshot)) {
			return null;
		}

		$eligibilityState = isset($snapshot['eligibility_state']) ? (string) $snapshot['eligibility_state'] : $this->resolvePlayerEligibilityState($snapshot);
		$readinessState = isset($snapshot['readiness_state']) ? (string) $snapshot['readiness_state'] : $this->resolvePlayerReadinessState($snapshot);

		if ($eligibilityState === 'ineligible' || $eligibilityState === 'restricted') {
			return false;
		}

		if ($readinessState === 'ready' || $readinessState === 'connected_idle') {
			return true;
		}

		if (
			$readinessState === 'joining'
			|| $readinessState === 'waiting_slot'
			|| $readinessState === 'spectating'
			|| $readinessState === 'spectating_temporary'
			|| $readinessState === 'disconnected'
		) {
			return false;
		}

		return null;
	}

	/**
	 * @param array|null $currentPlayerSnapshot
	 * @param array|null $previousPlayerSnapshot
	 * @return array
	 */
	private function buildRosterStateTelemetry($currentPlayerSnapshot, $previousPlayerSnapshot) {
		$currentState = $this->buildPlayerRosterState($currentPlayerSnapshot);
		$previousState = $this->buildPlayerRosterState($previousPlayerSnapshot);

		$fieldAvailability = array(
			'current_snapshot' => is_array($currentPlayerSnapshot),
			'previous_snapshot' => is_array($previousPlayerSnapshot),
			'aggregate' => $this->maniaControl !== null,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'current' => $currentState,
			'previous' => $previousState,
			'delta' => $this->buildRosterStateDelta($previousState, $currentState),
			'aggregate' => $this->buildRosterAggregateSnapshot(),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	/**
	 * @param array|null $snapshot
	 * @return array
	 */
	private function buildPlayerRosterState($snapshot) {
		if (!is_array($snapshot)) {
			return array(
				'connectivity_state' => 'unknown',
				'spectator_state' => 'unknown',
				'team_id' => null,
				'readiness_state' => 'unknown',
				'eligibility_state' => 'unknown',
				'has_player_slot' => null,
				'can_join_round' => null,
				'forced_spectator_state' => null,
			);
		}

		$spectatorState = 'unknown';
		$isSpectator = $this->getSnapshotField($snapshot, 'is_spectator');
		$isTemporarySpectator = $this->getSnapshotField($snapshot, 'is_temporary_spectator');
		$isPureSpectator = $this->getSnapshotField($snapshot, 'is_pure_spectator');

		if ($isSpectator === false) {
			$spectatorState = 'player';
		} else if ($isSpectator === true && $isTemporarySpectator === true) {
			$spectatorState = 'temporary_spectator';
		} else if ($isSpectator === true && $isPureSpectator === true) {
			$spectatorState = 'pure_spectator';
		} else if ($isSpectator === true) {
			$spectatorState = 'spectator';
		}

		return array(
			'connectivity_state' => $this->resolvePlayerConnectivityState($snapshot),
			'spectator_state' => $spectatorState,
			'team_id' => $this->getSnapshotField($snapshot, 'team_id'),
			'readiness_state' => $this->resolvePlayerReadinessState($snapshot),
			'eligibility_state' => $this->resolvePlayerEligibilityState($snapshot),
			'has_player_slot' => $this->getSnapshotField($snapshot, 'has_player_slot'),
			'can_join_round' => $this->resolvePlayerCanJoinRound($snapshot),
			'forced_spectator_state' => $this->getSnapshotField($snapshot, 'forced_spectator_state'),
		);
	}

	/**
	 * @param array $previousState
	 * @param array $currentState
	 * @return array
	 */
	private function buildRosterStateDelta(array $previousState, array $currentState) {
		return array(
			'connectivity_state' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['connectivity_state']) ? $previousState['connectivity_state'] : 'unknown',
				isset($currentState['connectivity_state']) ? $currentState['connectivity_state'] : 'unknown'
			),
			'spectator_state' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['spectator_state']) ? $previousState['spectator_state'] : 'unknown',
				isset($currentState['spectator_state']) ? $currentState['spectator_state'] : 'unknown'
			),
			'team_id' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['team_id']) ? $previousState['team_id'] : null,
				isset($currentState['team_id']) ? $currentState['team_id'] : null
			),
			'readiness_state' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['readiness_state']) ? $previousState['readiness_state'] : 'unknown',
				isset($currentState['readiness_state']) ? $currentState['readiness_state'] : 'unknown'
			),
			'eligibility_state' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['eligibility_state']) ? $previousState['eligibility_state'] : 'unknown',
				isset($currentState['eligibility_state']) ? $currentState['eligibility_state'] : 'unknown'
			),
			'has_player_slot' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['has_player_slot']) ? $previousState['has_player_slot'] : null,
				isset($currentState['has_player_slot']) ? $currentState['has_player_slot'] : null
			),
			'can_join_round' => $this->buildPlayerStateDeltaEntry(
				isset($previousState['can_join_round']) ? $previousState['can_join_round'] : null,
				isset($currentState['can_join_round']) ? $currentState['can_join_round'] : null
			),
		);
	}

	/**
	 * @return array
	 */
	private function buildRosterAggregateSnapshot() {
		$aggregate = array(
			'player_count' => 0,
			'connected_count' => 0,
			'spectator_count' => 0,
			'temporary_spectator_count' => 0,
			'ready_count' => 0,
			'eligibility' => array(
				'eligible' => 0,
				'restricted' => 0,
				'ineligible' => 0,
				'unknown' => 0,
			),
			'readiness' => array(
				'ready' => 0,
				'connected_idle' => 0,
				'joining' => 0,
				'waiting_slot' => 0,
				'spectating' => 0,
				'spectating_temporary' => 0,
				'disconnected' => 0,
				'unknown' => 0,
			),
			'team_distribution' => array(),
		);

		if (!$this->maniaControl) {
			return $aggregate;
		}

		$players = $this->maniaControl->getPlayerManager()->getPlayers(false);
		if (!is_array($players)) {
			return $aggregate;
		}

		foreach ($players as $player) {
			if (!$player instanceof Player) {
				continue;
			}

			$aggregate['player_count']++;
			$playerSnapshot = $this->buildPlayerTelemetrySnapshot($player);
			if (!is_array($playerSnapshot)) {
				continue;
			}

			$connectivityState = $this->resolvePlayerConnectivityState($playerSnapshot);
			if ($connectivityState === 'connected') {
				$aggregate['connected_count']++;
			}

			if ($this->getSnapshotField($playerSnapshot, 'is_spectator') === true) {
				$aggregate['spectator_count']++;
			}

			if ($this->getSnapshotField($playerSnapshot, 'is_temporary_spectator') === true) {
				$aggregate['temporary_spectator_count']++;
			}

			$readinessState = $this->resolvePlayerReadinessState($playerSnapshot);
			if (!array_key_exists($readinessState, $aggregate['readiness'])) {
				$aggregate['readiness'][$readinessState] = 0;
			}
			$aggregate['readiness'][$readinessState]++;
			if ($readinessState === 'ready') {
				$aggregate['ready_count']++;
			}

			$eligibilityState = $this->resolvePlayerEligibilityState($playerSnapshot);
			if (!array_key_exists($eligibilityState, $aggregate['eligibility'])) {
				$aggregate['eligibility'][$eligibilityState] = 0;
			}
			$aggregate['eligibility'][$eligibilityState]++;

			$teamId = $this->getSnapshotField($playerSnapshot, 'team_id');
			$teamKey = ($teamId === null ? 'unknown' : (string) $teamId);
			if (!array_key_exists($teamKey, $aggregate['team_distribution'])) {
				$aggregate['team_distribution'][$teamKey] = 0;
			}
			$aggregate['team_distribution'][$teamKey]++;
		}

		ksort($aggregate['eligibility']);
		ksort($aggregate['readiness']);
		ksort($aggregate['team_distribution']);

		return $aggregate;
	}

	/**
	 * @param array|null $currentPlayerSnapshot
	 * @param array      $transitionDefinition
	 * @return array
	 */
	private function buildPlayerAdminCorrelation($currentPlayerSnapshot, array $transitionDefinition) {
		$this->pruneRecentAdminActionContexts();

		$playerLogin = trim((string) $this->getSnapshotField($currentPlayerSnapshot, 'login'));
		$hasRecentAdminActions = !empty($this->recentAdminActionContexts);
		if ($playerLogin === '' || !$hasRecentAdminActions) {
			return $this->buildEmptyPlayerAdminCorrelation($playerLogin !== '', $hasRecentAdminActions);
		}

		$now = time();
		for ($index = count($this->recentAdminActionContexts) - 1; $index >= 0; $index--) {
			$adminContext = $this->recentAdminActionContexts[$index];
			if (!is_array($adminContext) || !isset($adminContext['observed_at'])) {
				continue;
			}

			$secondsSinceAdminAction = max(0, $now - (int) $adminContext['observed_at']);
			if ($secondsSinceAdminAction > $this->adminCorrelationWindowSeconds) {
				continue;
			}

			$inferenceReasons = array();
			$confidence = 'low';

			$actorLogin = isset($adminContext['actor_login']) ? trim((string) $adminContext['actor_login']) : '';
			$targetId = isset($adminContext['target_id']) ? trim((string) $adminContext['target_id']) : '';
			$targetScope = isset($adminContext['target_scope']) ? trim((string) $adminContext['target_scope']) : 'unknown';

			if ($actorLogin !== '' && strcasecmp($actorLogin, $playerLogin) === 0) {
				$inferenceReasons[] = 'actor_login_match';
				$confidence = 'high';
			}

			if ($targetId !== '' && strcasecmp($targetId, $playerLogin) === 0) {
				$inferenceReasons[] = 'target_id_match';
				$confidence = 'high';
			}

			if (
				$targetScope === 'server'
				|| $targetScope === 'match'
				|| $targetScope === 'map'
				|| $targetScope === 'round'
			) {
				$inferenceReasons[] = 'target_scope_' . $targetScope;
				if ($confidence !== 'high') {
					$confidence = 'medium';
				}
			}

			if (isset($transitionDefinition['transition_kind']) && $transitionDefinition['transition_kind'] === 'batch_refresh') {
				$inferenceReasons[] = 'batch_refresh_transition';
			}

			if (empty($inferenceReasons)) {
				continue;
			}

			$fieldAvailability = array(
				'admin_event_id' => isset($adminContext['event_id']) && trim((string) $adminContext['event_id']) !== '',
				'action_name' => isset($adminContext['action_name']) && trim((string) $adminContext['action_name']) !== '',
				'target_scope' => $targetScope !== '',
				'actor_login' => $actorLogin !== '',
				'target_id' => $targetId !== '',
				'seconds_since_admin_action' => true,
			);

			$missingFields = array();
			foreach ($fieldAvailability as $field => $available) {
				if ($available) {
					continue;
				}

				$missingFields[] = $field;
			}

			return array(
				'correlated' => true,
				'window_seconds' => $this->adminCorrelationWindowSeconds,
				'confidence' => $confidence,
				'matched_by' => $inferenceReasons[0],
				'seconds_since_admin_action' => $secondsSinceAdminAction,
				'inference_reasons' => $inferenceReasons,
				'admin_event' => array(
					'event_id' => isset($adminContext['event_id']) ? (string) $adminContext['event_id'] : '',
					'event_name' => isset($adminContext['event_name']) ? (string) $adminContext['event_name'] : '',
					'source_callback' => isset($adminContext['source_callback']) ? (string) $adminContext['source_callback'] : '',
					'action_name' => isset($adminContext['action_name']) ? (string) $adminContext['action_name'] : '',
					'action_type' => isset($adminContext['action_type']) ? (string) $adminContext['action_type'] : 'unknown',
					'action_phase' => isset($adminContext['action_phase']) ? (string) $adminContext['action_phase'] : 'unknown',
					'target_scope' => $targetScope,
					'target_id' => $targetId,
					'initiator_kind' => isset($adminContext['initiator_kind']) ? (string) $adminContext['initiator_kind'] : 'unknown',
					'actor_login' => $actorLogin,
					'observed_at' => isset($adminContext['observed_at']) ? (int) $adminContext['observed_at'] : 0,
				),
				'field_availability' => $fieldAvailability,
				'missing_fields' => $missingFields,
			);
		}

		return $this->buildEmptyPlayerAdminCorrelation(true, true);
	}

	/**
	 * @param bool $hasPlayerLogin
	 * @param bool $hasRecentAdminActions
	 * @return array
	 */
	private function buildEmptyPlayerAdminCorrelation($hasPlayerLogin, $hasRecentAdminActions) {
		$fieldAvailability = array(
			'player_login' => (bool) $hasPlayerLogin,
			'recent_admin_actions' => (bool) $hasRecentAdminActions,
			'admin_event' => false,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'correlated' => false,
			'window_seconds' => $this->adminCorrelationWindowSeconds,
			'confidence' => 'none',
			'matched_by' => 'none',
			'seconds_since_admin_action' => null,
			'inference_reasons' => array(),
			'admin_event' => null,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	/**
	 * @param string $sourceCallback
	 * @param array  $payload
	 * @param EventEnvelope|null $enqueuedEnvelope
	 */
	private function trackRecentAdminActionContext($sourceCallback, array $payload, $enqueuedEnvelope = null) {
		if (!isset($payload['admin_action']) || !is_array($payload['admin_action'])) {
			return;
		}

		$adminAction = $payload['admin_action'];
		$envelopeArray = ($enqueuedEnvelope instanceof EventEnvelope) ? $enqueuedEnvelope->toArray() : array();
		$actorLogin = '';
		if (isset($adminAction['actor']) && is_array($adminAction['actor']) && isset($adminAction['actor']['login'])) {
			$actorLogin = trim((string) $adminAction['actor']['login']);
		}

		$this->recentAdminActionContexts[] = array(
			'event_id' => isset($envelopeArray['event_id']) ? (string) $envelopeArray['event_id'] : '',
			'event_name' => isset($envelopeArray['event_name']) ? (string) $envelopeArray['event_name'] : $this->buildEventName('lifecycle', $sourceCallback),
			'source_sequence' => isset($envelopeArray['source_sequence']) ? (int) $envelopeArray['source_sequence'] : 0,
			'source_time' => isset($envelopeArray['source_time']) ? (int) $envelopeArray['source_time'] : time(),
			'source_callback' => $sourceCallback,
			'action_name' => isset($adminAction['action_name']) ? (string) $adminAction['action_name'] : 'unknown',
			'action_type' => isset($adminAction['action_type']) ? (string) $adminAction['action_type'] : 'unknown',
			'action_phase' => isset($adminAction['action_phase']) ? (string) $adminAction['action_phase'] : 'unknown',
			'target_scope' => isset($adminAction['target_scope']) ? (string) $adminAction['target_scope'] : 'unknown',
			'target_id' => isset($adminAction['target_id']) ? (string) $adminAction['target_id'] : 'unknown',
			'initiator_kind' => isset($adminAction['initiator_kind']) ? (string) $adminAction['initiator_kind'] : 'unknown',
			'actor_login' => $actorLogin,
			'observed_at' => time(),
		);

		if (count($this->recentAdminActionContexts) > $this->adminCorrelationHistoryLimit) {
			$this->recentAdminActionContexts = array_slice($this->recentAdminActionContexts, -1 * $this->adminCorrelationHistoryLimit);
		}

		$this->pruneRecentAdminActionContexts();
	}

	/**
	 * Remove stale admin action contexts outside correlation window retention.
	 */
	private function pruneRecentAdminActionContexts() {
		if (empty($this->recentAdminActionContexts)) {
			return;
		}

		$minimumObservedAt = time() - ($this->adminCorrelationWindowSeconds * 3);
		$retainedContexts = array();

		foreach ($this->recentAdminActionContexts as $adminContext) {
			if (!is_array($adminContext) || !isset($adminContext['observed_at'])) {
				continue;
			}

			if ((int) $adminContext['observed_at'] < $minimumObservedAt) {
				continue;
			}

			$retainedContexts[] = $adminContext;
		}

		$this->recentAdminActionContexts = $retainedContexts;
	}

	/**
	 * @param mixed $authLevel
	 * @return string
	 */
	private function resolveAuthLevelName($authLevel) {
		if ($authLevel === null || !is_numeric($authLevel)) {
			return 'Unknown';
		}

		switch ((int) $authLevel) {
			case 4:
				return 'MasterAdmin';
			case 3:
				return 'SuperAdmin';
			case 2:
				return 'Admin';
			case 1:
				return 'Moderator';
			case 0:
				return 'Player';
			default:
				return 'Unknown';
		}
	}

	/**
	 * @param mixed $authLevel
	 * @return string
	 */
	private function resolveAuthLevelRole($authLevel) {
		if ($authLevel === null || !is_numeric($authLevel)) {
			return 'unknown';
		}

		switch ((int) $authLevel) {
			case 4:
				return 'master_admin';
			case 3:
				return 'super_admin';
			case 2:
				return 'admin';
			case 1:
				return 'moderator';
			case 0:
				return 'player';
			default:
				return 'unknown';
		}
	}

	/**
	 * @param array|null $currentPlayerSnapshot
	 * @return array|null
	 */
	private function resolvePreviousPlayerSnapshot($currentPlayerSnapshot) {
		if (!is_array($currentPlayerSnapshot) || !isset($currentPlayerSnapshot['login'])) {
			return null;
		}

		$playerLogin = trim((string) $currentPlayerSnapshot['login']);
		if ($playerLogin === '' || !isset($this->playerStateCache[$playerLogin]) || !is_array($this->playerStateCache[$playerLogin])) {
			return null;
		}

		return $this->playerStateCache[$playerLogin];
	}

	/**
	 * @param array $transitionDefinition
	 * @param array|null $currentPlayerSnapshot
	 */
	private function updatePlayerStateCache(array $transitionDefinition, $currentPlayerSnapshot) {
		if (!is_array($currentPlayerSnapshot) || !isset($currentPlayerSnapshot['login'])) {
			return;
		}

		$playerLogin = trim((string) $currentPlayerSnapshot['login']);
		if ($playerLogin === '') {
			return;
		}

		$snapshotToPersist = $currentPlayerSnapshot;
		if (isset($transitionDefinition['forced_connectivity']) && $transitionDefinition['forced_connectivity'] === 'disconnected') {
			$snapshotToPersist['is_connected'] = false;
			$snapshotToPersist['connectivity_state'] = 'disconnected';
			$snapshotToPersist['readiness_state'] = $this->resolvePlayerReadinessState($snapshotToPersist);
			$snapshotToPersist['eligibility_state'] = $this->resolvePlayerEligibilityState($snapshotToPersist);
			$snapshotToPersist['can_join_round'] = $this->resolvePlayerCanJoinRound($snapshotToPersist);
		}

		$this->playerStateCache[$playerLogin] = $snapshotToPersist;
	}

	/**
	 * @param array|null $currentPlayerSnapshot
	 * @param array      $stateDelta
	 * @return array
	 */
	private function buildPlayerPermissionSignals($currentPlayerSnapshot, array $stateDelta) {
		if (!is_array($currentPlayerSnapshot)) {
			$fieldAvailability = array(
				'auth_level' => false,
				'is_referee' => false,
				'has_player_slot' => false,
				'readiness_state' => false,
				'eligibility_state' => false,
				'can_join_round' => false,
				'forced_spectator_state' => false,
				'is_temporary_spectator' => false,
				'is_managed_by_other_server' => false,
			);

			return array(
				'auth_level' => null,
				'auth_name' => 'Unknown',
				'auth_role' => 'unknown',
				'is_referee' => null,
				'has_player_slot' => null,
				'can_admin_actions' => null,
				'readiness_state' => 'unknown',
				'eligibility_state' => 'unknown',
				'can_join_round' => null,
				'forced_spectator_state' => null,
				'is_temporary_spectator' => null,
				'is_managed_by_other_server' => null,
				'auth_level_changed' => false,
				'role_changed' => false,
				'slot_changed' => false,
				'readiness_changed' => false,
				'eligibility_changed' => false,
				'field_availability' => $fieldAvailability,
				'missing_fields' => array_keys($fieldAvailability),
			);
		}

		$authLevel = $this->getSnapshotField($currentPlayerSnapshot, 'auth_level');
		$canAdminActions = null;
		if ($authLevel !== null) {
			$canAdminActions = ((int) $authLevel >= 1);
		}

		$fieldAvailability = array(
			'auth_level' => $authLevel !== null,
			'is_referee' => $this->getSnapshotField($currentPlayerSnapshot, 'is_referee') !== null,
			'has_player_slot' => $this->getSnapshotField($currentPlayerSnapshot, 'has_player_slot') !== null,
			'readiness_state' => trim((string) $this->getSnapshotField($currentPlayerSnapshot, 'readiness_state')) !== '',
			'eligibility_state' => trim((string) $this->getSnapshotField($currentPlayerSnapshot, 'eligibility_state')) !== '',
			'can_join_round' => $this->getSnapshotField($currentPlayerSnapshot, 'can_join_round') !== null,
			'forced_spectator_state' => $this->getSnapshotField($currentPlayerSnapshot, 'forced_spectator_state') !== null,
			'is_temporary_spectator' => $this->getSnapshotField($currentPlayerSnapshot, 'is_temporary_spectator') !== null,
			'is_managed_by_other_server' => $this->getSnapshotField($currentPlayerSnapshot, 'is_managed_by_other_server') !== null,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'auth_level' => $authLevel,
			'auth_name' => $this->getSnapshotField($currentPlayerSnapshot, 'auth_name'),
			'auth_role' => $this->getSnapshotField($currentPlayerSnapshot, 'auth_role'),
			'is_referee' => $this->getSnapshotField($currentPlayerSnapshot, 'is_referee'),
			'has_player_slot' => $this->getSnapshotField($currentPlayerSnapshot, 'has_player_slot'),
			'can_admin_actions' => $canAdminActions,
			'readiness_state' => $this->getSnapshotField($currentPlayerSnapshot, 'readiness_state'),
			'eligibility_state' => $this->getSnapshotField($currentPlayerSnapshot, 'eligibility_state'),
			'can_join_round' => $this->getSnapshotField($currentPlayerSnapshot, 'can_join_round'),
			'forced_spectator_state' => $this->getSnapshotField($currentPlayerSnapshot, 'forced_spectator_state'),
			'is_temporary_spectator' => $this->getSnapshotField($currentPlayerSnapshot, 'is_temporary_spectator'),
			'is_managed_by_other_server' => $this->getSnapshotField($currentPlayerSnapshot, 'is_managed_by_other_server'),
			'auth_level_changed' => isset($stateDelta['auth_level']['changed']) ? (bool) $stateDelta['auth_level']['changed'] : false,
			'role_changed' => isset($stateDelta['auth_role']['changed']) ? (bool) $stateDelta['auth_role']['changed'] : false,
			'slot_changed' => isset($stateDelta['has_player_slot']['changed']) ? (bool) $stateDelta['has_player_slot']['changed'] : false,
			'readiness_changed' => isset($stateDelta['readiness_state']['changed']) ? (bool) $stateDelta['readiness_state']['changed'] : false,
			'eligibility_changed' => isset($stateDelta['eligibility_state']['changed']) ? (bool) $stateDelta['eligibility_state']['changed'] : false,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	/**
	 * @param array      $transitionDefinition
	 * @param array|null $currentPlayerSnapshot
	 * @param array|null $previousPlayerSnapshot
	 * @param array      $stateDelta
	 * @param array      $rosterSnapshot
	 * @param string     $sourceCallback
	 * @param int        $transitionSequence
	 * @param int        $observedAt
	 * @return array
	 */
	private function buildPlayerConstraintSignals(
		array $transitionDefinition,
		$currentPlayerSnapshot,
		$previousPlayerSnapshot,
		array $stateDelta,
		array $rosterSnapshot,
		$sourceCallback,
		$transitionSequence,
		$observedAt
	) {
		$policyContext = $this->resolvePlayerConstraintPolicyContext(false);
		$playerLogin = $this->resolvePlayerLoginFromSnapshots($currentPlayerSnapshot, $previousPlayerSnapshot);
		$playerTeamId = $this->getSnapshotField($currentPlayerSnapshot, 'team_id');
		$previousTeamId = $this->getSnapshotField($previousPlayerSnapshot, 'team_id');
		$hasPlayerSlot = $this->getSnapshotField($currentPlayerSnapshot, 'has_player_slot');
		$forcedSpectatorState = $this->getSnapshotField($currentPlayerSnapshot, 'forced_spectator_state');
		$isSpectator = $this->getSnapshotField($currentPlayerSnapshot, 'is_spectator');

		$teamChanged = false;
		if (isset($stateDelta['team_id']) && is_array($stateDelta['team_id']) && isset($stateDelta['team_id']['changed'])) {
			$teamChanged = (bool) $stateDelta['team_id']['changed'];
		} else {
			$teamChanged = ($playerTeamId !== $previousTeamId);
		}

		$forcedTeamsEnabled = null;
		if (array_key_exists('forced_teams_enabled', $policyContext)) {
			$forcedTeamsEnabled = $policyContext['forced_teams_enabled'];
		}

		$keepPlayerSlots = null;
		if (array_key_exists('keep_player_slots', $policyContext)) {
			$keepPlayerSlots = $policyContext['keep_player_slots'];
		}

		$maxPlayersCurrent = null;
		$maxPlayersNext = null;
		if (isset($policyContext['max_players']) && is_array($policyContext['max_players'])) {
			if (isset($policyContext['max_players']['current']) && is_numeric($policyContext['max_players']['current'])) {
				$maxPlayersCurrent = max(0, (int) $policyContext['max_players']['current']);
			}
			if (isset($policyContext['max_players']['next']) && is_numeric($policyContext['max_players']['next'])) {
				$maxPlayersNext = max(0, (int) $policyContext['max_players']['next']);
			}
		}

		$maxSpectatorsCurrent = null;
		$maxSpectatorsNext = null;
		if (isset($policyContext['max_spectators']) && is_array($policyContext['max_spectators'])) {
			if (isset($policyContext['max_spectators']['current']) && is_numeric($policyContext['max_spectators']['current'])) {
				$maxSpectatorsCurrent = max(0, (int) $policyContext['max_spectators']['current']);
			}
			if (isset($policyContext['max_spectators']['next']) && is_numeric($policyContext['max_spectators']['next'])) {
				$maxSpectatorsNext = max(0, (int) $policyContext['max_spectators']['next']);
			}
		}

		$activePlayers = (isset($rosterSnapshot['active']) && is_numeric($rosterSnapshot['active']))
			? max(0, (int) $rosterSnapshot['active'])
			: null;
		$availablePlayerSlots = null;
		$playerCapacityUtilization = null;
		if ($activePlayers !== null && $maxPlayersCurrent !== null && $maxPlayersCurrent > 0) {
			$availablePlayerSlots = max(0, $maxPlayersCurrent - $activePlayers);
			$playerCapacityUtilization = round($activePlayers / $maxPlayersCurrent, 4);
		}

		$forcedTeamPolicyState = 'unavailable';
		$forcedTeamReason = 'forced_team_policy_unavailable';
		if ($forcedTeamsEnabled === true) {
			if ($playerTeamId === null || $playerTeamId === '') {
				$forcedTeamPolicyState = 'enforced_missing_assignment';
				$forcedTeamReason = 'forced_team_policy_enabled_missing_team_assignment';
			} else if ($teamChanged) {
				$forcedTeamPolicyState = 'enforced_assignment_changed';
				$forcedTeamReason = 'forced_team_policy_enabled_team_changed';
			} else {
				$forcedTeamPolicyState = 'enforced_assignment_stable';
				$forcedTeamReason = 'forced_team_policy_enabled';
			}
		} else if ($forcedTeamsEnabled === false) {
			$forcedTeamPolicyState = 'disabled';
			$forcedTeamReason = 'forced_team_policy_disabled';
		}

		$slotPolicyState = 'unavailable';
		$slotPolicyReason = 'slot_policy_unavailable';
		if ($hasPlayerSlot === true) {
			if ($isSpectator === true && $keepPlayerSlots === true) {
				$slotPolicyState = 'slot_retained_while_spectating';
				$slotPolicyReason = 'slot_retained_by_keep_player_slots';
			} else {
				$slotPolicyState = 'slot_assigned';
				$slotPolicyReason = 'slot_assigned';
			}
		} else if ($hasPlayerSlot === false) {
			$slotPolicyState = 'slot_restricted';
			if (is_numeric($forcedSpectatorState) && (int) $forcedSpectatorState > 0) {
				$slotPolicyReason = 'slot_restricted_by_forced_spectator_state';
			} else if ($availablePlayerSlots !== null && $availablePlayerSlots === 0) {
				$slotPolicyReason = 'slot_restricted_player_limit_reached_or_reserved';
			} else {
				$slotPolicyReason = 'slot_restricted_signal_detected';
			}
		} else if (isset($policyContext['available']) && $policyContext['available']) {
			$slotPolicyState = 'slot_state_unknown';
			$slotPolicyReason = 'slot_policy_available_player_slot_unknown';
		}

		$playerTransitionSequence = $this->resolvePlayerTransitionSequenceForLogin($playerLogin);

		$fieldAvailability = array(
			'policy_context' => isset($policyContext['available']) ? (bool) $policyContext['available'] : false,
			'forced_teams_enabled' => $forcedTeamsEnabled !== null,
			'keep_player_slots' => $keepPlayerSlots !== null,
			'max_players_current' => $maxPlayersCurrent !== null,
			'max_spectators_current' => $maxSpectatorsCurrent !== null,
			'player_team_id' => $playerTeamId !== null,
			'has_player_slot' => $hasPlayerSlot !== null,
			'forced_spectator_state' => $forcedSpectatorState !== null,
			'player_transition_sequence' => $playerTransitionSequence !== null,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'event_kind' => isset($transitionDefinition['event_kind']) ? (string) $transitionDefinition['event_kind'] : 'player.unknown',
			'source_callback' => (string) $sourceCallback,
			'observed_at' => (int) $observedAt,
			'policy_context' => array(
				'available' => isset($policyContext['available']) ? (bool) $policyContext['available'] : false,
				'source' => isset($policyContext['source']) ? (string) $policyContext['source'] : 'unavailable',
				'captured_at' => isset($policyContext['captured_at']) ? (int) $policyContext['captured_at'] : 0,
				'cache_age_seconds' => isset($policyContext['cache_age_seconds']) ? (int) $policyContext['cache_age_seconds'] : null,
				'cache_ttl_seconds' => $this->playerConstraintPolicyTtlSeconds,
				'unavailable_reason' => isset($policyContext['unavailable_reason'])
					? (string) $policyContext['unavailable_reason']
					: 'unknown',
				'failure_codes' => (isset($policyContext['failure_codes']) && is_array($policyContext['failure_codes']))
					? $policyContext['failure_codes']
					: array(),
			),
			'forced_team_policy' => array(
				'available' => $forcedTeamsEnabled !== null,
				'enabled' => $forcedTeamsEnabled,
				'policy_state' => $forcedTeamPolicyState,
				'reason' => $forcedTeamReason,
				'player_team_id' => $playerTeamId,
				'previous_team_id' => $previousTeamId,
				'team_changed' => $teamChanged,
			),
			'slot_policy' => array(
				'available' => ($keepPlayerSlots !== null || $maxPlayersCurrent !== null || $maxSpectatorsCurrent !== null),
				'keep_player_slots' => $keepPlayerSlots,
				'max_players' => array(
					'current' => $maxPlayersCurrent,
					'next' => $maxPlayersNext,
				),
				'max_spectators' => array(
					'current' => $maxSpectatorsCurrent,
					'next' => $maxSpectatorsNext,
				),
				'policy_state' => $slotPolicyState,
				'reason' => $slotPolicyReason,
				'has_player_slot' => $hasPlayerSlot,
				'forced_spectator_state' => $forcedSpectatorState,
				'pressure' => array(
					'active_players' => $activePlayers,
					'max_players_current' => $maxPlayersCurrent,
					'available_player_slots' => $availablePlayerSlots,
					'player_capacity_utilization' => $playerCapacityUtilization,
				),
			),
			'ordering' => array(
				'global_transition_sequence' => (int) $transitionSequence,
				'player_transition_sequence' => $playerTransitionSequence,
			),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	/**
	 * @return array
	 */
	private function resolvePlayerConstraintPolicyContext($allowRefresh = true) {
		$now = time();
		$hasCachedContext = is_array($this->playerConstraintPolicyCache) && $this->playerConstraintPolicyCapturedAt > 0;

		if ($hasCachedContext) {
			$cachedContext = $this->playerConstraintPolicyCache;
			$cachedContext['cache_age_seconds'] = max(0, $now - $this->playerConstraintPolicyCapturedAt);
			$isStale = ((int) $cachedContext['cache_age_seconds']) > $this->playerConstraintPolicyTtlSeconds;
			if (!$isStale || !$allowRefresh) {
				if ($isStale) {
					$cachedContext['stale'] = true;
					if (isset($cachedContext['available']) && !$cachedContext['available']) {
						$cachedContext['unavailable_reason'] = 'policy_context_stale_refresh_deferred';
					}
				}
				return $cachedContext;
			}
		}

		if (!$allowRefresh) {
			return array(
				'available' => false,
				'source' => 'dedicated_api',
				'captured_at' => $now,
				'cache_age_seconds' => 0,
				'unavailable_reason' => 'policy_context_refresh_deferred',
				'forced_teams_enabled' => null,
				'keep_player_slots' => null,
				'max_players' => $this->buildServerLimitPolicySnapshot(null, 'max_players_unavailable'),
				'max_spectators' => $this->buildServerLimitPolicySnapshot(null, 'max_spectators_unavailable'),
				'failure_codes' => array('policy_context_refresh_deferred'),
				'field_availability' => array(
					'forced_teams_enabled' => false,
					'keep_player_slots' => false,
					'max_players' => false,
					'max_spectators' => false,
				),
				'missing_fields' => array('forced_teams_enabled', 'keep_player_slots', 'max_players', 'max_spectators'),
			);
		}

		if (!$this->maniaControl || !$this->maniaControl->getClient()) {
			$context = array(
				'available' => false,
				'source' => 'dedicated_api',
				'captured_at' => $now,
				'cache_age_seconds' => 0,
				'unavailable_reason' => 'dedicated_client_unavailable',
				'forced_teams_enabled' => null,
				'keep_player_slots' => null,
				'max_players' => $this->buildServerLimitPolicySnapshot(null, 'max_players_unavailable'),
				'max_spectators' => $this->buildServerLimitPolicySnapshot(null, 'max_spectators_unavailable'),
				'failure_codes' => array('dedicated_client_unavailable'),
				'field_availability' => array(
					'forced_teams_enabled' => false,
					'keep_player_slots' => false,
					'max_players' => false,
					'max_spectators' => false,
				),
				'missing_fields' => array('forced_teams_enabled', 'keep_player_slots', 'max_players', 'max_spectators'),
			);

			$this->playerConstraintPolicyCache = $context;
			$this->playerConstraintPolicyCapturedAt = $now;
			return $context;
		}

		$client = $this->maniaControl->getClient();
		$forcedTeamsEnabled = null;
		$keepPlayerSlots = null;
		$maxPlayersSnapshot = $this->buildServerLimitPolicySnapshot(null, 'max_players_unavailable');
		$maxSpectatorsSnapshot = $this->buildServerLimitPolicySnapshot(null, 'max_spectators_unavailable');
		$failureCodes = array();

		try {
			$forcedTeamsEnabled = (bool) $client->getForcedTeams();
		} catch (\Throwable $throwable) {
			$failureCodes[] = 'forced_teams_fetch_failed';
			$this->logPlayerConstraintPolicyFetchFailure('forced_teams', $throwable);
		}

		try {
			$keepPlayerSlots = (bool) $client->isKeepingPlayerSlots();
		} catch (\Throwable $throwable) {
			$failureCodes[] = 'keep_player_slots_fetch_failed';
			$this->logPlayerConstraintPolicyFetchFailure('keep_player_slots', $throwable);
		}

		try {
			$maxPlayersSnapshot = $this->buildServerLimitPolicySnapshot($client->getMaxPlayers(), 'max_players_unavailable');
		} catch (\Throwable $throwable) {
			$failureCodes[] = 'max_players_fetch_failed';
			$this->logPlayerConstraintPolicyFetchFailure('max_players', $throwable);
		}

		try {
			$maxSpectatorsSnapshot = $this->buildServerLimitPolicySnapshot($client->getMaxSpectators(), 'max_spectators_unavailable');
		} catch (\Throwable $throwable) {
			$failureCodes[] = 'max_spectators_fetch_failed';
			$this->logPlayerConstraintPolicyFetchFailure('max_spectators', $throwable);
		}

		$fieldAvailability = array(
			'forced_teams_enabled' => $forcedTeamsEnabled !== null,
			'keep_player_slots' => $keepPlayerSlots !== null,
			'max_players' => isset($maxPlayersSnapshot['available']) ? (bool) $maxPlayersSnapshot['available'] : false,
			'max_spectators' => isset($maxSpectatorsSnapshot['available']) ? (bool) $maxSpectatorsSnapshot['available'] : false,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		$contextAvailable = false;
		foreach ($fieldAvailability as $available) {
			if ($available) {
				$contextAvailable = true;
				break;
			}
		}

		$unavailableReason = 'available';
		if (!$contextAvailable) {
			$unavailableReason = (!empty($failureCodes) ? 'dedicated_policy_fetch_failed' : 'dedicated_policy_not_exposed');
		}

		$context = array(
			'available' => $contextAvailable,
			'source' => 'dedicated_api',
			'captured_at' => $now,
			'cache_age_seconds' => 0,
			'unavailable_reason' => $unavailableReason,
			'forced_teams_enabled' => $forcedTeamsEnabled,
			'keep_player_slots' => $keepPlayerSlots,
			'max_players' => $maxPlayersSnapshot,
			'max_spectators' => $maxSpectatorsSnapshot,
			'failure_codes' => array_values(array_unique($failureCodes)),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);

		$this->playerConstraintPolicyCache = $context;
		$this->playerConstraintPolicyCapturedAt = $now;

		return $context;
	}

	/**
	 * @param mixed  $rawServerLimit
	 * @param string $unavailableReason
	 * @return array
	 */
	private function buildServerLimitPolicySnapshot($rawServerLimit, $unavailableReason) {
		$currentValue = $this->resolveServerLimitValue($rawServerLimit, 'CurrentValue');
		$nextValue = $this->resolveServerLimitValue($rawServerLimit, 'NextValue');

		if ($currentValue === null && $nextValue !== null) {
			$currentValue = $nextValue;
		}

		if ($nextValue === null && $currentValue !== null) {
			$nextValue = $currentValue;
		}

		$available = ($currentValue !== null || $nextValue !== null);

		return array(
			'available' => $available,
			'current' => $currentValue,
			'next' => $nextValue,
			'reason' => ($available ? 'available' : (string) $unavailableReason),
		);
	}

	/**
	 * @param mixed  $rawServerLimit
	 * @param string $preferredKey
	 * @return int|null
	 */
	private function resolveServerLimitValue($rawServerLimit, $preferredKey) {
		if (is_numeric($rawServerLimit)) {
			return max(0, (int) $rawServerLimit);
		}

		if (!is_array($rawServerLimit)) {
			return null;
		}

		if (isset($rawServerLimit[$preferredKey]) && is_numeric($rawServerLimit[$preferredKey])) {
			return max(0, (int) $rawServerLimit[$preferredKey]);
		}

		$normalizedPreferredKey = strtolower((string) $preferredKey);
		foreach ($rawServerLimit as $rawKey => $rawValue) {
			if (!is_string($rawKey) || strtolower($rawKey) !== $normalizedPreferredKey || !is_numeric($rawValue)) {
				continue;
			}

			return max(0, (int) $rawValue);
		}

		return null;
	}

	/**
	 * @param string     $policyKey
	 * @param \Throwable $throwable
	 */
	private function logPlayerConstraintPolicyFetchFailure($policyKey, \Throwable $throwable) {
		$now = time();
		if (($now - $this->playerConstraintPolicyErrorLogAt) < $this->playerConstraintPolicyErrorCooldownSeconds) {
			return;
		}

		$this->playerConstraintPolicyErrorLogAt = $now;
		$reason = trim((string) $throwable->getMessage());
		if ($reason === '') {
			$reason = 'unknown';
		}

		Logger::logWarning(
			'[PixelControl][player][policy_fetch_failed] policy=' . (string) $policyKey
			. ', reason=' . $reason
			. '.'
		);
	}

	/**
	 * @param string $playerLogin
	 * @return int|null
	 */
	private function resolvePlayerTransitionSequenceForLogin($playerLogin) {
		$normalizedPlayerLogin = trim((string) $playerLogin);
		if ($normalizedPlayerLogin === '') {
			return null;
		}

		if (!isset($this->playerSessionStateCache[$normalizedPlayerLogin]) || !is_array($this->playerSessionStateCache[$normalizedPlayerLogin])) {
			return null;
		}

		$sessionState = $this->playerSessionStateCache[$normalizedPlayerLogin];
		if (!isset($sessionState['player_transition_count']) || !is_numeric($sessionState['player_transition_count'])) {
			return null;
		}

		$transitionCount = (int) $sessionState['player_transition_count'];
		if ($transitionCount < 1) {
			return null;
		}

		return $transitionCount;
	}

	/**
	 * @param array|null $snapshot
	 * @param string     $field
	 * @return mixed
	 */
	private function getSnapshotField($snapshot, $field) {
		if (!is_array($snapshot) || !array_key_exists($field, $snapshot)) {
			return null;
		}

		return $snapshot[$field];
	}

	/**
	 * @param string $variant
	 * @param string $sourceCallback
	 * @return array|null
	 */
	private function buildLifecycleAggregateTelemetry($variant, $sourceCallback) {
		if (!$this->playerCombatStatsStore) {
			return null;
		}

		$currentCounters = $this->playerCombatStatsStore->snapshotAll();
		$observedAt = time();

		if ($variant === 'map.begin') {
			$this->openMapAggregateWindow($currentCounters, $observedAt, $sourceCallback);
			$this->openRoundAggregateWindow($currentCounters, $observedAt, $sourceCallback);
			return null;
		}

		if ($variant === 'round.begin') {
			$this->openRoundAggregateWindow($currentCounters, $observedAt, $sourceCallback);
			return null;
		}

		$scope = null;
		$windowBaseline = null;
		$windowStartedAt = 0;
		$windowStartedBy = 'unknown';

		if ($variant === 'round.end') {
			$scope = 'round';
			$windowBaseline = $this->roundAggregateBaseline;
			$windowStartedAt = $this->roundAggregateStartedAt;
			$windowStartedBy = $this->roundAggregateStartedBy;
		} else if ($variant === 'map.end') {
			$scope = 'map';
			$windowBaseline = $this->mapAggregateBaseline;
			$windowStartedAt = $this->mapAggregateStartedAt;
			$windowStartedBy = $this->mapAggregateStartedBy;
		}

		if ($scope === null) {
			return null;
		}

		$baselineInitialized = is_array($windowBaseline);
		if (!$baselineInitialized) {
			$windowBaseline = array();
		}

		$counterDelta = $this->buildCombatCounterDelta($windowBaseline, $currentCounters);
		$totals = $this->buildCombatCounterTotals($counterDelta);
		$teamCounterBundle = $this->buildTeamCounterDelta($counterDelta);
		$teamCounters = isset($teamCounterBundle['teams']) && is_array($teamCounterBundle['teams']) ? $teamCounterBundle['teams'] : array();
		$teamSourceCounts = isset($teamCounterBundle['assignment_source_counts']) && is_array($teamCounterBundle['assignment_source_counts'])
			? $teamCounterBundle['assignment_source_counts']
			: array('player_manager' => 0, 'scores_snapshot' => 0, 'unknown' => 0);
		$unresolvedTeamPlayers = isset($teamCounterBundle['unresolved_players']) && is_array($teamCounterBundle['unresolved_players'])
			? $teamCounterBundle['unresolved_players']
			: array();
		$winContext = $this->buildWinContextSnapshot($scope);

		$fieldAvailability = array(
			'combat_store' => true,
			'window_baseline' => $baselineInitialized,
			'window_started_at' => $windowStartedAt > 0,
			'scores_context' => is_array($this->latestScoresSnapshot),
			'team_counters_delta' => !empty($teamCounters),
			'win_context_result' => is_array($winContext) && isset($winContext['result_state']),
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		$aggregatePayload = array(
			'scope' => $scope,
			'window_state' => 'closed',
			'counter_scope' => 'combat_delta',
			'counter_keys' => $this->getCombatCounterKeys(),
			'player_counters_delta' => $counterDelta,
			'team_counters_delta' => $teamCounters,
			'team_summary' => array(
				'team_count' => count($teamCounters),
				'assignment_source_counts' => $teamSourceCounts,
				'unresolved_player_logins' => $unresolvedTeamPlayers,
			),
			'totals' => $totals,
			'tracked_player_count' => count($counterDelta),
			'window' => array(
				'started_at' => $windowStartedAt,
				'ended_at' => $observedAt,
				'duration_seconds' => ($windowStartedAt > 0 ? max(0, $observedAt - $windowStartedAt) : null),
				'started_by_callback' => $windowStartedBy,
				'ended_by_callback' => $sourceCallback,
			),
			'source_coverage' => array(
				'combat_callbacks' => array(
					'shootmania_event_onshoot',
					'shootmania_event_onhit',
					'shootmania_event_onnearmiss',
					'shootmania_event_onarmorempty',
				),
				'score_callback' => 'shootmania_event_scores',
				'team_assignment' => array(
					'resolution_order' => array('player_manager', 'scores_snapshot', 'unknown'),
					'source_counts' => $teamSourceCounts,
					'unresolved_player_logins' => $unresolvedTeamPlayers,
				),
				'win_context' => array(
					'source_callback' => 'shootmania_event_scores',
					'section_scope_match' => isset($winContext['scope_matches_boundary']) ? (bool) $winContext['scope_matches_boundary'] : false,
					'result_state' => isset($winContext['result_state']) ? (string) $winContext['result_state'] : 'unavailable',
					'fallback_applied' => isset($winContext['fallback_applied']) ? (bool) $winContext['fallback_applied'] : true,
				),
				'notes' => array(
					'Counters are derived from callback deltas between lifecycle boundaries.',
					'Accuracy is recomputed from delta hits/shots for each player and totals.',
					'Team aggregates are grouped using player-manager team ids with score-snapshot fallback when runtime player rows are missing.',
				),
			),
			'win_context' => $winContext,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);

		if ($scope === 'round') {
			$this->openRoundAggregateWindow($currentCounters, $observedAt, $sourceCallback);
		} else {
			$this->openMapAggregateWindow($currentCounters, $observedAt, $sourceCallback);
			$this->openRoundAggregateWindow($currentCounters, $observedAt, $sourceCallback);
		}

		return $aggregatePayload;
	}

	/**
	 * @param array $baselineCounters
	 * @param array $currentCounters
	 * @return array
	 */
	private function buildCombatCounterDelta(array $baselineCounters, array $currentCounters) {
		$deltaCounters = array();
		$counterKeys = $this->getCombatCounterKeys();
		$numericCounterKeys = array('kills', 'deaths', 'hits', 'shots', 'misses', 'rockets', 'lasers');

		$logins = array_values(array_unique(array_merge(array_keys($baselineCounters), array_keys($currentCounters))));
		sort($logins);

		foreach ($logins as $login) {
			$baselineRow = array_key_exists($login, $baselineCounters) && is_array($baselineCounters[$login]) ? $baselineCounters[$login] : array();
			$currentRow = array_key_exists($login, $currentCounters) && is_array($currentCounters[$login]) ? $currentCounters[$login] : array();

			$deltaRow = array();
			foreach ($numericCounterKeys as $counterKey) {
				$baselineValue = isset($baselineRow[$counterKey]) ? (int) $baselineRow[$counterKey] : 0;
				$currentValue = isset($currentRow[$counterKey]) ? (int) $currentRow[$counterKey] : 0;
				$deltaRow[$counterKey] = max(0, $currentValue - $baselineValue);
			}

			$deltaShots = isset($deltaRow['shots']) ? (int) $deltaRow['shots'] : 0;
			$deltaHits = isset($deltaRow['hits']) ? (int) $deltaRow['hits'] : 0;
			$deltaRow['accuracy'] = ($deltaShots > 0 ? round($deltaHits / $deltaShots, 4) : 0.0);

			$hasNonZeroCounter = false;
			foreach ($counterKeys as $counterKey) {
				if (!isset($deltaRow[$counterKey])) {
					$deltaRow[$counterKey] = 0;
				}

				if ($counterKey === 'accuracy') {
					continue;
				}

				if ((int) $deltaRow[$counterKey] > 0) {
					$hasNonZeroCounter = true;
				}
			}

			if ($hasNonZeroCounter || !empty($currentRow) || !empty($baselineRow)) {
				$deltaCounters[$login] = $deltaRow;
			}
		}

		ksort($deltaCounters);

		return $deltaCounters;
	}

	/**
	 * @param array $counterRows
	 * @return array
	 */
	private function buildCombatCounterTotals(array $counterRows) {
		$totals = $this->buildZeroCounterRow();

		foreach ($counterRows as $counterRow) {
			if (!is_array($counterRow)) {
				continue;
			}

			$totals['kills'] += isset($counterRow['kills']) ? (int) $counterRow['kills'] : 0;
			$totals['deaths'] += isset($counterRow['deaths']) ? (int) $counterRow['deaths'] : 0;
			$totals['hits'] += isset($counterRow['hits']) ? (int) $counterRow['hits'] : 0;
			$totals['shots'] += isset($counterRow['shots']) ? (int) $counterRow['shots'] : 0;
			$totals['misses'] += isset($counterRow['misses']) ? (int) $counterRow['misses'] : 0;
			$totals['rockets'] += isset($counterRow['rockets']) ? (int) $counterRow['rockets'] : 0;
			$totals['lasers'] += isset($counterRow['lasers']) ? (int) $counterRow['lasers'] : 0;
		}

		$totals['accuracy'] = ($totals['shots'] > 0 ? round($totals['hits'] / $totals['shots'], 4) : 0.0);

		return $totals;
	}

	/**
	 * @param array $playerCounterDelta
	 * @return array
	 */
	private function buildTeamCounterDelta(array $playerCounterDelta) {
		$teamsByKey = array();
		$assignmentSourceCounts = array(
			'player_manager' => 0,
			'scores_snapshot' => 0,
			'unknown' => 0,
		);
		$unresolvedPlayers = array();

		foreach ($playerCounterDelta as $login => $counterRow) {
			if (!is_array($counterRow)) {
				continue;
			}

			$assignment = $this->resolveTeamAssignmentForLogin($login);
			$teamId = isset($assignment['team_id']) ? $assignment['team_id'] : null;
			$source = isset($assignment['source']) ? (string) $assignment['source'] : 'unknown';

			if (!array_key_exists($source, $assignmentSourceCounts)) {
				$source = 'unknown';
			}
			$assignmentSourceCounts[$source]++;

			if ($source === 'unknown') {
				$unresolvedPlayers[] = (string) $login;
			}

			$teamKey = ($teamId === null ? 'unknown' : (string) ((int) $teamId));
			if (!array_key_exists($teamKey, $teamsByKey)) {
				$teamsByKey[$teamKey] = array(
					'team_id' => ($teamId === null ? null : (int) $teamId),
					'team_side' => ($teamId === null ? 'unknown' : 'team_' . (int) $teamId),
					'team_key' => $teamKey,
					'player_logins' => array(),
					'player_count' => 0,
					'totals' => $this->buildZeroCounterRow(),
					'assignment_sources' => array(),
				);
			}

			$teamsByKey[$teamKey]['player_logins'][] = (string) $login;
			if (!in_array($source, $teamsByKey[$teamKey]['assignment_sources'], true)) {
				$teamsByKey[$teamKey]['assignment_sources'][] = $source;
			}

			$teamsByKey[$teamKey]['totals']['kills'] += isset($counterRow['kills']) ? (int) $counterRow['kills'] : 0;
			$teamsByKey[$teamKey]['totals']['deaths'] += isset($counterRow['deaths']) ? (int) $counterRow['deaths'] : 0;
			$teamsByKey[$teamKey]['totals']['hits'] += isset($counterRow['hits']) ? (int) $counterRow['hits'] : 0;
			$teamsByKey[$teamKey]['totals']['shots'] += isset($counterRow['shots']) ? (int) $counterRow['shots'] : 0;
			$teamsByKey[$teamKey]['totals']['misses'] += isset($counterRow['misses']) ? (int) $counterRow['misses'] : 0;
			$teamsByKey[$teamKey]['totals']['rockets'] += isset($counterRow['rockets']) ? (int) $counterRow['rockets'] : 0;
			$teamsByKey[$teamKey]['totals']['lasers'] += isset($counterRow['lasers']) ? (int) $counterRow['lasers'] : 0;
		}

		foreach ($teamsByKey as &$teamRow) {
			sort($teamRow['player_logins']);
			sort($teamRow['assignment_sources']);
			$teamRow['player_count'] = count($teamRow['player_logins']);

			$shots = isset($teamRow['totals']['shots']) ? (int) $teamRow['totals']['shots'] : 0;
			$hits = isset($teamRow['totals']['hits']) ? (int) $teamRow['totals']['hits'] : 0;
			$teamRow['totals']['accuracy'] = ($shots > 0 ? round($hits / $shots, 4) : 0.0);
		}
		unset($teamRow);

		uksort($teamsByKey, function ($left, $right) {
			if ($left === $right) {
				return 0;
			}

			if ($left === 'unknown') {
				return 1;
			}

			if ($right === 'unknown') {
				return -1;
			}

			if (is_numeric($left) && is_numeric($right)) {
				return ((int) $left) - ((int) $right);
			}

			return strcmp((string) $left, (string) $right);
		});

		$unresolvedPlayers = array_values(array_unique($unresolvedPlayers));
		sort($unresolvedPlayers);

		return array(
			'teams' => array_values($teamsByKey),
			'assignment_source_counts' => $assignmentSourceCounts,
			'unresolved_players' => $unresolvedPlayers,
		);
	}

	/**
	 * @param string $login
	 * @return array
	 */
	private function resolveTeamAssignmentForLogin($login) {
		$normalizedLogin = trim((string) $login);
		if ($normalizedLogin === '') {
			return array('team_id' => null, 'source' => 'unknown');
		}

		if ($this->maniaControl) {
			$player = $this->maniaControl->getPlayerManager()->getPlayer($normalizedLogin);
			if ($player instanceof Player && isset($player->teamId) && $player->teamId !== null && $player->teamId !== '') {
				return array('team_id' => (int) $player->teamId, 'source' => 'player_manager');
			}
		}

		$snapshotTeamId = $this->resolveTeamIdFromScoresSnapshot($normalizedLogin);
		if ($snapshotTeamId !== null) {
			return array('team_id' => (int) $snapshotTeamId, 'source' => 'scores_snapshot');
		}

		return array('team_id' => null, 'source' => 'unknown');
	}

	/**
	 * @param string $login
	 * @return int|null
	 */
	private function resolveTeamIdFromScoresSnapshot($login) {
		if (!is_array($this->latestScoresSnapshot) || !isset($this->latestScoresSnapshot['player_scores']) || !is_array($this->latestScoresSnapshot['player_scores'])) {
			return null;
		}

		foreach ($this->latestScoresSnapshot['player_scores'] as $playerScoreRow) {
			if (!is_array($playerScoreRow)) {
				continue;
			}

			$scoreLogin = isset($playerScoreRow['login']) ? trim((string) $playerScoreRow['login']) : '';
			if ($scoreLogin === '' || strcasecmp($scoreLogin, (string) $login) !== 0) {
				continue;
			}

			if (isset($playerScoreRow['team_id']) && is_numeric($playerScoreRow['team_id'])) {
				return (int) $playerScoreRow['team_id'];
			}
		}

		return null;
	}

	/**
	 * @return string[]
	 */
	private function getCombatCounterKeys() {
		return array('kills', 'deaths', 'hits', 'shots', 'misses', 'rockets', 'lasers', 'accuracy');
	}

	/**
	 * @return array
	 */
	private function buildZeroCounterRow() {
		return array(
			'kills' => 0,
			'deaths' => 0,
			'hits' => 0,
			'shots' => 0,
			'misses' => 0,
			'rockets' => 0,
			'lasers' => 0,
			'accuracy' => 0.0,
		);
	}

	/**
	 * @param array $baselineCounters
	 * @param int   $startedAt
	 * @param string $sourceCallback
	 */
	private function openRoundAggregateWindow(array $baselineCounters, $startedAt, $sourceCallback) {
		$this->roundAggregateBaseline = $baselineCounters;
		$this->roundAggregateStartedAt = (int) $startedAt;
		$this->roundAggregateStartedBy = (string) $sourceCallback;
	}

	/**
	 * @param array $baselineCounters
	 * @param int   $startedAt
	 * @param string $sourceCallback
	 */
	private function openMapAggregateWindow(array $baselineCounters, $startedAt, $sourceCallback) {
		$this->mapAggregateBaseline = $baselineCounters;
		$this->mapAggregateStartedAt = (int) $startedAt;
		$this->mapAggregateStartedBy = (string) $sourceCallback;
	}

	/**
	 * @param string $scope
	 * @return array
	 */
	private function buildWinContextSnapshot($scope) {
		if (!is_array($this->latestScoresSnapshot)) {
			$fieldAvailability = array(
				'scores_snapshot' => false,
				'winning_side' => false,
				'winning_reason' => false,
				'result_state' => false,
				'winner_team_id' => false,
				'winner_player_login' => false,
			);

			return array(
				'available' => false,
				'source_callback' => 'shootmania_event_scores',
				'section' => 'unknown',
				'scope_matches_boundary' => false,
				'score_metric' => 'unknown',
				'result_state' => 'unavailable',
				'winning_side' => 'unknown',
				'winning_side_kind' => 'unknown',
				'winning_reason' => 'scores_callback_not_observed',
				'fallback_applied' => true,
				'is_tie' => false,
				'is_draw' => true,
				'score_gap' => null,
				'reason' => 'scores_callback_not_observed',
				'use_teams' => null,
				'winner_team_id' => null,
				'winner_player_login' => '',
				'winner_player_nickname' => '',
				'team_scores' => array(),
				'player_scores' => array(),
				'captured_at' => 0,
				'field_availability' => $fieldAvailability,
				'missing_fields' => array_keys($fieldAvailability),
			);
		}

		$section = isset($this->latestScoresSnapshot['section']) ? (string) $this->latestScoresSnapshot['section'] : 'unknown';
		$normalizedSection = strtolower($section);
		$scopeMatchesBoundary = false;
		if ($scope === 'round' && $normalizedSection === 'endround') {
			$scopeMatchesBoundary = true;
		} else if ($scope === 'map' && ($normalizedSection === 'endmap' || $normalizedSection === 'endmatch')) {
			$scopeMatchesBoundary = true;
		}

		$useTeams = isset($this->latestScoresSnapshot['use_teams']) ? (bool) $this->latestScoresSnapshot['use_teams'] : false;
		$metricKey = $this->resolveWinContextMetricBySection($section);
		$teamScores = isset($this->latestScoresSnapshot['team_scores']) && is_array($this->latestScoresSnapshot['team_scores'])
			? $this->latestScoresSnapshot['team_scores']
			: array();
		$playerScores = isset($this->latestScoresSnapshot['player_scores']) && is_array($this->latestScoresSnapshot['player_scores'])
			? $this->latestScoresSnapshot['player_scores']
			: array();

		$winnerTeamId = isset($this->latestScoresSnapshot['winner_team_id']) && is_numeric($this->latestScoresSnapshot['winner_team_id'])
			? (int) $this->latestScoresSnapshot['winner_team_id']
			: null;
		if ($winnerTeamId !== null && $winnerTeamId < 0) {
			$winnerTeamId = null;
		}

		$winnerPlayerLogin = isset($this->latestScoresSnapshot['winner_player_login'])
			? trim((string) $this->latestScoresSnapshot['winner_player_login'])
			: '';
		$winnerPlayerNickname = isset($this->latestScoresSnapshot['winner_player_nickname'])
			? (string) $this->latestScoresSnapshot['winner_player_nickname']
			: '';

		$resultState = 'draw';
		$winningSide = 'draw';
		$winningSideKind = 'draw';
		$winningReason = 'winner_not_exposed';
		$fallbackApplied = true;
		$isTie = false;
		$isDraw = false;
		$scoreGap = null;

		if ($useTeams) {
			$teamRanking = $this->buildScoreRankingRows($teamScores, $metricKey, 'team_id');
			$scoreGap = $this->resolveScoreGapFromRanking($teamRanking);

			$topTeams = array();
			if (!empty($teamRanking)) {
				$topScore = $teamRanking[0]['score'];
				foreach ($teamRanking as $rankingRow) {
					if (!isset($rankingRow['score']) || $rankingRow['score'] !== $topScore) {
						break;
					}
					$topTeams[] = $rankingRow;
				}
			}

			if ($winnerTeamId !== null) {
				$resultState = 'team_win';
				$winningSide = 'team_' . $winnerTeamId;
				$winningSideKind = 'team';
				$winningReason = 'winner_team_id';
				$fallbackApplied = false;
			} else if (count($topTeams) === 1 && isset($topTeams[0]['id']) && is_numeric($topTeams[0]['id'])) {
				$winnerTeamId = (int) $topTeams[0]['id'];
				$resultState = 'team_win';
				$winningSide = 'team_' . $winnerTeamId;
				$winningSideKind = 'team';
				$winningReason = 'team_score_fallback';
				$fallbackApplied = true;
			} else if (count($topTeams) > 1) {
				$resultState = 'tie';
				$winningSide = 'tie';
				$winningSideKind = 'tie';
				$winningReason = 'team_score_tie';
				$fallbackApplied = true;
				$isTie = true;
			} else {
				$resultState = 'draw';
				$winningSide = 'draw';
				$winningSideKind = 'draw';
				$winningReason = 'team_winner_unavailable';
				$fallbackApplied = true;
				$isDraw = true;
			}
		} else {
			$playerRanking = $this->buildScoreRankingRows($playerScores, $metricKey, 'login');
			$scoreGap = $this->resolveScoreGapFromRanking($playerRanking);

			$topPlayers = array();
			if (!empty($playerRanking)) {
				$topScore = $playerRanking[0]['score'];
				foreach ($playerRanking as $rankingRow) {
					if (!isset($rankingRow['score']) || $rankingRow['score'] !== $topScore) {
						break;
					}
					$topPlayers[] = $rankingRow;
				}
			}

			if ($winnerPlayerLogin !== '') {
				$resultState = 'player_win';
				$winningSide = 'player:' . $winnerPlayerLogin;
				$winningSideKind = 'player';
				$winningReason = 'winner_player_login';
				$fallbackApplied = false;
			} else if (count($topPlayers) === 1 && isset($topPlayers[0]['id']) && trim((string) $topPlayers[0]['id']) !== '') {
				$winnerPlayerLogin = trim((string) $topPlayers[0]['id']);
				$winnerPlayerNickname = $this->resolvePlayerNicknameFromScoreRows($playerScores, $winnerPlayerLogin);
				$resultState = 'player_win';
				$winningSide = 'player:' . $winnerPlayerLogin;
				$winningSideKind = 'player';
				$winningReason = 'player_score_fallback';
				$fallbackApplied = true;
			} else if (count($topPlayers) > 1) {
				$resultState = 'tie';
				$winningSide = 'tie';
				$winningSideKind = 'tie';
				$winningReason = 'player_score_tie';
				$fallbackApplied = true;
				$isTie = true;
			} else {
				$resultState = 'draw';
				$winningSide = 'draw';
				$winningSideKind = 'draw';
				$winningReason = 'player_winner_unavailable';
				$fallbackApplied = true;
				$isDraw = true;
			}
		}

		if (!$scopeMatchesBoundary) {
			$winningReason .= '_scope_mismatch';
			$fallbackApplied = true;
		}

		$fieldAvailability = array(
			'scores_snapshot' => true,
			'section_scope_match' => $scopeMatchesBoundary,
			'score_metric' => $metricKey !== 'unknown',
			'winning_side' => $winningSide !== 'unknown',
			'winning_reason' => $winningReason !== '',
			'result_state' => $resultState !== '',
			'winner_team_id' => $winnerTeamId !== null,
			'winner_player_login' => $winnerPlayerLogin !== '',
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'available' => true,
			'source_callback' => 'shootmania_event_scores',
			'section' => $section,
			'scope_matches_boundary' => $scopeMatchesBoundary,
			'use_teams' => $useTeams,
			'score_metric' => $metricKey,
			'result_state' => $resultState,
			'winning_side' => $winningSide,
			'winning_side_kind' => $winningSideKind,
			'winning_reason' => $winningReason,
			'fallback_applied' => $fallbackApplied,
			'is_tie' => $isTie,
			'is_draw' => $isDraw,
			'score_gap' => $scoreGap,
			'reason' => $winningReason,
			'winner_team_id' => $winnerTeamId,
			'winner_player_login' => $winnerPlayerLogin,
			'winner_player_nickname' => $winnerPlayerNickname,
			'team_scores' => $teamScores,
			'player_scores' => $playerScores,
			'captured_at' => isset($this->latestScoresSnapshot['captured_at']) ? (int) $this->latestScoresSnapshot['captured_at'] : 0,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	/**
	 * @param string $section
	 * @return string
	 */
	private function resolveWinContextMetricBySection($section) {
		switch (strtolower((string) $section)) {
			case 'endround':
				return 'round_points';
			case 'endmatch':
				return 'match_points';
			case 'endmap':
				return 'map_points';
			default:
				return 'map_points';
		}
	}

	/**
	 * @param array  $scoreRows
	 * @param string $metricKey
	 * @param string $idField
	 * @return array
	 */
	private function buildScoreRankingRows(array $scoreRows, $metricKey, $idField) {
		$rankingRows = array();

		foreach ($scoreRows as $scoreRow) {
			if (!is_array($scoreRow) || !isset($scoreRow[$idField])) {
				continue;
			}

			$identifier = $scoreRow[$idField];
			if (is_string($identifier)) {
				$identifier = trim($identifier);
				if ($identifier === '') {
					continue;
				}
			}

			$scoreValue = 0;
			if (isset($scoreRow[$metricKey]) && is_numeric($scoreRow[$metricKey])) {
				$scoreValue = (int) $scoreRow[$metricKey];
			}

			$rankingRows[] = array(
				'id' => $identifier,
				'score' => $scoreValue,
			);
		}

		usort($rankingRows, function ($left, $right) {
			if ((int) $left['score'] === (int) $right['score']) {
				return strcmp((string) $left['id'], (string) $right['id']);
			}

			return ((int) $right['score']) - ((int) $left['score']);
		});

		return $rankingRows;
	}

	/**
	 * @param array $rankingRows
	 * @return int|null
	 */
	private function resolveScoreGapFromRanking(array $rankingRows) {
		if (count($rankingRows) < 2) {
			return null;
		}

		if (!isset($rankingRows[0]['score']) || !isset($rankingRows[1]['score'])) {
			return null;
		}

		return ((int) $rankingRows[0]['score']) - ((int) $rankingRows[1]['score']);
	}

	/**
	 * @param array  $playerScoreRows
	 * @param string $playerLogin
	 * @return string
	 */
	private function resolvePlayerNicknameFromScoreRows(array $playerScoreRows, $playerLogin) {
		foreach ($playerScoreRows as $scoreRow) {
			if (!is_array($scoreRow) || !isset($scoreRow['login'])) {
				continue;
			}

			$scoreLogin = trim((string) $scoreRow['login']);
			if ($scoreLogin === '' || strcasecmp($scoreLogin, (string) $playerLogin) !== 0) {
				continue;
			}

			return isset($scoreRow['nickname']) ? (string) $scoreRow['nickname'] : '';
		}

		return '';
	}

	/**
	 * @param OnScoresStructure $scoresStructure
	 * @return array
	 */
	private function buildScoresContextSnapshot(OnScoresStructure $scoresStructure) {
		$teamScores = array();
		foreach ($scoresStructure->getTeamScores() as $teamId => $teamScore) {
			if (!is_object($teamScore) || !method_exists($teamScore, 'getTeamId')) {
				continue;
			}

			$teamKey = is_numeric($teamId) ? (int) $teamId : (int) $teamScore->getTeamId();
			$teamScores[$teamKey] = array(
				'team_id' => (int) $teamScore->getTeamId(),
				'name' => method_exists($teamScore, 'getName') ? (string) $teamScore->getName() : '',
				'round_points' => method_exists($teamScore, 'getRoundPoints') ? (int) $teamScore->getRoundPoints() : 0,
				'map_points' => method_exists($teamScore, 'getMapPoints') ? (int) $teamScore->getMapPoints() : 0,
				'match_points' => method_exists($teamScore, 'getMatchPoints') ? (int) $teamScore->getMatchPoints() : 0,
			);
		}
		ksort($teamScores);

		$playerScores = array();
		foreach ($scoresStructure->getPlayerScores() as $login => $playerScore) {
			if (!is_object($playerScore) || !method_exists($playerScore, 'getPlayer')) {
				continue;
			}

			$player = $playerScore->getPlayer();
			if (!$player instanceof Player || !isset($player->login)) {
				continue;
			}

			$playerLogin = trim((string) $player->login);
			if ($playerLogin === '') {
				continue;
			}

			$playerScores[$playerLogin] = array(
				'login' => $playerLogin,
				'nickname' => isset($player->nickname) ? (string) $player->nickname : '',
				'team_id' => isset($player->teamId) ? (int) $player->teamId : null,
				'rank' => method_exists($playerScore, 'getRank') ? (int) $playerScore->getRank() : 0,
				'round_points' => method_exists($playerScore, 'getRoundPoints') ? (int) $playerScore->getRoundPoints() : 0,
				'map_points' => method_exists($playerScore, 'getMapPoints') ? (int) $playerScore->getMapPoints() : 0,
				'match_points' => method_exists($playerScore, 'getMatchPoints') ? (int) $playerScore->getMatchPoints() : 0,
			);
		}
		ksort($playerScores);

		$winnerPlayer = $scoresStructure->getWinnerPlayer();

		return array(
			'section' => (string) $scoresStructure->getSection(),
			'use_teams' => (bool) $scoresStructure->getUseTeams(),
			'winner_team_id' => $scoresStructure->getWinnerTeamId(),
			'winner_player_login' => ($winnerPlayer instanceof Player && isset($winnerPlayer->login)) ? (string) $winnerPlayer->login : '',
			'winner_player_nickname' => ($winnerPlayer instanceof Player && isset($winnerPlayer->nickname)) ? (string) $winnerPlayer->nickname : '',
			'team_scores' => array_values($teamScores),
			'player_scores' => array_values($playerScores),
			'captured_at' => time(),
		);
	}

	/**
	 * Reset in-memory veto/draft action buffers.
	 */
	private function resetVetoDraftActions() {
		$this->vetoDraftActions = array();
		$this->vetoDraftActionSequence = 0;
	}

	/**
	 * @param string      $variant
	 * @param string      $sourceCallback
	 * @param array       $callbackArguments
	 * @param array|null  $adminAction
	 */
	private function recordVetoDraftActionFromLifecycle($variant, $sourceCallback, array $callbackArguments, $adminAction = null) {
		$scriptPayload = $this->extractScriptCallbackPayload($callbackArguments);
		$rawActionValue = $this->extractFirstScalarPayloadValue(
			$scriptPayload,
			array('veto_action', 'draft_action', 'action_kind', 'action', 'kind', 'type', 'command')
		);
		$actionKind = $this->normalizeVetoActionKind($rawActionValue);
		$actionStatus = 'explicit';
		$actionSource = 'script_payload';

		if ($actionKind === 'unknown' && is_array($adminAction)) {
			$actionType = isset($adminAction['action_type']) ? (string) $adminAction['action_type'] : 'unknown';
			if ($actionType === 'map_loading' || $actionType === 'map_unloading') {
				$actionKind = 'lock';
				$actionStatus = 'inferred';
				$actionSource = 'admin_action';
			}
		}

		if ($actionKind === 'unknown' && $variant === 'map.begin') {
			$actionKind = 'lock';
			$actionStatus = 'inferred';
			$actionSource = 'map_boundary';
		}

		if ($actionKind === 'unknown') {
			return;
		}

		$currentMapSnapshot = $this->buildCurrentMapSnapshot();
		$mapUid = $this->extractFirstScalarPayloadValue($scriptPayload, array('map_uid', 'mapid', 'map_id', 'uid'));
		$mapName = $this->extractFirstScalarPayloadValue($scriptPayload, array('map_name', 'map'));
		if ($mapUid === '') {
			$mapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';
		}
		if ($mapName === '') {
			$mapName = isset($currentMapSnapshot['name']) ? (string) $currentMapSnapshot['name'] : '';
		}

		$actor = is_array($adminAction) && isset($adminAction['actor']) && is_array($adminAction['actor'])
			? $adminAction['actor']
			: $this->extractActorSnapshotFromPayload($scriptPayload);

		$this->vetoDraftActionSequence++;
		$orderIndex = $this->vetoDraftActionSequence;

		$fieldAvailability = array(
			'action_kind' => $actionKind !== 'unknown',
			'actor' => is_array($actor) && isset($actor['type']) && $actor['type'] !== 'unknown',
			'map_uid' => $mapUid !== '',
			'source_callback' => trim((string) $sourceCallback) !== '',
			'observed_at' => true,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		$this->vetoDraftActions[] = array(
			'order_index' => $orderIndex,
			'action_kind' => $actionKind,
			'action_status' => $actionStatus,
			'action_source' => $actionSource,
			'raw_action_value' => ($rawActionValue !== '' ? $rawActionValue : null),
			'source_callback' => $sourceCallback,
			'source_channel' => ($this->isScriptLifecycleCallback($sourceCallback) ? 'script' : 'maniaplanet'),
			'observed_at' => time(),
			'actor' => $actor,
			'map' => array(
				'uid' => $mapUid,
				'name' => $mapName,
			),
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);

		if (count($this->vetoDraftActions) > $this->vetoDraftActionLimit) {
			$this->vetoDraftActions = array_slice($this->vetoDraftActions, -1 * $this->vetoDraftActionLimit);
		}
	}

	/**
	 * @param string $rawActionValue
	 * @return string
	 */
	private function normalizeVetoActionKind($rawActionValue) {
		$normalizedValue = $this->normalizeIdentifier($rawActionValue, 'unknown');

		switch ($normalizedValue) {
			case 'ban':
			case 'pick':
			case 'pass':
			case 'lock':
				return $normalizedValue;
			case 'map_loading':
			case 'map_lock':
				return 'lock';
			default:
				return 'unknown';
		}
	}

	/**
	 * @param array $currentMapSnapshot
	 * @return array
	 */
	private function buildVetoDraftActionSnapshot(array $currentMapSnapshot) {
		$actions = $this->vetoDraftActions;
		$available = !empty($actions);
		$fieldAvailability = array(
			'actions' => $available,
			'current_map_uid' => isset($currentMapSnapshot['uid']) && trim((string) $currentMapSnapshot['uid']) !== '',
			'supported_action_kinds' => true,
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $availableField) {
			if ($availableField) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'available' => $available,
			'status' => ($available ? 'partial' : 'unavailable'),
			'reason' => ($available
				? 'veto_actions_inferred_from_available_callbacks'
				: 'veto_callbacks_not_exposed_in_current_runtime'),
			'action_count' => count($actions),
			'supported_action_kinds' => array('ban', 'pick', 'pass', 'lock'),
			'actions' => $actions,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	/**
	 * @param array  $vetoDraftActions
	 * @param array  $currentMapSnapshot
	 * @param array  $mapPool
	 * @param string $variant
	 * @return array
	 */
	private function buildVetoResultSnapshot(array $vetoDraftActions, array $currentMapSnapshot, array $mapPool, $variant) {
		$actions = isset($vetoDraftActions['actions']) && is_array($vetoDraftActions['actions'])
			? $vetoDraftActions['actions']
			: array();
		$actionCount = count($actions);
		$currentMapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';

		if ($actionCount === 0) {
			return array(
				'available' => false,
				'status' => 'unavailable',
				'reason' => 'veto_callbacks_not_exposed_in_current_runtime',
				'supported_fields' => array('actor', 'action', 'order', 'timestamp'),
			);
		}

		$lastAction = $actions[$actionCount - 1];
		$lastActionKind = isset($lastAction['action_kind']) ? (string) $lastAction['action_kind'] : 'unknown';

		$fieldAvailability = array(
			'action_count' => true,
			'current_map_uid' => $currentMapUid !== '',
			'last_action_kind' => $lastActionKind !== 'unknown',
			'played_map_order' => !empty($this->playedMapHistory),
			'map_pool' => !empty($mapPool),
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $availableField) {
			if ($availableField) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'available' => true,
			'status' => 'partial',
			'reason' => 'final_selection_inferred_from_partial_veto_actions',
			'variant' => $variant,
			'action_count' => $actionCount,
			'last_action_kind' => $lastActionKind,
			'selection_basis' => array(
				'current_map_uid' => $currentMapUid,
				'played_map_order' => $this->playedMapHistory,
				'map_pool_size' => count($mapPool),
			),
			'final_map' => $currentMapSnapshot,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	/**
	 * @param string $variant
	 * @param string $sourceCallback
	 * @return array|null
	 */
	private function buildLifecycleMapRotationTelemetry($variant, $sourceCallback) {
		if ($variant !== 'map.begin' && $variant !== 'map.end') {
			return null;
		}

		$currentMapSnapshot = $this->buildCurrentMapSnapshot();
		if ($variant === 'map.begin') {
			$this->recordPlayedMapOrderEntry($currentMapSnapshot, $sourceCallback);
		}

		$mapPool = $this->buildMapPoolSnapshot();
		$currentMapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';
		$currentMapIndex = null;
		if ($currentMapUid !== '') {
			foreach ($mapPool as $index => $mapIdentity) {
				if (!isset($mapIdentity['uid'])) {
					continue;
				}

				if ((string) $mapIdentity['uid'] === $currentMapUid) {
					$currentMapIndex = (int) $index;
					break;
				}
			}
		}

		$nextMaps = array();
		$mapPoolSize = count($mapPool);
		if ($currentMapIndex !== null && $mapPoolSize > 0) {
			for ($step = 1; $step <= min(3, $mapPoolSize); $step++) {
				$nextIndex = ($currentMapIndex + $step) % $mapPoolSize;
				if (!isset($mapPool[$nextIndex])) {
					continue;
				}

				$nextMaps[] = $mapPool[$nextIndex];
			}
		}

		$vetoDraftActions = $this->buildVetoDraftActionSnapshot($currentMapSnapshot);
		$vetoResult = $this->buildVetoResultSnapshot($vetoDraftActions, $currentMapSnapshot, $mapPool, $variant);

		$fieldAvailability = array(
			'map_pool' => !empty($mapPool),
			'current_map' => $currentMapUid !== '',
			'current_map_index' => $currentMapIndex !== null,
			'next_maps' => !empty($nextMaps),
			'played_map_order' => !empty($this->playedMapHistory),
			'veto_draft_actions' => isset($vetoDraftActions['available']) ? (bool) $vetoDraftActions['available'] : false,
			'veto_result' => is_array($vetoResult) && isset($vetoResult['status']),
		);

		$missingFields = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingFields[] = $field;
		}

		return array(
			'variant' => $variant,
			'map_pool_size' => $mapPoolSize,
			'current_map' => $currentMapSnapshot,
			'current_map_index' => $currentMapIndex,
			'next_maps' => $nextMaps,
			'map_pool' => $mapPool,
			'played_map_count' => count($this->playedMapHistory),
			'played_map_order' => $this->playedMapHistory,
			'veto_draft_actions' => $vetoDraftActions,
			'veto_result' => $vetoResult,
			'field_availability' => $fieldAvailability,
			'missing_fields' => $missingFields,
		);
	}

	/**
	 * @return array
	 */
	private function buildMapPoolSnapshot() {
		if (!$this->maniaControl) {
			return array();
		}

		$mapPool = array();
		$maps = $this->maniaControl->getMapManager()->getMaps();
		if (!is_array($maps)) {
			return $mapPool;
		}

		foreach (array_values($maps) as $index => $map) {
			if (!$map instanceof Map) {
				continue;
			}

			$mapPool[] = $this->buildMapIdentityFromObject($map, $index);
		}

		return $mapPool;
	}

	/**
	 * @param Map $map
	 * @param int $rotationIndex
	 * @return array
	 */
	private function buildMapIdentityFromObject(Map $map, $rotationIndex) {
		$identity = array(
			'uid' => isset($map->uid) ? (string) $map->uid : '',
			'name' => isset($map->name) ? (string) $map->name : '',
			'file' => isset($map->fileName) ? (string) $map->fileName : '',
			'environment' => isset($map->environment) ? (string) $map->environment : '',
			'map_type' => isset($map->mapType) ? (string) $map->mapType : '',
			'rotation_index' => (int) $rotationIndex,
			'external_ids' => array(),
		);

		if (isset($map->mx) && is_object($map->mx) && isset($map->mx->id) && is_numeric($map->mx->id)) {
			$identity['external_ids']['mx_id'] = (int) $map->mx->id;
		}

		if (empty($identity['external_ids'])) {
			$identity['external_ids'] = null;
		}

		return $identity;
	}

	/**
	 * @param array $currentMapSnapshot
	 * @param string $sourceCallback
	 */
	private function recordPlayedMapOrderEntry(array $currentMapSnapshot, $sourceCallback) {
		$mapUid = isset($currentMapSnapshot['uid']) ? trim((string) $currentMapSnapshot['uid']) : '';
		if ($mapUid === '') {
			return;
		}

		$isRepeat = false;
		if (!empty($this->playedMapHistory)) {
			$lastPlayedMap = $this->playedMapHistory[count($this->playedMapHistory) - 1];
			$lastUid = (isset($lastPlayedMap['uid']) ? (string) $lastPlayedMap['uid'] : '');
			$isRepeat = ($lastUid !== '' && $lastUid === $mapUid);
		}

		$this->playedMapHistory[] = array(
			'order' => count($this->playedMapHistory) + 1,
			'uid' => $mapUid,
			'name' => isset($currentMapSnapshot['name']) ? (string) $currentMapSnapshot['name'] : '',
			'file' => isset($currentMapSnapshot['file']) ? (string) $currentMapSnapshot['file'] : '',
			'environment' => isset($currentMapSnapshot['environment']) ? (string) $currentMapSnapshot['environment'] : '',
			'is_repeat' => $isRepeat,
			'observed_at' => time(),
			'source_callback' => (string) $sourceCallback,
		);

		if (count($this->playedMapHistory) > $this->playedMapHistoryLimit) {
			$this->playedMapHistory = array_slice($this->playedMapHistory, -1 * $this->playedMapHistoryLimit);
		}
	}

	/**
	 * @param string $sourceCallback
	 * @param array  $callbackArguments
	 * @return array
	 */
	private function buildCombatPayload($sourceCallback, array $callbackArguments) {
		$dimensionsBundle = $this->extractCombatDimensions($callbackArguments);
		$dimensions = $dimensionsBundle['dimensions'];
		$fieldAvailability = $dimensionsBundle['field_availability'];
		$callbackObject = $this->extractPrimaryCallbackObject($callbackArguments);
		$eventKind = $this->resolveCombatEventKind($sourceCallback);
		$this->logCombatAction($sourceCallback, $dimensions, $callbackObject);

		$this->updateCombatStatsCounters($sourceCallback, $dimensions);
		$trackedLogins = $this->collectCombatSnapshotLogins($dimensions, $callbackObject);

		$playerCounters = array();
		if ($this->playerCombatStatsStore) {
			if ($eventKind === 'shootmania_event_scores') {
				$playerCounters = $this->playerCombatStatsStore->snapshotAll();
			} else {
				$playerCounters = $this->playerCombatStatsStore->snapshotForPlayers($trackedLogins);
			}
		}

		$missingDimensions = array();
		foreach ($fieldAvailability as $field => $available) {
			if ($available) {
				continue;
			}

			$missingDimensions[] = $field;
		}

		$payload = array(
			'event_kind' => $eventKind,
			'counter_scope' => 'runtime_session',
			'player_counters' => $playerCounters,
			'tracked_player_count' => $this->playerCombatStatsStore ? $this->playerCombatStatsStore->getTrackedPlayerCount() : 0,
			'dimensions' => $dimensions,
			'field_availability' => $fieldAvailability,
			'missing_dimensions' => $missingDimensions,
			'raw_callback_summary' => $this->buildPayloadSummary($callbackArguments),
		);

		if ($callbackObject instanceof OnCaptureStructure) {
			$payload['capture_players'] = array_values($callbackObject->getLoginArray());
		}

		if ($callbackObject instanceof OnScoresStructure) {
			$scoresSnapshot = $this->buildScoresContextSnapshot($callbackObject);
			$this->latestScoresSnapshot = $scoresSnapshot;
			$payload['scores_section'] = isset($scoresSnapshot['section']) ? $scoresSnapshot['section'] : $callbackObject->getSection();
			$payload['scores_snapshot'] = $scoresSnapshot;
			$payload['scores_result'] = $this->buildWinContextSnapshot('score_update');
		}

		return $payload;
	}

	/**
	 * @param array $callbackArguments
	 * @return array
	 */
	private function extractCombatDimensions(array $callbackArguments) {
		$callbackObject = $this->extractPrimaryCallbackObject($callbackArguments);

		$weaponId = null;
		if ($callbackObject && method_exists($callbackObject, 'getWeapon')) {
			$weaponId = (int) $callbackObject->getWeapon();
		}

		$damage = null;
		if ($callbackObject instanceof OnHitStructure) {
			$damage = (int) $callbackObject->getDamage();
		}

		$distance = null;
		if ($callbackObject instanceof OnHitNearMissArmorEmptyBaseStructure) {
			$distance = (float) $callbackObject->getDistance();
		}

		$eventTime = null;
		if ($callbackObject && method_exists($callbackObject, 'getTime')) {
			$eventTime = (int) $callbackObject->getTime();
		}

		$shooter = null;
		if ($callbackObject && method_exists($callbackObject, 'getShooter')) {
			$shooter = $this->buildPlayerIdentity($callbackObject->getShooter());
		}

		$victim = null;
		if ($callbackObject && method_exists($callbackObject, 'getVictim')) {
			$victim = $this->buildPlayerIdentity($callbackObject->getVictim());
		}

		$shooterPosition = null;
		if ($callbackObject && method_exists($callbackObject, 'getShooterPosition')) {
			$shooterPosition = $this->buildPositionSnapshot($callbackObject->getShooterPosition());
		}

		$victimPosition = null;
		if ($callbackObject && method_exists($callbackObject, 'getVictimPosition')) {
			$victimPosition = $this->buildPositionSnapshot($callbackObject->getVictimPosition());
		}

		$dimensions = array(
			'weapon_id' => $weaponId,
			'damage' => $damage,
			'distance' => $distance,
			'event_time' => $eventTime,
			'shooter' => $shooter,
			'victim' => $victim,
			'shooter_position' => $shooterPosition,
			'victim_position' => $victimPosition,
		);

		$fieldAvailability = array(
			'weapon_id' => $weaponId !== null,
			'damage' => $damage !== null,
			'distance' => $distance !== null,
			'event_time' => $eventTime !== null,
			'shooter' => is_array($shooter),
			'victim' => is_array($victim),
			'shooter_position' => is_array($shooterPosition),
			'victim_position' => is_array($victimPosition),
		);

		return array(
			'dimensions' => $dimensions,
			'field_availability' => $fieldAvailability,
		);
	}

	/**
	 * @param string $sourceCallback
	 * @param array  $dimensions
	 */
	private function updateCombatStatsCounters($sourceCallback, array $dimensions) {
		if (!$this->playerCombatStatsStore) {
			return;
		}

		$normalizedSourceCallback = $this->resolveCombatEventKind($sourceCallback);
		$shooterLogin = '';
		if (isset($dimensions['shooter']) && is_array($dimensions['shooter']) && isset($dimensions['shooter']['login'])) {
			$shooterLogin = (string) $dimensions['shooter']['login'];
		}

		$victimLogin = '';
		if (isset($dimensions['victim']) && is_array($dimensions['victim']) && isset($dimensions['victim']['login'])) {
			$victimLogin = (string) $dimensions['victim']['login'];
		}

		$weaponId = null;
		if (isset($dimensions['weapon_id']) && is_numeric($dimensions['weapon_id'])) {
			$weaponId = (int) $dimensions['weapon_id'];
		}

		switch ($normalizedSourceCallback) {
			case 'shootmania_event_onshoot':
				$this->playerCombatStatsStore->recordShot($shooterLogin, $weaponId);
				break;
			case 'shootmania_event_onhit':
				$this->playerCombatStatsStore->recordHit($shooterLogin);
				break;
			case 'shootmania_event_onnearmiss':
				$this->playerCombatStatsStore->recordMiss($shooterLogin);
				break;
			case 'shootmania_event_onarmorempty':
				$this->playerCombatStatsStore->recordKill($shooterLogin, $victimLogin);
				break;
		}
	}

	/**
	 * @param string      $sourceCallback
	 * @param array       $dimensions
	 * @param object|null $callbackObject
	 */
	private function logCombatAction($sourceCallback, array $dimensions, $callbackObject) {
		$eventKind = $this->resolveCombatEventKind($sourceCallback);
		$weaponId = null;
		if (isset($dimensions['weapon_id']) && is_numeric($dimensions['weapon_id'])) {
			$weaponId = (int) $dimensions['weapon_id'];
		}

		$weaponLabel = $this->resolveCombatWeaponLabel($weaponId);
		$shooterLogin = $this->resolveCombatLoginLabel($dimensions, 'shooter', 'unknown_shooter');
		$victimLogin = $this->resolveCombatLoginLabel($dimensions, 'victim', 'unknown_victim');

		switch ($eventKind) {
			case 'shootmania_event_onshoot':
				Logger::log('[Pixel Plugin] ' . $shooterLogin . ' shooted with ' . $weaponLabel . '.');
				return;

			case 'shootmania_event_onhit':
				Logger::log('[Pixel Plugin] ' . $shooterLogin . ' hit someone with ' . $weaponLabel . '.');
				Logger::log('[Pixel Plugin] ' . $victimLogin . ' got hit.');
				return;

			case 'shootmania_event_onnearmiss':
				Logger::log('[Pixel Plugin] ' . $shooterLogin . ' near missed with ' . $weaponLabel . '.');
				return;

			case 'shootmania_event_onarmorempty':
				Logger::log('[Pixel Plugin] ' . $victimLogin . ' armor got emptied by ' . $shooterLogin . ' with ' . $weaponLabel . '.');
				return;

			case 'shootmania_event_oncapture':
				$captureLogins = array();
				if ($callbackObject instanceof OnCaptureStructure) {
					foreach ($callbackObject->getLoginArray() as $login) {
						$normalizedLogin = trim((string) $login);
						if ($normalizedLogin === '') {
							continue;
						}

						$captureLogins[] = $normalizedLogin;
					}
				}

				if (empty($captureLogins)) {
					Logger::log('[Pixel Plugin] Capture event detected.');
					return;
				}

				Logger::log('[Pixel Plugin] Capture event by ' . implode(', ', $captureLogins) . '.');
				return;

			case 'shootmania_event_scores':
				$scoreSection = 'unknown';
				$scorePlayerCount = 0;
				$winnerTeamLabel = 'unknown';
				$winnerPlayerLogin = 'none';
				$useTeamsLabel = 'unknown';
				if ($callbackObject instanceof OnScoresStructure) {
					$scoreSection = (string) $callbackObject->getSection();
					$scorePlayerCount = count($callbackObject->getPlayerScores());
					$useTeamsLabel = $callbackObject->getUseTeams() ? 'true' : 'false';

					$winnerTeamId = $callbackObject->getWinnerTeamId();
					if (is_numeric($winnerTeamId)) {
						$winnerTeamLabel = (string) ((int) $winnerTeamId);
					}

					$winnerPlayer = $callbackObject->getWinnerPlayer();
					if ($winnerPlayer instanceof Player && isset($winnerPlayer->login)) {
						$winnerPlayerCandidate = trim((string) $winnerPlayer->login);
						if ($winnerPlayerCandidate !== '') {
							$winnerPlayerLogin = $winnerPlayerCandidate;
						}
					}
				}

				Logger::log(
					'[Pixel Plugin] Scores updated (section=' . $scoreSection
					. ', players=' . $scorePlayerCount
					. ', use_teams=' . $useTeamsLabel
					. ', winner_team=' . $winnerTeamLabel
					. ', winner_player=' . $winnerPlayerLogin
					. ').'
				);
				return;
		}

		Logger::log('[Pixel Plugin] Combat callback received: ' . $eventKind . '.');
	}

	/**
	 * @param string $sourceCallback
	 * @return string
	 */
	private function resolveCombatEventKind($sourceCallback) {
		$normalized = $this->normalizeIdentifier($sourceCallback, 'unknown');

		if (strpos($normalized, 'onnearmiss') !== false) {
			return 'shootmania_event_onnearmiss';
		}

		if (strpos($normalized, 'onarmorempty') !== false) {
			return 'shootmania_event_onarmorempty';
		}

		if (strpos($normalized, 'oncapture') !== false) {
			return 'shootmania_event_oncapture';
		}

		if (strpos($normalized, 'onscores') !== false || strpos($normalized, 'scores') !== false) {
			return 'shootmania_event_scores';
		}

		if (strpos($normalized, 'onhit') !== false) {
			return 'shootmania_event_onhit';
		}

		if (strpos($normalized, 'onshoot') !== false) {
			return 'shootmania_event_onshoot';
		}

		return $normalized;
	}

	/**
	 * @param array  $dimensions
	 * @param string $key
	 * @param string $fallback
	 * @return string
	 */
	private function resolveCombatLoginLabel(array $dimensions, $key, $fallback) {
		if (!isset($dimensions[$key]) || !is_array($dimensions[$key])) {
			return $fallback;
		}

		$login = isset($dimensions[$key]['login']) ? trim((string) $dimensions[$key]['login']) : '';
		if ($login === '') {
			return $fallback;
		}

		return $login;
	}

	/**
	 * @param int|null $weaponId
	 * @return string
	 */
	private function resolveCombatWeaponLabel($weaponId) {
		if ($weaponId === PlayerCombatStatsStore::WEAPON_LASER) {
			return 'laser';
		}

		if ($weaponId === PlayerCombatStatsStore::WEAPON_ROCKET) {
			return 'rocket';
		}

		switch ((int) $weaponId) {
			case 3:
				return 'nucleus';
			case 4:
				return 'grenade';
			case 5:
				return 'arrow';
			case 6:
				return 'missile';
			default:
				if ($weaponId === null) {
					return 'unknown_weapon';
				}

				return 'weapon_' . (string) $weaponId;
		}
	}

	/**
	 * @param array       $dimensions
	 * @param object|null $callbackObject
	 * @return string[]
	 */
	private function collectCombatSnapshotLogins(array $dimensions, $callbackObject) {
		$logins = array();

		if (isset($dimensions['shooter']) && is_array($dimensions['shooter']) && isset($dimensions['shooter']['login'])) {
			$shooterLogin = trim((string) $dimensions['shooter']['login']);
			if ($shooterLogin !== '') {
				$logins[] = $shooterLogin;
			}
		}

		if (isset($dimensions['victim']) && is_array($dimensions['victim']) && isset($dimensions['victim']['login'])) {
			$victimLogin = trim((string) $dimensions['victim']['login']);
			if ($victimLogin !== '') {
				$logins[] = $victimLogin;
			}
		}

		if ($callbackObject instanceof OnCaptureStructure) {
			foreach ($callbackObject->getLoginArray() as $login) {
				$normalizedLogin = trim((string) $login);
				if ($normalizedLogin === '') {
					continue;
				}

				$logins[] = $normalizedLogin;
			}
		}

		if ($callbackObject instanceof OnScoresStructure) {
			foreach ($callbackObject->getPlayerScores() as $login => $playerScore) {
				if (is_string($login) && trim($login) !== '') {
					$logins[] = trim($login);
					continue;
				}

				if (is_object($playerScore) && method_exists($playerScore, 'getPlayer')) {
					$scorePlayer = $playerScore->getPlayer();
					if ($scorePlayer instanceof Player && isset($scorePlayer->login)) {
						$scoreLogin = trim((string) $scorePlayer->login);
						if ($scoreLogin !== '') {
							$logins[] = $scoreLogin;
						}
					}
				}
			}
		}

		$logins = array_values(array_unique($logins));
		sort($logins);

		return $logins;
	}

	/**
	 * @param array $callbackArguments
	 * @return object|null
	 */
	private function extractPrimaryCallbackObject(array $callbackArguments) {
		if (empty($callbackArguments)) {
			return null;
		}

		$firstArgument = $callbackArguments[0];
		if (!is_object($firstArgument)) {
			return null;
		}

		return $firstArgument;
	}

	/**
	 * @param mixed $player
	 * @return array|null
	 */
	private function buildPlayerIdentity($player) {
		if (!$player instanceof Player) {
			return null;
		}

		return array(
			'login' => isset($player->login) ? (string) $player->login : '',
			'nickname' => isset($player->nickname) ? (string) $player->nickname : '',
			'team_id' => isset($player->teamId) ? (int) $player->teamId : -1,
			'is_spectator' => isset($player->isSpectator) ? (bool) $player->isSpectator : false,
		);
	}

	/**
	 * @param mixed $position
	 * @return array|null
	 */
	private function buildPositionSnapshot($position) {
		if (!$position instanceof Position) {
			return null;
		}

		return array(
			'x' => (float) $position->getX(),
			'y' => (float) $position->getY(),
			'z' => (float) $position->getZ(),
		);
	}

	/**
	 * @param array $callbackArguments
	 * @return array
	 */
	private function extractScriptLifecycleSnapshot(array $callbackArguments) {
		if (empty($callbackArguments) || !is_array($callbackArguments[0])) {
			return array();
		}

		$scriptCallbackData = $callbackArguments[0];
		$snapshot = array(
			'name' => isset($scriptCallbackData[0]) && is_string($scriptCallbackData[0]) ? $scriptCallbackData[0] : 'unknown',
		);

		if (array_key_exists(1, $scriptCallbackData)) {
			$snapshot['payload_summary'] = $this->buildPayloadSummary(array($scriptCallbackData[1]));
		}

		$payload = $this->extractScriptCallbackPayload($callbackArguments);
		if (!empty($payload)) {
			$snapshot['payload'] = $payload;
		}

		return $snapshot;
	}

	/**
	 * @param string $sourceCallback
	 * @return string
	 */
	private function resolveLifecycleVariant($sourceCallback, array $callbackArguments = array()) {
		switch ($this->normalizeIdentifier($sourceCallback, 'unknown')) {
			case 'maniaplanet_warmup_start':
				return 'warmup.start';
			case 'maniaplanet_warmup_end':
				return 'warmup.end';
			case 'maniaplanet_warmup_status':
				return 'warmup.status';
			case 'maniaplanet_pause_status':
				$pauseStatusPayload = $this->extractScriptCallbackPayload($callbackArguments);
				$pauseActive = $this->extractBooleanPayloadValue($pauseStatusPayload, array('active'));
				if ($pauseActive === true) {
					return 'pause.start';
				}

				if ($pauseActive === false) {
					return 'pause.end';
				}

				return 'pause.status';
			case 'maniaplanet_beginmatch':
			case 'maniaplanet_startmatch_start':
			case 'maniaplanet_startmatch_end':
				return 'match.begin';
			case 'maniaplanet_endmatch':
			case 'maniaplanet_endmatch_start':
			case 'maniaplanet_endmatch_end':
				return 'match.end';
			case 'maniaplanet_beginmap':
			case 'maniaplanet_loadingmap_start':
			case 'maniaplanet_loadingmap_end':
				return 'map.begin';
			case 'maniaplanet_endmap':
			case 'maniaplanet_unloadingmap_start':
			case 'maniaplanet_unloadingmap_end':
				return 'map.end';
			case 'maniaplanet_beginround':
			case 'maniaplanet_startround_start':
			case 'maniaplanet_startround_end':
				return 'round.begin';
			case 'maniaplanet_endround':
			case 'maniaplanet_endround_start':
			case 'maniaplanet_endround_end':
				return 'round.end';
			default:
				return 'lifecycle.unknown';
		}
	}

	/**
	 * @param string $sourceCallback
	 * @return bool
	 */
	private function isScriptLifecycleCallback($sourceCallback) {
			switch ($this->normalizeIdentifier($sourceCallback, 'unknown')) {
				case 'maniaplanet_warmup_start':
				case 'maniaplanet_warmup_end':
				case 'maniaplanet_warmup_status':
				case 'maniaplanet_pause_status':
				case 'maniaplanet_startmatch_start':
				case 'maniaplanet_startmatch_end':
			case 'maniaplanet_endmatch_start':
			case 'maniaplanet_endmatch_end':
			case 'maniaplanet_loadingmap_start':
			case 'maniaplanet_loadingmap_end':
			case 'maniaplanet_unloadingmap_start':
			case 'maniaplanet_unloadingmap_end':
			case 'maniaplanet_startround_start':
			case 'maniaplanet_startround_end':
			case 'maniaplanet_endround_start':
			case 'maniaplanet_endround_end':
				return true;
			default:
				return false;
		}
	}

	/**
	 * @param string $eventCategory
	 * @param string $sourceCallback
	 * @param array  $payload
	 * @param array  $metadata
	 */
	private function enqueueEnvelope($eventCategory, $sourceCallback, array $payload, array $metadata = array()) {
		if (!$this->eventQueue) {
			return null;
		}

		$sourceSequence = $this->nextSourceSequence();
		$eventId = $this->buildEventId($eventCategory, $sourceCallback, $sourceSequence);
		$idempotencyKey = $this->buildIdempotencyKey($eventId);
		$envelope = new EventEnvelope(
			$this->buildEventName($eventCategory, $sourceCallback),
			self::ENVELOPE_SCHEMA_VERSION,
			$eventId,
			$eventCategory,
			$sourceCallback,
			$sourceSequence,
			time(),
			$idempotencyKey,
			$payload,
			$metadata
		);

		$identityValidation = $this->validateEnvelopeIdentity($envelope);
		if (!$identityValidation['valid']) {
			$this->queueIdentityDropCount++;
			Logger::logWarning(
				'[PixelControl][queue][drop_identity_invalid] phase=enqueue'
				. ', event_name=' . $envelope->getEventName()
				. ', event_id=' . $envelope->getEventId()
				. ', key=' . $envelope->getIdempotencyKey()
				. ', reason=' . $identityValidation['reason']
				. ', dropped_identity_total=' . $this->queueIdentityDropCount
				. '.'
			);
			return null;
		}

		$this->enforceQueueCapacity();
		$this->eventQueue->enqueue(new QueueItem($envelope->getIdempotencyKey(), $envelope));
		$this->recordQueueDepthAfterMutation('enqueue');

		return $envelope;
	}

	/**
	 * Dispatches queued events with bounded callback-time work.
	 */
	private function dispatchQueuedEvents() {
		if (!$this->eventQueue || !$this->apiClient) {
			return;
		}

		for ($dispatchCount = 0; $dispatchCount < $this->dispatchBatchSize; $dispatchCount++) {
			$queueItem = $this->eventQueue->dequeueReady();
			if (!$queueItem) {
				return;
			}

			$identityValidation = $this->validateEnvelopeIdentity($queueItem->getEnvelope());
			if (!$identityValidation['valid']) {
				$this->queueIdentityDropCount++;
				Logger::logWarning(
					'[PixelControl][queue][drop_identity_invalid] phase=dispatch'
					. ', event_name=' . $queueItem->getEnvelope()->getEventName()
					. ', event_id=' . $queueItem->getEnvelope()->getEventId()
					. ', key=' . $queueItem->getEnvelope()->getIdempotencyKey()
					. ', reason=' . $identityValidation['reason']
					. ', queue_depth=' . ($this->eventQueue ? $this->eventQueue->count() : 0)
					. ', dropped_identity_total=' . $this->queueIdentityDropCount
					. '.'
				);
				continue;
			}

			$dispatchAccepted = $this->apiClient->sendEvent(
				$queueItem->getEnvelope(),
				$queueItem->getAttempt(),
				function ($delivered, $deliveryError) use ($queueItem) {
					if ($delivered) {
						$this->handleDeliverySuccess($queueItem);
						return;
					}

					$this->handleDeliveryFailure($queueItem, $deliveryError);
				}
			);

			if (!$dispatchAccepted) {
				$this->handleDeliveryFailure($queueItem, 'request enqueue failed');
			}
		}
	}

	/**
	 * Keep queue growth bounded so callback processing remains stable.
	 */
	private function enforceQueueCapacity() {
		if (!$this->eventQueue) {
			return;
		}

		while ($this->eventQueue->count() >= $this->queueMaxSize) {
			$droppedItem = $this->eventQueue->dequeueReady(PHP_INT_MAX);
			if (!$droppedItem) {
				return;
			}

			$this->queueDropCount++;

			Logger::logWarning(
				'[PixelControl][queue][drop_capacity] key=' . $droppedItem->getEnvelope()->getIdempotencyKey()
				. ', queue_depth=' . $this->eventQueue->count()
				. ', queue_max=' . $this->queueMaxSize
				. ', dropped_total=' . $this->queueDropCount
				. '.'
			);
		}
	}

	/**
	 * @param QueueItem                  $queueItem
	 * @param DeliveryError|string|null  $deliveryError
	 */
	private function handleDeliveryFailure(QueueItem $queueItem, $deliveryError = null) {
		if (!$this->eventQueue) {
			return;
		}

		$resolvedDeliveryError = $this->normalizeDeliveryError($deliveryError);
		$reason = $resolvedDeliveryError->getMessage();
		$reasonCode = $resolvedDeliveryError->getCode();
		$retryAfterSeconds = $resolvedDeliveryError->getRetryAfterSeconds();
		$retryDelaySeconds = $this->retryPolicy ? $this->retryPolicy->getDelaySeconds($queueItem) : 0;
		if ($retryAfterSeconds > $retryDelaySeconds) {
			$retryDelaySeconds = $retryAfterSeconds;
		}

		if ($this->retryPolicy && $this->retryPolicy->shouldRetry($queueItem, $resolvedDeliveryError)) {
			$queueItem->markRetry($retryDelaySeconds);
			$this->eventQueue->requeue($queueItem);
			$this->registerOutageFailure($resolvedDeliveryError);
			$this->recordQueueDepthAfterMutation('retry_requeue');

			Logger::logWarning(
				'[PixelControl][queue][retry_scheduled] key=' . $queueItem->getEnvelope()->getIdempotencyKey()
				. ', next_attempt=' . $queueItem->getAttempt()
				. ', code=' . $reasonCode
				. ', retry_after_seconds=' . $retryDelaySeconds
				. ', queue_depth=' . $this->eventQueue->count()
				. ', reason=' . $reason
				. '.'
			);
			return;
		}

		if ($resolvedDeliveryError->isRetryable()) {
			$this->registerOutageFailure($resolvedDeliveryError);
		}

		Logger::logWarning(
			'[PixelControl][queue][drop_retry_exhausted] key=' . $queueItem->getEnvelope()->getIdempotencyKey()
			. ', attempt=' . $queueItem->getAttempt()
			. ', code=' . $reasonCode
			. ', retryable=' . ($resolvedDeliveryError->isRetryable() ? 'yes' : 'no')
			. ', reason=' . $reason
			. '.'
		);
	}

	/**
	 * @param DeliveryError|string|null $deliveryError
	 * @return DeliveryError
	 */
	private function normalizeDeliveryError($deliveryError) {
		if ($deliveryError instanceof DeliveryError) {
			return $deliveryError;
		}

		$message = $deliveryError === null ? 'unknown' : trim((string) $deliveryError);
		if ($message === '') {
			$message = 'unknown';
		}

		return new DeliveryError('delivery_failure', $message, true, 0);
	}

	/**
	 * Reset queue/outage delivery telemetry at startup/unload boundaries.
	 */
	private function resetDeliveryTelemetry() {
		$this->queueHighWatermark = 0;
		$this->queueDropCount = 0;
		$this->queueIdentityDropCount = 0;
		$this->outageActive = false;
		$this->outageStartedAt = 0;
		$this->outageFailureCount = 0;
		$this->outageLastErrorCode = '';
		$this->outageLastErrorMessage = '';
		$this->recoveryFlushPending = false;
		$this->recoverySuccessCount = 0;
		$this->recoveryStartedAt = 0;
	}

	/**
	 * @return array
	 */
	private function buildQueueTelemetrySnapshot() {
		$queueDepth = $this->eventQueue ? $this->eventQueue->count() : 0;

		return array(
			'depth' => $queueDepth,
			'max_size' => $this->queueMaxSize,
			'high_watermark' => max($this->queueHighWatermark, $queueDepth),
			'dropped_on_capacity' => $this->queueDropCount,
			'dropped_on_identity_validation' => $this->queueIdentityDropCount,
			'growth_log_step' => $this->queueGrowthLogStep,
			'recovery_flush_pending' => $this->recoveryFlushPending,
		);
	}

	/**
	 * @return array
	 */
	private function buildRetryTelemetrySnapshot() {
		return array(
			'max_retry_attempts' => $this->apiClient ? $this->apiClient->getMaxRetryAttempts() : 0,
			'retry_backoff_ms' => $this->apiClient ? $this->apiClient->getRetryBackoffMs() : 0,
			'dispatch_batch_size' => $this->dispatchBatchSize,
		);
	}

	/**
	 * @return array
	 */
	private function buildOutageTelemetrySnapshot() {
		return array(
			'active' => $this->outageActive,
			'started_at' => $this->outageStartedAt,
			'failure_count' => $this->outageFailureCount,
			'last_error_code' => $this->outageLastErrorCode,
			'last_error_message' => $this->outageLastErrorMessage,
			'recovery_flush_pending' => $this->recoveryFlushPending,
			'recovery_success_count' => $this->recoverySuccessCount,
			'recovery_started_at' => $this->recoveryStartedAt,
		);
	}

	/**
	 * @param string $mutationSource
	 */
	private function recordQueueDepthAfterMutation($mutationSource) {
		if (!$this->eventQueue) {
			return;
		}

		$currentQueueDepth = $this->eventQueue->count();
		$previousHighWatermark = $this->queueHighWatermark;
		if ($currentQueueDepth <= $previousHighWatermark) {
			return;
		}

		$this->queueHighWatermark = $currentQueueDepth;
		$logStep = max(1, $this->queueGrowthLogStep);
		$previousBucket = (int) floor($previousHighWatermark / $logStep);
		$currentBucket = (int) floor($currentQueueDepth / $logStep);

		if ($previousHighWatermark === 0 || $currentQueueDepth === 1 || $currentBucket > $previousBucket || $currentQueueDepth >= $this->queueMaxSize) {
			Logger::logWarning(
				'[PixelControl][queue][growth] source=' . $mutationSource
				. ', queue_depth=' . $currentQueueDepth
				. ', queue_max=' . $this->queueMaxSize
				. ', high_watermark=' . $this->queueHighWatermark
				. '.'
			);
		}
	}

	/**
	 * @param DeliveryError $deliveryError
	 */
	private function registerOutageFailure(DeliveryError $deliveryError) {
		$this->outageFailureCount++;
		$this->outageLastErrorCode = $deliveryError->getCode();
		$this->outageLastErrorMessage = $deliveryError->getMessage();

		if ($this->outageActive) {
			return;
		}

		$this->outageActive = true;
		$this->outageStartedAt = time();
		$this->recoveryFlushPending = false;
		$this->recoverySuccessCount = 0;
		$this->recoveryStartedAt = 0;

		Logger::logWarning(
			'[PixelControl][queue][outage_entered] queue_depth=' . ($this->eventQueue ? $this->eventQueue->count() : 0)
			. ', code=' . $deliveryError->getCode()
			. ', reason=' . $deliveryError->getMessage()
			. '.'
		);
	}

	/**
	 * @param QueueItem $queueItem
	 */
	private function handleDeliverySuccess(QueueItem $queueItem) {
		$currentQueueDepth = $this->eventQueue ? $this->eventQueue->count() : 0;

		if ($this->outageActive) {
			$outageDurationSeconds = 0;
			if ($this->outageStartedAt > 0) {
				$outageDurationSeconds = max(0, time() - $this->outageStartedAt);
			}

			$this->outageActive = false;
			$this->recoveryFlushPending = true;
			$this->recoverySuccessCount = 1;
			$this->recoveryStartedAt = time();

			Logger::log(
				'[PixelControl][queue][outage_recovered] key=' . $queueItem->getEnvelope()->getIdempotencyKey()
				. ', outage_seconds=' . $outageDurationSeconds
				. ', queue_depth=' . $currentQueueDepth
				. '.'
			);
		} else if ($this->recoveryFlushPending) {
			$this->recoverySuccessCount++;
		}

		if ($this->recoveryFlushPending && $currentQueueDepth === 0) {
			Logger::log(
				'[PixelControl][queue][recovery_flush_complete] delivered_after_recovery=' . $this->recoverySuccessCount
				. ', dropped_on_capacity=' . $this->queueDropCount
				. '.'
			);

			$this->recoveryFlushPending = false;
			$this->recoverySuccessCount = 0;
			$this->recoveryStartedAt = 0;
			$this->outageFailureCount = 0;
			$this->outageLastErrorCode = '';
			$this->outageLastErrorMessage = '';
		}
	}

	/**
	 * @param string $eventCategory
	 * @param string $sourceCallback
	 * @return string
	 */
	private function buildEventName($eventCategory, $sourceCallback) {
		$normalizedCallback = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $sourceCallback), '_'));
		if ($normalizedCallback === '') {
			$normalizedCallback = 'unknown_callback';
		}

		return 'pixel_control.' . $eventCategory . '.' . $normalizedCallback;
	}

	/**
	 * @param string $eventCategory
	 * @param string $sourceCallback
	 * @param int    $sequence
	 * @return string
	 */
	private function buildEventId($eventCategory, $sourceCallback, $sequence) {
		$normalizedCategory = $this->normalizeIdentifier($eventCategory, 'event');
		$normalizedCallback = $this->normalizeIdentifier($sourceCallback, 'unknown_callback');
		$normalizedSequence = max(1, (int) $sequence);

		return 'pc-evt-' . $normalizedCategory . '-' . $normalizedCallback . '-' . $normalizedSequence;
	}

	/**
	 * @param string $eventId
	 * @return string
	 */
	private function buildIdempotencyKey($eventId) {
		return 'pc-idem-' . sha1((string) $eventId);
	}

	/**
	 * @param EventEnvelope $envelope
	 * @return array
	 */
	private function validateEnvelopeIdentity(EventEnvelope $envelope) {
		$validationErrors = array();

		$eventCategory = trim((string) $envelope->getEventCategory());
		$sourceCallback = trim((string) $envelope->getSourceCallback());
		$sourceSequence = (int) $envelope->getSourceSequence();
		$eventName = trim((string) $envelope->getEventName());
		$eventId = trim((string) $envelope->getEventId());
		$idempotencyKey = trim((string) $envelope->getIdempotencyKey());

		$allowedEventCategories = array('connectivity', 'lifecycle', 'player', 'combat', 'mode');
		if (!in_array($eventCategory, $allowedEventCategories, true)) {
			$validationErrors[] = 'event_category_invalid';
		}

		if ($sourceCallback === '') {
			$validationErrors[] = 'source_callback_missing';
		}

		if ($sourceSequence < 1) {
			$validationErrors[] = 'source_sequence_invalid';
		}

		if ($eventName === '') {
			$validationErrors[] = 'event_name_missing';
		}

		if ($eventId === '') {
			$validationErrors[] = 'event_id_missing';
		}

		if ($idempotencyKey === '') {
			$validationErrors[] = 'idempotency_key_missing';
		}

		if ($eventCategory !== '' && $sourceCallback !== '' && $eventName !== '') {
			$expectedEventName = $this->buildEventName($eventCategory, $sourceCallback);
			if ($eventName !== $expectedEventName) {
				$validationErrors[] = 'event_name_mismatch';
			}
		}

		if ($eventCategory !== '' && $sourceCallback !== '' && $sourceSequence > 0 && $eventId !== '') {
			$expectedEventId = $this->buildEventId($eventCategory, $sourceCallback, $sourceSequence);
			if ($eventId !== $expectedEventId) {
				$validationErrors[] = 'event_id_mismatch';
			}
		}

		if ($eventId !== '' && $idempotencyKey !== '') {
			$expectedIdempotencyKey = $this->buildIdempotencyKey($eventId);
			if ($idempotencyKey !== $expectedIdempotencyKey) {
				$validationErrors[] = 'idempotency_key_mismatch';
			}
		}

		return array(
			'valid' => empty($validationErrors),
			'errors' => $validationErrors,
			'reason' => (empty($validationErrors) ? 'ok' : implode(',', $validationErrors)),
		);
	}

	/**
	 * @param string $value
	 * @param string $fallback
	 * @return string
	 */
	private function normalizeIdentifier($value, $fallback) {
		$normalized = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $value), '_'));
		if ($normalized === '') {
			return $fallback;
		}

		return $normalized;
	}

	/**
	 * @param array $callbackArguments
	 * @return string
	 */
	private function extractSourceCallback(array $callbackArguments) {
		if (empty($callbackArguments)) {
			return 'unknown';
		}

		$firstArgument = $callbackArguments[0];
		if (is_array($firstArgument) && isset($firstArgument[0]) && is_string($firstArgument[0])) {
			return $firstArgument[0];
		}

		if (is_object($firstArgument)) {
			return get_class($firstArgument);
		}

		if (is_string($firstArgument)) {
			return $firstArgument;
		}

		return 'unknown';
	}

	/**
	 * @param array $callbackArguments
	 * @return array
	 */
	private function buildPayloadSummary(array $callbackArguments) {
		$summary = array();
		foreach ($callbackArguments as $argument) {
			if (is_object($argument)) {
				$summary[] = array('type' => 'object', 'value' => get_class($argument));
				continue;
			}

			if (is_array($argument)) {
				$summary[] = array('type' => 'array', 'value' => 'size:' . count($argument));
				continue;
			}

			if (is_scalar($argument) || $argument === null) {
				$summary[] = array('type' => gettype($argument), 'value' => $argument);
				continue;
			}

			$summary[] = array('type' => gettype($argument), 'value' => 'unsupported');
		}

		return array(
			'arguments_count' => count($callbackArguments),
			'arguments' => $summary,
		);
	}

	/**
	 * @param array $modeCallbacks
	 * @return int
	 */
	private function countModeCallbackCount(array $modeCallbacks) {
		$total = 0;
		foreach ($modeCallbacks as $callbacks) {
			$total += count($callbacks);
		}

		return $total;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getId()
	 */
	public static function getId() {
		return self::ID;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getName()
	 */
	public static function getName() {
		return self::NAME;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getVersion()
	 */
	public static function getVersion() {
		return self::VERSION;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getAuthor()
	 */
	public static function getAuthor() {
		return self::AUTHOR;
	}

	/**
	 * @see \ManiaControl\Plugins\Plugin::getDescription()
	 */
	public static function getDescription() {
		return self::DESCRIPTION;
	}
}
