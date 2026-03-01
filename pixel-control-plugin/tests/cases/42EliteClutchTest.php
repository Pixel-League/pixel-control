<?php
declare(strict_types=1);

use PixelControl\Stats\PlayerCombatStatsStore;
use PixelControl\Tests\Support\Assert;

return array(
	'alive defenders list starts full when round opens' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1', 'def2', 'def3'));

		Assert::same(3, $store->getAliveDefenderCount());
		Assert::same(array('def1', 'def2', 'def3'), $store->getAliveDefenderLogins());
	},

	'alive defenders decrements when a defender dies' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1', 'def2', 'def3'));

		$store->recordKill('attacker1', 'def1');

		Assert::same(2, $store->getAliveDefenderCount());
		$alive = $store->getAliveDefenderLogins();
		Assert::same(false, in_array('def1', $alive));
		Assert::same(true, in_array('def2', $alive));
		Assert::same(true, in_array('def3', $alive));
	},

	'alive defenders not decremented when attacker dies' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1', 'def2'));

		// def1 kills the attacker (attacker dies, not a defender)
		$store->recordKill('def1', 'attacker1');

		Assert::same(2, $store->getAliveDefenderCount());
	},

	'alive defenders resets when new round opens' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1', 'def2', 'def3'));
		$store->recordKill('attacker1', 'def1');
		$store->recordKill('attacker1', 'def2');
		Assert::same(1, $store->getAliveDefenderCount());

		$store->closeEliteRound(1);
		$store->openEliteRound('attacker1', array('defA', 'defB'));
		Assert::same(2, $store->getAliveDefenderCount());
	},

	'clutch scenario: 3 defenders, 2 die, time_limit victory -> clutch detected' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1', 'def2', 'def3'));

		// 2 defenders die
		$store->recordKill('attacker1', 'def1');
		$store->recordKill('attacker1', 'def2');

		// Verify alive count = 1 and total = 3
		Assert::same(1, $store->getAliveDefenderCount());
		Assert::same(3, count($store->getEliteDefenderLogins()));

		// defense_success = true (time_limit = 1)
		$defenseSuccess = true;
		$aliveCount = $store->getAliveDefenderCount();
		$totalDefenders = count($store->getEliteDefenderLogins());
		$isClutch = $defenseSuccess && $aliveCount === 1 && $totalDefenders > 1;

		Assert::same(true, $isClutch);
		$aliveLogins = $store->getAliveDefenderLogins();
		Assert::same('def3', $aliveLogins[0]);
	},

	'no clutch: 3 defenders, 0 die, defense success -> not a clutch (all alive)' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1', 'def2', 'def3'));

		// Nobody dies
		Assert::same(3, $store->getAliveDefenderCount());

		$defenseSuccess = true;
		$aliveCount = $store->getAliveDefenderCount();
		$totalDefenders = count($store->getEliteDefenderLogins());
		$isClutch = $defenseSuccess && $aliveCount === 1 && $totalDefenders > 1;

		Assert::same(false, $isClutch);
	},

	'no clutch: 3 defenders, 2 die, attacker captures -> not a clutch (defense failed)' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1', 'def2', 'def3'));

		$store->recordKill('attacker1', 'def1');
		$store->recordKill('attacker1', 'def2');

		// Attacker captures = defense fails (victoryType=2)
		$defenseSuccess = false;
		$aliveCount = $store->getAliveDefenderCount();
		$totalDefenders = count($store->getEliteDefenderLogins());
		$isClutch = $defenseSuccess && $aliveCount === 1 && $totalDefenders > 1;

		Assert::same(false, $isClutch);
	},

	'no clutch: 1 defender total, 0 die, defense success -> not a clutch (totalDefenders > 1 check)' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1'));

		Assert::same(1, $store->getAliveDefenderCount());

		$defenseSuccess = true;
		$aliveCount = $store->getAliveDefenderCount();
		$totalDefenders = count($store->getEliteDefenderLogins());
		$isClutch = $defenseSuccess && $aliveCount === 1 && $totalDefenders > 1;

		Assert::same(false, $isClutch);
	},

	'clutch: 2 defenders, 1 dies, attacker eliminated (victoryType=3) -> clutch detected' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1', 'def2'));

		$store->recordKill('def1', 'attacker1');
		// def1 kills attacker but def2 also died earlier? No - let's say def2 died first
		// Actually the scenario: def2 dies, then def1 kills attacker (attacker eliminated, victoryType=3)
		// Let's redo: def2 dies first
		$store->recordKill('attacker1', 'def2');

		// Now: 1 alive defender (def1), victoryType=3 = attacker eliminated = defense success
		$defenseSuccess = true; // victoryType=3
		$aliveCount = $store->getAliveDefenderCount();
		$totalDefenders = count($store->getEliteDefenderLogins());
		$isClutch = $defenseSuccess && $aliveCount === 1 && $totalDefenders > 1;

		Assert::same(true, $isClutch);
		$aliveLogins = $store->getAliveDefenderLogins();
		Assert::same('def1', $aliveLogins[0]);
	},

	'alive defenders resets to empty after store reset' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1', 'def2'));
		Assert::same(2, $store->getAliveDefenderCount());

		$store->reset();
		Assert::same(0, $store->getAliveDefenderCount());
		Assert::same(array(), $store->getAliveDefenderLogins());
	},
);
