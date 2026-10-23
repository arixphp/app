<?php

declare(strict_types=1);

namespace Arix\Routing;

use Arix\Http\QueryPathValidator;
use Arix\HttpFoundation\Request;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

use function array_key_exists;
use function in_array;
use function is_string;

class ApplicationUrl
{
    private function __construct(
        public readonly string $origin,
        public readonly string $basePath,
        public readonly string $url,
    ) {}

    public static function fromConfiguration(#[SensitiveParameter] array $app): self
    {
        $url = array_key_exists('url', $app) ? $app['url'] : 'http://localhost';

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException('Invalid application URL.');
        }

        return self::fromString($url);
    }

    public static function fromString(#[SensitiveParameter] string $url): self
    {
        try {
            $parts = parse_url($url);

            if ($parts === false || ! filter_var($url, FILTER_VALIDATE_URL)
                || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                throw new InvalidArgumentException();
            }

            $path = $parts['path'] ?? '/';
            $decoded = QueryPathValidator::decode($path);
            $basePath = rtrim($decoded, '/');
            $origin = $parts['scheme'] . '://' . strtolower($parts['host']);

            if (isset($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }

            UrlPath::validateMount($basePath);
            $encodedPath = rtrim(UrlPath::encode($basePath), '/');

            return new self($origin, $basePath, "{$origin}{$encodedPath}");
        } catch (Throwable) {
            // Do not preserve a previous exception containing configuration values.
            throw new InvalidArgumentException('Invalid application URL.');
        }
    }

    public static function fromParts(#[SensitiveParameter] string $origin, #[SensitiveParameter] string $basePath): self
    {
        try {
            $root = self::fromString($origin);

            if (parse_url($origin, PHP_URL_PATH) !== null) {
                throw new InvalidArgumentException();
            }

            UrlPath::validateMount($basePath);
            $encodedPath = rtrim(UrlPath::encode($basePath), '/');

            return new self($root->origin, $basePath, "{$root->origin}{$encodedPath}");
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid application URL.');
        }
    }

    public function request(): Request
    {
        $request = Request::create("{$this->url}/");
        $request->server->set('SCRIPT_NAME', "{$this->basePath}/index.php");
        $request->server->set('SCRIPT_FILENAME', DIRECTORY_SEPARATOR . 'index.php');

        return $request;
    }
}
