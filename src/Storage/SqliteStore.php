<?php
declare(strict_types=1);

namespace App\Storage;

use PDO;

final class SqliteStore
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Recommended for SQLite
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
    }

    public function init(): void
    {
        // oauth_tokens: provider should be UNIQUE so we can UPSERT
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS oauth_tokens (
                provider TEXT PRIMARY KEY,
                access_token TEXT NOT NULL,
                refresh_token TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            );
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ingestion_state (
                provider TEXT PRIMARY KEY,
                last_successful_date TEXT NULL,
                last_run_at TEXT NULL
            );
        ");
    }

    /** @return array{provider:string, access_token:string, refresh_token:string, expires_at:int}|null */
    public function getOAuthToken(string $provider): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT provider, access_token, refresh_token, expires_at
             FROM oauth_tokens
             WHERE provider = :p
             LIMIT 1'
        );
        $stmt->execute([':p' => $provider]);
        $row = $stmt->fetch();

        if (!$row) return null;

        return [
            'provider' => (string)$row['provider'],
            'access_token' => (string)$row['access_token'],
            'refresh_token' => (string)$row['refresh_token'],
            'expires_at' => (int)$row['expires_at'],
        ];
    }

    public function saveOAuthToken(string $provider, string $accessToken, string $refreshToken, int $expiresAt): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO oauth_tokens(provider, access_token, refresh_token, expires_at)
            VALUES(:p,:a,:r,:e)
            ON CONFLICT(provider) DO UPDATE SET
                access_token=excluded.access_token,
                refresh_token=excluded.refresh_token,
                expires_at=excluded.expires_at
        ");

        $stmt->execute([
            ':p' => $provider,
            ':a' => $accessToken,
            ':r' => $refreshToken,
            ':e' => $expiresAt,
        ]);
    }

    /** @return array{provider:string, last_successful_date:?string, last_run_at:?string}|null */
    public function getState(string $provider): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT provider, last_successful_date, last_run_at
             FROM ingestion_state
             WHERE provider = :p
             LIMIT 1'
        );
        $stmt->execute([':p' => $provider]);
        $row = $stmt->fetch();

        if (!$row) return null;

        return [
            'provider' => (string)$row['provider'],
            'last_successful_date' => $row['last_successful_date'] !== null ? (string)$row['last_successful_date'] : null,
            'last_run_at' => $row['last_run_at'] !== null ? (string)$row['last_run_at'] : null,
        ];
    }

    public function upsertState(string $provider, ?string $lastSuccessfulDate, ?string $lastRunAt): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ingestion_state(provider, last_successful_date, last_run_at)
            VALUES(:p,:d,:r)
            ON CONFLICT(provider) DO UPDATE SET
                last_successful_date=excluded.last_successful_date,
                last_run_at=excluded.last_run_at
        ");

        $stmt->execute([
            ':p' => $provider,
            ':d' => $lastSuccessfulDate,
            ':r' => $lastRunAt,
        ]);
    }

    // Optional: if you ever need raw access (debug)
    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
