<?php
declare(strict_types=1);
namespace ScoutWeb;

use AltchaOrg\Altcha\{Altcha, CreateChallengeOptions, Payload, VerifySolutionOptions};
use AltchaOrg\Altcha\Algorithm\Pbkdf2;

/** Proof of work adds automation cost; it is not proof that a visitor is human. */
final class ChallengeGuard
{
    private readonly Altcha $altcha;

    public function __construct(private readonly string $secret)
    {
        $this->altcha = new Altcha(
            hmacSignatureSecret: hash_hmac('sha256', 'challenge-signature', $secret),
            hmacKeySignatureSecret: hash_hmac('sha256', 'challenge-key', $secret),
        );
    }

    public function issue(string $url, string $ip): array
    {
        return $this->altcha->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(), cost: 2000, counter: random_int(200, 400),
            expiresAt: time() + 120,
            data: ['binding' => $this->binding($url, $ip)],
        ))->toArray();
    }

    /** Returns a replay key and expiry. The job ledger consumes it atomically on creation. */
    public function verify(string $encoded, string $url, string $ip): array
    {
        try {
            if (strlen($encoded) > 4096) throw new \RuntimeException();
            $payload = Payload::fromBase64($encoded);
            $p = $payload->challenge->parameters;
            // Bound work before invoking a cryptographic library with untrusted parameters.
            if ($p->algorithm !== 'PBKDF2/SHA-256' || $p->cost !== 2000 || $p->keyLength !== 32
                || $p->memoryCost !== null || $p->parallelism !== null
                || !preg_match('/\A[a-f0-9]{32}\z/', $p->nonce) || !preg_match('/\A[a-f0-9]{32}\z/', $p->salt)
                || !preg_match('/\A[a-f0-9]{32}\z/', $p->keyPrefix)
                || !preg_match('/\A[a-f0-9]{64}\z/', $p->keySignature ?? '')
                || !preg_match('/\A[a-f0-9]{64}\z/', $payload->solution->derivedKey)
                || $payload->solution->counter < 0 || $payload->solution->counter > 400
                || $p->expiresAt === null || $p->expiresAt <= time() || $p->expiresAt > time() + 120
                || ($p->data['binding'] ?? '') !== $this->binding($url, $ip)) throw new \RuntimeException();
            $verified = $this->altcha->verifySolution(new VerifySolutionOptions(algorithm: new Pbkdf2(), payload: $payload));
            if (!$verified->verified) throw new \RuntimeException();
            return ['key' => hash('sha256', $p->nonce), 'expires' => $p->expiresAt];
        } catch (\Throwable) {
            throw new ApiError('Browser verification failed or expired. Please start a new scan.', 403);
        }
    }

    private function binding(string $url, string $ip): string
    {
        return hash_hmac('sha256', ClientIdentity::bucket($ip) . "\n" . $url, $this->secret);
    }
}
