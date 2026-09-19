<?php
declare(strict_types=1);
namespace ScoutWeb;

final class ClientIdentity
{
    /** Group IPv6 privacy addresses by /64 so rotating addresses does not reset quotas. */
    public static function bucket(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") return inet_ntop(substr($packed, 12));
            return bin2hex(substr($packed, 0, 8)) . '/64';
        }
        return $ip;
    }
}
