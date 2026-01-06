<?php
declare(strict_types=1);

namespace Tests\Validation;

use App\Sources\StravaSource;
use App\Storage\SqliteStore;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DateFormatTest extends TestCase
{
    public function test_invalid_date_format_throws_exception(): void
    {
        $http = new Client(); // ne bo poklican
        $store = new SqliteStore(':memory:');
        $store->init();
        $logger = new NullLogger();

        $source = new StravaSource($http, $store, $logger, [
            'STRAVA_CLIENT_ID' => 'x',
            'STRAVA_CLIENT_SECRET' => 'y',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid date");

        // invalid date format → must fail before any API call
        $source->fetchDaily('2026-1-1', '2026-01-05');
    }
}
