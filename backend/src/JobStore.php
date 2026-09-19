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
        if ($resolved === false || ($public !== false && ($resolved === $public || str_starts_with($resolved, $public . DIRECTORY_SEPARATOR)))) throw new ApiError('Private scan storage must be outside the web directory.', 503);
        $this->withLedger(function (array &$ledger): void {
            $path = $this->directory . '/.secret';
            if (!is_file($path)) $this->write($path, bin2hex(random_bytes(32)));
            $this->secret = file_get_contents($path);
            if (strlen($this->secret) !== 64) throw new ApiError('Scan storage is unavailable.', 503);
        });
    }

    public function create(array $state, string $ip): array
    {
        return $this->withLedger(function (array &$ledger) use ($state, $ip): array {
            $now = time();
            $this->prune($ledger, $now);
            if (($ledger['day'] ?? '') !== gmdate('Y-m-d')) {
                $ledger['day'] = gmdate('Y-m-d');
                $ledger['total'] = 0;
                $ledger['clients'] = [];
            }
            $hash = hash_hmac('sha256', $ip, $this->secret);
            $client = $ledger['clients'][$hash] ?? ['day' => 0, 'hour' => '', 'count' => 0];
            if ($client['hour'] !== gmdate('Y-m-d-H')) { $client['hour'] = gmdate('Y-m-d-H'); $client['count'] = 0; }
            if ($ledger['total'] >= Limits::GLOBAL_DAILY) throw new ApiError('The daily scan allowance has been reached. Please try again tomorrow.', 429);
            if ($client['day'] >= Limits::IP_DAILY || $client['count'] >= Limits::IP_HOURLY) throw new ApiError('Your scan allowance has been reached. Please try again later.', 429);
            if (count($ledger['active']) >= Limits::ACTIVE_JOBS) throw new ApiError('All scan slots are busy. Please try again in a minute.', 429);
            $id = bin2hex(random_bytes(16));
            $token = bin2hex(random_bytes(32));
            $state += ['id' => $id, 'tokenHash' => hash('sha256', $token), 'created' => $now, 'expires' => $now + Limits::RETENTION_SECONDS, 'expiresAt' => gmdate('c', $now + Limits::RETENTION_SECONDS), 'lastStep' => 0.0];
            $this->write($this->path($id), json_encode($state, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            $client['day']++;
            $client['count']++;
            $ledger['clients'][$hash] = $client;
            $ledger['total']++;
            $ledger['active'][$id] = $now + 60;
            return ['scan' => ScanEngine::view($state), 'token' => $token];
        });
    }

    public function update(string $id, string $token, callable $operation): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id) || !preg_match('/\A[a-f0-9]{64}\z/', $token) || !is_file($this->path($id))) throw new ApiError('This scan is unavailable or has expired.', 404);
        $lock = @fopen($this->directory . '/' . $id . '.lock', 'c');
        if ($lock === false) throw new ApiError('Scan storage is unavailable.', 503);
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new ApiError('A scan step is already running. Please wait.', 409); }
        try {
            $raw = @file_get_contents($this->path($id));
            if ($raw === false) throw new ApiError('This scan is unavailable or has expired.', 404);
            $state = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (!hash_equals($state['tokenHash'], hash('sha256', $token)) || $state['expires'] <= time()) throw new ApiError('This scan is unavailable or has expired.', 404);
            if (in_array($state['phase'], ['discovering', 'checking'], true)) {
                if ($state['created'] + Limits::JOB_SECONDS <= time()) {
                    $state['phase'] = 'cancelled';
                    $state['complete'] = false;
                    $state['finishedAt'] = gmdate('c');
                } else {
                    if (microtime(true) - $state['lastStep'] < 0.4) throw new ApiError('Please wait briefly before the next scan step.', 409);
                    $this->withLedger(function (array &$ledger) use ($id): void {
                        $this->prune($ledger, time());
                        if (!isset($ledger['active'][$id]) && count($ledger['active']) >= Limits::ACTIVE_JOBS) throw new ApiError('All scan slots are busy. Please try again in a minute.', 429);
                        $ledger['active'][$id] = time() + 60;
                    });
                    $state = $operation($state);
                    $state['lastStep'] = microtime(true);
                }
            }
            $this->write($this->path($id), json_encode($state, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            if (in_array($state['phase'], ['complete','cancelled'], true)) $this->withLedger(static function (array &$ledger) use ($id): void { unset($ledger['active'][$id]); });
            return ScanEngine::view($state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function path(string $id): string { return $this->directory . '/' . $id . '.json'; }

    private function withLedger(callable $operation): mixed
    {
        $lock = @fopen($this->directory . '/.ledger.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new ApiError('Scan storage is unavailable.', 503);
        try {
            $path = $this->directory . '/.ledger.json';
            $ledger = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
            $result = $operation($ledger);
            $this->write($path, json_encode($ledger, JSON_THROW_ON_ERROR));
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function prune(array &$ledger, int $now): void
    {
        $ledger['active'] = array_filter($ledger['active'] ?? [], static fn (int $until): bool => $until > $now);
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            if (preg_match('/\A[a-f0-9]{32}\.json\z/', basename($path)) && filemtime($path) < $now - Limits::RETENTION_SECONDS) {
                @unlink($path);
                @unlink(substr($path, 0, -5) . '.lock');
            }
        }
    }

    private function write(string $path, string $contents): void
    {
        $temp = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
        if (file_put_contents($temp, $contents, LOCK_EX) !== strlen($contents)) { @unlink($temp); throw new ApiError('Scan storage is unavailable.', 503); }
        @chmod($temp, 0600);
        if (!rename($temp, $path)) { @unlink($temp); throw new ApiError('Scan storage is unavailable.', 503); }
    }
}
