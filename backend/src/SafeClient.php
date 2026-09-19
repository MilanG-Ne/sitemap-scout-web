<?php
declare(strict_types=1);
namespace ScoutWeb;

final class SafeClient implements HttpClient
{
    public function get(string $url, int $limit): Response
    {
        try {
            $url = PublicNetwork::validateUrl($url);
            $address = PublicNetwork::resolve(parse_url($url, PHP_URL_HOST));
            return (new CurlTransport())->get($url, $address, $limit);
        } catch (\RuntimeException | \InvalidArgumentException $error) {
            return new Response(0, error: $error->getMessage());
        }
    }
}
