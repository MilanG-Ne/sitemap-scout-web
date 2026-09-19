<?php
declare(strict_types=1);
namespace ScoutWeb;

final class SafeClient implements HttpClient
{
    public function __construct(private readonly OutboundSlots $slots) {}

    public function get(string $url, int $limit): Response
    {
        $global = $destination = null;
        try {
            $url = PublicNetwork::validateUrl($url);
            $global = $this->slots->acquireGlobal();
            $address = PublicNetwork::resolve(parse_url($url, PHP_URL_HOST));
            $destination = $this->slots->acquireAddress($address);
            return (new CurlTransport())->get($url, $address, $limit);
        } catch (\RuntimeException | \InvalidArgumentException $error) {
            return new Response(0, error: $error->getMessage());
        } finally {
            OutboundSlots::release($destination);
            OutboundSlots::release($global);
        }
    }
}
