<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

/** Destinations are approved by the server operator, resolved, then pinned for each request. */
final class EndpointPolicy
{
    public function options(string $url, bool $allowLocal = false): array
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || ! $host || ! in_array($host, config('crm.approved_hosts'), true)) {
            throw new \RuntimeException('This HTTPS integration destination is not approved by the server operator.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(array_column(dns_get_record($host, DNS_A) ?: [], 'ip'), array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
        if (! $ips) {
            throw new \RuntimeException('Integration hostname could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Private or reserved integration destinations are blocked.');
            }
        }
        $port = $parts['port'] ?? 443;
        if (! in_array($port, [443, 8443], true)) {
            throw new \RuntimeException('Integration port is not permitted.');
        }
        $ip = $ips[0];
        if (str_contains($ip, ':')) {
            $ip = '['.$ip.']';
        }

        return ['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$ip]]];
    }

    public function smtp(string $host, int $port): string
    {
        if (! in_array($host, config('crm.approved_hosts'), true) || ! in_array($port, [465, 587], true)) {
            throw new \RuntimeException('Approve the SMTP host in CRM_APPROVED_HOSTS; TLS ports 465 and 587 are supported.');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (! $ips) {
            throw new \RuntimeException('SMTP DNS lookup failed.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Private SMTP addresses are blocked.');
            }
        }

        return $ips[0];
    }
}
