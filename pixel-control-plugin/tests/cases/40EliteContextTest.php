<?php
declare(strict_types=1);

use PixelControl\Stats\PlayerCombatStatsStore;
use PixelControl\Tests\Support\Assert;

return array(
	'turn number starts at zero and increments on each openEliteRound' => function () {
		$store = new PlayerCombatStatsStore();
		Assert::same(0, $store->getEliteTurnNumber());

		$store->openEliteRound('attacker1', array('defender1', 'defender2'));
		Assert::same(1, $store->getEliteTurnNumber());

		$store->closeEliteRound(1);
		$store->openEliteRound('attacker1', array('defender1', 'defender2'));
		Assert::same(2, $store->getEliteTurnNumber());

		$store->closeEliteRound(2);
		$store->openEliteRound('attacker2', array('defender1'));
		Assert::same(3, $store->getEliteTurnNumber());
	},

	'turn number resets to zero on store reset' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('defender1'));
		$store->openEliteRound('attacker1', array('defender1'));
		Assert::same(2, $store->getEliteTurnNumber());

		$store->reset();
		Assert::same(0, $store->getEliteTurnNumber());
	},

	'getEliteAttackerLogin returns attacker during active round' => function () {
		$store = new PlayerCombatStatsStore();
		Assert::same(null, $store->getEliteAttackerLogin());

		$store->openEliteRound('attacker_login', array('defender1'));
		Assert::same('attacker_login', $store->getEliteAttackerLogin());
	},

	'getEliteDefenderLogins returns defender list during active round' => function () {
		$store = new PlayerCombatStatsStore();
		Assert::same(array(), $store->getEliteDefenderLogins());

		$store->openEliteRound('attacker1', array('def1', 'def2', 'def3'));
		$defenders = $store->getEliteDefenderLogins();
		Assert::same(3, count($defenders));
		Assert::same('def1', $defenders[0]);
		Assert::same('def2', $defenders[1]);
		Assert::same('def3', $defenders[2]);
	},

	'getEliteAttackerTeamId returns team ID when provided' => function () {
		$store = new PlayerCombatStatsStore();
		Assert::same(null, $store->getEliteAttackerTeamId());

		$store->openEliteRound('attacker1', array('def1'), 0);
		Assert::same(0, $store->getEliteAttackerTeamId());

		$store->closeEliteRound(1);
		$store->openEliteRound('attacker2', array('def2'), 1);
		Assert::same(1, $store->getEliteAttackerTeamId());
	},

	'getEliteAttackerTeamId returns null when not provided' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1'));
		Assert::same(null, $store->getEliteAttackerTeamId());
	},

	'isEliteRoundActive is false before any round is opened' => function () {
		$store = new PlayerCombatStatsStore();
		Assert::same(false, $store->isEliteRoundActive());
	},

	'isEliteRoundActive is true during an open round' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1'));
		Assert::same(true, $store->isEliteRoundActive());
	},

	'isEliteRoundActive is false after round is closed' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1'));
		$store->closeEliteRound(1);
		Assert::same(false, $store->isEliteRoundActive());
	},

	'attacker_team_id resets to null after store reset' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1'), 1);
		Assert::same(1, $store->getEliteAttackerTeamId());

		$store->reset();
		Assert::same(null, $store->getEliteAttackerTeamId());
	},
);
