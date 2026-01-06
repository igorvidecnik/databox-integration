<?php
declare(strict_types=1);

namespace App\OAuth;

use App\Storage\SqliteStore;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

final class StravaOAuth
{
    private const AUTH_URL  = 'https://www.strava.com/oauth/authorize';
    private const TOKEN_URL = 'https://www.strava.com/oauth/token'; // Strava docs navajajo ta endpoint :contentReference[oaicite:3]{index=3}

    public function __construct(
        private Client $http,
        private SqliteStore $store,
        private LoggerInterface $logger,
        private array $env
    ) {}

    public function buildAuthorizeUrl(string $state): string
    {
        $params = http_build_query([
            'client_id'       => $this->env['STRAVA_CLIENT_ID'],
            'redirect_uri'    => $this->env['STRAVA_REDIRECT_URI'],
            'response_type'   => 'code',
            'approval_prompt' => 'auto',
            // Za branje aktivnosti priporočam activity:read_all (če želiš tudi private) :contentReference[oaicite:4]{index=4}
            'scope'           => 'read,activity:read_all',
            'state'           => $state,
        ]);

        return self::AUTH_URL . '?' . $params;
    }

    public function exchangeCodeForToken(string $code): array
    {
        // grant_type=authorization_code :contentReference[oaicite:5]{index=5}
        $resp = $this->http->post(self::TOKEN_URL, [
            'form_params' => [
                'client_id'     => $this->env['STRAVA_CLIENT_ID'],
                'client_secret' => $this->env['STRAVA_CLIENT_SECRET'],
                'code'          => $code,
                'grant_type'    => 'authorization_code',
            ],
            'timeout' => 20,
        ]);

        /** @var array $json */
        $json = json_decode((string)$resp->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->store->saveToken(
            'strava',
            $json['access_token'],
            $json['refresh_token'],
            (int)$json['expires_at']
        );

        $this->logger->info('Strava token saved', [
            'provider' => 'strava',
            'expires_at' => $json['expires_at'] ?? null,
            'scope' => $json['scope'] ?? null,
            'athlete_id' => $json['athlete']['id'] ?? null,
        ]);

        return $json;
    }

    public function getValidAccessToken(): string
    {
        $row = $this->store->getToken('strava');
        if (!$row) {
            throw new \RuntimeException('No Strava token found. Visit /auth/strava first.');
        }

        $expiresAt = (int)$row['expires_at'];
        $now = time();

        // Refresh če poteče v naslednji 1 uri (Strava docs to omenjajo kot priporočilo) :contentReference[oaicite:6]{index=6}
        if ($expiresAt - $now <= 3600) {
            $this->refreshToken($row['refresh_token']);
            $row = $this->store->getToken('strava');
            if (!$row) {
                throw new \RuntimeException('Token refresh failed and token row missing.');
            }
        }

        return $row['access_token'];
    }

    public function refreshToken(string $refreshToken): array
    {
        // grant_type=refresh_token :contentReference[oaicite:7]{index=7}
        $resp = $this->http->post(self::TOKEN_URL, [
            'form_params' => [
                'client_id'     => $this->env['STRAVA_CLIENT_ID'],
                'client_secret' => $this->env['STRAVA_CLIENT_SECRET'],
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
            'timeout' => 20,
        ]);

        /** @var array $json */
        $json = json_decode((string)$resp->getBody(), true, 512, JSON_THROW_ON_ERROR);

        // Strava lahko vrne NOV refresh_token; starega invalidira. Vedno shrani zadnjega. :contentReference[oaicite:8]{index=8}
        $this->store->saveToken(
            'strava',
            $json['access_token'],
            $json['refresh_token'],
            (int)$json['expires_at']
        );

        $this->logger->info('Strava token refreshed', [
            'provider' => 'strava',
            'expires_at' => $json['expires_at'] ?? null,
        ]);

        return $json;
    }
}
