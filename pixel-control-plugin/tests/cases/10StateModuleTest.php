<?php
declare(strict_types=1);

use PixelControl\AccessControl\WhitelistCatalog;
use PixelControl\AccessControl\WhitelistState;
use PixelControl\SeriesControl\SeriesControlCatalog;
use PixelControl\SeriesControl\SeriesControlState;
use PixelControl\TeamControl\TeamRosterCatalog;
use PixelControl\TeamControl\TeamRosterState;
use PixelControl\Tests\Support\Assert;
use PixelControl\VoteControl\VotePolicyCatalog;
use PixelControl\VoteControl\VotePolicyState;

return array(
	'whitelist bootstrap normalizes and deduplicates logins' => function () {
		$state = new WhitelistState();

		$result = $state->bootstrap(
			array(
				'enabled' => 'yes',
				'logins' => 'Alice, alice; BOB',
			),
			WhitelistCatalog::UPDATE_SOURCE_ENV,
			''
		);

		Assert::true($result['success']);
		Assert::same('whitelist_bootstrap_applied', $result['code']);

		$snapshot = $state->getSnapshot();
		Assert::true($snapshot['enabled']);
		Assert::same(array('alice', 'bob'), $snapshot['logins']);
		Assert::same(2, $snapshot['count']);
		Assert::same(WhitelistCatalog::UPDATE_SOURCE_ENV, $snapshot['update_source']);
		Assert::same('system', $snapshot['updated_by']);
	},

	'whitelist add remove and clean handle edge cases' => function () {
		$state = new WhitelistState();
		$state->bootstrap(array(), WhitelistCatalog::UPDATE_SOURCE_SETTING, 'bootstrap');

		$invalidAdd = $state->addLogin('   ', WhitelistCatalog::UPDATE_SOURCE_CHAT, 'moderator');
		Assert::false($invalidAdd['success']);
		Assert::same('invalid_parameters', $invalidAdd['code']);

		$addResult = $state->addLogin('"PlayerOne"', WhitelistCatalog::UPDATE_SOURCE_CHAT, 'moderator');
		Assert::true($addResult['success']);
		Assert::same('whitelist_login_added', $addResult['code']);
		Assert::true($state->hasLogin('playerone'));

		$addAgainResult = $state->addLogin('playerone', WhitelistCatalog::UPDATE_SOURCE_CHAT, 'moderator');
		Assert::true($addAgainResult['success']);
		Assert::same('whitelist_login_present', $addAgainResult['code']);

		$missingRemove = $state->removeLogin('ghost', WhitelistCatalog::UPDATE_SOURCE_CHAT, 'moderator');
		Assert::true($missingRemove['success']);
		Assert::same('whitelist_login_missing', $missingRemove['code']);

		$removeResult = $state->removeLogin('PLAYERONE', WhitelistCatalog::UPDATE_SOURCE_CHAT, 'moderator');
		Assert::true($removeResult['success']);
		Assert::same('whitelist_login_removed', $removeResult['code']);
		Assert::false($state->hasLogin('playerone'));

		$cleanResult = $state->clean(WhitelistCatalog::UPDATE_SOURCE_CHAT, 'moderator');
		Assert::true($cleanResult['success']);
		Assert::same('whitelist_already_empty', $cleanResult['code']);
	},

	'vote policy state enforces mode validation and aliases' => function () {
		$state = new VotePolicyState();
		$state->bootstrap(array(), VotePolicyCatalog::UPDATE_SOURCE_SETTING, 'bootstrap');

		$setStrict = $state->setMode('strict', VotePolicyCatalog::UPDATE_SOURCE_CHAT, 'admin');
		Assert::true($setStrict['success']);
		Assert::same('vote_policy_mode_updated', $setStrict['code']);
		Assert::same(VotePolicyCatalog::MODE_DISABLE_CALLVOTES, $state->getMode());
		Assert::true($state->getSnapshot()['strict_mode']);

		$setStrictAgain = $state->setMode(VotePolicyCatalog::MODE_DISABLE_CALLVOTES, VotePolicyCatalog::UPDATE_SOURCE_CHAT, 'admin');
		Assert::true($setStrictAgain['success']);
		Assert::same('vote_policy_mode_unchanged', $setStrictAgain['code']);

		$missingMode = $state->setMode('', VotePolicyCatalog::UPDATE_SOURCE_CHAT, 'admin');
		Assert::false($missingMode['success']);
		Assert::same('missing_parameters', $missingMode['code']);

		$invalidMode = $state->setMode('unsupported_mode', VotePolicyCatalog::UPDATE_SOURCE_CHAT, 'admin');
		Assert::false($invalidMode['success']);
		Assert::same('invalid_parameters', $invalidMode['code']);
	},

	'team roster state normalizes assignments and policy updates' => function () {
		$state = new TeamRosterState();
		$state->bootstrap(
			array(
				'policy_enabled' => '1',
				'switch_lock' => '0',
				'assignments' => array(
					' Alice ' => 'blue',
					'bob' => 'red',
					'invalid' => 'green',
				),
			),
			TeamRosterCatalog::UPDATE_SOURCE_SETTING,
			'bootstrap'
		);

		$snapshot = $state->getSnapshot();
		Assert::true($snapshot['policy_enabled']);
		Assert::false($snapshot['switch_lock_enabled']);
		Assert::same(2, $snapshot['assignment_count']);

		$assignResult = $state->assign('"Carol"', '1', TeamRosterCatalog::UPDATE_SOURCE_CHAT, 'moderator');
		Assert::true($assignResult['success']);
		Assert::same('team_assignment_updated', $assignResult['code']);
		Assert::same(TeamRosterCatalog::TEAM_B, $state->getAssignedTeam('carol'));
		Assert::same(1, $state->getAssignedTeamId('carol'));

		$assignSameResult = $state->assign('carol', 'red', TeamRosterCatalog::UPDATE_SOURCE_CHAT, 'moderator');
		Assert::true($assignSameResult['success']);
		Assert::same('team_assignment_unchanged', $assignSameResult['code']);

		$missingPolicy = $state->setPolicy(null, null, TeamRosterCatalog::UPDATE_SOURCE_CHAT, 'admin');
		Assert::false($missingPolicy['success']);
		Assert::same('missing_parameters', $missingPolicy['code']);

		$updatePolicy = $state->setPolicy('0', null, TeamRosterCatalog::UPDATE_SOURCE_CHAT, 'admin');
		Assert::true($updatePolicy['success']);
		Assert::same('team_policy_updated', $updatePolicy['code']);
		Assert::false($state->isPolicyEnabled());

		$missingUnassign = $state->unassign('ghost', TeamRosterCatalog::UPDATE_SOURCE_CHAT, 'moderator');
		Assert::true($missingUnassign['success']);
		Assert::same('team_assignment_missing', $missingUnassign['code']);

		$removeResult = $state->unassign('CAROL', TeamRosterCatalog::UPDATE_SOURCE_CHAT, 'moderator');
		Assert::true($removeResult['success']);
		Assert::same('team_assignment_removed', $removeResult['code']);
		Assert::false($state->hasAssignment('carol'));
	},

	'series control state sanitizes numeric updates and reports warnings' => function () {
		$state = new SeriesControlState();
		$state->bootstrap(
			array(
				SeriesControlCatalog::PARAM_BEST_OF => 4,
				SeriesControlCatalog::PARAM_MAPS_SCORE => array(
					SeriesControlCatalog::TEAM_A => 2,
					SeriesControlCatalog::TEAM_B => '3',
				),
				'current_map_score' => array(
					SeriesControlCatalog::TEAM_A => 10,
					SeriesControlCatalog::TEAM_B => 11,
				),
			),
			SeriesControlCatalog::UPDATE_SOURCE_ENV,
			'bootstrap'
		);

		$bootstrapSnapshot = $state->getSnapshot();
		Assert::same(5, $bootstrapSnapshot['best_of']);
		Assert::same(3, $bootstrapSnapshot['maps_score'][SeriesControlCatalog::TEAM_B]);

		$setBestOf = $state->setBestOf('2', SeriesControlCatalog::UPDATE_SOURCE_CHAT, 'admin', array('active_session' => true));
		Assert::true($setBestOf['success']);
		Assert::same('best_of_updated', $setBestOf['code']);
		Assert::same(3, $setBestOf['details']['normalized_best_of']);
		Assert::same('next_session', $setBestOf['details']['apply_scope']);
		Assert::inArray('best_of_normalized', $setBestOf['details']['warnings']);

		$invalidBestOf = $state->setBestOf('bad', SeriesControlCatalog::UPDATE_SOURCE_CHAT, 'admin');
		Assert::false($invalidBestOf['success']);
		Assert::same('invalid_parameters', $invalidBestOf['code']);

		$invalidMapsScore = $state->setMatchMapsScore('blue', -1, SeriesControlCatalog::UPDATE_SOURCE_CHAT, 'admin');
		Assert::false($invalidMapsScore['success']);
		Assert::same('invalid_parameters', $invalidMapsScore['code']);

		$normalizedMapsScore = $state->setMatchMapsScore('red', 150, SeriesControlCatalog::UPDATE_SOURCE_CHAT, 'admin');
		Assert::true($normalizedMapsScore['success']);
		Assert::same(99, $normalizedMapsScore['details']['normalized_maps_score']);
		Assert::inArray('maps_score_normalized', $normalizedMapsScore['details']['warnings']);

		$normalizedCurrentScore = $state->setCurrentMapScore('team_a', 1500, SeriesControlCatalog::UPDATE_SOURCE_CHAT, 'admin');
		Assert::true($normalizedCurrentScore['success']);
		Assert::same(999, $normalizedCurrentScore['details']['normalized_score']);
		Assert::inArray('current_map_score_normalized', $normalizedCurrentScore['details']['warnings']);

		$invalidTeam = $state->setCurrentMapScore('unknown', 1, SeriesControlCatalog::UPDATE_SOURCE_CHAT, 'admin');
		Assert::false($invalidTeam['success']);
		Assert::same('invalid_parameters', $invalidTeam['code']);
	},
);
