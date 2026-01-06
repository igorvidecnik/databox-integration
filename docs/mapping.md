# Databox mapping

## Dataset → Metrics

Databox dataset fields are ingested as typed numbers/datetime.  
Custom metrics in Databox UI map **1:1** to dataset fields.

### Strava metrics

- Total Distance → `distance_km`
- Activity Volume → `activities_count`
- Total Calories → `calories_kcal`
- Moving Time → `moving_time_min`
- Elevation Gain → `elevation_m`

### Weather metrics

- Maximum Temperature → `tmax_c`
- Minimum Temperature → `tmin_c`
- Total Precipitation → `precip_mm`
- Average Temperature → `avg_temp_c`
- 7-day Average Temperature → `avg_temp_7d_c`

## Notes

- Time aggregation is controlled only by Databox time-range selector.
- Rolling metrics are computed before ingestion (pipeline responsibility).
