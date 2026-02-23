#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

php -r '
$pluginRoot = $argv[1];
$targets = array($pluginRoot . "/src", $pluginRoot . "/tests");
$phpFiles = array();

foreach ($targets as $target) {
	if (!is_dir($target)) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target));
	foreach ($iterator as $entry) {
		if (!$entry->isFile()) {
			continue;
		}

		$filePath = $entry->getPathname();
		if (substr($filePath, -4) !== ".php") {
			continue;
		}

		$phpFiles[] = $filePath;
	}
}

sort($phpFiles);
if (empty($phpFiles)) {
	fwrite(STDERR, "No PHP files found for lint.\n");
	exit(1);
}

foreach ($phpFiles as $phpFile) {
	$lintCommand = "php -l " . escapeshellarg($phpFile) . " >/dev/null";
	passthru($lintCommand, $exitCode);
	if ($exitCode !== 0) {
		fwrite(STDERR, "Lint failed: " . $phpFile . "\n");
		exit($exitCode);
	}
}

fwrite(STDOUT, "Lint OK for " . count($phpFiles) . " files.\n");
' "$PLUGIN_ROOT"

php "$PLUGIN_ROOT/tests/run.php" "$@"
