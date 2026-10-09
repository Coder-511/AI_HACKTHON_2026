<?php

namespace App\Services\Ai;

class SafeEndpoint
{
    public function resolve(string $url, string $approvedHost): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== strtolower($approvedHost)
            || ! preg_match('/^[a-z0-9.-]+$/i', $approvedHost)
            || filter_var($approvedHost, FILTER_VALIDATE_IP)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \InvalidArgumentException('Only the explicitly approved HTTPS service is allowed.');
        }
        $records = dns_get_record($approvedHost, DNS_A | DNS_AAAA);
        $addresses = array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records ?: [])));
        if ($addresses === []) {
            throw new \RuntimeException('The approved endpoint could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                throw new \InvalidArgumentException('Non-public service addresses are prohibited.');
            }
        }
        $ip = $addresses[0];

        return ['url' => $url, 'host' => $approvedHost, 'ip' => $ip,
            'resolve' => $approvedHost.':443:'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)];
    }

    public function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = array_map('intval', explode('.', $ip));
            [$a, $b, $c] = $octets;
            if (($a === 100 && $b >= 64 && $b <= 127)
                || ($a === 192 && ($b === 0 || ($b === 88 && $c === 99)))
                || ($a === 198 && ($b === 18 || $b === 19 || ($b === 51 && $c === 100)))
                || ($a === 203 && $b === 0 && $c === 113)) {
                return false;
            }
        }
        if (str_contains($ip, ':')) {
            // Restrict IPv6 to global-unicast and exclude transition/mapped/documentation addresses.
            $packed = inet_pton($ip);
            if (! $packed || (ord($packed[0]) & 0xE0) !== 0x20
                || str_starts_with(strtolower($ip), '2001:db8') || str_starts_with(strtolower($ip), '2002:')
                || str_starts_with(strtolower($ip), '2001:0:')) {
                return false;
            }
        }

        return true;
    }
}
