<?php

namespace App\Services\Partner;

use RuntimeException;

class PartnerWebhookUrlGuard
{
    /**
     * @return list<string>
     */
    public function assertAllowed(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Webhook URL is invalid');
        }

        $scheme = mb_strtolower($parts['scheme']);
        $allowedSchemes = config('partners.webhook_allow_http', false) ? ['http', 'https'] : ['https'];
        if (! in_array($scheme, $allowedSchemes, true)) {
            throw new RuntimeException('Webhook URL must use HTTPS');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('Webhook URL must not contain credentials or a fragment');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (! in_array($port, config('partners.webhook_allowed_ports', [443]), true)) {
            throw new RuntimeException('Webhook URL port is not allowed');
        }

        $host = mb_strtolower(rtrim(trim($parts['host'], '[]'), '.'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new RuntimeException('Webhook URL host is not allowed');
        }

        $addresses = $this->resolveAddresses($host);
        if ($addresses === []) {
            throw new RuntimeException('Webhook URL host could not be resolved');
        }

        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || $this->isExplicitlyBlockedAddress($address)) {
                throw new RuntimeException('Webhook URL resolves to a private or reserved address');
            }
        }

        return $addresses;
    }

    /**
     * @return list<string>
     */
    public function curlResolveEntries(string $url): array
    {
        $addresses = $this->assertAllowed($url);
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

        return array_map(
            fn (string $address): string => sprintf('%s:%d:%s', $host, $port, str_contains($address, ':') ? "[{$address}]" : $address),
            $addresses
        );
    }

    /**
     * @return list<string>
     */
    private function resolveAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
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

        return array_values(array_unique($addresses));
    }

    private function isExplicitlyBlockedAddress(string $address): bool
    {
        $ranges = str_contains($address, ':')
            ? ['::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32']
            : ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];

        foreach ($ranges as $range) {
            [$network, $prefixLength] = explode('/', $range, 2);
            $packedAddress = inet_pton($address);
            $packedNetwork = inet_pton($network);
            if ($packedAddress === false || $packedNetwork === false || strlen($packedAddress) !== strlen($packedNetwork)) {
                continue;
            }

            $wholeBytes = intdiv((int) $prefixLength, 8);
            $remainingBits = (int) $prefixLength % 8;
            if (substr($packedAddress, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
                continue;
            }
            if ($remainingBits === 0) {
                return true;
            }

            $mask = (0xff << (8 - $remainingBits)) & 0xff;
            if ((ord($packedAddress[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
