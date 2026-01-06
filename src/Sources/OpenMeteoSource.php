<?php
declare(strict_types=1);

namespace App\Sources;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

final class OpenMeteoSource
{
    private ClientInterface $http;
    private LoggerInterface $logger;

    /** @var array<string, mixed> */
    private array $env;

    public function __construct(ClientInterface $http, LoggerInterface $logger, array $env)
    {
        $this->http = $http;
        $this->logger = $logger;
        $this->env = $env;
    }

    /**
     * @return array<int, array{date:string, data:array<string, mixed>}>
     */
public function fetchDaily(?string $from, ?string $to): array
{
    $lat = trim((string)($this->env['OPEN_METEO_LAT'] ?? ''));
    $lon = trim((string)($this->env['OPEN_METEO_LON'] ?? ''));

    $tz = 'Europe/Zagreb';
    $localTz = new \DateTimeZone($tz);

    // default: today only
    $today = (new \DateTimeImmutable('now', $localTz))->format('Y-m-d');
    $fromDate = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : $today;
    $toDate   = $to   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to   : $today;

    if ($lat === '' || $lon === '') {
        $this->logger->warning('OpenMeteo skipped: missing OPEN_METEO_LAT/OPEN_METEO_LON');
        // Return the full date range with nulls (Databox-friendly continuity)
        return $this->emptyRangeRecords($fromDate, $toDate);
    }

    $url = 'https://api.open-meteo.com/v1/forecast';
    $query = [
        'latitude' => $lat,
        'longitude' => $lon,
        'timezone' => $tz,
        'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_sum',
        'start_date' => $fromDate,
        'end_date' => $toDate,
    ];

    try {
        $res = $this->http->request('GET', $url, [
            'query' => $query,
            'timeout' => 20,
        ]);
    } catch (RequestException $e) {

        $msg = $e->getMessage();

        if (str_contains($msg, "out of allowed range")) {
            $this->logger->warning('Open-Meteo date range not supported', [
                'provider' => 'open-meteo',
                'requested_from' => $query['start_date'] ?? null,
                'requested_to' => $query['end_date'] ?? null,
                'note' => 'No data ingested for this period'
            ]);

            return $this->emptyRangeRecords($fromDate, $toDate);
        }

        throw new \RuntimeException('Open-Meteo request failed: ' . $msg, 0, $e);
    }

    $data = json_decode((string)$res->getBody(), true);
    if (!is_array($data)) {
        throw new \RuntimeException('Open-Meteo response is not JSON.');
    }

    $daily = $data['daily'] ?? null;
    if (!is_array($daily) || !isset($daily['time']) || !is_array($daily['time'])) {
        throw new \RuntimeException('Open-Meteo response missing daily data.');
    }

    $times = $daily['time'];
    $tMaxs = $daily['temperature_2m_max'] ?? [];
    $tMins = $daily['temperature_2m_min'] ?? [];
    $precs = $daily['precipitation_sum'] ?? [];

    $records = [];
    foreach ($times as $i => $date) {
        $tMax = $tMaxs[$i] ?? null;
        $tMin = $tMins[$i] ?? null;
        $prec = $precs[$i] ?? null;

        $records[] = [
            'date' => (string)$date,
            'data' => [
                'tmax_c'    => is_numeric($tMax) ? (float)$tMax : null,
                'tmin_c'    => is_numeric($tMin) ? (float)$tMin : null,
                'precip_mm' => is_numeric($prec) ? (float)$prec : null,
            ],
        ];
    }

    // Ensure continuity (if API returns fewer days for any reason)
    $records = $this->fillMissingDates($fromDate, $toDate, $records);

    $this->logger->info('OpenMeteo OK', ['from' => $fromDate, 'to' => $toDate, 'days' => count($records)]);

    return $records;
}

/**
 * @return array<int, array{date:string, data:array<string, mixed>}>
 */
private function emptyRangeRecords(string $fromDate, string $toDate): array
{
    return $this->fillMissingDates($fromDate, $toDate, []);
}

/**
 * @param array<int, array{date:string, data:array<string, mixed>}> $records
 * @return array<int, array{date:string, data:array<string, mixed>}>
 */
private function fillMissingDates(string $fromDate, string $toDate, array $records): array
{
    $map = [];
    foreach ($records as $r) {
        $map[$r['date']] = $r;
    }

    $tz = new \DateTimeZone('Europe/Zagreb');
    $from = \DateTimeImmutable::createFromFormat('Y-m-d', $fromDate, $tz)->setTime(0, 0);
    $to = \DateTimeImmutable::createFromFormat('Y-m-d', $toDate, $tz)->setTime(0, 0);

    $out = [];
    for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
        $key = $d->format('Y-m-d');
        $out[] = $map[$key] ?? [
            'date' => $key,
            'data' => [
                'tmax_c' => null,
                'tmin_c' => null,
                'precip_mm' => null,
            ],
        ];
    }

    return $out;
}



}
