# Dataset schema

This document defines the full dataset contract produced by the ingestion pipeline.

---

## strava_daily

Daily aggregated activity dataset derived from Strava API.

| Field | Type | Unit | Description |
|------|------|------|-------------|
| date | datetime | — | Day bucket (`YYYY-MM-DD`) |
| activities_count | int | count | Total number of activities |
| run_count | int | count | Number of runs |
| ride_count | int | count | Number of rides |
| walk_count | int | count | Number of walks |
| hike_count | int | count | Number of hikes |
| distance_km | float | km | Total distance |
| moving_time_min | float | minute | Total moving time |
| elapsed_time_min | float | minute | Total elapsed time |
| elevation_m | float | meter | Total elevation gain |
| calories_kcal | float | kcal | Estimated calories burned |

---

## weather_daily

Daily aggregated weather dataset derived from Open-Meteo API.

| Field | Type | Unit | Description |
|------|------|------|-------------|
| date | datetime | — | Day bucket |
| tmax_c | float | °C | Maximum temperature |
| tmin_c | float | °C | Minimum temperature |
| precip_mm | float | mm | Total precipitation |
| avg_temp_c | float | °C | `(tmax_c + tmin_c) / 2` |
| avg_temp_7d_c | float | °C | Rolling 7-day average of `avg_temp_c` |

---

## Computed fields

Some fields are computed inside the ingestion pipeline:

- `avg_temp_c`
- `avg_temp_7d_c`
- rolling metrics (7-day windows)
- activity totals & groupings

All rolling metrics are computed **before ingestion** and pushed as finalized values to Databox.
