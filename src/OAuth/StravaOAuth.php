<?php
declare(strict_types=1);

namespace App\OAuth;

use App\Storage\SqliteStore;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

final class StravaOAuth
{
    private const AUTH_URL  = 'https://www.strava.com/oauth/authorize';
    private const TOKEN_URL = 'https://www.strava.com/oauth/token';

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
            'scope'           => 'read,activity:read_all',
            'state'           => $state,
        ]);

        return self::AUTH_URL . '?' . $params;
    }

    /**
     * Exchanges auth code for access+refresh token and stores them in SQLite.
     *
     * @return array<string, mixed> Minimal safe payload (no tokens)
     */
    public function exchangeCodeForToken(string $code): array
    {
        $resp = $this->http->post(self::TOKEN_URL, [
            'form_params' => [
                'client_id'     => $this->env['STRAVA_CLIENT_ID'],
                'client_secret' => $this->env['STRAVA_CLIENT_SECRET'],
                'code'          => $code,
                'grant_type'    => 'authorization_code',
            ],
            'timeout' => 20,
        ]);

        /** @var array<string, mixed> $json */
        $json = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (
            !isset($json['access_token'], $json['refresh_token'], $json['expires_at']) ||
            !is_string($json['access_token']) ||
            !is_string($json['refresh_token'])
        ) {
            throw new \RuntimeException('Strava token response missing expected fields.');
        }

        $this->store->saveToken(
            'strava',
            $json['access_token'],
            $json['refresh_token'],
            (int) $json['expires_at']
        );

        $this->logger->info('Strava token saved', [
            'provider'   => 'strava',
            'expires_at' => $json['expires_at'] ?? null,
            'scope'      => $json['scope'] ?? null,
            'athlete_id' => $json['athlete']['id'] ?? null,
        ]);

        // Return only non-sensitive metadata
        return [
            'provider'   => 'strava',
            'expires_at' => $json['expires_at'] ?? null,
            'scope'      => $json['scope'] ?? null,
            'athlete_id' => $json['athlete']['id'] ?? null,
        ];
    }

    public function getValidAccessToken(): string
    {
        $row = $this->store->getToken('strava');
        if (!$row) {
            throw new \RuntimeException('No Strava token found. Visit /auth/strava first.');
        }

        $expiresAt = (int) $row['expires_at'];
        $now = time();

        // Refresh if expiring within the next hour
        if ($expiresAt - $now <= 3600) {
            $this->refreshToken((string) $row['refresh_token']);

            $row = $this->store->getToken('strava');
            if (!$row) {
                throw new \RuntimeException('Token refresh failed and token row missing.');
            }
        }

        return (string) $row['access_token'];
    }

    /**
     * Refreshes token and stores the updated token pair in SQLite.
     *
     * @return array<string, mixed> Minimal safe payload (no tokens)
     */
    public function refreshToken(string $refreshToken): array
    {
        $resp = $this->http->post(self::TOKEN_URL, [
            'form_params' => [
                'client_id'     => $this->env['STRAVA_CLIENT_ID'],
                'client_secret' => $this->env['STRAVA_CLIENT_SECRET'],
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
            ],
            'timeout' => 20,
        ]);

        /** @var array<string, mixed> $json */
        $json = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (
            !isset($json['access_token'], $json['refresh_token'], $json['expires_at']) ||
            !is_string($json['access_token']) ||
            !is_string($json['refresh_token'])
        ) {
            throw new \RuntimeException('Strava refresh response missing expected fields.');
        }

        // Strava can rotate refresh_token - always persist the newest
        $this->store->saveToken(
            'strava',
            $json['access_token'],
            $json['refresh_token'],
            (int) $json['expires_at']
        );

        $this->logger->info('Strava token refreshed', [
            'provider'   => 'strava',
            'expires_at' => $json['expires_at'] ?? null,
        ]);

        // Return only non-sensitive metadata
        return [
            'provider'   => 'strava',
            'expires_at' => $json['expires_at'] ?? null,
        ];
    }
}
