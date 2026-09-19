<?php
declare(strict_types=1);
namespace ScoutWeb;

interface HttpClient
{
    public function get(string $url, int $limit): Response;
}
