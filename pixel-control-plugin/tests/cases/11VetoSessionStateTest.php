<?php
declare(strict_types=1);

use PixelControl\Tests\Support\Assert;
use PixelControl\VetoDraft\MatchmakingVoteSession;
use PixelControl\VetoDraft\TournamentDraftSession;
use PixelControl\VetoDraft\VetoDraftCatalog;

return array(
	'matchmaking session finalizes deterministic top-vote winner' => function () {
		$session = new MatchmakingVoteSession(
			'session-1',
			array(
				array('uid' => 'MAP-A', 'name' => 'Map A'),
				array('uid' => 'MAP-B', 'name' => 'Map B'),
			),
			60,
			100
		);

		Assert::true($session->isRunning());

		$voteA = $session->castVote('PlayerOne', 'MAP-A', 101);
		Assert::true($voteA['success']);

		$voteA2 = $session->castVote('PlayerTwo', 'MAP-A', 102);
		Assert::true($voteA2['success']);

		$voteB = $session->castVote('playerone', 'map-b', 103);
		Assert::true($voteB['success']);

		$voteB2 = $session->castVote('player-three', 'map-b', 104);
		Assert::true($voteB2['success']);

		$finalSnapshot = $session->finalize(200);
		Assert::same(VetoDraftCatalog::STATUS_COMPLETED, $finalSnapshot['status']);
		Assert::same('MAP-B', $finalSnapshot['winner_map_uid']);
		Assert::same('top_vote_winner', $finalSnapshot['resolution_reason']);
		Assert::false($finalSnapshot['tie_break_applied']);
		Assert::same(3, $finalSnapshot['vote_count']);
		Assert::same('MAP-B', $finalSnapshot['vote_totals'][0]['map_uid']);
		Assert::same(2, $finalSnapshot['vote_totals'][0]['vote_count']);
	},

	'matchmaking session reset and cancel paths keep counters consistent' => function () {
		$session = new MatchmakingVoteSession(
			'session-2',
			array(
				array('uid' => 'ONLY-MAP', 'name' => 'Only Map'),
			),
			30,
			10
		);

		$vote = $session->castVote('player', 'ONLY-MAP', 12);
		Assert::true($vote['success']);

		$session->resetVoteCounters();
		$resetSnapshot = $session->toArray();
		Assert::same(0, $resetSnapshot['vote_count']);
		Assert::same(0, $resetSnapshot['vote_totals'][0]['vote_count']);

		$cancelSnapshot = $session->cancel(20, 'manual_abort');
		Assert::same(VetoDraftCatalog::STATUS_CANCELLED, $cancelSnapshot['status']);
		Assert::same('manual_abort', $cancelSnapshot['resolution_reason']);
	},

	'tournament session enforces actor permissions and auto-locks decider' => function () {
		$sequence = array(
			'steps' => array(
				array('order_index' => 1, 'phase' => 'ban_1', 'team' => VetoDraftCatalog::TEAM_A, 'action_kind' => VetoDraftCatalog::ACTION_BAN),
				array('order_index' => 2, 'phase' => 'lock_1', 'team' => VetoDraftCatalog::TEAM_SYSTEM, 'action_kind' => VetoDraftCatalog::ACTION_LOCK),
			),
		);

		$session = new TournamentDraftSession(
			'tournament-1',
			array(
				array('uid' => 'map-a', 'name' => 'Map A'),
				array('uid' => 'map-b', 'name' => 'Map B'),
			),
			array(
				VetoDraftCatalog::TEAM_A => 'alice',
				VetoDraftCatalog::TEAM_B => 'bob',
			),
			$sequence,
			45,
			1000
		);

		$forbiddenResult = $session->applyAction('bob', 'map-a', 1001, 'chat', false);
		Assert::false($forbiddenResult['success']);
		Assert::same('actor_not_allowed', $forbiddenResult['code']);

		$appliedResult = $session->applyAction('alice', 'map-a', 1002, 'chat', false);
		Assert::true($appliedResult['success']);
		Assert::same('action_applied', $appliedResult['code']);
		Assert::arrayHasKey('auto_lock', $appliedResult);
		Assert::true($appliedResult['auto_lock']['success']);

		$finalSnapshot = $session->toArray();
		Assert::same(VetoDraftCatalog::STATUS_COMPLETED, $finalSnapshot['status']);
		Assert::same('map-b', $finalSnapshot['decider_map']['uid']);
		Assert::count(2, $finalSnapshot['actions']);
		Assert::same('explicit', $finalSnapshot['actions'][0]['action_status']);
		Assert::same('inferred', $finalSnapshot['actions'][1]['action_status']);
	},

	'tournament timeout fallback applies inferred action deterministically' => function () {
		$sequence = array(
			'steps' => array(
				array('order_index' => 1, 'phase' => 'pick_1', 'team' => VetoDraftCatalog::TEAM_A, 'action_kind' => VetoDraftCatalog::ACTION_PICK),
			),
		);

		$session = new TournamentDraftSession(
			'tournament-2',
			array(
				array('uid' => 'map-only', 'name' => 'Map Only'),
			),
			array(
				VetoDraftCatalog::TEAM_A => 'alice',
				VetoDraftCatalog::TEAM_B => 'bob',
			),
			$sequence,
			45,
			1100
		);

		$timeoutResult = $session->applyTimeoutFallback(1200);
		Assert::true($timeoutResult['success']);
		Assert::same('action_applied', $timeoutResult['code']);
		Assert::true($timeoutResult['action']['auto_action']);
		Assert::same('timeout_auto', $timeoutResult['action']['action_source']);
		Assert::same(VetoDraftCatalog::STATUS_COMPLETED, $timeoutResult['session']['status']);
	},
);
