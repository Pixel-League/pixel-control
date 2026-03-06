<?php
declare(strict_types=1);

use ManiaControl\Communication\CommunicationAnswer;
use PixelControl\Tests\Support\Assert;
use PixelControl\Tests\Support\FakeSettingManager;

// ---------------------------------------------------------------------------
// Minimal harness for VetoDraftCommandTrait tests.
// The VetoDraftCommandTrait calls $this->validateLinkAuth() which is defined
// in AdminCommandTrait, so both traits must be composed together.
// ---------------------------------------------------------------------------

class FakeMapManagerWithPool {
	private $maps = array();

	public function setMaps(array $uids) {
		$this->maps = array();
		foreach ($uids as $uid) {
			$map = new stdClass();
			$map->uid = $uid;
			$this->maps[] = $map;
		}
	}

	public function getMaps() {
		return $this->maps;
	}

	public function getMapActions() {
		return new class {
			public function skipMap() {}
			public function restartMap() {}
			public function skipToMap($map) {}
		};
	}
}

class FakeManiaControlForVetoDraft {
	public $settingManager;
	public $server;
	public $mapManager;
	public $client;

	public function __construct($settingValues = array()) {
		$this->settingManager = new FakeSettingManager($settingValues);
		$this->server         = new FakeServer();
		$this->mapManager     = new FakeMapManagerWithPool();
		$this->client         = new stdClass(); // minimal stub -- not needed for VetoDraft
	}

	public function getSettingManager() {
		return $this->settingManager;
	}

	public function getServer() {
		return $this->server;
	}

	public function getMapManager() {
		return $this->mapManager;
	}

	public function getCommunicationManager() {
		return null;
	}

	public function getClient() {
		return $this->client;
	}
}

class VetoDraftCommandTestHarness {
	use PixelControl\Domain\Admin\AdminCommandTrait;
	use PixelControl\Domain\VetoDraft\VetoDraftCommandTrait;

	const SETTING_LINK_TOKEN = 'Pixel Control Link Token';

	public $maniaControl;

	public function __construct($settingValues = array()) {
		$this->maniaControl = new FakeManiaControlForVetoDraft($settingValues);
	}

	public function callStatus($data) {
		return $this->handleVetoDraftStatus($data);
	}

	public function callReady($data) {
		return $this->handleVetoDraftReady($data);
	}

	public function callStart($data) {
		return $this->handleVetoDraftStart($data);
	}

	public function callAction($data) {
		return $this->handleVetoDraftAction($data);
	}

	public function callCancel($data) {
		return $this->handleVetoDraftCancel($data);
	}

	public function setMapPool(array $uids) {
		$this->maniaControl->mapManager->setMaps($uids);
	}
}

/**
 * Builds a stdClass data object for VetoDraft communication calls.
 */
function makeVetoRequest($serverLogin = 'test-server.local', $token = 'valid-token', $parameters = null) {
	$data = new stdClass();
	$data->server_login = $serverLogin;
	$data->auth = new stdClass();
	$data->auth->mode = 'link_bearer';
	$data->auth->token = $token;
	if ($parameters !== null) {
		$data->parameters = $parameters;
	}
	return $data;
}

function makeVetoStartParams($mode, $extra = array()) {
	$p = new stdClass();
	$p->mode = $mode;
	foreach ($extra as $k => $v) {
		$p->$k = $v;
	}
	return $p;
}

function makeVetoActionParams($actorLogin, $map, $extra = array()) {
	$p = new stdClass();
	$p->actor_login = $actorLogin;
	$p->map = $map;
	foreach ($extra as $k => $v) {
		$p->$k = $v;
	}
	return $p;
}

