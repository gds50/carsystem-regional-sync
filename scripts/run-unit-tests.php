<?php

declare(strict_types=1);

final class CRS_Unit_Test_Runner
{
    /** @var int */
    private $passed = 0;

    /** @var int */
    private $failed = 0;

    public function assertSame($expected, $actual, string $message): void
    {
        if ($expected === $actual) {
            $this->passed++;
            return;
        }

        $this->failed++;
        fwrite(STDERR, "[FAIL] {$message}\n");
        fwrite(STDERR, "  expected: " . var_export($expected, true) . "\n");
        fwrite(STDERR, "  actual:   " . var_export($actual, true) . "\n");
    }

    public function assertTrue($actual, string $message): void
    {
        $this->assertSame(true, (bool) $actual, $message);
    }

    public function assertFalse($actual, string $message): void
    {
        $this->assertSame(false, (bool) $actual, $message);
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    public function summary(): string
    {
        return sprintf("Passed: %d, Failed: %d", $this->passed, $this->failed);
    }
}

require_once __DIR__ . '/../tests/unit/bootstrap.php';
require_once __DIR__ . '/../tests/unit/settings-dictionary-test.php';

$runner = new CRS_Unit_Test_Runner();
crs_run_settings_dictionary_tests($runner);

if ($runner->hasFailures()) {
    fwrite(STDERR, $runner->summary() . "\n");
    exit(1);
}

fwrite(STDOUT, $runner->summary() . "\n");
exit(0);
