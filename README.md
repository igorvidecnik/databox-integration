# Databox Integration (Strava + Open-Meteo)

**Production-grade PHP ingestion pipeline for personal performance & weather analytics**

This service:
- fetches daily data from **Strava API** and **Open-Meteo API**,
- aggregates it into **daily datasets**,
- pushes the data into **Databox Ingestion API**,
- enables visualization via **Databox Metrics & Dashboards**.

---

## Tech Stack

- **PHP 8.3** (CLI)
- **SQLite** — runtime state + OAuth token storage
- **Guzzle** — HTTP client for external APIs
- **Monolog** — structured JSONL logging (`logs/app.jsonl`)
- **Dotenv** — environment configuration (`.env`)
- **PHPUnit** — automated testing framework
- Entry point: `php bin/ingest`

---

## Project Structure

```
databox-integration/
├─ bin/
│  ├─ ingest
│  └─ setup_databox.php
├─ public/
│  └─ index.php
├─ src/
│  ├─ OAuth/StravaOAuth.php
│  ├─ Storage/SqliteStore.php
│  ├─ Sources/StravaSource.php
│  ├─ Sources/OpenMeteoSource.php
│  ├─ Pipeline/IngestionRunner.php
│  ├─ Databox/DataboxClient.php
│  └─ Logging/LoggerFactory.php
├─ data/app.db
├─ logs/app.jsonl
├─ docs/
│  ├─ schema.md
│  └─ mapping.md
└─ .env
```

---

## Setup

### 1) Requirements

- PHP 8.3
- Composer

### 2) Install

```bash
composer install
cp .env.example .env
```

### 3) Strava OAuth

Configure your Strava application with the redirect URI defined in `STRAVA_REDIRECT_URI`.

For local OAuth callback:

```bash
php -S localhost:8000 -t public
```

Complete the OAuth flow in the browser.  
Tokens are securely stored in SQLite (`oauth_tokens` table).

### 4) Databox

Create a **Push Token** in Databox and set it in `.env`:

```
DATABOX_TOKEN=...
```

Dataset structure is documented in `docs/schema.md`.

---

## Running Ingestion

Run ingestion without arguments (defaults to last 30 days):

```bash
php bin/ingest
```

Run ingestion for a specific date range:

```bash
php bin/ingest 2025-12-01 2026-01-04
```
Both dates must be in YYYY-MM-DD format.
If an invalid format is provided, the process fails fast before calling any external APIs.

### Dry-Run Mode

If Databox is not configured or:

```
INGEST_DRY_RUN=1
```

the pipeline executes fully **without sending data** to Databox.

---

## Architecture Notes

- **Dataset** — raw storage layer
- **Metric** — business logic layer
- **Dashboard** — presentation layer
- Rolling metrics (e.g. 7-day averages) are computed **inside the pipeline**
- Ingestion is **idempotent** via persistent ingestion state
- External API failures are handled via **graceful degradation**

---

## Logging & Security

Structured JSON logs are written to:

```
logs/app.jsonl
```

Security features:

- Sensitive values are automatically **redacted** from logs
- OAuth tokens, secrets and authorization headers are masked
- Payload logging uses **summary logging** (counts & date ranges only)
- Optional `LOG_VERBOSE` flag enables deeper debug output

---

## Documentation

| File | Description |
|-----|------------|
| `docs/schema.md` | Dataset fields, types & units |
| `docs/mapping.md` | Mapping to Databox metrics & dashboards |

---

# Testing

This project uses **PHPUnit** for automated testing.

### Run tests

```bash
composer test
```

Or directly:

```bash
vendor/bin/phpunit
```

### What is tested (testing strategy)

Tests are designed to validate critical behavior:

- **Unit tests**
  - Date parsing / date-range normalization (`YYYY-MM-DD`)
  - Aggregation logic (daily buckets, counters, sums)
  - Record validation and type casting

- **Integration-style tests**
  - End-to-end pipeline execution in safe modes (e.g. dry-run behavior)
  - Provider execution using mocked HTTP responses (no real external calls)

- **Contract / schema checks**
  - Ensures produced records match the documented dataset schema (`docs/schema.md`)
  - Prevents accidental breaking changes when evolving datasets

> Notes:
> - External APIs are not called during tests (HTTP is mocked).
> - Sensitive data must never appear in logs; logging redaction is validated indirectly by structured logging behavior.

---

## License

MIT
