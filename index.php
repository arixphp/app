<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "ArixPHP does not render pages from the PHP command line. Run 'php tests/run.php' for tests.\n");
    exit(1);
}

if (PHP_SAPI === 'cli-server') {
    http_response_code(503);

    return;
}

$debug = match (strtolower(trim((string) getenv('APP_DEBUG')))) {
    'true', '1' => true,
    default => false,
};

ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

if ($debug) {
    error_reporting(E_ALL);
}

try {
    /** @var Arix\Application $application */
    $application = require __DIR__ . '/app/bootstrap.php';

    $request = Arix\Http\Request::fromGlobals($application->maxBodyBytes());

    $application->handle($request)->send($request->method);
} catch (Throwable $exception) {
    error_log('Arix bootstrap: ' . $exception);

    $httpError = $exception instanceof Arix\Http\HttpException;
    http_response_code($httpError ? $exception->status : 500);

    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
        echo $httpError ? $exception->getMessage() : ($debug ? (string) $exception : 'Internal server error.');
    }
}
