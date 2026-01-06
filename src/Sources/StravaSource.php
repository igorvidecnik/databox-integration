<?php
declare(strict_types=1);

namespace App\Sources;

use App\Storage\SqliteStore;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

final class StravaSource
{
    private ClientInterface $http;
    private SqliteStore $store;
    private LoggerInterface $logger;

    /** @var array<string, mixed> */
    private array $env;

    private \DateTimeZone $localTz;

    public function __construct(ClientInterface $http, SqliteStore $store, LoggerInterface $logger, array $env)
    {
        $this->http = $http;
        $this->store = $store;
        $this->logger = $logger;
        $this->env = $env;

        $this->localTz = new \DateTimeZone('Europe/Ljubljana');
    }

    /**
     * Daily aggregation of Strava activities.
     *
     * Input:
     *  - $from, $to: "YYYY-MM-DD" (optional)
     *  - if omitted: last 30 days up to today
     * @return array<int, array{date:string, data:array<string, mixed>}>
     */
    public function fetchDaily(?string $from, ?string $to): array
    {
        [$fromDate, $toDate] = $this->normalizeDateRange($from, $to);


        $afterEpoch  = $fromDate->setTime(0, 0, 0)->getTimestamp();
        $beforeEpoch = $toDate->modify('+1 day')->setTime(0, 0, 0)->getTimestamp();

        $accessToken = $this->getValidAccessToken();

        $activities = $this->listActivities($accessToken, $afterEpoch, $beforeEpoch);

        // DEBUG: pokaži prvo aktivnost
        //$this->logger->info('Strava sample activity', $activities[0] ?? []);

        // Prepare day buckets with zeros (ensures continuity)
        $buckets = [];
        for ($d = $fromDate; $d <= $toDate; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $buckets[$key] = $this->emptyDay();
        }

        // Aggregate
        foreach ($activities as $a) {

            $startLocal = $a['start_date_local'] ?? null;
            $dt = $this->parseStravaDateTime($startLocal ?: ($a['start_date'] ?? null));

            // Group by local date
            $localDate = $dt->setTimezone($this->localTz)->format('Y-m-d');

            if (!isset($buckets[$localDate])) {
                continue;
            }

            $type = (string)($a['type'] ?? 'Unknown');

            $buckets[$localDate]['activities_count'] += 1;
            $buckets[$localDate]['distance_m'] += (float)($a['distance'] ?? 0.0);
            $buckets[$localDate]['moving_time_s'] += (int)($a['moving_time'] ?? 0);
            $buckets[$localDate]['elapsed_time_s'] += (int)($a['elapsed_time'] ?? 0);
            $buckets[$localDate]['elevation_m'] += (float)($a['total_elevation_gain'] ?? 0.0);

            // Optional calories (niso vedno prisotne)
            $cal = null;
            if (isset($a['calories']) && is_numeric($a['calories'])) {
                $cal = (float)$a['calories'];
            } else {
                // ocena kalorij brez dodatnega API klica za detail
                $cal = $this->estimateCaloriesKcal($a);
            }

            $buckets[$localDate]['calories_kcal'] += (float)$cal;

            // Type counters
            if ($type === 'Run') $buckets[$localDate]['run_count'] += 1;
            if ($type === 'Ride') $buckets[$localDate]['ride_count'] += 1;
            if ($type === 'Walk') $buckets[$localDate]['walk_count'] += 1;
            if ($type === 'Hike') $buckets[$localDate]['hike_count'] += 1;
        }

        // Convert to Databox-friendly
        $records = [];
        foreach ($buckets as $date => $m) {
            $records[] = [
                'date' => $date,
                'data' => [
                    'activities_count' => (int)$m['activities_count'],
                    'run_count' => (int)$m['run_count'],
                    'ride_count' => (int)$m['ride_count'],
                    'walk_count' => (int)$m['walk_count'],
                    'hike_count' => (int)$m['hike_count'],

                    'distance_km' => round(((float)$m['distance_m']) / 1000.0, 3),
                    'moving_time_min' => (int) round(((int)$m['moving_time_s']) / 60),
                    'elapsed_time_min' => (int) round(((int)$m['elapsed_time_s']) / 60),
                    'elevation_m' => round((float)$m['elevation_m'], 1),

                    // calories may be 0 if not present
                    'calories_kcal' => round((float)$m['calories_kcal'], 0),
                ],
            ];
        }

        $this->logger->info('Strava daily aggregation OK', [
            'from' => $fromDate->format('Y-m-d'),
            'to' => $toDate->format('Y-m-d'),
            'days' => count($records),
            'activities_fetched' => count($activities),
        ]);

        return $records;
    }

