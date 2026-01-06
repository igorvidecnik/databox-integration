<?php
declare(strict_types=1);

namespace App\Databox;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

final class DataboxClient
{
    private ClientInterface $http;
    private string $apiKey;

    public function __construct(ClientInterface $http, string $apiKey)
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('DATABOX_TOKEN (x-api-key) is missing/empty.');
        }

        $this->http = $http;
        $this->apiKey = $apiKey;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{ingestionId?: string, status?: string, requestId?: string, message?: string}
     */
    /*
    public function ingest(string $datasetId, array $records): array
    {
        $datasetId = trim($datasetId);
        if ($datasetId === '') {
            throw new \InvalidArgumentException('datasetId is missing/empty.');
        }

        // Databox expects: { "records": [ ... ] }
        $payload = ['records' => array_values($records)];

        try {
            $res = $this->http->request('POST', $this->datasetDataUrl($datasetId), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key'     => $this->apiKey,
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Databox ingest request failed: ' . $e->getMessage(), 0, $e);
        }

        return $this->decodeJson($res);
    }
    */

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<string, mixed>
     */
    public function ingest(string $datasetId, array $records): array
    {
        $datasetId = trim($datasetId);
        if ($datasetId === '') {
            throw new \InvalidArgumentException('datasetId is missing/empty.');
        }

        $records = array_values($records);

        // Databox hard limit: 100 records per request
        $maxBatch = 100;

        // Short-circuit
        if ($records === []) {
            return [
                'batches' => 0,
                'totalRecords' => 0,
                'results' => [],
            ];
        }

        $results = [];
        foreach (array_chunk($records, $maxBatch) as $batchIndex => $batch) {
            $payload = ['records' => $batch];

            try {
                $res = $this->http->request('POST', $this->datasetDataUrl($datasetId), [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-api-key'     => $this->apiKey,
                    ],
                    'json' => $payload,
                ]);
            } catch (GuzzleException $e) {
                throw new \RuntimeException(
                    "Databox ingest request failed (batch {$batchIndex}, size " . count($batch) . "): " . $e->getMessage(),
                    0,
                    $e
                );
            }

            $decoded = $this->decodeJson($res);

            $results[] = [
                'batch' => $batchIndex,
                'batchSize' => count($batch),
                'response' => $decoded,
            ];
        }

        return [
            'batches' => count($results),
            'totalRecords' => count($records),
            'results' => $results,
        ];
    }


    /**
     * Optional: check ingestion status.
     * @return array<string, mixed>
     */
    public function getIngestion(string $datasetId, string $ingestionId): array
    {
        try {
            $res = $this->http->request('GET', $this->datasetIngestionUrl($datasetId, $ingestionId), [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Databox getIngestion failed: ' . $e->getMessage(), 0, $e);
        }

        return $this->decodeJson($res);
    }

    private function datasetDataUrl(string $datasetId): string
    {
        // API v1 endpoint:
        // POST https://api.databox.com/v1/datasets/{datasetId}/data :contentReference[oaicite:1]{index=1}
        return 'https://api.databox.com/v1/datasets/' . rawurlencode($datasetId) . '/data';
    }

    private function datasetIngestionUrl(string $datasetId, string $ingestionId): string
    {
        // GET https://api.databox.com/v1/datasets/{datasetId}/ingestions/{ingestionId} :contentReference[oaicite:2]{index=2}
        return 'https://api.databox.com/v1/datasets/' . rawurlencode($datasetId)
            . '/ingestions/' . rawurlencode($ingestionId);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(ResponseInterface $res): array
    {
        $body = (string) $res->getBody();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new \RuntimeException('Databox response is not valid JSON. HTTP ' . $res->getStatusCode() . ' Body: ' . $body);
        }

        // Helpful fail-fast if Databox returns error payload with 200/4xx etc.
        if (($data['status'] ?? null) === 'error') {
            throw new \RuntimeException('Databox API returned error: ' . ($data['message'] ?? 'unknown'));
        }

        return $data;
    }
}
