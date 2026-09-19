<?php
declare(strict_types=1);
namespace ScoutWeb;

final class Api
{
    public function __construct(private readonly JobStore $store, private readonly ScanEngine $engine, private readonly string $origin) {}

    /** Returns [HTTP status, response body]. Kept independent of PHP globals for request-level tests. */
    public function handle(string $method, string $action, array $headers, string $body, string $ip): array
    {
        try {
            if ($method === 'GET' && $action === 'status') return [200, ['ready' => true, 'version' => '0.1.0', 'limits' => ['urls' => Limits::URLS, 'sitemaps' => Limits::SITEMAPS, 'perHour' => Limits::IP_HOURLY]]];
            if ($method !== 'POST') throw new ApiError('Use a POST request for scans.', 405);
            if (($headers['origin'] ?? '') !== $this->origin) throw new ApiError('This request must come from the checker website.', 403);
            if (strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])) !== 'application/json') throw new ApiError('Expected a JSON request.', 415);
            if (strlen($body) > 4096) throw new ApiError('The request is too large.', 413);
            try { $input = json_decode($body, true, flags: JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new ApiError('The request contains invalid JSON.'); }
            if (!is_array($input) || array_is_list($input)) throw new ApiError('Expected a JSON object.');
            if ($action === 'create') {
                if (!is_string($input['sitemap'] ?? null)) throw new ApiError('Enter a sitemap URL.');
                try { $state = $this->engine->create(trim($input['sitemap'])); } catch (\RuntimeException | \InvalidArgumentException $error) { throw new ApiError($error->getMessage(), 422); }
                return [201, $this->store->create($state, $ip)];
            }
            if (!in_array($action, ['step','cancel'], true)) throw new ApiError('Unknown request.', 404);
            if (!is_string($input['id'] ?? null) || !is_string($headers['x-scan-token'] ?? null)) throw new ApiError('This scan is unavailable.', 404);
            $result = $this->store->update($input['id'], $headers['x-scan-token'], function (array $state) use ($action): array {
                if ($action === 'cancel') {
                    $state['phase'] = 'cancelled'; $state['complete'] = false; $state['finishedAt'] = gmdate('c');
                    return $state;
                }
                return $this->engine->step($state);
            });
            return [200, ['scan' => $result]];
        } catch (ApiError $error) { return [$error->status, ['error' => $error->getMessage()]]; }
        catch (\Throwable) { return [503, ['error' => 'The scan could not be completed. Please try again later.']]; }
    }
}
