<?php
declare(strict_types=1);
namespace ScoutWeb;

final class JobStore
{
    private string $secret;

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true)) throw new ApiError('Scan storage is unavailable.', 503);
        $public = realpath(dirname(__DIR__, 2) . '/public');
        $resolved = realpath($directory);
        if ($resolved === false || ($public !== false && ($resolved === $public || str_starts_with($resolved, $public . DIRECTORY_SEPARATOR)))) {
            throw new ApiError('Private scan storage must be outside the web directory.', 503);
        }
        $path = $directory . '/.secret';
        if (!is_file($path)) {
            $this->withLedger(function (array &$ledger) use ($path): void {
                if (!is_file($path)) $this->write($path, bin2hex(random_bytes(32)));
            });
        }
        $this->secret = (string) file_get_contents($path);
        if (!preg_match('/\A[a-f0-9]{64}\z/', $this->secret)) throw new ApiError('Scan storage is unavailable.', 503);
    }

    public function challengeGuard(): ChallengeGuard { return new ChallengeGuard($this->secret); }

    /** Bound challenge/create attempts, with one bounded ledger rather than a file per IP. */
    public function admit(string $ip): void
    {
        $this->withLedger(function (array &$ledger) use ($ip): void {
            $minute = intdiv(time(), 60);
            $request = $ledger['requests'] ?? [];
            if (($request['minute'] ?? -1) !== $minute) $request = ['minute' => $minute, 'total' => 0, 'clients' => []];
            $client = $this->clientHash($ip);
            if ($request['total'] >= 60 || ($request['clients'][$client] ?? 0) >= 8) {
                throw new ApiError('Too many scan requests. Please wait a minute.', 429);
            }
            $request['total']++;
            $request['clients'][$client] = ($request['clients'][$client] ?? 0) + 1;
            $ledger['requests'] = $request;
        });
    }

    public function create(array $state, string $ip, ?array $proof = null): array
    {
        return $this->withLedger(function (array &$ledger) use ($state, $ip, $proof): array {
            $now = time();
            $this->prune($ledger, $now);
            if (($ledger['day'] ?? '') !== gmdate('Y-m-d')) {
                $ledger['day'] = gmdate('Y-m-d');
                $ledger['total'] = 0;
                $ledger['clients'] = [];
                $ledger['targets'] = [];
            }
            $hash = $this->clientHash($ip);
            $targetHash = hash_hmac('sha256', parse_url($state['sitemap'], PHP_URL_HOST), $this->secret);
            $client = $ledger['clients'][$hash] ?? ['day' => 0, 'hour' => '', 'count' => 0];
            $target = $ledger['targets'][$targetHash] ?? ['day' => 0, 'hour' => '', 'count' => 0];
            foreach (['client', 'target'] as $name) {
                if (${$name}['hour'] !== gmdate('Y-m-d-H')) { ${$name}['hour'] = gmdate('Y-m-d-H'); ${$name}['count'] = 0; }
            }
            if ($ledger['total'] >= Limits::GLOBAL_DAILY) throw new ApiError('The daily scan allowance has been reached. Please try again tomorrow.', 429);
            if ($client['day'] >= Limits::IP_DAILY || $client['count'] >= Limits::IP_HOURLY) throw new ApiError('Your scan allowance has been reached. Please try again later.', 429);
            if ($target['day'] >= 10 || $target['count'] >= 3) throw new ApiError('This website has reached its scan allowance. Please try again later.', 429);
            $this->checkSlots($ledger, $hash, $targetHash);
            if ($proof !== null && isset($ledger['proofs'][$proof['key']])) throw new ApiError('This verification was already used. Please start a new scan.', 403);
            $id = bin2hex(random_bytes(16));
            $token = bin2hex(random_bytes(32));
            $state += ['id' => $id, 'tokenHash' => hash('sha256', $token), 'clientHash' => $hash, 'targetHash' => $targetHash,
                'created' => $now, 'expires' => $now + Limits::RETENTION_SECONDS,
                'expiresAt' => gmdate('c', $now + Limits::RETENTION_SECONDS), 'lastStep' => 0.0];
            $this->write($this->path($id), json_encode($state, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            $client['day']++; $client['count']++; $target['day']++; $target['count']++;
            $ledger['clients'][$hash] = $client;
            $ledger['targets'][$targetHash] = $target;
            $ledger['total']++;
            $ledger['active'][$id] = ['until' => $now + 60, 'client' => $hash, 'target' => $targetHash];
            if ($proof !== null) $ledger['proofs'][$proof['key']] = $proof['expires'];
            return ['scan' => ScanEngine::view($state), 'token' => $token];
        });
    }

    public function update(string $id, string $token, callable $operation, string $ip): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) throw new ApiError('This scan is unavailable or has expired.', 404);
        // Authenticate before acquiring/creating a lock. Bad tokens cannot contend with a scan.
        $state = $this->readAuthorized($id, $token, $ip);
        if (in_array($state['phase'], ['complete', 'cancelled'], true)) return ScanEngine::view($state);
        $lock = @fopen($this->directory . '/' . $id . '.lock', 'c');
        if ($lock === false) throw new ApiError('Scan storage is unavailable.', 503);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new ApiError('A scan step is already running. Please wait.', 409); }
        try {
            $state = $this->readAuthorized($id, $token, $ip);
            if (in_array($state['phase'], ['complete', 'cancelled'], true)) return ScanEngine::view($state);
            if ($state['created'] + Limits::JOB_SECONDS <= time()) {
                $state['phase'] = 'cancelled'; $state['complete'] = false; $state['finishedAt'] = gmdate('c');
            } else {
                if (microtime(true) - $state['lastStep'] < 1.0) throw new ApiError('Please wait briefly before the next scan step.', 409);
                $this->withLedger(function (array &$ledger) use ($id, $state): void {
                    $this->prune($ledger, time());
                    unset($ledger['active'][$id]);
                    $this->checkSlots($ledger, $state['clientHash'], $state['targetHash']);
                    $ledger['active'][$id] = ['until' => time() + 60, 'client' => $state['clientHash'], 'target' => $state['targetHash']];
                });
                $state = $operation($state);
                $state['lastStep'] = microtime(true);
            }
            if (in_array($state['phase'], ['complete', 'cancelled'], true)) {
                // Completed jobs retain only report data and access controls, never pending raw URLs.
                $state['sitemap'] = Url::display($state['sitemap']);
                unset($state['queue'], $state['pending'], $state['seenMaps'], $state['seenUrls'], $state['origin']);
                $this->withLedger(static function (array &$ledger) use ($id): void { unset($ledger['active'][$id]); });
            }
            $this->write($this->path($id), json_encode($state, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            return ScanEngine::view($state);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function checkSlots(array $ledger, string $client, string $target): void
    {
        if (count($ledger['active']) >= Limits::ACTIVE_JOBS) throw new ApiError('All scan slots are busy. Please try again in a minute.', 429);
        foreach ($ledger['active'] as $active) {
            if ($active['client'] === $client) throw new ApiError('You already have an active scan. Finish it or wait a minute.', 429);
            if ($active['target'] === $target) throw new ApiError('This website is already being scanned. Please wait a minute.', 429);
        }
    }

    private function readAuthorized(string $id, string $token, string $ip): array
    {
        $raw = @file_get_contents($this->path($id));
        if ($raw === false) throw new ApiError('This scan is unavailable or has expired.', 404);
        $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!hash_equals($state['tokenHash'], hash('sha256', $token)) || $state['expires'] <= time()
            || !hash_equals($state['clientHash'] ?? '', $this->clientHash($ip))) throw new ApiError('This scan is unavailable or has expired.', 404);
        return $state;
    }

    private function clientHash(string $ip): string { return hash_hmac('sha256', ClientIdentity::bucket($ip), $this->secret); }
    private function path(string $id): string { return $this->directory . '/' . $id . '.json'; }

    private function withLedger(callable $operation): mixed
    {
        $lock = @fopen($this->directory . '/.ledger.lock', 'c');
        if ($lock === false) throw new ApiError('Scan storage is unavailable.', 503);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new ApiError('The scanner is busy. Please try again shortly.', 503); }
        try {
            $path = $this->directory . '/.ledger.json';
            $ledger = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
            $original = $ledger;
            $result = $operation($ledger);
            if ($ledger !== $original) $this->write($path, json_encode($ledger, JSON_THROW_ON_ERROR));
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function prune(array &$ledger, int $now): void
    {
        // Old releases used integer leases. Expire these rather than carrying unauthenticated jobs forward.
        $ledger['active'] = array_filter($ledger['active'] ?? [], static fn ($entry): bool => is_array($entry) && $entry['until'] > $now);
        $ledger['proofs'] = array_filter($ledger['proofs'] ?? [], static fn (int $until): bool => $until > $now);
        if (($ledger['cleanupAt'] ?? 0) > $now - 60) return;
        $ledger['cleanupAt'] = $now;
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            if (!preg_match('/\A[a-f0-9]{32}\.json\z/', basename($path))) continue;
            $state = json_decode((string) @file_get_contents($path), true);
            if (!is_array($state) || ($state['expires'] ?? 0) > $now) continue;
            $lockPath = substr($path, 0, -5) . '.lock';
            $lock = @fopen($lockPath, 'c');
            if ($lock !== false) {
                if (flock($lock, LOCK_EX | LOCK_NB)) { @unlink($path); @unlink($lockPath); flock($lock, LOCK_UN); }
                fclose($lock);
            }
        }
    }

    private function write(string $path, string $contents): void
    {
        $temp = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
        $previous = umask(0077);
        try { $written = file_put_contents($temp, $contents, LOCK_EX); } finally { umask($previous); }
        if ($written !== strlen($contents)) { @unlink($temp); throw new ApiError('Scan storage is unavailable.', 503); }
        if (!rename($temp, $path)) { @unlink($temp); throw new ApiError('Scan storage is unavailable.', 503); }
    }
}
