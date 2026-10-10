<?php

namespace HytaleHosting\Core\Services;

use HytaleHosting\Core\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Optional source address checks for broker requests.
 */
class NetworkGuard
{
    public function __construct(private Settings $settings)
    {
    }

    public function allows(?string $ip, Server $server): bool
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $networks = $this->allowedNetworks();
        $inAllowedNetwork = $networks !== [] && IpUtils::checkIp($ip, $networks);

        if ($this->settings->get('bind_node_ip')) {
            return $inAllowedNetwork || in_array($ip, $this->nodeAddresses($server), true);
        }

        return $networks === [] || $inAllowedNetwork;
    }

    /**
     * @return list<string>
     */
    public function allowedNetworks(): array
    {
        $raw = (string) $this->settings->get('allowed_cidrs');

        return array_values(array_filter(array_map('trim', explode(',', $raw)), function (string $entry) {
            if ($entry === '') {
                return false;
            }
            [$address] = explode('/', $entry, 2);

            return filter_var($address, FILTER_VALIDATE_IP) !== false;
        }));
    }

    /**
     * @return list<string>
     */
    private function nodeAddresses(Server $server): array
    {
        $fqdn = (string) $server->node?->fqdn;
        if ($fqdn === '') {
            return [];
        }

        return Cache::remember('hytale-hosting:node-ips:' . sha1($fqdn), 300, function () use ($fqdn) {
            if (filter_var($fqdn, FILTER_VALIDATE_IP) !== false) {
                return [$fqdn];
            }

            $addresses = [];
            foreach (@dns_get_record($fqdn, DNS_A | DNS_AAAA) ?: [] as $record) {
                $addresses[] = $record['ip'] ?? $record['ipv6'] ?? null;
            }

            return array_values(array_filter($addresses));
        });
    }
}
