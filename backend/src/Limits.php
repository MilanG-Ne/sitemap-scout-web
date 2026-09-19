<?php
declare(strict_types=1);
namespace ScoutWeb;

final class Limits
{
    public const URLS = 25;
    public const SITEMAPS = 3;
    public const XML_BYTES = 1_048_576;
    public const HTML_BYTES = 262_144;
    public const REQUEST_SECONDS = 8;
    public const JOB_SECONDS = 900;
    public const RETENTION_SECONDS = 3600;
    public const SLOW_MS = 1000;
    public const GLOBAL_DAILY = 150;
    public const IP_DAILY = 20;
    public const IP_HOURLY = 5;
    public const ACTIVE_JOBS = 3;
    public const FINDINGS = 100;
}
