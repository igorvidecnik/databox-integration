<?php

//die("uporabi samo za kreiranje datasetov");

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

Dotenv::createImmutable(__DIR__ . '/..')->load();

$apiKey = $_ENV['DATABOX_TOKEN'] ?? null;
if (!$apiKey) {
    exit("Missing DATABOX_TOKEN in .env\n");
}

$http = new Client([
    'base_uri' => 'https://api.databox.com/v1/',
    'headers' => [
        'x-api-key' => $apiKey,
        'Content-Type' => 'application/json'
    ],
]);

function post(Client $http, string $uri, array $json)
{
    $res = $http->post($uri, ['json' => $json]);
    return json_decode((string)$res->getBody(), true);
}

/* 1) Create Data Source */
$ds = post($http, 'data-sources', [
    'title' => 'Databox Integration – PHP',
    'timezone' => 'Europe/Zagreb'
]);

$dataSourceId = $ds['id'];

/* 2) Create Strava dataset */
$strava = post($http, 'datasets', [
    'title' => 'Strava Daily',
    'dataSourceId' => $dataSourceId,
    'primaryKeys' => ['date']
]);

$stravaId = $strava['id'];

/* 3) Create Weather dataset */
$weather = post($http, 'datasets', [
    'title' => 'Weather Daily',
    'dataSourceId' => $dataSourceId,
    'primaryKeys' => ['date']
]);

$weatherId = $weather['id'];

/* 4) Seed Strava */
post($http, "datasets/{$stravaId}/data", [
    'records' => [[
        'date' => date('Y-m-d'),
        'activities_count' => 0,
        'run_count' => 0,
        'ride_count' => 0,
        'walk_count' => 0,
        'hike_count' => 0,
        'distance_km' => 0,
        'moving_time_min' => 0,
        'elapsed_time_min' => 0,
        'elevation_m' => 0,
        'calories_kcal' => 0,
    ]]
]);

/* 5) Seed Weather */
post($http, "datasets/{$weatherId}/data", [
    'records' => [[
        'date' => date('Y-m-d'),
        'tmax_c' => 0,
        'tmin_c' => 0,
        'precip_mm' => 0,
    ]]
]);

echo "\n=== ADD TO .env ===\n";
echo "DATABOX_DATASET_STRAVA={$stravaId}\n";
echo "DATABOX_DATASET_WEATHER={$weatherId}\n";
