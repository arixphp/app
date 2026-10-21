<?php

declare(strict_types=1);

namespace Arix\Http\Client;

use Arix\Constants\HttpMethod;
use CurlHandle;
use InvalidArgumentException;
use JsonException;

use function array_keys;
use function array_map;
use function array_values;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function defined;
use function explode;
use function filter_var;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function trim;

class Http
{
    private const int DEFAULT_TIMEOUT = 15;

    private const int DEFAULT_CONNECT_TIMEOUT = 5;

    private const int DEFAULT_MAX_BYTES = 8388608;

    /** @var array<string, string> */
    private array $headers = ['User-Agent' => 'Arix'];

    private int $timeout = self::DEFAULT_TIMEOUT;

    private int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT;

    private int $maxBytes = self::DEFAULT_MAX_BYTES;

    private int $redirects = 0;

    private HostPolicy $hosts;

    private string $proxy = '';

    public function __construct()
    {
        $this->hosts = new HostPolicy();
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * Restrict the hosts the client may reach.
     *
     * Without an allowlist the client refuses the addresses that belong to
     * the deployment instead of to a remote service.
     *
     * @param  list<string>  $hosts
     */
    public function withAllowedHosts(array $hosts): self
    {
        $clone = clone $this;
        $clone->hosts = new HostPolicy($hosts);

        return $clone;
    }

    /** Route the requests through a proxy instead of the environment. */
    public function withProxy(string $proxy): self
    {
        $clone = clone $this;
        $clone->proxy = $proxy;

        return $clone;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;

        foreach ($headers as $name => $value) {
            self::assertHeaderIsSafe((string) $name, (string) $value);

            $existing = self::findHeader($clone->headers, $name);

            if ($existing !== null) {
                unset($clone->headers[$existing]);
            }

            $clone->headers[$name] = $value;
        }

        return $clone;
    }

    public function withToken(string $token): self
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}"]);
    }

    public function withTimeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('Timeout must be positive.');
        }

        $clone = clone $this;
        $clone->timeout = $seconds;

        return $clone;
    }

    public function withConnectTimeout(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('Connect timeout must be positive.');
        }

        $clone = clone $this;
        $clone->connectTimeout = $seconds;

        return $clone;
    }

    public function withMaxBytes(int $bytes): self
    {
        if ($bytes < 1) {
            throw new InvalidArgumentException('The maximum response size must be positive.');
        }

        $clone = clone $this;
        $clone->maxBytes = $bytes;

        return $clone;
    }

    public function withRedirects(int $maximum): self
    {
        if ($maximum < 0 || $maximum > 10) {
            throw new InvalidArgumentException('Redirect limit must be between 0 and 10.');
        }

        $clone = clone $this;
        $clone->redirects = $maximum;

        return $clone;
    }

    /** @param array<string, mixed> $query */
    public function get(string $url, array $query = []): Response
    {
        return $this->request(HttpMethod::GET, $url, query: $query);
    }

    /** @param array<string, mixed> $data */
    public function post(string $url, array $data = []): Response
    {
        return $this->request(HttpMethod::POST, $url, json: $data);
    }

    /** @param array<string, mixed> $data */
    public function put(string $url, array $data = []): Response
    {
        return $this->request(HttpMethod::PUT, $url, json: $data);
    }

    /** @param array<string, mixed> $data */
    public function patch(string $url, array $data = []): Response
    {
        return $this->request(HttpMethod::PATCH, $url, json: $data);
    }

    public function delete(string $url): Response
    {
        return $this->request(HttpMethod::DELETE, $url);
    }

    public function head(string $url): Response
    {
        return $this->request(HttpMethod::HEAD, $url);
    }

    /** @param array<string, scalar|null> $data */
    public function asForm(string $url, array $data, HttpMethod $method = HttpMethod::POST): Response
    {
        return $this->request($method, $url, form: $data);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     * @param  array<string, scalar|null>|null  $form
     */
    public function request(HttpMethod $method, string $url, array $query = [], array|null $json = null, array|null $form = null): Response
    {
        if ($json !== null && $form !== null) {
            throw new InvalidArgumentException('Choose JSON or form data, not both.');
        }

        $url = $this->normalize($url);

        if ($query !== []) {
            if (! str_ends_with($url, '?') && ! str_ends_with($url, '&')) {
                $url .= str_contains($url, '?') ? '&' : '?';
            }

            $url .= http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = $this->headers;
        $body = null;

        if ($json !== null) {
            $body = $this->encode($json);
            $headers = $this->withContentType($headers, 'application/json');
        } elseif ($form !== null) {
            $body = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
            $headers = $this->withContentType($headers, 'application/x-www-form-urlencoded');
        }

        if (! $this->curlAvailable()) {
            throw new HttpClientException('The Arix HTTP client requires ext-curl.');
        }

        $current = $url;

        for ($hop = 0; $hop <= $this->redirects; $hop++) {
            $response = $this->send($method, $current, $headers, $body);
            $location = $response->header('location');

            if ($location === null || $hop === $this->redirects) {
                return $response;
            }

            $next = $this->redirect($current, $location);

            if (strtolower((string) parse_url($current, PHP_URL_SCHEME)) === 'https'
                && strtolower((string) parse_url($next, PHP_URL_SCHEME)) === 'http') {
                throw new HttpClientException('HTTPS redirects cannot downgrade to HTTP.');
            }

            // Credentials belong to an origin, including its scheme and port.
            if ($this->origin($next) !== $this->origin($current)) {
                $headers = self::withoutCredentials($headers);
            }

            $current = $next;
        }

        return $this->send($method, $current, $headers, $body);
    }

    /** @return array{string, string, int} */
    private function origin(string $url): array
    {

        return [strtolower((string) parse_url($url, PHP_URL_SCHEME)), HostPolicy::host($url), HostPolicy::port($url)];
    }

    /**
     * Resolve a redirect target against the URL that produced it.
     */
    private function redirect(string $current, string $location): string
    {
        $location = trim($location);

        if ($location === '') {
            throw new HttpClientException('The response contained an empty redirect location.');
        }

        if (str_starts_with($location, '/')) {
            $parts = parse_url($current);

            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                throw new HttpClientException('The redirect location cannot be resolved.');
            }

            $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

            return $this->normalize($parts['scheme'] . '://' . $authority . $location);
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $location) === 1) {
            return $this->normalize($location);
        }

        return $this->normalize($current);
    }

    /**
     * The URL of a request, without its fragment and with a supported scheme.
     */
    private function normalize(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only HTTP and HTTPS URLs are supported.');
        }

        if (str_contains($url, '#')) {
            $url = explode('#', $url, 2)[0];
        }

        return $url;
    }

    /**
     * The headers a redirect to another origin may receive.
     *
     * A redirect to another origin is a different service: the credentials of
     * the first one must not travel with it.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function withoutCredentials(array $headers): array
    {
        $safe = [];

        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), ['user-agent', 'content-type'], true)) {
                $safe[$name] = $value;
            }
        }

        return $safe;
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function send(HttpMethod $method, string $url, array $headers, string|null $body): Response
    {
        $target = $this->hosts->resolve($url);
        $handle = curl_init();

        if ($handle === false) {
            throw new HttpClientException('Unable to initialize cURL.');
        }

        $responseHeaders = [];
        $responseBody = '';
        $exceeded = false;

        try {
            $options = [
                CURLOPT_URL => $url,
                CURLOPT_CUSTOMREQUEST => $method->value,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min($this->connectTimeout, $this->timeout),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array_map(
                    static fn (string $name, string $value): string => "{$name}: {$value}",
                    array_keys($headers),
                    array_values($headers)
                ),
                CURLOPT_WRITEFUNCTION => function (CurlHandle $curl, string $chunk) use (&$responseBody, &$exceeded): int {
                    $responseBody .= $chunk;

                    if (strlen($responseBody) > $this->maxBytes) {
                        $exceeded = true;

                        return 0;
                    }

                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function (CurlHandle $curl, string $line) use (&$responseHeaders): int {
                    $length = strlen($line);

                    if (str_starts_with($line, 'HTTP/')) {
                        $responseHeaders = [];
                    }

                    $position = strpos($line, ':');

                    if ($position !== false) {
                        $name = strtolower(trim(substr($line, 0, $position)));
                        $responseHeaders[$name][] = trim(substr($line, $position + 1));
                    }

                    return $length;
                },
            ];

            foreach ($this->protocolRestrictions() as $option => $value) {
                $options[$option] = $value;
            }

            // The request is pinned to the addresses the policy accepted, so a
            // second resolution cannot reach another target.
            if ($target['addresses'] !== [] && filter_var($target['host'], FILTER_VALIDATE_IP) === false) {
                $options[CURLOPT_RESOLVE] = ["{$target['host']}:{$target['port']}:" . implode(',', $target['addresses'])];
            }

            // The environment must not route the request to another proxy.
            $options[CURLOPT_PROXY] = $this->proxy;

            if ($method === HttpMethod::HEAD) {
                $options[CURLOPT_NOBODY] = true;
            }

            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }

            if (! curl_setopt_array($handle, $options)) {
                throw new HttpClientException('Unable to configure cURL.');
            }

            $result = curl_exec($handle);

            if ($result === false) {
                if ($exceeded) {
                    throw new HttpClientException("The response exceeded the maximum size of {$this->maxBytes} bytes.", curl_errno($handle));
                }

                $error = curl_error($handle);

                throw new HttpClientException("Network error: {$error}", curl_errno($handle));
            }

            return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseHeaders, $responseBody);
        } finally {
            unset($handle);
        }
    }

    protected function curlAvailable(): bool
    {
        return function_exists('curl_init');
    }

    /**
     * @return array<int, int|string>
     */
    protected function protocolRestrictions(): array
    {
        if ($this->supportsProtocolStrings()) {
            return [
                CURLOPT_PROTOCOLS_STR => 'http,https',
                CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            ];
        }

        return [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
    }

    protected function supportsProtocolStrings(): bool
    {
        return defined('CURLOPT_PROTOCOLS_STR');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HttpClientException('The request payload could not be encoded as JSON.', previous: $exception);
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function withContentType(array $headers, string $contentType): array
    {
        if (self::findHeader($headers, 'Content-Type') === null) {
            $headers['Content-Type'] = $contentType;
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function findHeader(array $headers, string $name): string|null
    {
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return $existing;
            }
        }

        return null;
    }

    private static function assertHeaderIsSafe(string $name, string $value): void
    {
        foreach ([$name, $value] as $part) {
            if (str_contains($part, "\r") || str_contains($part, "\n")) {
                throw new InvalidArgumentException('Header names and values must not contain line breaks.');
            }
        }
    }
}
