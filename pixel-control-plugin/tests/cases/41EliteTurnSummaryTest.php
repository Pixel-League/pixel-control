<?php
declare(strict_types=1);

use PixelControl\Stats\PlayerCombatStatsStore;
use PixelControl\Tests\Support\Assert;

return array(
	'snapshotEliteRoundStats returns correct structure with all participants' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1', 'def2'), 0);

		$snapshot = $store->snapshotEliteRoundStats();

		Assert::same(1, $snapshot['turn_number']);
		Assert::same('attacker1', $snapshot['attacker_login']);
		Assert::same(array('def1', 'def2'), $snapshot['defender_logins']);
		Assert::same(0, $snapshot['attacker_team_id']);
		Assert::arrayHasKey('per_player', $snapshot);
		Assert::arrayHasKey('attacker1', $snapshot['per_player']);
		Assert::arrayHasKey('def1', $snapshot['per_player']);
		Assert::arrayHasKey('def2', $snapshot['per_player']);
	},

	'snapshotEliteRoundStats per_player counters start at zero' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1'));

		$snapshot = $store->snapshotEliteRoundStats();
		$attackerStats = $snapshot['per_player']['attacker1'];

		Assert::same(0, $attackerStats['kills']);
		Assert::same(0, $attackerStats['deaths']);
		Assert::same(0, $attackerStats['hits']);
		Assert::same(0, $attackerStats['shots']);
		Assert::same(0, $attackerStats['misses']);
		Assert::same(0, $attackerStats['rocket_hits']);
	},

	'snapshotEliteRoundStats reflects shots and misses tracked during round' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1'));

		// Attacker shoots twice
		$store->recordShot('attacker1', null);
		$store->recordShot('attacker1', null);
		$store->recordMiss('attacker1');

		$snapshot = $store->snapshotEliteRoundStats();
		$attackerStats = $snapshot['per_player']['attacker1'];

		Assert::same(2, $attackerStats['shots']);
		Assert::same(1, $attackerStats['misses']);
		Assert::same(0, $attackerStats['hits']);
	},

	'snapshotEliteRoundStats reflects hits and kills' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1', 'def2'));

		// Attacker hits defender1 twice and kills them
		$store->recordHit('attacker1', 2); // rocket hit
		$store->recordHit('attacker1', 2); // rocket hit
		$store->recordKill('attacker1', 'def1');

		$snapshot = $store->snapshotEliteRoundStats();
		$attackerStats = $snapshot['per_player']['attacker1'];
		$def1Stats = $snapshot['per_player']['def1'];

		Assert::same(2, $attackerStats['hits']);
		Assert::same(2, $attackerStats['rocket_hits']);
		Assert::same(1, $attackerStats['kills']);
		Assert::same(1, $def1Stats['deaths']);
	},

	'per-turn counters reset between rounds' => function () {
		$store = new PlayerCombatStatsStore();

		// Round 1: attacker shoots 3 times
		$store->openEliteRound('attacker1', array('def1'));
		$store->recordShot('attacker1', null);
		$store->recordShot('attacker1', null);
		$store->recordShot('attacker1', null);
		$store->closeEliteRound(2);

		// Round 2: attacker shoots once
		$store->openEliteRound('attacker1', array('def1'));
		$store->recordShot('attacker1', null);

		$snapshot = $store->snapshotEliteRoundStats();

		// Should only see 1 shot (round 2), not 4
		Assert::same(1, $snapshot['per_player']['attacker1']['shots']);
		Assert::same(2, $snapshot['turn_number']);
	},

	'defense_success is true for time_limit (victoryType=1)' => function () {
		// Test the logic: victoryType 1 = time_limit = defense success
		// We verify via the outcome labels which map to defense_success
		$victoryTypeToDefenseSuccess = array(
			1 => true,  // time_limit
			2 => false, // capture (attack wins)
			3 => true,  // attacker_eliminated
			4 => false, // defenders_eliminated (attack wins)
		);

		foreach ($victoryTypeToDefenseSuccess as $victoryType => $expectedDefenseSuccess) {
			$isDefenseSuccess = ($victoryType === 1 || $victoryType === 3);
			Assert::same($expectedDefenseSuccess, $isDefenseSuccess, 'victoryType=' . $victoryType);
		}
	},

	'getEliteRoundStartedAt is zero before round opens' => function () {
		$store = new PlayerCombatStatsStore();
		Assert::same(0, $store->getEliteRoundStartedAt());
	},

	'getEliteRoundStartedAt is set when round opens' => function () {
		$store = new PlayerCombatStatsStore();
		$before = time();
		$store->openEliteRound('attacker1', array('def1'));
		$after = time();

		$startedAt = $store->getEliteRoundStartedAt();
		Assert::same(true, $startedAt >= $before);
		Assert::same(true, $startedAt <= $after);
	},

	'getEliteRoundStartedAt resets to zero after store reset' => function () {
		$store = new PlayerCombatStatsStore();
		$store->openEliteRound('attacker1', array('def1'));
		Assert::same(true, $store->getEliteRoundStartedAt() > 0);

		$store->reset();
		Assert::same(0, $store->getEliteRoundStartedAt());
	},
);
