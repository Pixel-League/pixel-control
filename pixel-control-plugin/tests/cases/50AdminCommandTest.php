<?php
declare(strict_types=1);

use ManiaControl\Communication\CommunicationAnswer;
use PixelControl\Tests\Support\Assert;
use PixelControl\Tests\Support\FakeSettingManager;

// ---------------------------------------------------------------------------
// Minimal harness for AdminCommandTrait tests.
// Uses a standalone class that composes the trait and a minimal fake
// ManiaControl instance to avoid the full plugin wiring.
// ---------------------------------------------------------------------------

class FakeServer {
	public $login = 'test-server.local';
}

class FakeClientForAdmin {
	public $forceSpectatorCalls = array();
	public $forcePlayerTeamCalls = array();

	public function forceSpectator($login, $mode) {
		$this->forceSpectatorCalls[] = array('login' => $login, 'mode' => $mode);
		return true;
	}

	public function forcePlayerTeam($login, $team) {
		$this->forcePlayerTeamCalls[] = array('login' => $login, 'team' => $team);
		return true;
	}

	public function triggerModeScriptEventArray($event, $params) {
		return true;
	}
}

class FakePlayerManagerForAdmin {
	public function getPlayers() {
		return array();
	}
}

class FakeManiaControlForAdmin {
	public $settingManager;
	public $server;
	public $client;
	public $playerManager;

	public function __construct($settingValues = array()) {
		$this->settingManager = new FakeSettingManager($settingValues);
		$this->server = new FakeServer();
		$this->client = new FakeClientForAdmin();
		$this->playerManager = new FakePlayerManagerForAdmin();
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

	public function getClient() {
		return $this->client;
	}

	public function getPlayerManager() {
		return $this->playerManager;
	}
}

class AdminCommandTestHarness {
	use PixelControl\Domain\Admin\AdminCommandTrait;
	use PixelControl\Domain\StateSync\StateSyncTrait;

	const SETTING_LINK_TOKEN             = 'Pixel Control Link Token';
	const SETTING_API_BASE_URL           = 'Pixel Control API Base URL';
	const SETTING_LINK_SERVER_URL        = 'Pixel Control Link Server URL';
	const SETTING_STATE_SYNC_ENABLED     = 'Pixel Control State Sync Enabled';

	public $maniaControl;

	public function __construct($settingValues = array()) {
		$this->maniaControl = new FakeManiaControlForAdmin($settingValues);
	}

	/** No-op in tests -- prevents actual HTTP calls. */
	private function pushStateToServer($serverLogin, array $snapshot) {}

	/** No-op in tests -- prevents actual HTTP calls. */
	private function fetchStateFromServer($serverLogin) { return null; }

