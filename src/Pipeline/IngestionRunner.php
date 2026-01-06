<?php
declare(strict_types=1);

namespace App\Pipeline;

use App\Databox\DataboxClient;
use App\Storage\SqliteStore;
use Psr\Log\LoggerInterface;

final class IngestionRunner
{
    private LoggerInterface $logger;
    private SqliteStore $store;
    private DataboxClient $databox;

    public function __construct(LoggerInterface $logger, SqliteStore $store, DataboxClient $databox)
    {
        $this->logger = $logger;
        $this->store = $store;
        $this->databox = $databox;
    }

    /**
     * @param array<string, array{0:object, 1:string}> $sourcesMap provider => [source, datasetId]
     */
    public function run(?string $from, ?string $to, array $sourcesMap): void
    {
        $runAt = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM);

        foreach ($sourcesMap as $provider => $pair) {
            [$source, $datasetId] = $pair;

            $this->logger->info('Ingestion start', ['provider' => $provider, 'from' => $from, 'to' => $to]);

            // last_run_at always updated even on failure (so you can see attempts)
            $state = $this->store->getState($provider);
            $this->store->upsertState($provider, $state['last_successful_date'] ?? null, $runAt);

            try {
                if (!method_exists($source, 'fetchDaily')) {
                    throw new \RuntimeException("Source for {$provider} missing fetchDaily()");
                }

                /** @var array<int, array{date:string, data:array<string,mixed>}> $records */
                $records = $source->fetchDaily($from, $to);


                // FLATTEN: {date, data:{...}} -> {date, ...}
                $records = array_map(static function (array $r): array {
                    $date = $r['date'] ?? null;
                    $data = $r['data'] ?? null;

                    if (!is_string($date) || !is_array($data)) {
                        return $r;
                    }

                    // NE pošiljaj "data" stolpca
                    return array_merge(['date' => $date], $data);
                }, $records);

                $records = $this->castRecordTypes($provider, $records);

                $this->validateRecords($provider, $records);

                $resp = $this->databox->ingest($datasetId, $records);

                $this->logger->info('Databox ingest OK', [
                    'provider' => $provider,
                    'dataset_id' => $datasetId,
                    'response' => $resp,
                    'records' => count($records),
                ]);

                // crude success date: max date from records
                $lastDate = $this->maxRecordDate($records);
                $this->store->upsertState($provider, $lastDate, $runAt);

            } catch (\Throwable $e) {
                $this->logger->error('Ingestion failed', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    /**
     * @param array<int, array<string,mixed>> $records
     * @return array<int, array<string,mixed>>
     */
    private function castRecordTypes(string $provider, array $records): array
    {
        $casts = match ($provider) {
            'strava' => [
                'activities_count' => 'int',
                'run_count' => 'int',
                'ride_count' => 'int',
                'walk_count' => 'int',
                'hike_count' => 'int',
                'distance_km' => 'float',
                'moving_time_min' => 'int',
                'elapsed_time_min' => 'int',
                'elevation_m' => 'float',
                'calories_kcal' => 'float',
            ],
            'weather' => [
                'tmax_c' => 'float',
                'tmin_c' => 'float',
                'precip_mm' => 'float',
            ],
            default => [],
        };

        foreach ($records as &$r) {
            foreach ($casts as $k => $t) {
                if (!array_key_exists($k, $r) || $r[$k] === null || $r[$k] === '') continue;

                // če slučajno pride kot string "12.3", ga normaliziraj
                if ($t === 'int')   $r[$k] = (int)$r[$k];
                if ($t === 'float') $r[$k] = (float)$r[$k];
            }
        }
        unset($r);

        return $records;
    }


    /**
     * @param array<int, array{date:string, data:array<string,mixed>}> $records
     */
    private function validateRecords(string $provider, array $records): void
    {
        if ($records === []) {
            $this->logger->warning("{$provider}: no records for this period");
            return;
        }
        foreach ($records as $i => $r) {
            if (!isset($r['date']) || !is_string($r['date'])) {
                throw new \RuntimeException("{$provider}: invalid record at index {$i} (missing date).");
            }
            if (!is_string($r['date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['date'])) {
                throw new \RuntimeException("{$provider}: invalid date format at index {$i} (expected YYYY-MM-DD).");
            }
        }
    }

    /**
     * @param array<int, array{date:string, data:array<string,mixed>}> $records
     */
    private function maxRecordDate(array $records): string
    {
        $max = '0000-00-00';
        foreach ($records as $r) {
            if ($r['date'] > $max) $max = $r['date'];
        }
        return $max;
    }
}
