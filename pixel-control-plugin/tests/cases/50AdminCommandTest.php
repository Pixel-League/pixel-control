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

class FakeManiaControlForAdmin {
	public $settingManager;
	public $server;

	public function __construct($settingValues = array()) {
		$this->settingManager = new FakeSettingManager($settingValues);
		$this->server = new FakeServer();
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
}

class AdminCommandTestHarness {
	use PixelControl\Domain\Admin\AdminCommandTrait;

	const SETTING_LINK_TOKEN = 'Pixel Control Link Token';

	public $maniaControl;

	public function __construct($settingValues = array()) {
		$this->maniaControl = new FakeManiaControlForAdmin($settingValues);
	}

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

);
