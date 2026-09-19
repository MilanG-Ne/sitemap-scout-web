<?php
declare(strict_types=1);
namespace ScoutWeb;

use RuntimeException;

final class PublicNetwork
{
    public static function validateUrl(string $value): string
    {
        $url = Url::normalize($value);
        $parts = parse_url($url);
        $host = $parts['host'];
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if ($port !== ($parts['scheme'] === 'https' ? 443 : 80) || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]*\z/i', $host)) {
            throw new RuntimeException('Use a public website hostname on the standard HTTP or HTTPS port.');
        }
        foreach (['.localhost', '.local', '.internal', '.test', '.invalid', '.example', '.onion'] as $suffix) {
            if (str_ends_with($host, $suffix)) throw new RuntimeException('This hostname is not a public website.');
        }
        return $url;
    }

    public static function isPublicIp(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $range) {
                if (self::inRange($address, $range)) return false;
            }
            return true;
        }
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) || !self::inRange($address, '2000::/3')) return false;
        foreach (['2001::/23','2001:db8::/32','2002::/16','3fff::/20'] as $range) {
            if (self::inRange($address, $range)) return false;
        }
        return true;
    }

    private static function inRange(string $address, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $ip = inet_pton($address);
        $net = inet_pton($network);
        if ($ip === false || $net === false || strlen($ip) !== strlen($net)) return false;
        $bytes = intdiv((int) $bits, 8);
        $remaining = (int) $bits % 8;
        return substr($ip, 0, $bytes) === substr($net, 0, $bytes)
            && ($remaining === 0 || (ord($ip[$bytes]) & (255 << (8 - $remaining))) === (ord($net[$bytes]) & (255 << (8 - $remaining))));
    }

    /** Resolve first, then pin this exact public address in cURL; never resolve again during the connection. */
    public static function resolve(string $host): string
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) throw new RuntimeException('The website hostname could not be resolved.');
        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($address !== null) {
                if (!self::isPublicIp($address)) throw new RuntimeException('This hostname resolves to a restricted network address.');
                $addresses[] = $address;
            }
        }
        if ($addresses === []) throw new RuntimeException('No public address was found for this hostname.');
        usort($addresses, static fn (string $a, string $b): int => strlen(inet_pton($a)) <=> strlen(inet_pton($b)));
        return $addresses[0];
    }
}
