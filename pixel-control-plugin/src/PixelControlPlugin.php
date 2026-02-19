<?php

namespace PixelControl;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Logger;
use ManiaControl\ManiaControl;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Plugins\PluginManager;
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

class PixelControlPlugin implements CallbackListener, TimerListener, Plugin {
	const ID = 100001;
	const VERSION = '0.1.0-dev';
	const ENVELOPE_SCHEMA_VERSION = '2026-02-19.1';
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
	/** @var int $sourceSequence */
	private $sourceSequence = 0;
	/** @var int $queueMaxSize */
	private $queueMaxSize = 2000;
	/** @var int $dispatchBatchSize */
	private $dispatchBatchSize = 3;
	/** @var int $heartbeatIntervalSeconds */
	private $heartbeatIntervalSeconds = 15;

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
		$this->heartbeatIntervalSeconds = 15;
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
			'monotonic_source_sequence' => true,
			'async_delivery' => true,
			'local_retry_queue' => true,
			'periodic_heartbeat' => true,
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

		return array(
			'uid' => isset($currentMap->uid) ? (string) $currentMap->uid : '',
			'name' => isset($currentMap->name) ? (string) $currentMap->name : '',
			'file' => isset($currentMap->fileName) ? (string) $currentMap->fileName : '',
			'environment' => isset($currentMap->environment) ? (string) $currentMap->environment : '',
		);
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
		$metadata = $this->buildEnvelopeMetadata($eventCategory, $sourceCallback);

		$this->enqueueEnvelope($eventCategory, $sourceCallback, $payload, $metadata);
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

		return $this->buildPayloadSummary($callbackArguments);
	}

	/**
	 * @param string $eventCategory
	 * @param string $sourceCallback
	 * @return array
	 */
	private function buildEnvelopeMetadata($eventCategory, $sourceCallback) {
		$metadata = array(
			'plugin_version' => self::VERSION,
			'schema_version' => self::ENVELOPE_SCHEMA_VERSION,
			'mode_family' => 'multi-mode',
			'signal_kind' => 'callback',
		);

		if ($eventCategory === 'lifecycle') {
			$metadata['lifecycle_variant'] = $this->resolveLifecycleVariant($sourceCallback);
			$metadata['context'] = $this->buildRuntimeContextSnapshot();
		}

		return $metadata;
	}

	/**
	 * @param string $sourceCallback
	 * @param array  $callbackArguments
	 * @return array
	 */
	private function buildLifecyclePayload($sourceCallback, array $callbackArguments) {
		$variant = $this->resolveLifecycleVariant($sourceCallback);
		$variantParts = explode('.', $variant, 2);
		$isScriptLifecycle = $this->isScriptLifecycleCallback($sourceCallback);

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

		return $payload;
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

		return $snapshot;
	}

	/**
	 * @param string $sourceCallback
	 * @return string
	 */
	private function resolveLifecycleVariant($sourceCallback) {
		switch ($this->normalizeIdentifier($sourceCallback, 'unknown')) {
			case 'maniaplanet_warmup_start':
				return 'warmup.start';
			case 'maniaplanet_warmup_end':
				return 'warmup.end';
			case 'maniaplanet_warmup_status':
				return 'warmup.status';
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
			return;
		}

		$sourceSequence = $this->nextSourceSequence();
		$eventId = $this->buildEventId($eventCategory, $sourceCallback, $sourceSequence);
		$envelope = new EventEnvelope(
			$this->buildEventName($eventCategory, $sourceCallback),
			self::ENVELOPE_SCHEMA_VERSION,
			$eventId,
			$eventCategory,
			$sourceCallback,
			$sourceSequence,
			time(),
			$this->buildIdempotencyKey($eventId),
			$payload,
			$metadata
		);

		$this->enforceQueueCapacity();
		$this->eventQueue->enqueue(new QueueItem($envelope->getIdempotencyKey(), $envelope));
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

			$dispatchAccepted = $this->apiClient->sendEvent(
				$queueItem->getEnvelope(),
				$queueItem->getAttempt(),
				function ($delivered, $deliveryError) use ($queueItem) {
					if ($delivered) {
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

			Logger::logWarning('[PixelControl] Dropping oldest queued event due to queue pressure: key=' . $droppedItem->getEnvelope()->getIdempotencyKey() . '.');
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

			Logger::logWarning(
				'[PixelControl] Requeued envelope after delivery failure: key=' . $queueItem->getEnvelope()->getIdempotencyKey()
				. ', next_attempt=' . $queueItem->getAttempt()
				. ', code=' . $reasonCode
				. ', retry_after_seconds=' . $retryDelaySeconds
				. ', reason=' . $reason
				. '.'
			);
			return;
		}

		Logger::logWarning(
			'[PixelControl] Dropping envelope after retry budget exhausted: key=' . $queueItem->getEnvelope()->getIdempotencyKey()
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
