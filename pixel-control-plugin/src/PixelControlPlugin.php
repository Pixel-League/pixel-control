<?php

namespace PixelControl;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\ManiaControl;
use ManiaControl\Plugins\Plugin;
use PixelControl\Api\PixelControlApiClientInterface;
use PixelControl\Callbacks\CallbackRegistry;
use PixelControl\Queue\EventQueueInterface;
use PixelControl\Retry\RetryPolicyInterface;
use PixelControl\Stats\PlayerCombatStatsStore;
use PixelControl\Domain\Combat\CombatDomainTrait;
use PixelControl\Domain\Connectivity\ConnectivityDomainTrait;
use PixelControl\Domain\Core\CoreDomainTrait;
use PixelControl\Domain\Lifecycle\LifecycleDomainTrait;
use PixelControl\Domain\Match\MatchDomainTrait;
use PixelControl\Domain\Pipeline\PipelineDomainTrait;
use PixelControl\Domain\Player\PlayerDomainTrait;

class PixelControlPlugin implements CallbackListener, TimerListener, Plugin {
	use CoreDomainTrait;
	use ConnectivityDomainTrait;
	use LifecycleDomainTrait;
	use PlayerDomainTrait;
	use MatchDomainTrait;
	use CombatDomainTrait;
	use PipelineDomainTrait;

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
}
