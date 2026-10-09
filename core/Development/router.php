<?php

declare(strict_types=1);

// Development only; the CLI passes the application directory as document root.
$root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
if ($root === false) {
    http_response_code(500);
    return;
}
require_once dirname(__DIR__) . '/autoload.php';
try {
    $path = Arix\Http\Request::fromGlobals()->path();
} catch (Arix\Http\HttpException $exception) {
    http_response_code($exception->status);
    return;
}
if (preg_match('~(^|/)\.|^/(?:app|core|cli|tests|docs)(?:/|$)~i', $path)) {
    http_response_code(403);
    return;
}
if (preg_match('~^/(styles|scripts|assets)(?:/|$)~i', $path)) {
    $allowed = Arix\Http\PublicAssets::allows(ltrim($path, '/'));
    $file = realpath($root . $path);
    $directory = '/' . explode('/', ltrim($path, '/'), 2)[0] . '/';
    if (!$allowed) {
        http_response_code(403);
        return;
    }
    if ($file === false || !is_file($file)) {
        http_response_code(404);
        return;
    }
    if (!str_starts_with($file, $root . $directory)) {
        http_response_code(403);
        return;
    }

    return false;
}
if ($path === '/robots.txt' && is_file($root . $path)) {
    return false;
}
require $root . '/index.php';
