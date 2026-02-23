<?php
declare(strict_types=1);

use PixelControl\Admin\AdminActionCatalog;
use PixelControl\Tests\Support\AdminVetoNormalizationHarness;
use PixelControl\Tests\Support\Assert;

return array(
	'admin command parsing preserves positionals and normalizes aliases' => function () {
		$harness = new AdminVetoNormalizationHarness();

		$request = $harness->parseAdminCommandRequest(array(
			1 => array(2 => '//pcadmin maps.set blue 7'),
		));

		Assert::same(AdminActionCatalog::ACTION_MATCH_MAPS_SET, $request['action_name']);
		Assert::same(array('blue', '7'), $request['parameters']['_positionals']);

		$normalized = $harness->normalizeAdminActionParameters($request['action_name'], $request['parameters']);
		Assert::same('blue', $normalized['target_team']);
		Assert::same('7', $normalized['maps_score']);
	},

	'admin parameter normalization maps aliases into canonical keys' => function () {
		$harness = new AdminVetoNormalizationHarness();

		$mapsParams = $harness->normalizeAdminActionParameters(
			AdminActionCatalog::ACTION_MATCH_MAPS_SET,
			array('team' => 'red', 'score' => '5')
		);
		Assert::same('red', $mapsParams['target_team']);
		Assert::same('5', $mapsParams['maps_score']);

		$boParams = $harness->normalizeAdminActionParameters(
			AdminActionCatalog::ACTION_MATCH_BO_SET,
			array('bo' => '7')
		);
		Assert::same('7', $boParams['best_of']);

		$rosterParams = $harness->normalizeAdminActionParameters(
			AdminActionCatalog::ACTION_TEAM_ROSTER_ASSIGN,
			array('target_login' => 'alice', 'target_team' => 'blue')
		);
		Assert::same('blue', $rosterParams['team']);
	},

	'veto command parsing supports mixed positional and key-value inputs' => function () {
		$harness = new AdminVetoNormalizationHarness();

		$startRequest = $harness->parseVetoCommandRequest(array(
			1 => array(2 => '//pcveto start tournament captain_a=Alpha captain_b=Beta bo=5'),
		));
		Assert::same('start', $startRequest['operation']);
		Assert::same('tournament', $startRequest['parameters']['_positionals'][0]);
		Assert::same('Alpha', $startRequest['parameters']['captain_a']);
		Assert::same('Beta', $startRequest['parameters']['captain_b']);
		Assert::same('5', $startRequest['parameters']['bo']);

		$voteRequest = $harness->parseVetoCommandRequest(array(
			1 => array(2 => '//pcveto vote 3'),
		));
		Assert::same('vote', $voteRequest['operation']);
		Assert::same('3', $voteRequest['parameters']['_positionals'][0]);
	},

	'communication payload normalization decodes objects and rejects scalar input' => function () {
		$harness = new AdminVetoNormalizationHarness();

		$normalizedObjectPayload = $harness->normalizePayload((object) array(
			'action' => 'map.skip',
			'parameters' => (object) array('map_uid' => 'UID-1'),
		));
		Assert::same('map.skip', $normalizedObjectPayload['action']);
		Assert::same('UID-1', $normalizedObjectPayload['parameters']['map_uid']);

		$normalizedScalarPayload = $harness->normalizePayload('invalid');
		Assert::same(array(), $normalizedScalarPayload);
	},

	'empty command inputs return deterministic empty requests' => function () {
		$harness = new AdminVetoNormalizationHarness();

		$emptyAdmin = $harness->parseAdminCommandRequest(array());
		Assert::same('', $emptyAdmin['action_name']);
		Assert::same(array(), $emptyAdmin['parameters']);

		$emptyVeto = $harness->parseVetoCommandRequest(array(
			1 => array(2 => '//pcveto'),
		));
		Assert::same('', $emptyVeto['operation']);
		Assert::same(array(), $emptyVeto['parameters']);
	},
);
