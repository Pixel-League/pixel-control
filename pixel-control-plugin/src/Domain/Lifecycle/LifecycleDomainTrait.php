<?php

namespace PixelControl\Domain\Lifecycle;

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
trait LifecycleDomainTrait {
	private function buildLifecyclePayload($sourceCallback, array $callbackArguments) {
		$variant = $this->resolveLifecycleVariant($sourceCallback, $callbackArguments);
		$variantParts = explode('.', $variant, 2);
		$isScriptLifecycle = $this->isScriptLifecycleCallback($sourceCallback);
		$this->observePauseStateFromLifecycle($variant, $callbackArguments);

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
}
