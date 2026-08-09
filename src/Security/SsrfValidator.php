<?php

namespace MohamedSamy902\LaravelMediaVault\Security;

use MohamedSamy902\LaravelMediaVault\Contracts\SsrfValidatorContract;
use MohamedSamy902\LaravelMediaVault\Exceptions\SsrfException;

/**
 * Validates remote URLs against SSRF (Server-Side Request Forgery) attack vectors.
 *
 * Checks:
 *  1. Only HTTP/HTTPS schemes are allowed
 *  2. Hostname resolves via DNS
 *  3. Resolved IP is not in any private/reserved range (IPv4 + IPv6)
 *  4. Optional domain allowlist enforcement
 */
final class SsrfValidator implements SsrfValidatorContract
{
    /**
     * CIDR blocks that are always blocked.
     * Each entry: [network_address, prefix_length]
     *
     * @var array<int,array{0:string,1:int}>
     */
    private const BLOCKED_CIDRS_V4 = [
        ['0.0.0.0',     8],   // "this" network (RFC 1122)
        ['10.0.0.0',    8],   // Private (RFC 1918)
        ['100.64.0.0',  10],  // Shared address space (RFC 6598)
        ['127.0.0.0',   8],   // Loopback (RFC 1122)
        ['169.254.0.0', 16],  // Link-local (RFC 3927)
        ['172.16.0.0',  12],  // Private (RFC 1918)
        ['192.0.0.0',   24],  // IETF Protocol Assignments (RFC 6890)
        ['192.168.0.0', 16],  // Private (RFC 1918)
        ['198.18.0.0',  15],  // Benchmarking (RFC 2544)
        ['198.51.100.0',24],  // Documentation (RFC 5737)
        ['203.0.113.0', 24],  // Documentation (RFC 5737)
        ['224.0.0.0',   4],   // Multicast (RFC 3171)
        ['240.0.0.0',   4],   // Reserved (RFC 1112)
        ['255.255.255.255', 32], // Broadcast
    ];

    /**
     * @var array<int,array{0:string,1:int}>
     */
    private const BLOCKED_CIDRS_V6 = [
        ['::1',          128], // Loopback
        ['::',           128], // Unspecified
        ['fc00::',       7],   // Unique local (ULA)
        ['fe80::',       10],  // Link-local
        ['ff00::',       8],   // Multicast
        ['2001:db8::',   32],  // Documentation (RFC 3849)
        ['100::',        64],  // Discard (RFC 6666)
    ];

    #[\Override]
    public function validate(string $url): string
    {
        $parsed = parse_url($url);

        if ($parsed === false || empty($parsed['host'])) {
            throw new SsrfException("Invalid or unparsable URL: {$url}");
        }

        // 1. Scheme check — only http and https allowed
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new SsrfException(
                "Blocked non-HTTP scheme [{$scheme}] in URL: {$url}"
            );
        }

        $host = $parsed['host'];

        // Strip IPv6 brackets if present (e.g. [::1] -> ::1)
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // 2. Domain allowlist enforcement
        $allowedDomains = config('media-vault.url_upload.allowed_domains', []);
        if (!empty($allowedDomains)) {
            $hostToCheck = $parsed['host'];
            if (!in_array($hostToCheck, $allowedDomains, true)) {
                throw new SsrfException(
                    "Domain [{$hostToCheck}] is not in the allowed domains allowlist."
                );
            }
        }

        // 3. Resolve hostname — block if it already looks like a raw IP
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->assertNotPrivateIpV4($host, $url);
            return $host;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->assertNotPrivateIpV6($host, $url);
            return $host;
        }

        // DNS resolution
        $resolved = gethostbyname($host);

        if ($resolved === $host) {
            throw new SsrfException(
                "Could not resolve hostname [{$host}] to an IP address."
            );
        }

        $this->assertNotPrivateIpV4($resolved, $url);

        // Also check AAAA records for IPv6
        $records = [];
        try {
            $result = dns_get_record($host, DNS_AAAA);
            $records = $result ?: [];
        } catch (\ErrorException $e) {
            \Illuminate\Support\Facades\Log::debug("DNS AAAA lookup failed for host [{$host}]: " . $e->getMessage());
        }
        
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ipv6'])) {
                    $this->assertNotPrivateIpV6($record['ipv6'], $url);
                }
            }
        }
        
        return $resolved;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function assertNotPrivateIpV4(string $ip, string $url): void
    {
        foreach (self::BLOCKED_CIDRS_V4 as [$cidr, $prefix]) {
            if ($this->ipV4InCidr($ip, $cidr, $prefix)) {
                throw new SsrfException(
                    "URL [{$url}] resolves to a blocked private/reserved IP [{$ip}]."
                );
            }
        }
    }

    private function assertNotPrivateIpV6(string $ip, string $url): void
    {
        foreach (self::BLOCKED_CIDRS_V6 as [$cidr, $prefix]) {
            if ($this->ipV6InCidr($ip, $cidr, $prefix)) {
                throw new SsrfException(
                    "URL [{$url}] resolves to a blocked private/reserved IPv6 [{$ip}]."
                );
            }
        }
    }

    private function ipV4InCidr(string $ip, string $cidr, int $prefix): bool
    {
        $ipLong   = ip2long($ip);
        $netLong  = ip2long($cidr);

        if ($ipLong === false || $netLong === false) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : (~0 << (32 - $prefix));

        return ($ipLong & $mask) === ($netLong & $mask);
    }

    private function ipV6InCidr(string $ip, string $cidr, int $prefix): bool
    {
        $ipBin   = inet_pton($ip);
        $cidrBin = inet_pton($cidr);

        if ($ipBin === false || $cidrBin === false) {
            return false;
        }

        $bytes  = (int) ceil($prefix / 8);
        $remain = $prefix % 8;

        // Compare full bytes
        if (substr($ipBin, 0, $bytes - ($remain > 0 ? 1 : 0))
            !== substr($cidrBin, 0, $bytes - ($remain > 0 ? 1 : 0))) {
            return false;
        }

        // Compare the partial byte if prefix is not byte-aligned
        if ($remain > 0) {
            $byteIndex = $bytes - 1;
            $mask      = 0xFF << (8 - $remain) & 0xFF;
            return (ord($ipBin[$byteIndex]) & $mask) === (ord($cidrBin[$byteIndex]) & $mask);
        }

        return true;
    }
}
