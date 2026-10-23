<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Constants\HttpStatusCode;
use Arix\HttpFoundation\Request;

use function is_string;

class RequestUrlContext
{
    private function __construct(
        public readonly string $baseUrl,
        public readonly string $basePath,
        public readonly string $path,
    ) {}

    public static function fromRequest(Request $request): self
    {
        // Validate the entire URI before examining any detected mount.
        (new QueryPathValidator())->path($request);

        // A trusted proxy prefix is external: it is not present in REQUEST_URI.
        // Compute the backend mount independently without modifying proxy trust.
        $uri = explode('?', $request->getRequestUri(), 2)[0];
        $detection = $request->duplicate();

        if (! str_ends_with($uri, '/')) {
            // HttpFoundation needs the directory boundary to recognize /mount.
            $detection = $request->duplicate(server: array_replace($request->server->all(), ['REQUEST_URI' => "{$uri}/"]));
        }

        // SAPIs may expose script URL metadata encoded or decoded. HttpFoundation
        // expects decoded script metadata when matching it to the encoded URI.
        // Never normalize SCRIPT_FILENAME: that is a filesystem path, not a URL.
        foreach (['SCRIPT_NAME', 'PHP_SELF', 'ORIG_SCRIPT_NAME'] as $key) {
            $script = $detection->server->get($key);

            if (is_string($script) && str_contains($script, '%')) {
                $detection->server->set($key, QueryPathValidator::decode($script));
            }

        }

        $backend = $detection->duplicate();
        $backend->headers->remove('X-Forwarded-Prefix');
        $internalBase = self::prefix($backend->getBaseUrl());

        return new self(
            self::prefix($detection->getBaseUrl()),
            self::prefix($detection->getBasePath()),
            (new QueryPathValidator($internalBase))->path($request),
        );
    }

    private static function prefix(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }

        if (str_contains($prefix, '?') || str_contains($prefix, '#') || str_ends_with($prefix, '/')) {
            throw new HttpException(HttpStatusCode::BAD_REQUEST, 'Invalid path.');
        }

        return QueryPathValidator::decode($prefix);
    }
}
