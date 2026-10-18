<?php

namespace App\Core\Ai;

/**
 * Checks that a provider URL points at the public internet before the server sends a key there.
 * Private, loopback, link-local and reserved addresses (this Docker network, cloud metadata, the
 * host) are refused, so a stored provider can't be used to reach internal services.
 */
class PublicEndpoint
{
    /**
     * The IP to connect to (pinned, so DNS can't change between this check and the request),
     * or null if the URL isn't an https URL on a public address.
     */
    public function resolve(string $url): ?string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return null;
        }

        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->lookup($host);
        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }

        return $ips[0];
    }

    /**
     * @return list<string>
     */
    protected function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
    }
}
