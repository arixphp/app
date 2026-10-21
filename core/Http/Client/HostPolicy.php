<?php

declare(strict_types=1);

namespace Arix\Http\Client;

use InvalidArgumentException;

use function array_values;
use function dns_get_record;
use function filter_var;
use function gethostbynamel;
use function hexdec;
use function in_array;
use function inet_ntop;
use function inet_pton;
use function ip2long;
use function is_array;
use function is_int;
use function is_string;
use function str_repeat;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

use const DNS_A;
use const DNS_AAAA;
use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_IPV6;
use const FILTER_VALIDATE_IP;

/**
 * The hosts an HTTP request may reach.
 *
 * A reserved address belongs to the deployment instead of to a remote
 * service, so a request to one reaches an internal target the visitor may
 * not know about. Those addresses are refused unless the application allows
 * the host explicitly.
 */
class HostPolicy
{
    /** @var list<string> */
    private array $hosts;

    /** @param list<string> $hosts */
    public function __construct(array $hosts = [])
    {
        $this->hosts = array_values($hosts);
    }

    /**
     * The addresses a request may use, and the host it must be pinned to.
     *
     * @return array{host: string, port: int, addresses: list<string>}
     */
    public function resolve(string $url): array
    {
        $host = self::host($url);
        $port = self::port($url);

        if ($this->hosts !== []) {
            if (! in_array($host, $this->hosts, true)) {
                throw new InvalidArgumentException("The host '{$host}' is not allowed.");
            }

            return ['host' => $host, 'port' => $port, 'addresses' => self::addresses($host)];
        }

        $addresses = self::addresses($host);

        foreach ($addresses as $address) {
            if (self::isReserved($address)) {
                throw new InvalidArgumentException("The host '{$host}' resolves to the reserved address {$address}.");
            }
        }

        if ($addresses === []) {
            throw new InvalidArgumentException("The host '{$host}' could not be resolved.");
        }

        return ['host' => $host, 'port' => $port, 'addresses' => $addresses];
    }

    public static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('The request URL must contain a host.');
        }

        return strtolower(trim($host, '[]'));
    }

    public static function port(string $url): int
    {
        $port = parse_url($url, PHP_URL_PORT);

        if (is_int($port)) {
            return $port;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' ? 443 : 80;
    }

    /** @return list<string> */
    private static function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        if ($addresses !== []) {
            return $addresses;
        }

        $resolved = @gethostbynamel($host);

        return is_array($resolved) ? array_values($resolved) : [];
    }

    private static function isReserved(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return self::isReservedIpv4($address);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return self::isReservedIpv6($address);
        }

        return true;
    }

    private static function isReservedIpv4(string $address): bool
    {
        $value = ip2long($address);

        if ($value === false) {
            return true;
        }

        $value = (int) $value;

        foreach ([
            ['0.0.0.0', 8],
            ['10.0.0.0', 8],
            ['100.64.0.0', 10],
            ['127.0.0.0', 8],
            ['169.254.0.0', 16],
            ['172.16.0.0', 12],
            ['192.0.0.0', 24],
            ['192.0.2.0', 24],
            ['192.168.0.0', 16],
            ['198.18.0.0', 15],
            ['198.51.100.0', 24],
            ['203.0.113.0', 24],
            ['224.0.0.0', 4],
            ['240.0.0.0', 4],
        ] as [$network, $bits]) {
            $mask = -1 << (32 - $bits);
            $base = ip2long($network);

            if ($base === false) {
                continue;
            }

            if (($value & $mask) === ((int) $base & $mask)) {
                return true;
            }
        }

        return false;
    }

    private static function isReservedIpv6(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        $hex = bin2hex($packed);

        // An IPv4 mapped address carries the IPv4 rules.
        if (str_starts_with($hex, '00000000000000000000ffff')) {
            return self::isReservedIpv4((string) inet_ntop((string) substr($packed, 12)));
        }

        // ::/128 and ::1/128.
        if ($hex === str_repeat('0', 32) || $hex === str_repeat('0', 31) . '1') {
            return true;
        }

        $first = substr($hex, 0, 2);
        $second = hexdec(substr($hex, 2, 1));

        // fc00::/7, fe80::/10 and ff00::/8.
        return in_array($first, ['fc', 'fd', 'ff'], true) || ($first === 'fe' && $second >= 8 && $second <= 11);
    }
}
