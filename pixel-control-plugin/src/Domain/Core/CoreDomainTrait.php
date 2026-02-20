<?php

namespace PixelControl\Domain\Core;

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
trait CoreDomainTrait {
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

	public function load(ManiaControl $maniaControl) {
		Logger::log('[PixelControl] Loading plugin v' . self::VERSION . '.');

		$this->maniaControl = $maniaControl;
		$this->initializeSettings();
		$this->initializeSourceSequence();
		$this->initializeEventPipeline();
		$this->initializeAdminDelegationLayer();
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

	public function unload() {
		Logger::log('[PixelControl] Unloading plugin.');
		$this->unregisterAdminControlEntryPoints();

		$this->nativeAdminGateway = null;
		$this->adminControlEnabled = false;
		$this->adminControlCommandName = 'pcadmin';
		$this->adminControlPauseActive = null;
		$this->adminControlPauseObservedAt = 0;
		$this->adminControlPauseStateMaxAgeSeconds = 120;
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

	public function handleLifecycleCallback(...$callbackArguments) {
		$this->queueCallbackEvent('lifecycle', $callbackArguments);
	}

	public function handlePlayerCallback(...$callbackArguments) {
		$this->queueCallbackEvent('player', $callbackArguments);
	}

	public function handleCombatCallback(...$callbackArguments) {
		$this->queueCallbackEvent('combat', $callbackArguments);
	}

	public function handleModeCallback(...$callbackArguments) {
		$this->queueCallbackEvent('mode', $callbackArguments);
	}

	public function handleDispatchTimerTick() {
		$this->dispatchQueuedEvents();
	}

	public function handleHeartbeatTimerTick() {
		$this->resolvePlayerConstraintPolicyContext(true);
		$this->queueConnectivityEvent('heartbeat', $this->buildHeartbeatPayload());
		$this->dispatchQueuedEvents();
	}

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
		$this->initializeAdminControlSettings();
	}

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

	private function registerPeriodicTimers() {
		if (!$this->maniaControl) {
			return;
		}

		$timerManager = $this->maniaControl->getTimerManager();
		$timerManager->registerTimerListening($this, 'handleDispatchTimerTick', 1000);
		$timerManager->registerTimerListening($this, 'handleHeartbeatTimerTick', $this->heartbeatIntervalSeconds * 1000);

		Logger::log('[PixelControl] Timers registered: dispatch=1s, heartbeat=' . $this->heartbeatIntervalSeconds . 's.');
	}

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

	private function initializeSourceSequence() {
		$seed = (int) floor(microtime(true) * 1000);
		$this->sourceSequence = max(0, $seed);

		Logger::log('[PixelControl] Monotonic source sequence initialized at ' . $this->sourceSequence . '.');
	}

	private function nextSourceSequence() {
		$this->sourceSequence++;
		return $this->sourceSequence;
	}

	private function countModeCallbackCount(array $modeCallbacks) {
		$total = 0;
		foreach ($modeCallbacks as $callbacks) {
			$total += count($callbacks);
		}

		return $total;
	}

	public static function getId() {
		return self::ID;
	}

	public static function getName() {
		return self::NAME;
	}

	public static function getVersion() {
		return self::VERSION;
	}

	public static function getAuthor() {
		return self::AUTHOR;
	}

	public static function getDescription() {
		return self::DESCRIPTION;
	}
}
