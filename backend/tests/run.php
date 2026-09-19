<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use ScoutWeb\{Api, ApiError, CurlTransport, HttpClient, JobStore, Limits, PageInspector, PublicNetwork, Response, ScanEngine, SitemapParser, Url};

$passed = 0;
$failed = 0;
function test(string $name, callable $run): void {
    global $passed, $failed;
    try { $run(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { $failed++; echo "FAIL $name: {$e->getMessage()}\n"; }
}
function same(mixed $expected, mixed $actual): void {
    if ($actual !== $expected) throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function raises(callable $run, ?int $status = null): void {
    try { $run(); } catch (Throwable $e) {
        if ($status !== null && (!$e instanceof ApiError || $e->status !== $status)) throw $e;
        return;
    }
    throw new RuntimeException('Expected an exception');
}
function xml(array $urls, bool $index = false): string {
    $root = $index ? 'sitemapindex' : 'urlset'; $node = $index ? 'sitemap' : 'url';
    return '<' . $root . ' xmlns="' . SitemapParser::NS . '">' . implode('', array_map(fn ($url) => "<$node><loc>" . htmlspecialchars($url, ENT_XML1) . "</loc></$node>", $urls)) . "</$root>";
}
final class FakeHttp implements HttpClient {
    public array $calls = [];
    public function __construct(public array $responses = []) {}
    public function get(string $url, int $limit): Response {
        $this->calls[] = $url;
        return $this->responses[$url] ?? new Response(200, '<head><title>Sample</title></head>', ['content-type' => ['text/html']]);
    }
}
function codes(array $state): array { return array_column($state['findings'], 'code'); }
function finish(ScanEngine $engine, array $state): array {
    for ($i = 0; $i < 50 && $state['phase'] !== 'complete'; $i++) $state = $engine->step($state);
    same('complete', $state['phase']); return $state;
}
function temporary(callable $run): void {
    $dir = sys_get_temp_dir() . '/scout-test-' . bin2hex(random_bytes(8));
    try { $run($dir); } finally { foreach (glob($dir . '/{*,.*}', GLOB_BRACE) ?: [] as $file) if (is_file($file)) unlink($file); if (is_dir($dir)) rmdir($dir); }
}
function cancelState(array $s): array { $s['phase'] = 'cancelled'; $s['complete'] = false; return $s; }

foreach (['127.0.0.1','10.1.2.3','169.254.169.254','172.31.255.255','192.168.1.1','100.64.0.1','0.0.0.0','192.0.2.1','198.18.0.1','198.51.100.2','203.0.113.2','224.1.2.3','255.255.255.255','::1','::ffff:127.0.0.1','fc00::1','fe80::1','2001:db8::1','2002:7f00:1::','3fff::1'] as $ip) test("blocks $ip", fn () => same(false, PublicNetwork::isPublicIp($ip)));
foreach (['1.1.1.1','8.8.8.8','77.237.235.52','2606:4700:4700::1111','2001:4860:4860::8888'] as $ip) test("accepts public $ip", fn () => same(true, PublicNetwork::isPublicIp($ip)));
foreach (['http://127.0.0.1/','http://[::1]/','http://2130706433/','http://site.local/','https://example.com:8080/','ftp://example.com/','https://user:pass@example.com/','https://example.com/#fragment',"https://example.com/\r\nx:1"] as $url) test('rejects unsafe URL ' . json_encode($url), fn () => raises(fn () => PublicNetwork::validateUrl($url)));
test('normalizes default ports and redacts queries', function () {
    same('https://example.com/a?token=secret', PublicNetwork::validateUrl('https://EXAMPLE.com:443/a?token=secret'));
    same(false, str_contains(Url::display('https://example.com/a?token=secret'), 'secret'));
});
test('parses gzip and XML entities', fn () => same('https://example.com/?a=1&b=2', (new SitemapParser())->parse(gzencode(xml(['https://example.com/?a=1&b=2'])))['entries'][0]['loc']));
foreach (['<!DOCTYPE urlset [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset/>','<urlset/>','<broken>',gzencode(str_repeat('a', Limits::XML_BYTES + 10))] as $i => $input) test("rejects dangerous or invalid XML $i", fn () => raises(fn () => (new SitemapParser())->parse($input)));
test('validates calendar dates', function () { same(false, SitemapParser::validDate('2025-02-29')); same(true, SitemapParser::validDate('2024-02-29T12:00:00Z')); });
test('canonical, base URL, robots and unicode title', function () {
    $p = (new PageInspector())->inspect('https://example.com/a', new Response(200, '<head><title>Résumé &amp; café</title><base href="/blog/"><link rel="canonical" href="b"><meta name="robots" content="noindex, follow"></head>', ['content-type' => ['text/html']]));
    same('Résumé & café', $p['title']); same('https://example.com/blog/b', $p['canonical']); same('noindex', $p['indexability']); same(['canonical_mismatch','noindex'], codes($p));
});
test('HTTP robots header applies to non-HTML', fn () => same('noindex', (new PageInspector())->inspect('https://example.com/a.pdf', new Response(200, '', ['x-robots-tag' => ['googlebot: noindex']]))['indexability']));
test('truncated HTML does not claim complete indexing checks', function () {
    $p = (new PageInspector())->inspect('https://example.com/a', new Response(200, '<head><title>Test</title>', ['content-type' => ['text/html']], 40, true));
    same('unknown', $p['indexability']); same(['metadata_incomplete'], codes($p));
});
test('redirects are reported without following page targets', function () { same(['redirect'], codes((new PageInspector())->inspect('https://example.com/a', new Response(302, '', ['location' => ['http://127.0.0.1/']])))); });
test('engine deduplicates and stays within the original origin', function () {
    $http = new FakeHttp(['https://example.com/sitemap.xml' => new Response(200, xml(['https://example.com/a?key=secret','https://example.com/a?key=secret','https://other.com/b']))]);
    $engine = new ScanEngine($http); $state = finish($engine, $engine->create('https://example.com/sitemap.xml'));
    same(1, $state['checked']); same(2, count($http->calls)); same(false, $state['complete']);
    same(['duplicate_url','outside_origin'], codes($state));
    same(false, str_contains(json_encode(ScanEngine::view($state)), 'secret'));
});
test('one request per step, sitemap redirects stay in scope', function () {
    $http = new FakeHttp(['https://example.com/sitemap.xml' => new Response(302, '', ['location' => ['https://other.com/map.xml']])]);
    $e = new ScanEngine($http); $s = finish($e, $e->create('https://example.com/sitemap.xml'));
    same(1, count($http->calls)); same(false, $s['complete']);
});
test('caps URL coverage and marks partial reports', function () {
    $urls = array_map(fn ($n) => "https://example.com/$n", range(1, 30));
    $http = new FakeHttp(['https://example.com/map.xml' => new Response(200, xml($urls))]);
    $e = new ScanEngine($http); $s = finish($e, $e->create('https://example.com/map.xml'));
    same(25, $s['checked']); same(30, $s['discovered']); same(false, $s['complete']); same(true, in_array('url_limit', codes($s)));
});
test('caps sitemap indexes at three documents', function () {
    $http = new FakeHttp(['https://example.com/map.xml' => new Response(200, xml(array_map(fn ($n) => "https://example.com/$n.xml", range(1, 6)), true))]);
    $e = new ScanEngine($http); $s = finish($e, $e->create('https://example.com/map.xml')); same(3, count($http->calls)); same(false, $s['complete']);
});
test('HTTP 429 stops without retries', function () {
    $http = new FakeHttp(['https://example.com/map.xml' => new Response(429)]); $e = new ScanEngine($http);
    $s = finish($e, $e->create('https://example.com/map.xml')); same(1, count($http->calls)); same(['rate_limited'], codes($s));
});
test('bounded findings reveal truncation', function () {
    $http = new FakeHttp(['https://example.com/map.xml' => new Response(200, xml(array_fill(0, 130, 'https://other.com/no')))]);
    $e = new ScanEngine($http); $s = finish($e, $e->create('https://example.com/map.xml')); same(100, count($s['findings'])); same(true, $s['findingsTruncated']);
});
test('tokens, concurrency, expiry and private state', fn () => temporary(function ($dir) {
    $store = new JobStore($dir); $created = $store->create((new ScanEngine(new FakeHttp()))->create('https://example.com/map.xml?key=private'), '203.0.113.10');
    $id = $created['scan']['id']; $token = $created['token'];
    same(false, str_contains(json_encode($created['scan']), 'private')); same(false, isset($created['scan']['tokenHash'])); same(false, isset($created['scan']['queue']));
    raises(fn () => $store->update($id, str_repeat('0', 64), 'cancelState'), 404);
    $lock = fopen("$dir/$id.lock", 'c'); flock($lock, LOCK_EX);
    raises(fn () => $store->update($id, $token, 'cancelState'), 409); fclose($lock);
    $store->update($id, $token, fn ($s) => $s);
    raises(fn () => $store->update($id, $token, fn ($s) => $s), 409);
    $state = json_decode(file_get_contents("$dir/$id.json"), true); $state['expires'] = time() - 1;
    file_put_contents("$dir/$id.json", json_encode($state)); raises(fn () => $store->update($id, $token, 'cancelState'), 404);
    same(false, str_contains(file_get_contents("$dir/.ledger.json"), '203.0.113.10'));
}));
test('active job admission limit', fn () => temporary(function ($dir) {
    $store = new JobStore($dir); $s = (new ScanEngine(new FakeHttp()))->create('https://example.com/map.xml');
    for ($i = 0; $i < 3; $i++) $store->create($s, "client-$i");
    raises(fn () => $store->create($s, 'fourth'), 429);
}));
test('per-IP hourly quota survives store restart', fn () => temporary(function ($dir) {
    $store = new JobStore($dir); $s = (new ScanEngine(new FakeHttp()))->create('https://example.com/map.xml');
    for ($i = 0; $i < 5; $i++) { $c = $store->create($s, 'client'); $store->update($c['scan']['id'], $c['token'], 'cancelState'); }
    raises(fn () => (new JobStore($dir))->create($s, 'client'), 429);
}));
test('API validates origin, payloads and bearer token', fn () => temporary(function ($dir) {
    $api = new Api(new JobStore($dir), new ScanEngine(new FakeHttp()), 'https://scout.example.com');
    $headers = ['origin' => 'https://scout.example.com', 'content-type' => 'application/json'];
    $body = '{"sitemap":"https://example.com/map.xml"}';
    same(200, $api->handle('GET', 'status', [], '', 'client')[0]);
    same(405, $api->handle('GET', 'create', [], '', 'client')[0]);
    same(403, $api->handle('POST', 'create', [], $body, 'client')[0]);
    same(415, $api->handle('POST', 'create', ['origin' => $headers['origin']], $body, 'client')[0]);
    same(413, $api->handle('POST', 'create', $headers, str_repeat('x', 4097), 'client')[0]);
    same(400, $api->handle('POST', 'create', $headers, '{', 'client')[0]);
    same(422, $api->handle('POST', 'create', $headers, '{"sitemap":"http://127.0.0.1/"}', 'client')[0]);
    [$status, $c] = $api->handle('POST', 'create', $headers, $body, 'client'); same(201, $status);
    $step = json_encode(['id' => $c['scan']['id']]);
    same(404, $api->handle('POST', 'step', $headers, $step, 'client')[0]);
    same(200, $api->handle('POST', 'cancel', $headers + ['x-scan-token' => $c['token']], $step, 'client')[0]);
}));
test('cURL pins DNS, refuses redirects and bounds decoded bodies', function () {
    $port = random_int(20000, 45000);
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/http-fixture.php'], [0 => ['pipe','r'], 1 => ['file','/dev/null','w'], 2 => ['file','/dev/null','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start transport fixture');
    try {
        fclose($pipes[0]);
        for ($i = 0; $i < 40; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
            if ($socket) { fclose($socket); break; }
            usleep(25000);
        }
        $transport = new CurlTransport();
        // Production SafeClient never accepts this private address. Direct transport testing
        // lets an intentionally nonexistent hostname prove CURLOPT_RESOLVE is actually used.
        $url = "http://fixture.invalid:$port";
        $r = $transport->get($url . '/', '127.0.0.1', 1000);
        same(200, $r->status); same(null, $r->error); same(true, str_contains($r->body, 'fixture.invalid'));
        same('noindex', $r->header('x-robots-tag'));
        same(302, $transport->get($url . '/redirect', '127.0.0.1', 1000)->status);
        foreach (['large','compressed'] as $path) {
            $r = $transport->get($url . '/' . $path, '127.0.0.1', 1024);
            same(1024, strlen($r->body)); same(true, $r->truncated); same(null, $r->error);
        }
    } finally { proc_terminate($process); proc_close($process); }
});
echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
