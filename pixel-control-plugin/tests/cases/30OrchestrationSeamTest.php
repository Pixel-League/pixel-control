<?php
declare(strict_types=1);

use ManiaControl\Players\Player;
use PixelControl\Tests\Support\Assert;
use PixelControl\Tests\Support\FakeAuthenticationManager;
use PixelControl\Tests\Support\FakeManiaControl;
use PixelControl\Tests\Support\FakeMapPoolService;
use PixelControl\Tests\Support\FakeSettingManager;
use PixelControl\Tests\Support\FakeVetoCoordinator;
use PixelControl\Tests\Support\SeriesPersistenceHarness;
use PixelControl\Tests\Support\VetoReadyLifecyclePermissionHarness;
use PixelControl\VetoDraft\VetoDraftCatalog;

return array(
	'matchmaking ready gate blocks start until armed and consumes on success' => function () {
		$settingManager = new FakeSettingManager();
		$authenticationManager = new FakeAuthenticationManager();
		$maniaControl = new FakeManiaControl($settingManager, $authenticationManager);
		$vetoCoordinator = new FakeVetoCoordinator();
		$mapPoolService = new FakeMapPoolService(array(
			array('uid' => 'map-1', 'name' => 'Map 1'),
		));

		$harness = new VetoReadyLifecyclePermissionHarness($maniaControl, $vetoCoordinator, $mapPoolService);

		$startBeforeArm = $harness->startWithReadyGate('test_case', 1000, 60);
		Assert::false($startBeforeArm['success']);
		Assert::same('matchmaking_ready_required', $startBeforeArm['code']);

		$armResult = $harness->armReadyGate('test_case');
		Assert::true($armResult['success']);
		Assert::same('matchmaking_ready_armed', $armResult['code']);
		Assert::true($harness->isReadyArmed());

		$startAfterArm = $harness->startWithReadyGate('test_case', 1005, 60);
		Assert::true($startAfterArm['success']);
		Assert::same('matchmaking_started', $startAfterArm['code']);
		Assert::false($harness->isReadyArmed());
		Assert::false($harness->getAutostartArmed());

		$armWhileActive = $harness->armReadyGate('test_case');
		Assert::false($armWhileActive['success']);
		Assert::same('session_active', $armWhileActive['code']);
	},

	'matchmaking lifecycle completion snapshots active context and resets gates' => function () {
		$maniaControl = new FakeManiaControl();
		$harness = new VetoReadyLifecyclePermissionHarness(
			$maniaControl,
			new FakeVetoCoordinator(),
			new FakeMapPoolService(array())
		);

		$harness->setLifecycleContext(array(
			'active' => true,
			'status' => 'running',
			'session_id' => 'session-42',
			'ready_for_next_players' => false,
		));

		$harness->completeLifecycle('completed', 'selected_map_cycle_completed', 'test_source', array('branch' => 'runtime_poll'));

		Assert::same(null, $harness->getLifecycleContext());

		$lastSnapshot = $harness->getLifecycleLastSnapshot();
		Assert::true(is_array($lastSnapshot));
		Assert::false($lastSnapshot['active']);
		Assert::same('completed', $lastSnapshot['status']);
		Assert::same('selected_map_cycle_completed', $lastSnapshot['resolution_reason']);
		Assert::true($lastSnapshot['ready_for_next_players']);
		Assert::same('runtime_poll', $lastSnapshot['completion_details']['branch']);

		Assert::false($harness->isReadyArmed());
		Assert::false($harness->getAutostartArmed());
		Assert::false($harness->getAutostartSuppressed());
	},

	'series persistence reports successful full-write path' => function () {
		$settingManager = new FakeSettingManager();
		$maniaControl = new FakeManiaControl($settingManager);
		$harness = new SeriesPersistenceHarness($maniaControl);

		$targetSnapshot = array(
			'best_of' => 5,
			'maps_score' => array('team_a' => 2, 'team_b' => 1),
			'current_map_score' => array('team_a' => 4, 'team_b' => 3),
		);

		$result = $harness->persistSnapshot($targetSnapshot, array());
		Assert::true($result['success']);
		Assert::same('settings_persisted', $result['code']);
		Assert::count(5, $result['details']['written_settings']);
	},

	'series persistence failure path attempts rollback to previous snapshot' => function () {
		$initialValues = array(
			SeriesPersistenceHarness::SETTING_VETO_DRAFT_DEFAULT_BEST_OF => 3,
			SeriesPersistenceHarness::SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_A => 0,
			SeriesPersistenceHarness::SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_B => 0,
			SeriesPersistenceHarness::SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_A => 0,
			SeriesPersistenceHarness::SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_B => 0,
		);
		$failOnSet = array(
			SeriesPersistenceHarness::SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_B => true,
		);

		$settingManager = new FakeSettingManager($initialValues, $failOnSet);
		$maniaControl = new FakeManiaControl($settingManager);
		$harness = new SeriesPersistenceHarness($maniaControl);

		$previousSnapshot = array(
			'best_of' => 3,
			'maps_score' => array('team_a' => 0, 'team_b' => 0),
			'current_map_score' => array('team_a' => 0, 'team_b' => 0),
		);
		$targetSnapshot = array(
			'best_of' => 7,
			'maps_score' => array('team_a' => 2, 'team_b' => 5),
			'current_map_score' => array('team_a' => 9, 'team_b' => 4),
		);

		$result = $harness->persistSnapshot($targetSnapshot, $previousSnapshot);
		Assert::false($result['success']);
		Assert::same('setting_write_failed', $result['code']);
		Assert::inArray(SeriesPersistenceHarness::SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_B, $result['details']['failed_settings']);
		Assert::true($result['details']['rollback_attempted']);
		Assert::count(0, $result['details']['rollback_failed_settings']);

		Assert::same(3, $settingManager->values[SeriesPersistenceHarness::SETTING_VETO_DRAFT_DEFAULT_BEST_OF]);
		Assert::same(0, $settingManager->values[SeriesPersistenceHarness::SETTING_SERIES_CONTROL_MAPS_SCORE_TEAM_A]);
		Assert::same(0, $settingManager->values[SeriesPersistenceHarness::SETTING_SERIES_CONTROL_CURRENT_MAP_SCORE_TEAM_A]);
	},

	'veto override permission requires force flag and explicit right' => function () {
		$authenticationManager = new FakeAuthenticationManager();
		$maniaControl = new FakeManiaControl(new FakeSettingManager(), $authenticationManager);
		$harness = new VetoReadyLifecyclePermissionHarness(
			$maniaControl,
			new FakeVetoCoordinator(),
			new FakeMapPoolService(array(array('uid' => 'map-1', 'name' => 'Map 1')))
		);
		$player = new Player('captain');

		Assert::false($harness->resolveOverride(array('force' => '1'), $player));

		$authenticationManager->setPermission(VetoDraftCatalog::RIGHT_OVERRIDE, true);
		Assert::true($harness->resolveOverride(array('force' => '1'), $player));
		Assert::false($harness->resolveOverride(array(), $player));
	},
);
