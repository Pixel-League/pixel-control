<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$filter = '';
foreach ($argv as $argument) {
	if (strpos((string) $argument, '--filter=') === 0) {
		$filter = trim(substr((string) $argument, strlen('--filter=')));
	}
}

$caseFiles = glob(__DIR__ . '/cases/*Test.php');
if (!is_array($caseFiles)) {
	$caseFiles = array();
}
sort($caseFiles);

$tests = array();
foreach ($caseFiles as $caseFile) {
	$caseDefinitions = require $caseFile;
	if (!is_array($caseDefinitions)) {
		throw new RuntimeException('Test case file must return an array: ' . $caseFile);
	}

	foreach ($caseDefinitions as $testName => $testCallable) {
		if (!is_callable($testCallable)) {
			throw new RuntimeException('Test definition is not callable: ' . (string) $testName);
		}

		$fullName = basename($caseFile) . ' :: ' . (string) $testName;
		if ($filter !== '' && stripos($fullName, $filter) === false) {
			continue;
		}

		$tests[$fullName] = $testCallable;
	}
}

$total = count($tests);
$passed = 0;
$failed = 0;

if ($total === 0) {
	fwrite(STDOUT, "No tests selected.\n");
	exit(0);
}

fwrite(STDOUT, 'Running ' . $total . " tests...\n");
foreach ($tests as $testName => $testCallable) {
	try {
		$testCallable();
		$passed++;
		fwrite(STDOUT, 'ok - ' . $testName . "\n");
	} catch (Throwable $throwable) {
		$failed++;
		fwrite(STDOUT, 'not ok - ' . $testName . "\n");
		fwrite(STDOUT, '  ' . get_class($throwable) . ': ' . $throwable->getMessage() . "\n");
	}
}

fwrite(STDOUT, "\n");
fwrite(STDOUT, 'Result: passed=' . $passed . ', failed=' . $failed . ', total=' . $total . "\n");

if ($failed > 0) {
	exit(1);
}

exit(0);
