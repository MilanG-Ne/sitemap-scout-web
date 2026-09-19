<?php
declare(strict_types=1);
namespace ScoutWeb;

final class CurlTransport
{
    /** $address must come from the public network validator, not a request parameter. */
    public function get(string $url, string $address, int $limit): Response
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $pin = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $body = '';
        $headers = [];
        $headerBytes = 0;
        $truncated = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$parts['host'] . ':' . $port . ':' . $pin],
            CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => Limits::REQUEST_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'SitemapScoutWeb/0.1 (+https://scout.ivig.dev/)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xml,text/xml,*/*;q=0.1'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers, &$headerBytes): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 32768) return 0;
                if (str_starts_with($line, 'HTTP/')) $headers = [];
                elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    if (in_array($name, ['location','content-type','x-robots-tag'], true)) $headers[$name][] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$truncated, $limit): int {
                $remaining = $limit - strlen($body);
                $body .= substr($chunk, 0, $remaining);
                if (strlen($chunk) > $remaining) { $truncated = true; return 0; }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $ms = (int) round(curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000);
        $errno = curl_errno($curl);
        $error = null;
        if ($ok === false && !$truncated) $error = $errno === CURLE_OPERATION_TIMEDOUT ? 'The request timed out.' : 'The connection could not be completed.';
        unset($curl);
        return new Response($status, $body, $headers, $ms, $truncated, $error);
    }
}
