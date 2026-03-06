<?php
declare(strict_types=1);

use PixelControl\Tests\Support\Assert;
use PixelControl\Tests\Support\FakeSettingManager;

// ---------------------------------------------------------------------------
// Minimal harness for StateSyncTrait tests.
// Composes AdminCommandTrait + VetoDraftCommandTrait + StateSyncTrait so that
// all shared $this fields are accessible.
// ---------------------------------------------------------------------------

class FakeManiaControlForStateSync {
	public $settingManager;
	public $server;

	public function __construct($settingValues = array()) {
		$this->settingManager = new FakeSettingManager($settingValues);
		$server = new stdClass();
		$server->login = 'test-server.local';
		$this->server = $server;
	}

	public function getSettingManager() {
		return $this->settingManager;
	}

	public function getServer() {
		return $this->server;
	}

	public function getCommunicationManager() {
		return null;
	}

	public function getMapManager() {
		return null;
	}

	public function getClient() {
		return null;
	}
}

class StateSyncTestHarness {
	use PixelControl\Domain\Admin\AdminCommandTrait;
	use PixelControl\Domain\VetoDraft\VetoDraftCommandTrait;
	use PixelControl\Domain\StateSync\StateSyncTrait;

	const SETTING_LINK_TOKEN           = 'Pixel Control Link Token';
	const SETTING_API_BASE_URL         = 'Pixel Control API Base URL';
	const SETTING_LINK_SERVER_URL      = 'Pixel Control Link Server URL';
	const SETTING_STATE_SYNC_ENABLED   = 'Pixel Control State Sync Enabled';

	public $maniaControl;

	/** @var array Captured push calls for assertions. */
	public $pushCalls = array();

	/** @var bool Whether to intercept pushStateToServer (disable actual HTTP). */
	public $interceptPush = true;

	public function __construct($settingValues = array()) {
		$this->maniaControl = new FakeManiaControlForStateSync($settingValues);
	}

	/**
	 * Overrides pushStateToServer so tests don't attempt real HTTP.
	 */
	private function pushStateToServer($serverLogin, array $snapshot) {
		if ($this->interceptPush) {
			$this->pushCalls[] = array('serverLogin' => $serverLogin, 'snapshot' => $snapshot);
			return;
		}
		// No-op -- in tests we never want to actually fire HTTP.
	}

	/**
	 * Overrides fetchStateFromServer — tests don't attempt real HTTP.
	 * Returns null by default (no prior state).
	 */
	private function fetchStateFromServer($serverLogin) {
		return null;
	}

	public function callBuildStateSnapshot() {
		return $this->buildStateSnapshot();
	}

	public function callRestoreStateFromSnapshot(array $snapshot) {
		$this->restoreStateFromSnapshot($snapshot);
	}

	public function callSyncStateOnLoad() {
		$this->syncStateOnLoad();
	}

	public function callPushStateAfterCommand() {
		$this->pushStateAfterCommand();
	}

	// Expose internal state for assertions.
	public function getCurrentBestOf() { return $this->currentBestOf; }
	public function getTeamMapsScore() { return $this->teamMapsScore; }
	public function getTeamRoundScore() { return $this->teamRoundScore; }
	public function getTeamPolicyEnabled() { return $this->teamPolicyEnabled; }
	public function getTeamSwitchLock() { return $this->teamSwitchLock; }
	public function getTeamRoster() { return $this->teamRoster; }
	public function getWhitelistEnabled() { return $this->whitelistEnabled; }
	public function getWhitelist() { return $this->whitelist; }
	public function getVotePolicy() { return $this->votePolicy; }
	public function getVoteRatios() { return $this->voteRatios; }
	public function getVetoDraftSession() { return $this->vetoDraftSession; }
	public function getMatchmakingReadyArmed() { return $this->matchmakingReadyArmed; }
	public function getVetoDraftVotes() { return $this->vetoDraftVotes; }
}

// ---------------------------------------------------------------------------
// Helper: build a complete valid snapshot
// ---------------------------------------------------------------------------

function makeFullSnapshot($overrides = array()) {
	$base = array(
		'state_version' => '1.0',
		'captured_at'   => 1741276800,
		'admin'         => array(
			'current_best_of'     => 5,
			'team_maps_score'     => array('team_a' => 2, 'team_b' => 1),
			'team_round_score'    => array('team_a' => 3, 'team_b' => 4),
			'team_policy_enabled' => true,
			'team_switch_lock'    => true,
			'team_roster'         => array('player.a' => 'team_a', 'player.b' => 'team_b'),
			'whitelist_enabled'   => true,
			'whitelist'           => array('allowed.player.1', 'allowed.player.2'),
			'vote_policy'         => 'strict',
			'vote_ratios'         => array('kick' => 0.6, 'ban' => 0.75),
		),
		'veto_draft' => array(
			'session'                 => array('status' => 'running', 'mode' => 'matchmaking_vote'),
			'matchmaking_ready_armed' => true,
			'votes'                   => array('captain.a' => 'uid-map-1'),
		),
	);

	foreach ($overrides as $key => $value) {
		$base[$key] = $value;
	}

	return $base;
}

