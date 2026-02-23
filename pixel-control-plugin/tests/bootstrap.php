<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');

if (!defined('PIXEL_CONTROL_PLUGIN_ROOT')) {
	define('PIXEL_CONTROL_PLUGIN_ROOT', dirname(__DIR__));
}

spl_autoload_register(function ($className) {
	$prefix = 'PixelControl\\';
	if (strpos((string) $className, $prefix) !== 0) {
		return;
	}

	$relativeClassName = substr((string) $className, strlen($prefix));
	$relativePath = str_replace('\\', '/', $relativeClassName) . '.php';
	$filePath = PIXEL_CONTROL_PLUGIN_ROOT . '/src/' . $relativePath;

	if (is_file($filePath)) {
		require_once $filePath;
	}
});

require_once __DIR__ . '/Support/ManiaControlStubs.php';
require_once __DIR__ . '/Support/Assert.php';
require_once __DIR__ . '/Support/Fakes.php';
require_once __DIR__ . '/Support/Harnesses.php';
