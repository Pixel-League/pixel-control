<?php

namespace PixelControl\Domain\Connectivity;

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
trait ConnectivityDomainTrait {
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

	private function buildRuntimeContextSnapshot() {
		return array(
			'server' => $this->buildServerSnapshot(),
			'players' => $this->buildPlayerSnapshot(),
		);
	}
}