return array(

	// ─── Status with no active session ───────────────────────────────────────────

	'status with no active session returns idle' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = new stdClass(); // Status requires no auth

		$answer = $harness->callStatus($data);
		Assert::same(false, $answer->error);
		Assert::same(false, $answer->data['status']['active']);
		Assert::same(null, $answer->data['status']['mode']);
		Assert::same('idle', $answer->data['status']['session']['status']);
		Assert::same('PixelControl.VetoDraft.Status', $answer->data['communication']['status']);
	},

	// ─── Ready handler ───────────────────────────────────────────────────────────

	'ready arms the matchmaking gate' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = new stdClass();

		$answer = $harness->callReady($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('matchmaking_ready_armed', $answer->data['code']);
	},

	'double ready returns already_armed' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = new stdClass();

		$harness->callReady($data);
		$answer = $harness->callReady($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('matchmaking_ready_already_armed', $answer->data['code']);
	},

	// ─── Start: missing/invalid auth ─────────────────────────────────────────────

	'start without auth returns link_auth_missing' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = new stdClass(); // No server_login or auth

		$answer = $harness->callStart($data);
		Assert::same('link_auth_missing', $answer->data['code']);
		Assert::same(false, $answer->data['success']);
	},

	'start with wrong token returns link_auth_invalid' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeVetoRequest('test-server.local', 'wrong-token',
			makeVetoStartParams('matchmaking_vote'));

		$answer = $harness->callStart($data);
		Assert::same('link_auth_invalid', $answer->data['code']);
	},

	// ─── Start matchmaking ────────────────────────────────────────────────────────

	'start matchmaking without ready gate returns matchmaking_ready_required' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2', 'map3'));
		$data = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('matchmaking_vote'));

		$answer = $harness->callStart($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('matchmaking_ready_required', $answer->data['code']);
	},

	'start matchmaking with ready gate succeeds' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2', 'map3'));

		// Arm the ready gate.
		$harness->callReady(new stdClass());

		$data = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('matchmaking_vote'));

		$answer = $harness->callStart($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('session_started', $answer->data['code']);
		Assert::same('matchmaking_vote', $answer->data['details']['mode']);
	},

	'start matchmaking with empty map pool returns map_pool_empty' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array());

		$harness->callReady(new stdClass());
		$data = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('matchmaking_vote'));

		$answer = $harness->callStart($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('map_pool_empty', $answer->data['code']);
	},

	// ─── Start tournament ─────────────────────────────────────────────────────────

	'start tournament with valid captains succeeds' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2', 'map3'));

		$data = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('tournament_draft', array(
				'captain_a' => 'playerA',
				'captain_b' => 'playerB',
				'best_of'   => 3,
			)));

		$answer = $harness->callStart($data);
		Assert::same(true, $answer->data['success']);
		Assert::same('session_started', $answer->data['code']);
		Assert::same('tournament_draft', $answer->data['details']['mode']);
		Assert::same('playerA', $answer->data['details']['captain_a']);
		Assert::same('playerB', $answer->data['details']['captain_b']);
	},

	'start tournament with same captain_a and captain_b returns captain_conflict' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2', 'map3'));

		$data = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('tournament_draft', array(
				'captain_a' => 'samePlayer',
				'captain_b' => 'samePlayer',
			)));

		$answer = $harness->callStart($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('captain_conflict', $answer->data['code']);
	},

	'start tournament with missing captain_a returns invalid_captain' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2', 'map3'));

		$data = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('tournament_draft', array('captain_b' => 'playerB')));

		$answer = $harness->callStart($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_captain', $answer->data['code']);
	},

	'start tournament with insufficient map pool returns map_pool_insufficient' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1')); // Only 1 map, but best_of=3 requires 3+

		$data = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('tournament_draft', array(
				'captain_a' => 'playerA',
				'captain_b' => 'playerB',
				'best_of'   => 3,
			)));

		$answer = $harness->callStart($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('map_pool_insufficient', $answer->data['code']);
	},

	// ─── Start when session already active ───────────────────────────────────────

	'start when session already active returns session_active' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2', 'map3'));

		// Start a matchmaking session.
		$harness->callReady(new stdClass());
		$startData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('matchmaking_vote'));
		$harness->callStart($startData);

		// Try to start again.
		$harness->callReady(new stdClass()); // re-arm (reset by previous start)
		$answer = $harness->callStart($startData);
		Assert::same(false, $answer->data['success']);
		Assert::same('session_active', $answer->data['code']);
	},

	// ─── Invalid mode ─────────────────────────────────────────────────────────────

	'start with invalid mode returns invalid_mode' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$data = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('unknown_mode'));

		$answer = $harness->callStart($data);
		Assert::same(false, $answer->data['success']);
		Assert::same('invalid_mode', $answer->data['code']);
	},

	// ─── Action on active matchmaking session ────────────────────────────────────

	'action vote on active matchmaking session succeeds' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2', 'map3'));

		$harness->callReady(new stdClass());
		$startData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('matchmaking_vote'));
		$harness->callStart($startData);

		$actionData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoActionParams('playerA', 'map1'));
		$answer = $harness->callAction($actionData);

		Assert::same(true, $answer->data['success']);
		Assert::same('vote_recorded', $answer->data['code']);
		Assert::same('playerA', $answer->data['details']['actor_login']);
		Assert::same('map1', $answer->data['details']['map']);
	},

	'action vote with map not in pool returns map_not_in_pool' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2'));

		$harness->callReady(new stdClass());
		$startData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('matchmaking_vote'));
		$harness->callStart($startData);

		$actionData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoActionParams('playerA', 'mapNotExists'));
		$answer = $harness->callAction($actionData);

		Assert::same(false, $answer->data['success']);
		Assert::same('map_not_in_pool', $answer->data['code']);
	},

	'action on inactive session returns session_not_running' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		$actionData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoActionParams('playerA', 'map1'));
		$answer = $harness->callAction($actionData);

		Assert::same(false, $answer->data['success']);
		Assert::same('session_not_running', $answer->data['code']);
	},

	// ─── Cancel ───────────────────────────────────────────────────────────────────

	'cancel active session succeeds' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2', 'map3'));

		$harness->callReady(new stdClass());
		$startData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('matchmaking_vote'));
		$harness->callStart($startData);

		$cancelData = makeVetoRequest('test-server.local', 'valid-token');
		$answer = $harness->callCancel($cancelData);

		Assert::same(true, $answer->data['success']);
		Assert::same('session_cancelled', $answer->data['code']);
	},

	'cancel inactive session returns session_not_running' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));

		$cancelData = makeVetoRequest('test-server.local', 'valid-token');
		$answer = $harness->callCancel($cancelData);

		Assert::same(false, $answer->data['success']);
		Assert::same('session_not_running', $answer->data['code']);
	},

	// ─── Status with active session ───────────────────────────────────────────────

	'status with active session returns running state' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2', 'map3'));

		$harness->callReady(new stdClass());
		$startData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('matchmaking_vote'));
		$harness->callStart($startData);

		$statusData = new stdClass();
		$answer = $harness->callStatus($statusData);

		Assert::same(true, $answer->data['status']['active']);
		Assert::same('matchmaking_vote', $answer->data['status']['mode']);
		Assert::same('running', $answer->data['status']['session']['status']);
	},

	// ─── Tournament action: correct captain succeeds ──────────────────────────────

	'tournament action by correct captain succeeds' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2', 'map3', 'map4', 'map5'));

		$startData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('tournament_draft', array(
				'captain_a' => 'captainA',
				'captain_b' => 'captainB',
				'best_of'   => 1,
			)));
		$harness->callStart($startData);

		// First step should be ban for team_a (captainA).
		$actionData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoActionParams('captainA', 'map1'));
		$answer = $harness->callAction($actionData);

		Assert::same(true, $answer->data['success']);
		Assert::same('ban_applied', $answer->data['code']);
	},

	'tournament action by wrong captain returns wrong_actor' => function () {
		$harness = new VetoDraftCommandTestHarness(array('Pixel Control Link Token' => 'valid-token'));
		$harness->setMapPool(array('map1', 'map2', 'map3', 'map4', 'map5'));

		$startData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoStartParams('tournament_draft', array(
				'captain_a' => 'captainA',
				'captain_b' => 'captainB',
				'best_of'   => 1,
			)));
		$harness->callStart($startData);

		// First step is ban for team_a. Sending captainB instead should fail.
		$actionData = makeVetoRequest('test-server.local', 'valid-token',
			makeVetoActionParams('captainB', 'map1'));
		$answer = $harness->callAction($actionData);

		Assert::same(false, $answer->data['success']);
		Assert::same('wrong_actor', $answer->data['code']);
	},

);
