# Migration Quick Reference

## All 38 New Migrations

### Users Module
| # | File | Table | Description |
|---|------|-------|-------------|
| 01 | `2026_01_30_150000_extend_users_table_complete.php` | users | Extends users with all Spark fields |

### Events Module
| # | File | Table | Description |
|---|------|-------|-------------|
| 02 | `2026_01_30_150001_create_event_type_configs_table.php` | event_type_configs | Event type configurations |
| 03 | `2026_01_30_150002_create_events_table.php` | events | Main events table |
| 04 | `2026_01_30_150003_create_event_convocations_table.php` | event_convocations | Athlete call-ups |
| 05 | `2026_01_30_150004_create_convocation_groups_table.php` | convocation_groups | Call-up groups |
| 06 | `2026_01_30_150005_create_convocation_athletes_table.php` | convocation_athletes | Athletes in groups (pivot) |
| 07 | `2026_01_30_150006_create_event_attendances_table.php` | event_attendances | Attendance tracking |
| 08 | `2026_01_30_150007_create_event_results_table.php` | event_results | Event results |
| 09 | `2026_01_30_150008_create_result_provas_table.php` | result_provas | Individual race results |

### Sports Module
| # | File | Table | Description |
|---|------|-------|-------------|
| 10 | `2026_01_30_150009_create_seasons_table.php` | seasons | Training seasons (épocas) |
| 11 | `2026_01_30_150010_create_macrocycles_table.php` | macrocycles | Large training cycles |
| 12 | `2026_01_30_150011_create_mesocycles_table.php` | mesocycles | Medium training cycles |
| 13 | `2026_01_30_150012_create_microcycles_table.php` | microcycles | Weekly cycles |
| 14 | `2026_01_30_150013_create_trainings_table.php` | trainings | Training sessions |
| 15 | `2026_01_30_150014_create_training_series_table.php` | training_series | Series in trainings |
| 16 | `2026_01_30_150015_create_training_athletes_table.php` | training_athletes | Athlete participation |
| 17 | `2026_01_30_150016_create_athlete_sports_data_table.php` | athlete_sports_data | Extended athlete data |
| 18 | `2026_01_30_150017_create_presences_table.php` | presences | Presence tracking |
| 19 | `2026_01_30_150018_create_competitions_table.php` | competitions | Competitions |
| 20 | `2026_01_30_150019_create_provas_table.php` | provas | Races in competitions |
| 21 | `2026_01_30_150020_create_competition_registrations_table.php` | competition_registrations | Race registrations |
| 22 | `2026_01_30_150021_create_results_table.php` | results | Race results |
| 23 | `2026_01_30_150022_create_result_splits_table.php` | result_splits | Split times |

### Financial Module
| # | File | Table | Description |
|---|------|-------|-------------|
| 24 | `2026_01_30_150023_create_monthly_fees_table.php` | monthly_fees | Monthly fee types |
| 25 | `2026_01_30_150024_create_invoices_table.php` | invoices | Invoices (faturas) |
| 26 | `2026_01_30_150025_create_invoice_items_table.php` | invoice_items | Invoice line items |
| 27 | `2026_01_30_150026_create_movements_table.php` | movements | Financial movements |
| 28 | `2026_01_30_150027_create_movement_items_table.php` | movement_items | Movement line items |
| 29 | `2026_01_30_150028_create_convocation_movements_table.php` | convocation_movements | Call-up movements |
| 30 | `2026_01_30_150029_create_convocation_movement_items_table.php` | convocation_movement_items | Call-up items |
| 31 | `2026_01_30_150030_create_financial_entries_table.php` | financial_entries | General ledger |
| 32 | `2026_01_30_150031_create_bank_statements_table.php` | bank_statements | Bank reconciliation |

### Inventory Module
| # | File | Table | Description |
|---|------|-------|-------------|
| 33 | `2026_01_30_150032_create_products_table.php` | products | Product catalog |
| 34 | `2026_01_30_150033_create_sales_table.php` | sales | Sales transactions |

### Other Modules
| # | File | Table | Description |
|---|------|-------|-------------|
| 35 | `2026_01_30_150034_create_sponsors_table.php` | sponsors | Sponsor management |
| 36 | `2026_01_30_150035_create_news_items_table.php` | news_items | News articles |
| 37 | `2026_01_30_150036_create_communications_table.php` | communications | Communications |
| 38 | `2026_01_30_150037_create_automated_communications_table.php` | automated_communications | Automated templates |

## Common Commands

```bash
# Run all migrations
php artisan migrate

# Rollback last batch
php artisan migrate:rollback

# Reset and re-run
php artisan migrate:fresh

# Check status
php artisan migrate:status

# Rollback specific steps
php artisan migrate:rollback --step=5
```

## Key Table Relationships

### Events → Users
- events.criado_por → users.id
- event_convocations.user_id → users.id
- event_attendances.user_id → users.id

### Events → Financial
- convocation_groups.movimento_id → movements.id
- movements.user_id → users.id

### Sports → Events
- trainings.evento_id → events.id
- competitions.evento_id → events.id

### Financial → Users
- invoices.user_id → users.id
- movements.user_id → users.id
- sales.cliente_id → users.id