return array(

	// ─── buildStateSnapshot: structure ────────────────────────────────────────────

	'buildStateSnapshot returns correct top-level keys' => function () {
		$harness = new StateSyncTestHarness();
		$snapshot = $harness->callBuildStateSnapshot();

		Assert::same('1.0', $snapshot['state_version']);
		Assert::same(true, array_key_exists('captured_at', $snapshot));
		Assert::same(true, array_key_exists('admin', $snapshot));
		Assert::same(true, array_key_exists('veto_draft', $snapshot));
	},

	'buildStateSnapshot includes all admin fields with defaults' => function () {
		$harness = new StateSyncTestHarness();
		$snapshot = $harness->callBuildStateSnapshot();
		$admin = $snapshot['admin'];

		Assert::same(3, $admin['current_best_of']);
		Assert::same(array('team_a' => 0, 'team_b' => 0), $admin['team_maps_score']);
		Assert::same(array('team_a' => 0, 'team_b' => 0), $admin['team_round_score']);
		Assert::same(false, $admin['team_policy_enabled']);
		Assert::same(false, $admin['team_switch_lock']);
		Assert::same(array(), $admin['team_roster']);
		Assert::same(false, $admin['whitelist_enabled']);
		Assert::same(array(), $admin['whitelist']);
		Assert::same('default', $admin['vote_policy']);
		Assert::same(array(), $admin['vote_ratios']);
	},

	'buildStateSnapshot includes all veto_draft fields with defaults' => function () {
		$harness = new StateSyncTestHarness();
		$snapshot = $harness->callBuildStateSnapshot();
		$vd = $snapshot['veto_draft'];

		Assert::same(null, $vd['session']);
		Assert::same(false, $vd['matchmaking_ready_armed']);
		Assert::same(array(), $vd['votes']);
	},

	'buildStateSnapshot captures mutated admin state' => function () {
		$harness = new StateSyncTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		// Directly set internal state to simulate post-command state.
		$harness->callRestoreStateFromSnapshot(makeFullSnapshot());

		$snapshot = $harness->callBuildStateSnapshot();
		$admin = $snapshot['admin'];

		Assert::same(5, $admin['current_best_of']);
		Assert::same(array('team_a' => 2, 'team_b' => 1), $admin['team_maps_score']);
		Assert::same(true, $admin['team_policy_enabled']);
		Assert::same(true, $admin['team_switch_lock']);
		Assert::same(array('player.a' => 'team_a', 'player.b' => 'team_b'), $admin['team_roster']);
		Assert::same(true, $admin['whitelist_enabled']);
		Assert::same(array('allowed.player.1', 'allowed.player.2'), $admin['whitelist']);
		Assert::same('strict', $admin['vote_policy']);
		Assert::same(array('kick' => 0.6, 'ban' => 0.75), $admin['vote_ratios']);
	},

	// ─── restoreStateFromSnapshot: admin fields ───────────────────────────────────

	'restoreStateFromSnapshot restores all admin fields' => function () {
		$harness = new StateSyncTestHarness();
		$harness->callRestoreStateFromSnapshot(makeFullSnapshot());

		Assert::same(5, $harness->getCurrentBestOf());
		Assert::same(array('team_a' => 2, 'team_b' => 1), $harness->getTeamMapsScore());
		Assert::same(array('team_a' => 3, 'team_b' => 4), $harness->getTeamRoundScore());
		Assert::same(true, $harness->getTeamPolicyEnabled());
		Assert::same(true, $harness->getTeamSwitchLock());
		Assert::same(array('player.a' => 'team_a', 'player.b' => 'team_b'), $harness->getTeamRoster());
		Assert::same(true, $harness->getWhitelistEnabled());
		Assert::same(array('allowed.player.1', 'allowed.player.2'), $harness->getWhitelist());
		Assert::same('strict', $harness->getVotePolicy());
		Assert::same(array('kick' => 0.6, 'ban' => 0.75), $harness->getVoteRatios());
	},

	'restoreStateFromSnapshot restores veto_draft fields' => function () {
		$harness = new StateSyncTestHarness();
		$harness->callRestoreStateFromSnapshot(makeFullSnapshot());

		$session = $harness->getVetoDraftSession();
		Assert::same('running', $session['status']);
		Assert::same('matchmaking_vote', $session['mode']);
		Assert::same(true, $harness->getMatchmakingReadyArmed());
		Assert::same(array('captain.a' => 'uid-map-1'), $harness->getVetoDraftVotes());
	},

	'restoreStateFromSnapshot handles null veto_draft session' => function () {
		$harness = new StateSyncTestHarness();
		$snapshot = makeFullSnapshot();
		$snapshot['veto_draft']['session'] = null;
		$harness->callRestoreStateFromSnapshot($snapshot);

		Assert::same(null, $harness->getVetoDraftSession());
	},

	// ─── restoreStateFromSnapshot: unknown version ────────────────────────────────

	'restoreStateFromSnapshot rejects unknown state_version' => function () {
		$harness = new StateSyncTestHarness();
		$snapshot = makeFullSnapshot(array('state_version' => '99.0'));

		// Should not restore (version mismatch) -- state stays at defaults.
		$harness->callRestoreStateFromSnapshot($snapshot);

		Assert::same(3, $harness->getCurrentBestOf());     // default
		Assert::same(false, $harness->getWhitelistEnabled()); // default
	},

	'restoreStateFromSnapshot rejects empty state_version' => function () {
		$harness = new StateSyncTestHarness();
		$snapshot = makeFullSnapshot(array('state_version' => ''));
		$harness->callRestoreStateFromSnapshot($snapshot);

		Assert::same(3, $harness->getCurrentBestOf()); // unchanged
	},

	// ─── restoreStateFromSnapshot: partial/missing fields ────────────────────────

	'restoreStateFromSnapshot handles missing admin section gracefully' => function () {
		$harness = new StateSyncTestHarness();
		$snapshot = array('state_version' => '1.0', 'captured_at' => 1741276800);
		$harness->callRestoreStateFromSnapshot($snapshot);

		// All fields should remain at defaults.
		Assert::same(3, $harness->getCurrentBestOf());
		Assert::same(false, $harness->getWhitelistEnabled());
		Assert::same('default', $harness->getVotePolicy());
	},

	'restoreStateFromSnapshot handles missing veto_draft section gracefully' => function () {
		$harness = new StateSyncTestHarness();
		$snapshot = array('state_version' => '1.0', 'captured_at' => 1741276800, 'admin' => array());
		$harness->callRestoreStateFromSnapshot($snapshot);

		Assert::same(null, $harness->getVetoDraftSession());
		Assert::same(false, $harness->getMatchmakingReadyArmed());
	},

	// ─── Round-trip: snapshot -> restore -> snapshot ──────────────────────────────

	'round-trip snapshot -> restore -> snapshot produces identical admin data' => function () {
		$harness = new StateSyncTestHarness();

		// First, restore from a known snapshot.
		$original = makeFullSnapshot();
		$harness->callRestoreStateFromSnapshot($original);

		// Then re-capture a snapshot.
		$roundTrip = $harness->callBuildStateSnapshot();

		$origAdmin = $original['admin'];
		$rtAdmin   = $roundTrip['admin'];

		Assert::same($origAdmin['current_best_of'],     $rtAdmin['current_best_of']);
		Assert::same($origAdmin['team_maps_score'],      $rtAdmin['team_maps_score']);
		Assert::same($origAdmin['team_round_score'],     $rtAdmin['team_round_score']);
		Assert::same($origAdmin['team_policy_enabled'],  $rtAdmin['team_policy_enabled']);
		Assert::same($origAdmin['team_switch_lock'],     $rtAdmin['team_switch_lock']);
		Assert::same($origAdmin['team_roster'],          $rtAdmin['team_roster']);
		Assert::same($origAdmin['whitelist_enabled'],    $rtAdmin['whitelist_enabled']);
		Assert::same($origAdmin['whitelist'],            $rtAdmin['whitelist']);
		Assert::same($origAdmin['vote_policy'],          $rtAdmin['vote_policy']);
		Assert::same($origAdmin['vote_ratios'],          $rtAdmin['vote_ratios']);
	},

	'round-trip snapshot -> restore -> snapshot produces identical veto_draft data' => function () {
		$harness = new StateSyncTestHarness();

		$original = makeFullSnapshot();
		$harness->callRestoreStateFromSnapshot($original);
		$roundTrip = $harness->callBuildStateSnapshot();

		$origVd = $original['veto_draft'];
		$rtVd   = $roundTrip['veto_draft'];

		Assert::same($origVd['matchmaking_ready_armed'], $rtVd['matchmaking_ready_armed']);
		Assert::same($origVd['votes'],                   $rtVd['votes']);
		// session shape is preserved (array)
		Assert::same($origVd['session']['status'], $rtVd['session']['status']);
		Assert::same($origVd['session']['mode'],   $rtVd['session']['mode']);
	},

	// ─── pushStateAfterCommand ────────────────────────────────────────────────────

	'pushStateAfterCommand calls pushStateToServer with current snapshot' => function () {
		$settings = array(
			'Pixel Control Link Token'       => 'test-token',
			'Pixel Control API Base URL'     => 'http://localhost:3000/v1',
			'Pixel Control Link Server URL'  => '',
			'Pixel Control State Sync Enabled' => true,
		);
		$harness = new StateSyncTestHarness($settings);

		$harness->callPushStateAfterCommand();

		Assert::same(1, count($harness->pushCalls));
		$call = $harness->pushCalls[0];
		Assert::same('test-server.local', $call['serverLogin']);
		Assert::same('1.0', $call['snapshot']['state_version']);
		Assert::same(true, array_key_exists('admin', $call['snapshot']));
	},

);
