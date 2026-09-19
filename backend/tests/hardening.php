<?php
// Loaded by run.php; shares its test helpers and fake HTTP client.
use ScoutWeb\{Api, ChallengeGuard, ClientIdentity, JobStore, OutboundSlots, ScanEngine};

test('status and invalid requests never initialize storage', function () {
    $factory = static function (): JobStore { throw new RuntimeException('Storage was initialized'); };
    $api = new Api($factory, new ScanEngine(new FakeHttp()), 'https://scout.example.com');
    same(200, $api->handle('GET', 'status', [], '', 'client')[0]);
    same(405, $api->handle('GET', 'create', [], '', 'client')[0]);
    same(403, $api->handle('POST', 'create', [], '{}', 'client')[0]);
    same(404, $api->handle('POST', 'unknown', ['origin'=>'https://scout.example.com','content-type'=>'application/json'], '{"anything":true}', 'client')[0]);
});
test('IPv6 subnet quotas normalize equivalent and rotating addresses', function () {
    same(ClientIdentity::bucket('2001:4860:1111:2222::1'), ClientIdentity::bucket('2001:4860:1111:2222:ffff:0:0:abcd'));
    same('192.0.2.1', ClientIdentity::bucket('::ffff:192.0.2.1'));
});
test('challenge binds client and URL; rejects tampering and expiry', function () {
    $guard = new ChallengeGuard(str_repeat('a', 64));
    $url = 'https://example.com/map.xml'; $ip = '192.0.2.1';
    $issued = $guard->issue($url, $ip); $proof = solveProof($issued);
    same(true, isset($guard->verify($proof, $url, $ip)['key']));
    raises(fn () => $guard->verify($proof, $url, '192.0.2.2'), 403);
    raises(fn () => $guard->verify($proof, 'https://other.com/map.xml', $ip), 403);
    foreach (['cost' => 999999999, 'expiresAt' => time()-1, 'keyPrefix' => '', 'algorithm' => 'SCRYPT'] as $key => $value) {
        $p = json_decode(base64_decode($proof), true); $p['challenge']['parameters'][$key] = $value;
        $start = microtime(true);
        raises(fn () => $guard->verify(base64_encode(json_encode($p)), $url, $ip), 403);
        same(true, microtime(true) - $start < 0.5);
    }
    $p = json_decode(base64_decode($proof), true); $p['challenge']['signature'] = str_repeat('0',64);
    raises(fn () => $guard->verify(base64_encode(json_encode($p)), $url, $ip), 403);
    raises(fn () => $guard->verify('not base64', $url, $ip), 403);
});
test('proof cannot start two jobs, even after the first job finishes', fn () => temporary(function ($dir) {
    $store = new JobStore($dir); $e = new ScanEngine(new FakeHttp()); $state = $e->create('https://example.com/map.xml');
    $guard = $store->challengeGuard(); $proof = $guard->verify(solveProof($guard->issue($state['sitemap'], 'client')), $state['sitemap'], 'client');
    $c = $store->create($state, 'client', $proof); $store->update($c['scan']['id'], $c['token'], 'cancelState', 'client');
    raises(fn () => (new JobStore($dir))->create($state, 'client', $proof), 403);
}));
test('admission limits failed attempts and rejects without more writes', fn () => temporary(function ($dir) {
    $store = new JobStore($dir);
    for ($i=0;$i<8;$i++) $store->admit('client');
    $before = file_get_contents("$dir/.ledger.json");
    raises(fn () => $store->admit('client'), 429); same($before, file_get_contents("$dir/.ledger.json"));
    for ($i=8;$i<60;$i++) $store->admit("client-$i");
    raises(fn () => $store->admit('new-client'), 429);
}));
test('one active scan per client and target; target quotas survive IP rotation', fn () => temporary(function ($dir) {
    $store = new JobStore($dir); $e = new ScanEngine(new FakeHttp()); $s = $e->create('https://example.com/map.xml');
    $c = $store->create($s, 'client');
    raises(fn () => $store->create($s, 'other-client'), 429);
    raises(fn () => $store->create($e->create('https://other.com/map.xml'), 'client'), 429);
    $store->update($c['scan']['id'], $c['token'], 'cancelState', 'client');
    for ($i=0;$i<2;$i++) { $c = $store->create($s, "client-$i"); $store->update($c['scan']['id'], $c['token'], 'cancelState', "client-$i"); }
    raises(fn () => $store->create($s, 'new-client'), 429);
}));
test('tokens are IP bound and completed reads do not change files', fn () => temporary(function ($dir) {
    $store = new JobStore($dir); $c = $store->create((new ScanEngine(new FakeHttp()))->create('https://example.com/map.xml?secret=remove-me'), 'client');
    $id=$c['scan']['id']; $token=$c['token'];
    raises(fn () => $store->update($id, $token, 'cancelState', 'other-client'), 404);
    same(false, is_file("$dir/$id.lock"));
    $store->update($id, $token, 'cancelState', 'client');
    $before=file_get_contents("$dir/$id.json"); same(false, str_contains($before, 'remove-me')); same(false, str_contains($before, 'pending'));
    $ledger=file_get_contents("$dir/.ledger.json");
    $store->update($id, $token, fn () => throw new RuntimeException('Must not run'), 'client');
    same($before, file_get_contents("$dir/$id.json")); same($ledger, file_get_contents("$dir/.ledger.json"));
}));
test('storage contention fails immediately instead of queuing PHP workers', fn () => temporary(function ($dir) {
    $store=new JobStore($dir); $lock=fopen("$dir/.ledger.lock",'c'); flock($lock,LOCK_EX);
    try {
        $start=microtime(true); $other=new JobStore($dir);
        raises(fn () => $other->admit('client'), 503);
        same(true, microtime(true)-$start < 0.5);
    } finally { fclose($lock); }
}));
test('outbound slots enforce process concurrency and destination exclusion', fn () => temporary(function ($dir) {
    new JobStore($dir); $slots=new OutboundSlots($dir); $held=[];
    try {
        for ($i=0;$i<3;$i++) $held[]=$slots->acquireGlobal();
        raises(fn () => $slots->acquireGlobal());
        $held[]=$slots->acquireAddress('1.1.1.1'); raises(fn () => $slots->acquireAddress('1.1.1.1'));
    } finally { foreach ($held as $lock) OutboundSlots::release($lock); }
    $lock=$slots->acquireGlobal(); OutboundSlots::release($lock);
}));
