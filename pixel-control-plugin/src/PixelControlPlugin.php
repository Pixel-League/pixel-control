<?php

namespace PixelControl;

use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Commands\CommandListener;
use ManiaControl\Communication\CommunicationListener;
use ManiaControl\ManiaControl;
use ManiaControl\Plugins\Plugin;
use PixelControl\Admin\NativeAdminGateway;
use PixelControl\AccessControl\WhitelistStateInterface;
use PixelControl\Api\PixelControlApiClientInterface;
use PixelControl\Callbacks\CallbackRegistry;
use PixelControl\Domain\AccessControl\AccessControlDomainTrait;
use PixelControl\Domain\Admin\AdminControlDomainTrait;
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
use PixelControl\Domain\SeriesControl\SeriesControlDomainTrait;
use PixelControl\Domain\TeamControl\TeamControlDomainTrait;
use PixelControl\Domain\VetoDraft\VetoDraftDomainTrait;
use PixelControl\SeriesControl\SeriesControlStateInterface;
use PixelControl\TeamControl\TeamRosterStateInterface;
use PixelControl\VetoDraft\MapPoolService;
use PixelControl\VetoDraft\VetoDraftCoordinator;
use PixelControl\VetoDraft\VetoDraftQueueApplier;
use PixelControl\VoteControl\VotePolicyStateInterface;

