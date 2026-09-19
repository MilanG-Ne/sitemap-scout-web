<?php
declare(strict_types=1);
namespace ScoutWeb;

final readonly class Response
{
    /** @param array<string, list<string>> $headers */
    public function __construct(public int $status, public string $body = '', public array $headers = [], public int $durationMs = 0, public bool $truncated = false, public ?string $error = null) {}
    public function header(string $name): ?string { return $this->headers[strtolower($name)][0] ?? null; }
}