	public function callHandleAdminExecuteAction($data) {
		return $this->handleAdminExecuteAction($data);
	}
}

/**
 * Builds a stdClass data object simulating what CommunicationManager passes.
 */
function makeAdminRequest($action, $serverLogin = 'test-server.local', $token = 'valid-token', $parameters = null) {
	$data = new stdClass();
	$data->action = $action;
	$data->server_login = $serverLogin;
	$data->auth = new stdClass();
	$data->auth->mode = 'link_bearer';
	$data->auth->token = $token;
	if ($parameters !== null) {
		$data->parameters = $parameters;
	}
	return $data;
}

return array(

	// ─── Link-auth validation ─────────────────────────────────────────────────────

	'link_auth_missing when server_login absent' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = new stdClass();
		$data->action = 'map.skip';
		// No server_login or auth.

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->error);
		Assert::same('link_auth_missing', $answer->data['code']);
		Assert::same(false, $answer->data['success']);
	},

	'link_auth_missing when auth absent' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = new stdClass();
		$data->action = 'map.skip';
		$data->server_login = 'test-server.local';
		// No auth.

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same('link_auth_missing', $answer->data['code']);
	},

	'link_server_mismatch when server_login does not match' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('map.skip', 'wrong-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same('link_server_mismatch', $answer->data['code']);
		Assert::same(false, $answer->data['success']);
	},

	'link_auth_invalid when auth mode is not link_bearer' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('map.skip', 'test-server.local', 'valid-token');
		$data->auth->mode = 'basic';

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same('link_auth_invalid', $answer->data['code']);
	},

	'link_auth_invalid when token does not match' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('map.skip', 'test-server.local', 'wrong-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same('link_auth_invalid', $answer->data['code']);
		Assert::same(false, $answer->data['success']);
	},

	'link_auth_invalid when stored token is empty' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => ''));
		$data = makeAdminRequest('map.skip', 'test-server.local', 'some-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same('link_auth_invalid', $answer->data['code']);
	},

	// ─── Action routing ───────────────────────────────────────────────────────────

	'action_not_found for unknown action' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('unknown.action', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same('action_not_found', $answer->data['code']);
		Assert::same(false, $answer->data['success']);
	},

	// ─── Match best-of ────────────────────────────────────────────────────────────

	'match.bo.get returns default best_of=3' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('match.bo.get', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('match_bo_retrieved', $answer->data['code']);
		Assert::same(3, $answer->data['details']['best_of']);
	},

	'match.bo.set updates best_of and returns new value' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		$params = new stdClass();
		$params->best_of = 5;
		$data = makeAdminRequest('match.bo.set', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('match_bo_set', $answer->data['code']);
		Assert::same(5, $answer->data['details']['best_of']);

		// Verify get now returns updated value.
		$getData = makeAdminRequest('match.bo.get', 'test-server.local', 'valid-token');
		$getAnswer = $harness->callHandleAdminExecuteAction($getData);
		Assert::same(5, $getAnswer->data['details']['best_of']);
	},

	'match.bo.set returns invalid_best_of for missing parameter' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('match.bo.set', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_best_of', $answer->data['code']);
	},

	// ─── Maps score ───────────────────────────────────────────────────────────────

	'match.maps.get returns default zeros' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('match.maps.get', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same(0, $answer->data['details']['team_a_maps']);
		Assert::same(0, $answer->data['details']['team_b_maps']);
	},

	'match.maps.set updates team_a maps score' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		$params = new stdClass();
		$params->target_team = 'team_a';
		$params->maps_score = 2;
		$data = makeAdminRequest('match.maps.set', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same(2, $answer->data['details']['team_a_maps']);
		Assert::same(0, $answer->data['details']['team_b_maps']);
	},

	'match.maps.set returns invalid_team for unknown team' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		$params = new stdClass();
		$params->target_team = 'team_x';
		$params->maps_score = 1;
		$data = makeAdminRequest('match.maps.set', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_team', $answer->data['code']);
	},

	// ─── Round score ──────────────────────────────────────────────────────────────

	'match.score.get returns default zeros' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('match.score.get', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same(0, $answer->data['details']['team_a_score']);
		Assert::same(0, $answer->data['details']['team_b_score']);
	},

	'match.score.set updates team_b score' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		$params = new stdClass();
		$params->target_team = 'team_b';
		$params->score = 150;
		$data = makeAdminRequest('match.score.set', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same(0, $answer->data['details']['team_a_score']);
		Assert::same(150, $answer->data['details']['team_b_score']);
	},

	'match.score.set returns invalid_team for unknown team' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		$params = new stdClass();
		$params->target_team = 'bad_team';
		$params->score = 50;
		$data = makeAdminRequest('match.score.set', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_team', $answer->data['code']);
	},

	// ─── Map management parameter validation ──────────────────────────────────────

	'map.jump returns invalid_map_uid when map_uid is missing' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('map.jump', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_map_uid', $answer->data['code']);
	},

	'map.queue returns invalid_map_uid when map_uid is missing' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('map.queue', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_map_uid', $answer->data['code']);
	},

	'map.add returns invalid_mx_id when mx_id is missing' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('map.add', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_mx_id', $answer->data['code']);
	},

	'map.remove returns invalid_map_uid when map_uid is missing' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('map.remove', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_map_uid', $answer->data['code']);
	},

	'warmup.extend returns invalid_seconds when seconds is missing' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('warmup.extend', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_seconds', $answer->data['code']);
	},

	// ─── P4 Player management ─────────────────────────────────────────────────────

	'player.force_team with valid params succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'somePlayer';
		$params->team = 'team_a';
		$data = makeAdminRequest('player.force_team', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('player_team_forced', $answer->data['code']);
		Assert::same('somePlayer', $answer->data['details']['target_login']);
		Assert::same('team_a', $answer->data['details']['team']);
	},

	'player.force_team with numeric team 1 normalizes to team_b' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'somePlayer';
		$params->team = '1';
		$data = makeAdminRequest('player.force_team', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('team_b', $answer->data['details']['team']);
	},

	'player.force_team with missing target_login returns invalid_parameter' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->team = 'team_a';
		$data = makeAdminRequest('player.force_team', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_parameter', $answer->data['code']);
	},

	'player.force_team with invalid team returns invalid_team' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'somePlayer';
		$params->team = 'invalid_value';
		$data = makeAdminRequest('player.force_team', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_team', $answer->data['code']);
	},

	'player.force_play with valid params succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'somePlayer';
		$data = makeAdminRequest('player.force_play', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('player_forced_play', $answer->data['code']);
		Assert::same('somePlayer', $answer->data['details']['target_login']);
	},

	'player.force_play with missing target_login returns invalid_parameter' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('player.force_play', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_parameter', $answer->data['code']);
	},

	'player.force_spec with valid params succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'somePlayer';
		$data = makeAdminRequest('player.force_spec', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('player_forced_spec', $answer->data['code']);
		Assert::same('somePlayer', $answer->data['details']['target_login']);
	},

	// ─── P4 Team control ─────────────────────────────────────────────────────────

	'team.policy.set with enabled=true succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->enabled = true;
		$params->switch_lock = false;
		$data = makeAdminRequest('team.policy.set', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('team_policy_set', $answer->data['code']);
		Assert::same(true, $answer->data['details']['enabled']);
		Assert::same(false, $answer->data['details']['switch_lock']);
	},

	'team.policy.set with missing enabled returns invalid_parameter' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('team.policy.set', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_parameter', $answer->data['code']);
	},

	'team.policy.get returns current policy' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		// Set policy first.
		$setParams = new stdClass();
		$setParams->enabled = true;
		$setParams->switch_lock = true;
		$setData = makeAdminRequest('team.policy.set', 'test-server.local', 'valid-token', $setParams);
		$harness->callHandleAdminExecuteAction($setData);

		// Now get.
		$getData = makeAdminRequest('team.policy.get', 'test-server.local', 'valid-token');
		$answer = $harness->callHandleAdminExecuteAction($getData);
		Assert::same(true, $answer->data['success']);
		Assert::same('team_policy_retrieved', $answer->data['code']);
		Assert::same(true, $answer->data['details']['enabled']);
		Assert::same(true, $answer->data['details']['switch_lock']);
	},

	'team.roster.assign with valid params succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'playerX';
		$params->team = 'team_b';
		$data = makeAdminRequest('team.roster.assign', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('team_roster_assigned', $answer->data['code']);
		Assert::same('playerX', $answer->data['details']['target_login']);
		Assert::same('team_b', $answer->data['details']['team']);
		Assert::same('team_b', $answer->data['details']['roster']['playerX']);
	},

	'team.roster.assign with invalid team returns invalid_team' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'playerX';
		$params->team = 'invalid';
		$data = makeAdminRequest('team.roster.assign', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_team', $answer->data['code']);
	},

	'team.roster.assign accepts blue as team_b alias' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'playerX';
		$params->team = 'blue';
		$data = makeAdminRequest('team.roster.assign', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('team_b', $answer->data['details']['team']);
	},

	'team.roster.unassign with valid login succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		// Assign first.
		$assignParams = new stdClass();
		$assignParams->target_login = 'playerX';
		$assignParams->team = 'team_a';
		$harness->callHandleAdminExecuteAction(
			makeAdminRequest('team.roster.assign', 'test-server.local', 'valid-token', $assignParams)
		);

		// Now unassign.
		$params = new stdClass();
		$params->target_login = 'playerX';
		$data = makeAdminRequest('team.roster.unassign', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('team_roster_unassigned', $answer->data['code']);
		Assert::same('playerX', $answer->data['details']['target_login']);
		Assert::same(false, isset($answer->data['details']['roster']['playerX']));
	},

	'team.roster.unassign with unknown login returns player_not_in_roster' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'unknownPlayer';
		$data = makeAdminRequest('team.roster.unassign', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('player_not_in_roster', $answer->data['code']);
	},

	'team.roster.list returns roster array' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		// Assign two players.
		foreach (array('playerA' => 'team_a', 'playerB' => 'team_b') as $login => $team) {
			$p = new stdClass();
			$p->target_login = $login;
			$p->team = $team;
			$harness->callHandleAdminExecuteAction(
				makeAdminRequest('team.roster.assign', 'test-server.local', 'valid-token', $p)
			);
		}

		$data = makeAdminRequest('team.roster.list', 'test-server.local', 'valid-token');
		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('team_roster_retrieved', $answer->data['code']);
		Assert::same(2, $answer->data['details']['count']);
		Assert::same('team_a', $answer->data['details']['roster']['playerA']);
		Assert::same('team_b', $answer->data['details']['roster']['playerB']);
	},

	// ─── P5 Auth management ───────────────────────────────────────────────────────

	'auth.grant with valid params succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'somePlayer';
		$params->auth_level = 'admin';
		$data = makeAdminRequest('auth.grant', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('auth_granted', $answer->data['code']);
		Assert::same('somePlayer', $answer->data['details']['target_login']);
		Assert::same('admin', $answer->data['details']['auth_level']);
	},

	'auth.grant with missing target_login returns invalid_parameter' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->auth_level = 'moderator';
		$data = makeAdminRequest('auth.grant', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_parameter', $answer->data['code']);
	},

	'auth.grant with invalid auth_level returns invalid_parameter' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'somePlayer';
		$params->auth_level = 'god';
		$data = makeAdminRequest('auth.grant', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_parameter', $answer->data['code']);
	},

	'auth.revoke with valid params succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'somePlayer';
		$data = makeAdminRequest('auth.revoke', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('auth_revoked', $answer->data['code']);
		Assert::same('somePlayer', $answer->data['details']['target_login']);
	},

	// ─── P5 Whitelist management ──────────────────────────────────────────────────

	'whitelist.enable succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('whitelist.enable', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('whitelist_enabled', $answer->data['code']);
		Assert::same(true, $answer->data['details']['enabled']);
	},

	'whitelist.disable succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('whitelist.disable', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('whitelist_disabled', $answer->data['code']);
		Assert::same(false, $answer->data['details']['enabled']);
	},

	'whitelist.add with valid login succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'playerX';
		$data = makeAdminRequest('whitelist.add', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('whitelist_added', $answer->data['code']);
		Assert::same('playerX', $answer->data['details']['target_login']);
		Assert::same(true, in_array('playerX', $answer->data['details']['whitelist'], true));
	},

	'whitelist.add with missing login returns invalid_parameter' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('whitelist.add', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_parameter', $answer->data['code']);
	},

	'whitelist.remove with valid login succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		// Add first.
		$addParams = new stdClass();
		$addParams->target_login = 'playerX';
		$harness->callHandleAdminExecuteAction(
			makeAdminRequest('whitelist.add', 'test-server.local', 'valid-token', $addParams)
		);

		// Now remove.
		$params = new stdClass();
		$params->target_login = 'playerX';
		$data = makeAdminRequest('whitelist.remove', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('whitelist_removed', $answer->data['code']);
		Assert::same('playerX', $answer->data['details']['target_login']);
		Assert::same(false, in_array('playerX', $answer->data['details']['whitelist'], true));
	},

	'whitelist.remove with unknown login returns player_not_in_whitelist' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->target_login = 'unknownPlayer';
		$data = makeAdminRequest('whitelist.remove', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('player_not_in_whitelist', $answer->data['code']);
	},

	'whitelist.list returns current whitelist' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		// Add two players.
		foreach (array('playerA', 'playerB') as $login) {
			$p = new stdClass();
			$p->target_login = $login;
			$harness->callHandleAdminExecuteAction(
				makeAdminRequest('whitelist.add', 'test-server.local', 'valid-token', $p)
			);
		}

		$data = makeAdminRequest('whitelist.list', 'test-server.local', 'valid-token');
		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('whitelist_retrieved', $answer->data['code']);
		Assert::same(2, $answer->data['details']['count']);
		Assert::same(true, in_array('playerA', $answer->data['details']['whitelist'], true));
		Assert::same(true, in_array('playerB', $answer->data['details']['whitelist'], true));
	},

	'whitelist.clean clears the whitelist' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		// Add some players first.
		foreach (array('playerA', 'playerB', 'playerC') as $login) {
			$p = new stdClass();
			$p->target_login = $login;
			$harness->callHandleAdminExecuteAction(
				makeAdminRequest('whitelist.add', 'test-server.local', 'valid-token', $p)
			);
		}

		$data = makeAdminRequest('whitelist.clean', 'test-server.local', 'valid-token');
		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('whitelist_cleaned', $answer->data['code']);
		Assert::same(3, $answer->data['details']['previous_count']);

		// Verify whitelist is now empty.
		$listData = makeAdminRequest('whitelist.list', 'test-server.local', 'valid-token');
		$listAnswer = $harness->callHandleAdminExecuteAction($listData);
		Assert::same(0, $listAnswer->data['details']['count']);
	},

	'whitelist.sync succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('whitelist.sync', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('whitelist_synced', $answer->data['code']);
	},

	// ─── P5 Vote management ───────────────────────────────────────────────────────

	'vote.cancel succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('vote.cancel', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('vote_cancelled', $answer->data['code']);
	},

	'vote.set_ratio with valid params succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->command = 'kick';
		$params->ratio = 0.6;
		$data = makeAdminRequest('vote.set_ratio', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('vote_ratio_set', $answer->data['code']);
		Assert::same('kick', $answer->data['details']['command']);
		Assert::same(0.6, $answer->data['details']['ratio']);
	},

	'vote.set_ratio with invalid ratio returns invalid_parameter' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->command = 'kick';
		$params->ratio = 1.5;
		$data = makeAdminRequest('vote.set_ratio', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_parameter', $answer->data['code']);
	},

	'vote.custom_start with valid vote_index succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->vote_index = 2;
		$data = makeAdminRequest('vote.custom_start', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('custom_vote_started', $answer->data['code']);
		Assert::same(2, $answer->data['details']['vote_index']);
	},

	'vote.custom_start with missing vote_index returns invalid_parameter' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('vote.custom_start', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_parameter', $answer->data['code']);
	},

	'vote.policy.get returns current policy' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('vote.policy.get', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('vote_policy_retrieved', $answer->data['code']);
		Assert::same('default', $answer->data['details']['mode']);
		Assert::same(true, is_array($answer->data['details']['ratios']));
	},

	'vote.policy.set with valid mode succeeds' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$params = new stdClass();
		$params->mode = 'strict';
		$data = makeAdminRequest('vote.policy.set', 'test-server.local', 'valid-token', $params);

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('vote_policy_set', $answer->data['code']);
		Assert::same('strict', $answer->data['details']['mode']);

		// Verify get now returns updated mode.
		$getData = makeAdminRequest('vote.policy.get', 'test-server.local', 'valid-token');
		$getAnswer = $harness->callHandleAdminExecuteAction($getData);
		Assert::same('strict', $getAnswer->data['details']['mode']);
	},

	'vote.policy.set with missing mode returns invalid_parameter' => function () {
		$harness = new AdminCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeAdminRequest('vote.policy.set', 'test-server.local', 'valid-token');

		$answer = $harness->callHandleAdminExecuteAction($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_parameter', $answer->data['code']);
	},

);
