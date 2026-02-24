<?php
declare(strict_types=1);

use ManiaControl\Admin\AuthenticationManager;
use ManiaControl\Players\Player;
use PixelControl\Admin\AdminActionCatalog;
use PixelControl\Tests\Support\AdminLinkAuthHarness;
use PixelControl\Tests\Support\Assert;
use PixelControl\Tests\Support\FakeLinkApiClient;
use PixelControl\Tests\Support\FakeManiaControl;
use PixelControl\Tests\Support\FakeServer;
use PixelControl\Tests\Support\FakeSettingManager;

function latestChatMessage(FakeManiaControl $maniaControl): array {
	$messages = $maniaControl->getChat()->messages;
	if (count($messages) === 0) {
		return array('type' => '', 'message' => '');
	}

	return $messages[count($messages) - 1];
}

function infoChatMessages(FakeManiaControl $maniaControl): array {
	$messages = $maniaControl->getChat()->messages;
	$infoMessages = array();
	foreach ($messages as $message) {
		if (!is_array($message)) {
			continue;
		}

		if (!isset($message['type']) || (string) $message['type'] !== 'info') {
			continue;
		}

		$infoMessages[] = isset($message['message']) ? (string) $message['message'] : '';
	}

	return $infoMessages;
}

function deriveAdminHelpFamilyKey(string $actionName): string {
	$normalizedActionName = strtolower(trim($actionName));
	if ($normalizedActionName === '') {
		return 'unknown';
	}

	$segments = explode('.', $normalizedActionName);
	if (count($segments) <= 1) {
		return $normalizedActionName;
	}

	array_pop($segments);
	$familyKey = implode('.', $segments);
	if ($familyKey !== '') {
		return $familyKey;
	}

	return $normalizedActionName;
}

function expectedAdminHelpFamilyCount(): int {
	$actionDefinitions = AdminActionCatalog::getActionDefinitions();
	$familyIndex = array();
	foreach (array_keys($actionDefinitions) as $actionName) {
		$familyIndex[deriveAdminHelpFamilyKey((string) $actionName)] = true;
	}

	return count($familyIndex);
}

function findHelpFamilyLine(array $familyLines, string $familyKey): string {
	$prefix = '- ' . $familyKey . ': ';
	foreach ($familyLines as $familyLine) {
		if (strpos((string) $familyLine, $prefix) === 0) {
			return (string) $familyLine;
		}
	}

	return '';
}

