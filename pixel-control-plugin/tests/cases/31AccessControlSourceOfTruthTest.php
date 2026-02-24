<?php
declare(strict_types=1);

use ManiaControl\Players\Player;
use PixelControl\Admin\AdminActionCatalog;
use PixelControl\Tests\Support\AccessControlSourceOfTruthHarness;
use PixelControl\Tests\Support\Assert;
use PixelControl\Tests\Support\FakeClient;
use PixelControl\Tests\Support\FakeManiaControl;
use PixelControl\Tests\Support\FakePlayerManager;

return array(
	'whitelist sweep kicks active non-whitelisted players and skips protected identities' => function () {
		$playerManager = new FakePlayerManager();
		$playerManager->registerPlayer(new Player('allowed-player'));
		$playerManager->registerPlayer(new Player('blocked-player'));
		$playerManager->registerPlayer(new Player('server-player', 0, true, false));
		$playerManager->registerPlayer(new Player('fake-player', 0, false, true));

		$client = new FakeClient();
		$maniaControl = new FakeManiaControl(null, null, null, null, null, $playerManager, $client);
		$harness = new AccessControlSourceOfTruthHarness($maniaControl);
		$harness->bootstrapWhitelist(true, array('allowed-player'));

		$result = $harness->runWhitelistSweep('policy_change', true);

		Assert::same('sweep_completed', $result['code']);
		Assert::same(1, $result['applied_count']);
		Assert::same(0, $result['failed_count']);
		Assert::same(3, $result['skipped_count']);
		Assert::count(1, $client->kickCalls);
		Assert::same('blocked-player', $client->kickCalls[0]['login']);
	},

	'periodic access-control tick reconciles whitelist with interval guard' => function () {
		$playerManager = new FakePlayerManager();
		$playerManager->registerPlayer(new Player('allowed-player'));
		$playerManager->registerPlayer(new Player('blocked-player'));

		$client = new FakeClient();
		$maniaControl = new FakeManiaControl(null, null, null, null, null, $playerManager, $client);
		$harness = new AccessControlSourceOfTruthHarness($maniaControl);
		$harness->whitelistDenyCooldownSeconds = 0;
		$harness->whitelistReconcileIntervalSeconds = 90;

		$harness->bootstrapWhitelist(true, array('allowed-player'));
		$harness->runAccessControlPolicyTick();
		Assert::count(1, $client->kickCalls);

		$harness->runAccessControlPolicyTick();
		Assert::count(1, $client->kickCalls);

		$harness->whitelistLastReconcileAt = time() - 120;
		$harness->runAccessControlPolicyTick();
		Assert::count(2, $client->kickCalls);
	},

	'heartbeat capabilities include whitelist state and mutation queues immediate refresh' => function () {
		$playerManager = new FakePlayerManager();
		$client = new FakeClient();
		$maniaControl = new FakeManiaControl(null, null, null, null, null, $playerManager, $client);
		$harness = new AccessControlSourceOfTruthHarness($maniaControl);
		$harness->bootstrapWhitelist(true, array('Bravo', 'alpha'));

		$heartbeat = $harness->buildHeartbeatForTest();
		Assert::arrayHasKey('capabilities', $heartbeat);
		Assert::same(true, $heartbeat['capabilities']['admin_control']['whitelist']['enabled']);
		Assert::same(array('alpha', 'bravo'), $heartbeat['capabilities']['admin_control']['whitelist']['logins']);

		$harness->triggerCapabilityRefreshForAction(AdminActionCatalog::ACTION_WHITELIST_ENABLE);
		Assert::count(1, $harness->queuedConnectivityEnvelopes);
		Assert::same('plugin.heartbeat', $harness->queuedConnectivityEnvelopes[0]['event_name']);
		Assert::same(
			AdminActionCatalog::ACTION_WHITELIST_ENABLE,
			$harness->queuedConnectivityEnvelopes[0]['payload']['refresh']['action_name']
		);
		Assert::same(
			true,
			$harness->queuedConnectivityEnvelopes[0]['payload']['capabilities']['admin_control']['whitelist']['enabled']
		);
	},
);