class PixelControlPlugin implements CallbackListener, TimerListener, CommandListener, CommunicationListener, Plugin {
	use CoreDomainTrait;
	use AccessControlDomainTrait;
	use AdminControlDomainTrait;
	use ConnectivityDomainTrait;
	use LifecycleDomainTrait;
	use PlayerDomainTrait;
	use MatchDomainTrait;
	use CombatDomainTrait;
	use PipelineDomainTrait;
	use SeriesControlDomainTrait;
	use TeamControlDomainTrait;
	use VetoDraftDomainTrait;

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
	const SETTING_LINK_SERVER_URL = 'Pixel Control Link Server URL';
	const SETTING_LINK_TOKEN = 'Pixel Control Link Token';
	const SETTING_QUEUE_MAX_SIZE = 'Pixel Control Queue Max Size';
	const SETTING_DISPATCH_BATCH_SIZE = 'Pixel Control Dispatch Batch Size';
	const SETTING_HEARTBEAT_INTERVAL_SECONDS = 'Pixel Control Heartbeat Interval Seconds';
	const SETTING_WHITELIST_ENABLED = 'Pixel Control Whitelist Enabled';
	const SETTING_WHITELIST_LOGINS = 'Pixel Control Whitelist Logins';
	const SETTING_VOTE_POLICY_MODE = 'Pixel Control Vote Policy Mode';
	const SETTING_TEAM_POLICY_ENABLED = 'Pixel Control Team Policy Enabled';
	const SETTING_TEAM_SWITCH_LOCK_ENABLED = 'Pixel Control Team Switch Lock Enabled';
	const SETTING_TEAM_ROSTER_ASSIGNMENTS = 'Pixel Control Team Roster Assignments';
	const SETTING_ADMIN_CONTROL_ENABLED = 'Pixel Control Native Admin Control Enabled';
	const SETTING_ADMIN_CONTROL_COMMAND = 'Pixel Control Native Admin Command';
	const SETTING_ADMIN_CONTROL_PAUSE_STATE_MAX_AGE_SECONDS = 'Pixel Control Pause State Max Age Seconds';
	const SETTING_VETO_DRAFT_ENABLED = 'Pixel Control Veto Draft Enabled';
	const SETTING_VETO_DRAFT_COMMAND = 'Pixel Control Veto Draft Command';
	const SETTING_VETO_DRAFT_DEFAULT_MODE = 'Pixel Control Veto Draft Default Mode';
	const SETTING_VETO_DRAFT_MATCHMAKING_DURATION_SECONDS = 'Pixel Control Veto Draft Matchmaking Duration Seconds';
	const SETTING_VETO_DRAFT_MATCHMAKING_AUTOSTART_MIN_PLAYERS = 'Pixel Control Veto Draft Matchmaking Auto-start Min Players';
	const SETTING_VETO_DRAFT_TOURNAMENT_ACTION_TIMEOUT_SECONDS = 'Pixel Control Veto Draft Tournament Action Timeout Seconds';
	const SETTING_VETO_DRAFT_DEFAULT_BEST_OF = 'Pixel Control Veto Draft Default Best Of';
	const SETTING_VETO_DRAFT_LAUNCH_IMMEDIATELY = 'Pixel Control Veto Draft Launch Immediately';
	const SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_A = 'Pixel Control Series Maps Score Team A';
	const SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_B = 'Pixel Control Series Maps Score Team B';
	const SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_A = 'Pixel Control Series Current Map Score Team A';
	const SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_B = 'Pixel Control Series Current Map Score Team B';

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
	private $heartbeatIntervalSeconds = 120;
	/** @var NativeAdminGateway|null $nativeAdminGateway */
	private $nativeAdminGateway = null;
	/** @var WhitelistStateInterface|null $whitelistState */
	private $whitelistState = null;
	/** @var VotePolicyStateInterface|null $votePolicyState */
	private $votePolicyState = null;
	/** @var TeamRosterStateInterface|null $teamRosterState */
	private $teamRosterState = null;
	/** @var int[] $whitelistRecentDeniedAt */
	private $whitelistRecentDeniedAt = array();
	/** @var int $whitelistDenyCooldownSeconds */
	private $whitelistDenyCooldownSeconds = 5;
	/** @var int $whitelistReconcileIntervalSeconds */
	private $whitelistReconcileIntervalSeconds = 5;
	/** @var int $whitelistLastReconcileAt */
	private $whitelistLastReconcileAt = 0;
	/** @var string $whitelistGuestListLastSyncHash */
	private $whitelistGuestListLastSyncHash = '';
	/** @var int $whitelistGuestListLastSyncAt */
	private $whitelistGuestListLastSyncAt = 0;
	/** @var int $votePolicyLastCallVoteTimeoutMs */
	private $votePolicyLastCallVoteTimeoutMs = 0;
	/** @var bool $votePolicyStrictRuntimeApplied */
	private $votePolicyStrictRuntimeApplied = false;
	/** @var bool|null $teamControlForcedTeamsState */
	private $teamControlForcedTeamsState = null;
	/** @var int $teamControlLastRuntimeApplyAt */
	private $teamControlLastRuntimeApplyAt = 0;
	/** @var string $teamControlLastRuntimeApplySource */
	private $teamControlLastRuntimeApplySource = 'bootstrap';
	/** @var int[] $teamControlRecentForcedAt */
	private $teamControlRecentForcedAt = array();
	/** @var int $teamControlForceCooldownSeconds */
	private $teamControlForceCooldownSeconds = 4;
	/** @var int $teamControlReconcileIntervalSeconds */
	private $teamControlReconcileIntervalSeconds = 3;
	/** @var int $teamControlLastReconcileAt */
	private $teamControlLastReconcileAt = 0;
	/** @var bool $adminControlEnabled */
	private $adminControlEnabled = false;
	/** @var string $adminControlCommandName */
	private $adminControlCommandName = 'pcadmin';
	/** @var bool|null $adminControlPauseActive */
	private $adminControlPauseActive = null;
	/** @var int $adminControlPauseObservedAt */
	private $adminControlPauseObservedAt = 0;
	/** @var int $adminControlPauseStateMaxAgeSeconds */
	private $adminControlPauseStateMaxAgeSeconds = 120;
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
	/** @var bool $vetoDraftEnabled */
	private $vetoDraftEnabled = false;
	/** @var string $vetoDraftCommandName */
	private $vetoDraftCommandName = 'pcveto';
	/** @var string $vetoDraftDefaultMode */
	private $vetoDraftDefaultMode = 'matchmaking_vote';
	/** @var int $vetoDraftMatchmakingDurationSeconds */
	private $vetoDraftMatchmakingDurationSeconds = 60;
	/** @var int $vetoDraftMatchmakingAutostartMinPlayers */
	private $vetoDraftMatchmakingAutostartMinPlayers = 2;
	/** @var bool $vetoDraftMatchmakingAutostartArmed */
	private $vetoDraftMatchmakingAutostartArmed = true;
	/** @var bool $vetoDraftMatchmakingAutostartSuppressed */
	private $vetoDraftMatchmakingAutostartSuppressed = false;
	/** @var array|null $vetoDraftMatchmakingAutostartPending */
	private $vetoDraftMatchmakingAutostartPending = null;
	/** @var string $vetoDraftMatchmakingAutostartLastCancellation */
	private $vetoDraftMatchmakingAutostartLastCancellation = '';
	/** @var bool $vetoDraftMatchmakingReadyArmed */
	private $vetoDraftMatchmakingReadyArmed = false;
	/** @var int $vetoDraftTournamentActionTimeoutSeconds */
	private $vetoDraftTournamentActionTimeoutSeconds = 45;
	/** @var int $vetoDraftDefaultBestOf */
	private $vetoDraftDefaultBestOf = 3;
	/** @var bool $vetoDraftLaunchImmediately */
	private $vetoDraftLaunchImmediately = true;
	/** @var MapPoolService|null $vetoDraftMapPoolService */
	private $vetoDraftMapPoolService = null;
	/** @var VetoDraftQueueApplier|null $vetoDraftQueueApplier */
	private $vetoDraftQueueApplier = null;
	/** @var VetoDraftCoordinator|null $vetoDraftCoordinator */
	private $vetoDraftCoordinator = null;
	/** @var SeriesControlStateInterface|null $seriesControlState */
	private $seriesControlState = null;
	/** @var array|null $vetoDraftCompatibilitySnapshot */
	private $vetoDraftCompatibilitySnapshot = null;
	/** @var string $vetoDraftLastAppliedSessionId */
	private $vetoDraftLastAppliedSessionId = '';
	/** @var array|null $vetoDraftMatchmakingLifecycleContext */
	private $vetoDraftMatchmakingLifecycleContext = null;
	/** @var array|null $vetoDraftMatchmakingLifecycleLastSnapshot */
	private $vetoDraftMatchmakingLifecycleLastSnapshot = null;
	/** @var int $vetoDraftMatchmakingLifecycleHistoryLimit */
	private $vetoDraftMatchmakingLifecycleHistoryLimit = 32;
}
