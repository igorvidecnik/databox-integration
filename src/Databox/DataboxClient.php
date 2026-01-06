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
     * Push records into Databox dataset in batches of 100 records/request (Databox limit).
     *
     * @param array<int, array<string, mixed>> $records
     * @return array{
     *   batches:int,
     *   totalRecords:int,
     *   results: array<int, array{batch:int, batchSize:int, response: array<string,mixed>}>
     * }
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
     *
     * @return array<string, mixed>
     */
    public function getIngestion(string $datasetId, string $ingestionId): array
    {
        $datasetId = trim($datasetId);
        if ($datasetId === '') {
            throw new \InvalidArgumentException('datasetId is missing/empty.');
        }

        $ingestionId = trim($ingestionId);
        if ($ingestionId === '') {
            throw new \InvalidArgumentException('ingestionId is missing/empty.');
        }

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
        // POST https://api.databox.com/v1/datasets/{datasetId}/data
        return 'https://api.databox.com/v1/datasets/' . rawurlencode($datasetId) . '/data';
    }

    private function datasetIngestionUrl(string $datasetId, string $ingestionId): string
    {
        // GET https://api.databox.com/v1/datasets/{datasetId}/ingestions/{ingestionId}
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
            $snippet = mb_substr($body, 0, 2048);
            throw new \RuntimeException(
                'Databox response is not valid JSON. HTTP ' . $res->getStatusCode() . ' Body (first 2KB): ' . $snippet
            );
        }

        // Helpful fail-fast if Databox returns an error payload (sometimes even with 200)
        if (($data['status'] ?? null) === 'error') {
            throw new \RuntimeException('Databox API returned error: ' . ($data['message'] ?? 'unknown'));
        }

        return $data;
    }
}
