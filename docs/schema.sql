CREATE TABLE IF NOT EXISTS oauth_tokens (
    provider TEXT PRIMARY KEY,
    access_token TEXT NOT NULL,
    refresh_token TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS ingestion_state (
    provider TEXT PRIMARY KEY,
    last_successful_date TEXT,
    last_run_at TEXT
);
