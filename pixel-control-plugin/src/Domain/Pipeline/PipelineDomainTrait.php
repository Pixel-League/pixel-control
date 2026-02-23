<?php

namespace PixelControl\Domain\Pipeline;

use ManiaControl\Logger;
use PixelControl\Api\DeliveryError;
use PixelControl\Api\EventEnvelope;
use PixelControl\Queue\QueueItem;

trait PipelineDomainTrait {
	private function queueCallbackEvent($eventCategory, array $callbackArguments) {
		$sourceCallback = $this->extractSourceCallback($callbackArguments);
		if ($eventCategory === 'player') {
			$sourceCallback = $this->resolvePlayerSourceCallback($sourceCallback, $callbackArguments);
		}

		$payload = $this->buildCallbackPayload($eventCategory, $sourceCallback, $callbackArguments);
		$metadata = $this->buildEnvelopeMetadata($eventCategory, $sourceCallback, $payload);

		$enqueuedEnvelope = $this->enqueueEnvelope($eventCategory, $sourceCallback, $payload, $metadata);
		if ($eventCategory === 'lifecycle') {
			$this->trackRecentAdminActionContext($sourceCallback, $payload, $enqueuedEnvelope);
		}

		$this->dispatchQueuedEvents();
	}

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

	private function buildRetryTelemetrySnapshot() {
		return array(
			'max_retry_attempts' => $this->apiClient ? $this->apiClient->getMaxRetryAttempts() : 0,
			'retry_backoff_ms' => $this->apiClient ? $this->apiClient->getRetryBackoffMs() : 0,
			'dispatch_batch_size' => $this->dispatchBatchSize,
		);
	}

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

	private function buildEventName($eventCategory, $sourceCallback) {
		$normalizedCallback = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $sourceCallback), '_'));
		if ($normalizedCallback === '') {
			$normalizedCallback = 'unknown_callback';
		}

		return 'pixel_control.' . $eventCategory . '.' . $normalizedCallback;
	}

	private function buildEventId($eventCategory, $sourceCallback, $sequence) {
		$normalizedCategory = $this->normalizeIdentifier($eventCategory, 'event');
		$normalizedCallback = $this->normalizeIdentifier($sourceCallback, 'unknown_callback');
		$normalizedSequence = max(1, (int) $sequence);

		return 'pc-evt-' . $normalizedCategory . '-' . $normalizedCallback . '-' . $normalizedSequence;
	}

	private function buildIdempotencyKey($eventId) {
		return 'pc-idem-' . sha1((string) $eventId);
	}

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

	private function normalizeIdentifier($value, $fallback) {
		$normalized = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $value), '_'));
		if ($normalized === '') {
			return $fallback;
		}

		return $normalized;
	}

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
}
