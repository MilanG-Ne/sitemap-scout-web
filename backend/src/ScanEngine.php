<?php
declare(strict_types=1);
namespace ScoutWeb;

final class ScanEngine
{
    public function __construct(private readonly HttpClient $http) {}

    public function create(string $sitemap): array
    {
        $sitemap = PublicNetwork::validateUrl($sitemap);
        return [
            'sitemap' => $sitemap, 'origin' => Url::origin($sitemap), 'phase' => 'discovering', 'complete' => true,
            'queue' => [['url' => $sitemap, 'hops' => 0]], 'seenMaps' => [hash('sha256', $sitemap) => true],
            'pending' => [], 'seenUrls' => [], 'sitemapsRead' => 0, 'discovered' => 0, 'checked' => 0,
            'limit' => Limits::URLS, 'pages' => [], 'findings' => [],
            'startedAt' => gmdate('c'), 'finishedAt' => null,
        ];
    }

    /** At most one bounded HTTP request per step, compatible with shared-hosting timeouts. */
    public function step(array $state): array
    {
        if (!in_array($state['phase'], ['discovering','checking'], true)) return $state;
        if ($state['phase'] === 'discovering' && $state['queue'] !== []) {
            $entry = array_shift($state['queue']);
            $url = $entry['url'];
            if ($entry['hops'] === 0) $state['sitemapsRead']++;
            $response = $this->http->get($url, Limits::XML_BYTES);
            if ($response->status === 429) return $this->rateLimited($state);
            if ($response->error !== null || $response->truncated) {
                $this->add($state, 'error', 'sitemap_fetch', $url, $response->error ?? 'Sitemap exceeds the 1 MiB limit.');
                $state['complete'] = false;
            } elseif (in_array($response->status, [301,302,303,307,308], true)) {
                try {
                    $target = PublicNetwork::validateUrl(Url::resolve($url, $response->header('location') ?? ''));
                    if (Url::origin($target) !== $state['origin'] || $entry['hops'] >= 3 || isset($state['seenMaps'][hash('sha256', $target)])) throw new \RuntimeException();
                    $state['seenMaps'][hash('sha256', $target)] = true;
                    array_unshift($state['queue'], ['url' => $target, 'hops' => $entry['hops'] + 1]);
                    $this->add($state, 'warning', 'sitemap_redirect', $url, 'The sitemap redirects. Use its final URL where possible.');
                } catch (\RuntimeException | \InvalidArgumentException) {
                    $state['complete'] = false;
                    $this->add($state, 'error', 'sitemap_redirect', $url, 'The sitemap redirect is invalid, repeated, too long, or outside the starting origin.');
                }
            } elseif ($response->status < 200 || $response->status >= 300) {
                $state['complete'] = false;
                $this->add($state, 'error', 'sitemap_fetch', $url, 'Sitemap returned HTTP ' . $response->status . '.');
            } else {
                try {
                    $document = (new SitemapParser())->parse($response->body);
                    if ($document['entries'] === []) $this->add($state, 'warning', 'empty_sitemap', $url, 'This sitemap has no URL entries.');
                    if (count($document['entries']) > 2000) {
                        $state['complete'] = false;
                        $this->addOnce($state, 'entry_limit', $url, 'Only the first 2000 entries per sitemap are read.');
                    }
                    foreach (array_slice($document['entries'], 0, 2000) as $item) $this->discover($state, $url, $item, $document['index']);
                } catch (\RuntimeException $error) {
                    $state['complete'] = false;
                    $this->add($state, 'error', 'sitemap_xml', $url, $error->getMessage());
                }
            }
            if ($state['queue'] === []) $state['phase'] = 'checking';
        } elseif ($state['pending'] !== []) {
            $url = array_shift($state['pending']);
            $response = $this->http->get($url, Limits::HTML_BYTES);
            $page = (new PageInspector())->inspect($url, $response);
            $state['pages'][] = $page;
            $state['checked']++;
            foreach ($page['findings'] as $finding) $this->add($state, $finding['severity'], $finding['code'], $url, $finding['message']);
            if ($response->status === 429) return $this->rateLimited($state);
        }
        if ($state['queue'] === [] && $state['pending'] === []) {
            $state['phase'] = 'complete';
            $state['finishedAt'] = gmdate('c');
        }
        return $state;
    }

    private function discover(array &$state, string $source, array $entry, bool $index): void
    {
        try {
            $url = PublicNetwork::validateUrl($entry['loc'] ?? '');
        } catch (\RuntimeException | \InvalidArgumentException) {
            $state['complete'] = false;
            $this->add($state, 'error', 'invalid_url', $source, 'An entry is missing a valid public HTTP(S) URL.');
            return;
        }
        if (Url::origin($url) !== $state['origin']) {
            $state['complete'] = false;
            $this->add($state, 'error', 'outside_origin', $url, 'This URL is outside the sitemap origin and was not requested.');
            return;
        }
        if ($entry['lastmod'] !== null && !SitemapParser::validDate($entry['lastmod'])) $this->add($state, 'warning', 'invalid_lastmod', $url, 'The lastmod value is not a valid date or date-time.');
        $hash = hash('sha256', $url);
        if ($index) {
            if (isset($state['seenMaps'][$hash])) {
                $this->add($state, 'warning', 'duplicate_sitemap', $url, 'A repeated or circular sitemap reference was skipped.');
            } elseif (count($state['queue']) + $state['sitemapsRead'] >= Limits::SITEMAPS) {
                $state['complete'] = false;
                $this->addOnce($state, 'sitemap_limit', $source, 'Only the first 3 sitemap documents are checked.');
            } else {
                $state['seenMaps'][$hash] = true;
                $state['queue'][] = ['url' => $url, 'hops' => 0];
            }
        } elseif (isset($state['seenUrls'][$hash])) {
            $this->add($state, 'warning', 'duplicate_url', $url, 'This URL appears more than once. It is checked only once.');
        } else {
            $state['seenUrls'][$hash] = true;
            $state['discovered']++;
            if (count($state['pending']) < Limits::URLS) $state['pending'][] = $url;
            else {
                $state['complete'] = false;
                $this->addOnce($state, 'url_limit', $source, 'This scan checks the first 25 unique URLs. The remaining URLs were not checked.');
            }
        }
    }

    private function rateLimited(array $state): array
    {
        $state['complete'] = false;
        $state['phase'] = 'complete';
        $state['finishedAt'] = gmdate('c');
        $state['queue'] = $state['pending'] = [];
        $this->add($state, 'error', 'rate_limited', $state['sitemap'], 'The website returned HTTP 429. We stopped without retrying.');
        return $state;
    }

    private function addOnce(array &$state, string $code, string $url, string $message): void
    {
        if (!in_array($code, array_column($state['findings'], 'code'), true)) $this->add($state, 'warning', $code, $url, $message);
    }

    private function add(array &$state, string $severity, string $code, string $url, string $message): void
    {
        if (count($state['findings']) < Limits::FINDINGS) $state['findings'][] = compact('severity', 'code', 'message') + ['url' => Url::display($url)];
        else $state['findingsTruncated'] = true;
    }

    public static function view(array $state): array
    {
        $view = array_intersect_key($state, array_flip(['id','phase','complete','sitemapsRead','discovered','checked','limit','pages','findings','startedAt','finishedAt','expiresAt','findingsTruncated']));
        $view['sitemap'] = Url::display($state['sitemap']);
        return $view;
    }
}
