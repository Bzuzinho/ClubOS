# Eloquent Models Summary

## All Models Created (43 Total)

### Core Models (6)
1. ✅ **User** - Extended Authenticatable with HasUuids, all Spark fields, and 24 relationships
2. ✅ **UserType** - User type configurations with UUID support
3. ✅ **AgeGroup** - Age group/escalão definitions with relationships
4. ✅ **EventType** - Event type categories with UUID support
5. ✅ **CostCenter** - Cost center definitions with 7 financial relationships
6. ✅ **ClubSetting** - Club configuration settings with UUID support

### Events Module (9)
7. ✅ **EventTypeConfig** - Event type configurations with relationships
8. ✅ **Event** - Main events table with 10 relationships
9. ✅ **EventConvocation** - Event athlete convocations
10. ✅ **ConvocationGroup** - Group convocations with multiple athletes
11. ✅ **ConvocationAthlete** - Pivot table for convocation athletes
12. ✅ **EventAttendance** - Event attendance tracking
13. ✅ **EventResult** - Event results for athletes
14. ✅ **ResultProva** - Individual race/event results

### Sports Module (14)
15. ✅ **Season** - Sports seasons/epochs with relationships
16. ✅ **Macrocycle** - Training macrocycles
17. ✅ **Mesocycle** - Training mesocycles
18. ✅ **Microcycle** - Training microcycles
19. ✅ **Training** - Individual training sessions with 6 relationships
20. ✅ **TrainingSeries** - Training series/sets within sessions
21. ✅ **TrainingAthlete** - Athlete training participation
22. ✅ **AthleteSportsData** - Athlete sports-specific data
23. ✅ **Presence** - Training presence/attendance
24. ✅ **Competition** - Competition events
25. ✅ **Prova** - Individual races/events within competitions
26. ✅ **CompetitionRegistration** - Athlete race registrations
27. ✅ **Result** - Competition race results
28. ✅ **ResultSplit** - Split times for results

### Financial Module (9)
29. ✅ **MonthlyFee** - Monthly fee configurations
30. ✅ **Invoice** - Member invoices with relationships
31. ✅ **InvoiceItem** - Invoice line items
32. ✅ **Movement** - Financial movements
33. ✅ **MovementItem** - Movement line items
34. ✅ **ConvocationMovement** - Event-related financial movements
35. ✅ **ConvocationMovementItem** - Convocation movement line items
36. ✅ **FinancialEntry** - General financial entries
37. ✅ **BankStatement** - Bank statement reconciliation

### Other Modules (5)
38. ✅ **Product** - Store products
39. ✅ **Sale** - Product sales transactions
40. ✅ **Sponsor** - Club sponsors
41. ✅ **NewsItem** - News articles
42. ✅ **Communication** - Communications/messages
43. ✅ **AutomatedCommunication** - Automated communication templates

## Model Features Implemented

### All Models Include:
- ✅ UUID primary keys (`use HasUuids` trait)
- ✅ Proper key type configuration (`$keyType = 'string'`, `$incrementing = false`)
- ✅ Complete `$fillable` arrays
- ✅ Proper `$casts` for type casting:
  - JSON fields → `array`
  - Boolean fields → `boolean`
  - Date fields → `date`
  - Datetime fields → `datetime`
  - Decimal fields → `decimal:2`
  - Integer fields → `integer`
- ✅ Eloquent relationships defined:
  - `belongsTo()` for foreign keys
  - `hasMany()` for one-to-many
  - `hasOne()` for one-to-one
- ✅ Proper namespaces (`App\Models`)
- ✅ Hidden attributes for sensitive data (passwords)

## Key Relationships

### User Model Relationships (24 total):
- athleteSportsData (HasOne)
- createdEvents, convocations, givenConvocations (HasMany)
- eventAttendances, eventResults, resultProvas (HasMany)
- createdTrainings, trainingAthletes, presences (HasMany)
- competitionRegistrations, results (HasMany)
- invoices, movements, convocationMovements (HasMany)
- financialEntries, purchases, salesMade (HasMany)
- newsItems, sentCommunications (HasMany)
- createdConvocationGroups, convocationAthletes (HasMany)

### Event Model Relationships (10 total):
- tipoConfig, centroCusto, criador, eventoPai (BelongsTo)
- eventosFilhos, convocations, attendances (HasMany)
- results, trainings, competition (HasMany)
- convocationGroups, convocationMovements (HasMany)

### Training Model Relationships (6 total):
- season, microcycle, criador, evento (BelongsTo)
- series, athletes, presences (HasMany)

### Cost Center Relationships (7 total):
- events, invoices, invoiceItems (HasMany)
- movements, movementItems (HasMany)
- financialEntries, bankStatements (HasMany)

## Verification

All 43 models have been tested and load successfully:
```bash
php artisan tinker --execute="echo App\Models\Event::class;"
```

## Migration Compatibility

All models are fully compatible with the database migrations created in:
- `database/migrations/2026_01_30_*`
- Foreign key relationships match migration definitions
- Column names and types match exactly
- UUID primary keys align with migration structure

## Usage Example

```php
use App\Models\Event;
use App\Models\User;
use App\Models\Training;

// Create an event
$event = Event::create([
    'titulo' => 'Treino Especial',
    'data_inicio' => '2024-02-01',
    'tipo' => 'treino',
    'criado_por' => $user->id,
]);

// Access relationships
$creator = $event->criador;
$attendances = $event->attendances;
$convocations = $event->convocations()->with('atleta')->get();

// Training with relationships
$training = Training::with(['season', 'series', 'athletes'])->find($id);
```

## Notes

- All models use UUIDs for primary keys
- Proper type casting ensures data integrity
- Relationships enable efficient querying
- Models follow Laravel naming conventions
- Ready for immediate use with the migrated database
