<?php
declare(strict_types=1);

use PixelControl\Admin\AdminActionCatalog;
use PixelControl\Tests\Support\AdminVetoNormalizationHarness;
use PixelControl\Tests\Support\Assert;

return array(
	'harness bootstrap parses admin and veto commands' => function () {
		$harness = new AdminVetoNormalizationHarness();

		$adminRequest = $harness->parseAdminCommandRequest(array(
			1 => array(2 => '//pcadmin map.skip'),
		));
		Assert::same(AdminActionCatalog::ACTION_MAP_SKIP, $adminRequest['action_name']);
		Assert::arrayHasKey('parameters', $adminRequest);

		$vetoRequest = $harness->parseVetoCommandRequest(array(
			1 => array(2 => '//pcveto status'),
		));
		Assert::same('status', $vetoRequest['operation']);
		Assert::arrayHasKey('parameters', $vetoRequest);
	},
);
