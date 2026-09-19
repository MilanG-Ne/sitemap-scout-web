<?php
declare(strict_types=1);
namespace ScoutWeb;

/** Real process-held locks remain effective even if DNS stalls beyond a scan's lease. */
final class OutboundSlots
{
    public function __construct(private readonly string $directory) {}

    public function acquireGlobal(): mixed
    {
        for ($i = 0; $i < Limits::ACTIVE_JOBS; $i++) {
            $lock = $this->tryLock('outbound-' . $i);
            if ($lock !== null) return $lock;
        }
        throw new \RuntimeException('All outbound request slots are busy. Please try again later.');
    }

    public function acquireAddress(string $ip): mixed
    {
        // Fixed-size lock pool bounds disk use. Hash collisions conservatively share a slot.
        $bucket = hexdec(substr(hash('sha256', inet_pton($ip)), 0, 2));
        $lock = $this->tryLock('destination-' . $bucket);
        if ($lock === null) throw new \RuntimeException('This destination is busy. The request was not sent.');
        return $lock;
    }

    public static function release(mixed $lock): void
    {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function tryLock(string $name): mixed
    {
        $lock = @fopen($this->directory . '/.' . $name . '.lock', 'c');
        if ($lock === false) throw new \RuntimeException('Outbound request controls are unavailable.');
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return null; }
        return $lock;
    }
}