return array(
	'server.link.set rejects non-privileged callers' => function () {
		$settingManager = new FakeSettingManager();
		$maniaControl = new FakeManiaControl($settingManager);
		$harness = new AdminLinkAuthHarness($maniaControl);
		$player = new Player('operator', AuthenticationManager::AUTH_LEVEL_ADMIN);

		$harness->runCommand(
			array(1 => array(2 => '//pcadmin server.link.set base_url=https://pixel-control.local link_token=tok-secret')),
			$player,
		);

		Assert::same(1, $maniaControl->getAuthenticationManager()->notAllowedCount);
		Assert::count(0, $settingManager->writes);
	},

	'pcadmin help renders compact command-family lines' => function () {
		$settingManager = new FakeSettingManager();
		$maniaControl = new FakeManiaControl($settingManager);
		$harness = new AdminLinkAuthHarness($maniaControl);
		$player = new Player('operator', AuthenticationManager::AUTH_LEVEL_ADMIN);

		$harness->runCommand(array(1 => array(2 => '//pcadmin help')), $player);

		$infoMessages = infoChatMessages($maniaControl);
		$actionCount = count(array_keys(AdminActionCatalog::getActionDefinitions()));
		Assert::inArray('Pixel delegated admin actions (' . $actionCount . ').', $infoMessages);
		Assert::inArray('Usage: //pcadmin <action> key=value ...', $infoMessages);
		Assert::inArray(
			'Server link commands (super/master admin only): server.link.set base_url=<url> link_token=<token>, server.link.status',
			$infoMessages,
		);

		$familyLines = array();
		foreach ($infoMessages as $message) {
			if (strpos($message, '- ') === 0) {
				$familyLines[] = $message;
			}
		}

		Assert::same(expectedAdminHelpFamilyCount(), count($familyLines));

		$actionNames = array_keys(AdminActionCatalog::getActionDefinitions());
		foreach ($familyLines as $familyLine) {
			Assert::true(strpos($familyLine, ': ') !== false);
			foreach ($actionNames as $actionName) {
				Assert::false(strpos($familyLine, '- ' . $actionName) === 0);
			}
		}

		$teamRosterLine = findHelpFamilyLine($familyLines, 'team.roster');
		Assert::notSame('', $teamRosterLine);
		Assert::true(strpos($teamRosterLine, ' | ') !== false);
		Assert::true(strpos($teamRosterLine, 'assign(') !== false);
		Assert::false(strpos($teamRosterLine, 'team.roster.assign') !== false);

		$matchBoLine = findHelpFamilyLine($familyLines, 'match.bo');
		Assert::notSame('', $matchBoLine);
		Assert::true(strpos($matchBoLine, 'set(req:best_of)') !== false);
		Assert::false(strpos($matchBoLine, 'match.bo.set') !== false);

		$hasRequiredHints = false;
		$hasOptionalHints = false;
		foreach ($familyLines as $familyLine) {
			if (strpos($familyLine, '(req:') !== false) {
				$hasRequiredHints = true;
			}
			if (strpos($familyLine, ';opt:') !== false) {
				$hasOptionalHints = true;
			}
		}

		Assert::true($hasRequiredHints);
		Assert::true($hasOptionalHints);
	},

	'server.link.set persists settings and masks token in response' => function () {
		$settingManager = new FakeSettingManager();
		$maniaControl = new FakeManiaControl($settingManager);
		$harness = new AdminLinkAuthHarness($maniaControl);
		$harness->apiClient = new FakeLinkApiClient();
		$player = new Player('super-admin', AuthenticationManager::AUTH_LEVEL_SUPERADMIN);

		$harness->runCommand(
			array(1 => array(2 => '//pcadmin server.link.set base_url=https://pixel-control.local link_token=tok-secret')),
			$player,
		);

		$message = latestChatMessage($maniaControl);
		Assert::same('success', $message['type']);
		Assert::true(strpos($message['message'], 'token_fingerprint=') !== false);
		Assert::false(strpos($message['message'], 'tok-secret') !== false);

		Assert::same(
			'https://pixel-control.local',
			$settingManager->values[AdminLinkAuthHarness::SETTING_LINK_SERVER_URL],
		);
		Assert::same('tok-secret', $settingManager->values[AdminLinkAuthHarness::SETTING_LINK_TOKEN]);

		Assert::same('https://pixel-control.local', $harness->apiClient->baseUrl);
		Assert::same('bearer', $harness->apiClient->authMode);
		Assert::same('tok-secret', $harness->apiClient->authValue);
	},

	'server.link.status reports linked state with masked fingerprint only' => function () {
		$settingManager = new FakeSettingManager(array(
			AdminLinkAuthHarness::SETTING_LINK_SERVER_URL => 'https://pixel-control.local',
			AdminLinkAuthHarness::SETTING_LINK_TOKEN => 'tok-secret',
		));
		$maniaControl = new FakeManiaControl($settingManager);
		$harness = new AdminLinkAuthHarness($maniaControl);
		$player = new Player('master-admin', AuthenticationManager::AUTH_LEVEL_MASTERADMIN);

		$harness->runCommand(array(1 => array(2 => '//pcadmin server.link.status')), $player);

		$message = latestChatMessage($maniaControl);
		Assert::same('success', $message['type']);
		Assert::true(strpos($message['message'], 'Server link status: linked') !== false);
		Assert::true(strpos($message['message'], 'token_fingerprint=') !== false);
		Assert::false(strpos($message['message'], 'tok-secret') !== false);
	},

	'communication execute rejects missing link auth payload fields' => function () {
		$settingManager = new FakeSettingManager(array(
			AdminLinkAuthHarness::SETTING_LINK_SERVER_URL => 'https://pixel-control.local',
			AdminLinkAuthHarness::SETTING_LINK_TOKEN => 'tok-secret',
		));
		$maniaControl = new FakeManiaControl($settingManager, null, null, null, new FakeServer('server-alpha'));
		$harness = new AdminLinkAuthHarness($maniaControl);

		$answer = $harness->runCommunicationExecute(array(
			'action' => 'map.skip',
			'parameters' => array(),
		));

		Assert::true($answer->error);
		Assert::same('link_auth_missing', $answer->data['code']);
	},

	'communication execute rejects server-login mismatch before token validation' => function () {
		$settingManager = new FakeSettingManager(array(
			AdminLinkAuthHarness::SETTING_LINK_SERVER_URL => 'https://pixel-control.local',
			AdminLinkAuthHarness::SETTING_LINK_TOKEN => 'tok-secret',
		));
		$maniaControl = new FakeManiaControl($settingManager, null, null, null, new FakeServer('server-alpha'));
		$harness = new AdminLinkAuthHarness($maniaControl);

		$answer = $harness->runCommunicationExecute(array(
			'action' => 'map.skip',
			'server_login' => 'server-bravo',
			'auth' => array(
				'mode' => 'link_bearer',
				'token' => 'tok-secret',
			),
		));

		Assert::true($answer->error);
		Assert::same('link_server_mismatch', $answer->data['code']);
	},

	'communication execute rejects invalid bearer token' => function () {
		$settingManager = new FakeSettingManager(array(
			AdminLinkAuthHarness::SETTING_LINK_SERVER_URL => 'https://pixel-control.local',
			AdminLinkAuthHarness::SETTING_LINK_TOKEN => 'tok-secret',
		));
		$maniaControl = new FakeManiaControl($settingManager, null, null, null, new FakeServer('server-alpha'));
		$harness = new AdminLinkAuthHarness($maniaControl);

		$answer = $harness->runCommunicationExecute(array(
			'action' => 'map.skip',
			'server_login' => 'server-alpha',
			'auth' => array(
				'mode' => 'link_bearer',
				'token' => 'invalid-token',
			),
		));

		Assert::true($answer->error);
		Assert::same('link_auth_invalid', $answer->data['code']);
	},

	'communication execute accepts valid link auth and forwards hardened options' => function () {
		$settingManager = new FakeSettingManager(array(
			AdminLinkAuthHarness::SETTING_LINK_SERVER_URL => 'https://pixel-control.local',
			AdminLinkAuthHarness::SETTING_LINK_TOKEN => 'tok-secret',
		));
		$maniaControl = new FakeManiaControl($settingManager, null, null, null, new FakeServer('server-alpha'));
		$harness = new AdminLinkAuthHarness($maniaControl);

		$answer = $harness->runCommunicationExecute(array(
			'action' => 'map.skip',
			'parameters' => array(),
			'server_login' => 'server-alpha',
			'auth' => array(
				'mode' => 'link_bearer',
				'token' => 'tok-secret',
			),
		));

		Assert::false($answer->error);
		Assert::true($answer->data['success']);
		Assert::count(1, $harness->executeCalls);
		Assert::same('link_bearer', $harness->executeCalls[0]['request_options']['security_mode']);
		Assert::true($harness->executeCalls[0]['request_options']['allow_actorless']);
		Assert::true($harness->executeCalls[0]['request_options']['skip_permission_checks']);
	},

	'communication list requires auth and exposes linked-security metadata on success' => function () {
		$settingManager = new FakeSettingManager(array(
			AdminLinkAuthHarness::SETTING_LINK_SERVER_URL => 'https://pixel-control.local',
			AdminLinkAuthHarness::SETTING_LINK_TOKEN => 'tok-secret',
		));
		$maniaControl = new FakeManiaControl($settingManager, null, null, null, new FakeServer('server-alpha'));
		$harness = new AdminLinkAuthHarness($maniaControl);

		$missingAnswer = $harness->runCommunicationList(array());
		Assert::true($missingAnswer->error);
		Assert::same('link_auth_missing', $missingAnswer->data['code']);

		$validAnswer = $harness->runCommunicationList(array(
			'server_login' => 'server-alpha',
			'auth' => array(
				'mode' => 'link_bearer',
				'token' => 'tok-secret',
			),
		));

		Assert::false($validAnswer->error);
		Assert::same('link_bearer', $validAnswer->data['security']['communication']['authentication_mode']);
		Assert::same(true, $validAnswer->data['link']['linked']);
		Assert::inArray('server_login', $validAnswer->data['security']['communication']['required_fields']);
		Assert::inArray('auth.mode', $validAnswer->data['security']['communication']['required_fields']);
		Assert::inArray('auth.token', $validAnswer->data['security']['communication']['required_fields']);
	},
);
