<?php
declare(strict_types=1);
namespace ScoutWeb;

final class PageInspector
{
    public function inspect(string $url, Response $response): array
    {
        $display = Url::display($url);
        $page = ['url' => $display, 'status' => $response->status, 'durationMs' => $response->durationMs, 'title' => null, 'canonical' => null, 'indexability' => 'unknown', 'findings' => []];
        $add = static function (string $severity, string $code, string $message) use (&$page, $display): void {
            $page['findings'][] = compact('severity', 'code', 'message') + ['url' => $display];
        };
        if ($response->error !== null) {
            $add('error', 'request_failed', $response->error);
            return $page;
        }
        if (in_array($response->status, [301,302,303,307,308], true)) {
            $add('warning', 'redirect', 'This URL redirects. Put the final destination in your sitemap.');
            return $page;
        }
        if ($response->status < 200 || $response->status >= 300) {
            $add('error', 'http_error', 'Page returned HTTP ' . $response->status . '. Remove this URL or restore the page.');
            return $page;
        }
        if ($response->durationMs >= Limits::SLOW_MS) $add('warning', 'slow', 'The response took ' . $response->durationMs . ' ms, above the 1000 ms threshold.');
        $robots = implode(',', $response->headers['x-robots-tag'] ?? []);
        $html = str_contains(strtolower($response->header('content-type') ?? ''), 'text/html');
        if ($html && $response->body !== '') {
            $previous = libxml_use_internal_errors(true);
            try {
                $dom = new \DOMDocument();
                $dom->resolveExternals = false;
                $dom->substituteEntities = false;
                $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $response->body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                if ($loaded) {
                    $title = $dom->getElementsByTagName('title')->item(0)?->textContent ?? '';
                    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
                    preg_match('/\A.{0,200}/us', $title, $match);
                    $page['title'] = $match[0] ?? null;
                    foreach ($dom->getElementsByTagName('meta') as $meta) {
                        if (in_array(strtolower(trim($meta->getAttribute('name'))), ['robots','googlebot'], true)) $robots .= ',' . $meta->getAttribute('content');
                    }
                    $base = $url;
                    $baseElement = $dom->getElementsByTagName('base')->item(0);
                    if ($baseElement !== null && $baseElement->hasAttribute('href')) {
                        try { $base = Url::resolve($url, $baseElement->getAttribute('href')); } catch (\InvalidArgumentException) {}
                    }
                    $canonicals = [];
                    foreach ($dom->getElementsByTagName('link') as $link) {
                        if (preg_match('/(?:\A|\s)canonical(?:\s|\z)/i', trim($link->getAttribute('rel')))) $canonicals[] = $link->getAttribute('href');
                    }
                    if (count($canonicals) > 1) $add('warning', 'canonical_multiple', 'More than one canonical link was found. Keep a single consistent canonical.');
                    if ($canonicals !== []) {
                        try {
                            $canonical = Url::resolve($base, explode('#', $canonicals[0], 2)[0]);
                            $page['canonical'] = Url::display($canonical);
                            if ($canonical !== $url) $add('warning', 'canonical_mismatch', 'The canonical points to a different URL. Consider listing that URL instead.');
                        } catch (\InvalidArgumentException) {
                            $add('warning', 'canonical_invalid', 'The canonical link is not a usable HTTP(S) URL.');
                        }
                    }
                    if (!$response->truncated || stripos($response->body, '</head>') !== false) {
                        $page['indexability'] = 'allowed';
                        if (!$page['title']) $add('warning', 'title_missing', 'No page title was found.');
                    } else {
                        $add('warning', 'metadata_incomplete', 'Only the first 256 KiB was read and the HTML head was incomplete. Indexing signals may be missing.');
                    }
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        }
        if (preg_match('/(?:\A|[\s,:])(?:noindex|none)(?=[\s,;]|\z)/i', $robots)) {
            $page['indexability'] = 'noindex';
            $add('error', 'noindex', 'A robots directive asks search engines not to index this page.');
        }
        return $page;
    }
}