    /** @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} */
    private function normalizeDateRange(?string $from, ?string $to): array
    {
        $today = (new \DateTimeImmutable('now', $this->localTz))->setTime(0, 0, 0);

        if ($from === null || trim($from) === '') {
            $fromDate = $today->modify('-29 days'); // 30 days window
        } else {
            $fromDate = $this->parseDate($from);
        }

        if ($to === null || trim($to) === '') {
            $toDate = $today;
        } else {
            $toDate = $this->parseDate($to);
        }

        if ($toDate < $fromDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        return [$fromDate, $toDate];
    }

    private function parseDate(string $ymd): \DateTimeImmutable
    {
        $ymd = trim($ymd);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            throw new \InvalidArgumentException("Invalid date '{$ymd}' (expected YYYY-MM-DD).");
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $ymd, $this->localTz);
        if (!$dt) {
            throw new \InvalidArgumentException("Failed to parse date '{$ymd}'.");
        }
        return $dt->setTime(0, 0, 0);
    }

    private function parseStravaDateTime(?string $iso): \DateTimeImmutable
    {
        if ($iso === null || trim($iso) === '') {
            // fallback to "now"
            return new \DateTimeImmutable('now', $this->localTz);
        }

        try {
            // Strava returns ISO 8601
            return new \DateTimeImmutable($iso);
        } catch (\Throwable) {
            return new \DateTimeImmutable('now', $this->localTz);
        }
    }

    /** @return array<string, mixed> */
    private function emptyDay(): array
    {
        return [
            'activities_count' => 0,
            'run_count' => 0,
            'ride_count' => 0,
            'walk_count' => 0,
            'hike_count' => 0,

            'distance_m' => 0.0,
            'moving_time_s' => 0,
            'elapsed_time_s' => 0,
            'elevation_m' => 0.0,
            'calories_kcal' => 0.0,
        ];
    }

    /**
     * Fetch activities in a time window (after/before) with pagination.
     *
     * @return array<int, array<string, mixed>>
     */
    private function listActivities(string $accessToken, int $afterEpoch, int $beforeEpoch): array
    {
        $perPage = 200; // max Strava supports
        $page = 1;
        $out = [];

        while (true) {
            $url = 'https://www.strava.com/api/v3/athlete/activities';

            try {
                $res = $this->http->request('GET', $url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                    ],
                    'query' => [
                        'after' => $afterEpoch,
                        'before' => $beforeEpoch,
                        'page' => $page,
                        'per_page' => $perPage,
                    ],
                    'timeout' => 20,
                ]);
            } catch (GuzzleException $e) {
                throw new \RuntimeException('Strava activities request failed: ' . $e->getMessage(), 0, $e);
            }

            $data = json_decode((string)$res->getBody(), true);
            if (!is_array($data)) {
                throw new \RuntimeException('Strava activities response is not JSON array.');
            }

            $count = count($data);
            if ($count === 0) {
                break;
            }

            foreach ($data as $item) {
                if (is_array($item)) $out[] = $item;
            }

            // Last page?
            if ($count < $perPage) {
                break;
            }

            $page += 1;

