<?php

declare(strict_types=1);

use Arix\Testing\AssertionFailed;
use Arix\Testing\TestCase;

require dirname(__DIR__) . '/app/bootstrap.php';
ob_start();

$passed = 0;
$failed = 0;

$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);
foreach ($files as $file) {
    require_once $file;
}

$classes = array_values(array_filter(
    get_declared_classes(),
    static fn (string $class): bool => is_subclass_of($class, TestCase::class) && !(new ReflectionClass($class))->isAbstract(),
));
sort($classes);

foreach ($classes as $class) {
    $reflection = new ReflectionClass($class);
    $methods = array_values(array_filter(
        $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => str_starts_with($method->name, 'test')
            && !$method->isStatic()
            && $method->getNumberOfRequiredParameters() === 0,
    ));
    $setUp = new ReflectionMethod($class, 'setUp');
    $setUp->setAccessible(true);
    $tearDown = new ReflectionMethod($class, 'tearDown');
    $tearDown->setAccessible(true);
    foreach ($methods as $method) {
        $name = $reflection->getShortName() . '::' . $method->name;
        $test = $reflection->newInstance();
        try {
            try {
                $setUp->invoke($test);
                $test->{$method->name}();
            } finally {
                $tearDown->invoke($test);
            }
            $passed++;
            echo "PASS {$name}\n";
        } catch (AssertionFailed $exception) {
            $failed++;
            echo "FAIL {$name}: {$exception->getMessage()}\n";
        } catch (Throwable $exception) {
            $failed++;
            $type = $exception::class;
            echo "FAIL {$name}: {$type}: {$exception->getMessage()}\n";
        }
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
ob_end_flush();
exit($failed === 0 ? 0 : 1);