            // Safety: prevent runaway loops
            if ($page > 50) {
                $this->logger->warning('Strava pagination safety break', ['page' => $page]);
                break;
            }
        }

        return $out;
    }

    private function getValidAccessToken(): string
    {
        $row = $this->store->getOAuthToken('strava');
        if ($row === null) {
            throw new \RuntimeException('No Strava token found in DB (oauth_tokens). Connect Strava first.');
        }

        $access = $row['access_token'];
        $refresh = $row['refresh_token'];
        $expiresAt = (int)$row['expires_at'];

        if ($access === '' || $refresh === '' || $expiresAt <= 0) {
            throw new \RuntimeException('Invalid Strava token row in DB.');
        }

        // refresh 60s early
        if ($expiresAt <= time() + 60) {
            $this->logger->info('Refreshing Strava token', ['expires_at' => $expiresAt]);
            $new = $this->refreshToken($refresh);
            $this->store->saveOAuthToken('strava', $new['access_token'], $new['refresh_token'], $new['expires_at']);
            return $new['access_token'];
        }

        return $access;
    }

    /** @return array{access_token:string, refresh_token:string, expires_at:int} */
    private function refreshToken(string $refreshToken): array
    {
        $clientId = trim((string)($this->env['STRAVA_CLIENT_ID'] ?? ''));
        $clientSecret = trim((string)($this->env['STRAVA_CLIENT_SECRET'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('Missing STRAVA_CLIENT_ID or STRAVA_CLIENT_SECRET in .env');
        }

        try {
            $res = $this->http->request('POST', 'https://www.strava.com/oauth/token', [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'form_params' => [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
                'timeout' => 20,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Strava refresh request failed: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string)$res->getBody(), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Strava refresh response is not JSON.');
        }
        if (!isset($data['access_token'], $data['refresh_token'], $data['expires_at'])) {
            throw new \RuntimeException('Strava refresh response missing required fields.');
        }

        return [
            'access_token' => (string)$data['access_token'],
            'refresh_token' => (string)$data['refresh_token'],
            'expires_at' => (int)$data['expires_at'],
        ];
    }

    private function estimateCaloriesKcal(array $a): float
    {
        $weightKg = (float)($this->env['USER_WEIGHT_KG'] ?? 0);
        if ($weightKg <= 0) {
            return 0.0; // brez teže ne ugibamo :)
        }

        $type = (string)($a['type'] ?? '');
        $seconds = (int)($a['moving_time'] ?? 0);
        if ($seconds <= 0) return 0.0;

        $hours = $seconds / 3600.0;

        // HR-based estimate (boljša), če imamo average_heartrate
        $hasHr = (bool)($a['has_heartrate'] ?? false);
        $avgHr = $a['average_heartrate'] ?? null;

        if ($hasHr && is_numeric($avgHr) && (float)$avgHr > 0) {
            $hr = (float)$avgHr;
            $age = (int)($this->env['USER_AGE'] ?? 0);

            // formula za približek: kcal/min ≈ (0.6309*HR + 0.1988*W + 0.2017*A - 55.0969)/4.184
            $aYears = $age > 0 ? $age : 30;

            $kcalPerMin = (0.6309 * $hr + 0.1988 * $weightKg + 0.2017 * $aYears - 55.0969) / 4.184;
            if ($kcalPerMin < 0) $kcalPerMin = 0;

            $kcal = $kcalPerMin * ($seconds / 60.0);

            $capMet = $this->defaultMET($type);
            $cap = $capMet * $weightKg * $hours;
  
            return min($kcal, $cap * 1.6);
        }

        $met = $this->defaultMET($type);

        return $met * $weightKg * $hours;
    }

    private function defaultMET(string $type): float
    {
        // Konzervativne MET vrednosti (približki)
        return match ($type) {
            'Run'  => 9.8,  // zmeren tek
            'Ride' => 7.5,  // zmerno kolesarjenje
            'Hike' => 6.0,  // pohod
            'Walk' => 3.5,  // hoja
            default => 5.0, // splošno "active"
        };
    }

}
