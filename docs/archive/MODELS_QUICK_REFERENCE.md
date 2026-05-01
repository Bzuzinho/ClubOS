# Eloquent Models Quick Reference

## Quick Model Access Patterns

### User Management
```php
use App\Models\User;

// Find user with all relationships
$user = User::with(['athleteSportsData', 'createdEvents', 'invoices'])->find($id);

// Get all athletes
$athletes = User::whereJsonContains('tipo_membro', 'atleta')->get();

// Get users by escalão
$juvenis = User::whereJsonContains('escalao', 'juvenis')->get();
```

### Events
```php
use App\Models\Event;

// Get event with all related data
$event = Event::with([
    'criador',
    'tipoConfig',
    'convocations.atleta',
    'attendances.atleta',
    'results'
])->find($id);

// Get upcoming events
$upcoming = Event::where('data_inicio', '>=', now())
    ->where('estado', 'publicado')
    ->orderBy('data_inicio')
    ->get();

// Get events by type
$competitions = Event::where('tipo', 'competicao')->get();
```

### Training Management
```php
use App\Models\Training;

// Get training with all series and athletes
$training = Training::with([
    'season',
    'microcycle.mesocycle.macrocycle',
    'series',
    'athletes.atleta',
    'presences'
])->find($id);

// Get trainings for a date range
$trainings = Training::whereBetween('data', [$start, $end])
    ->with('criador')
    ->orderBy('data')
    ->get();

// Get training hierarchy
$season = Season::with([
    'macrocycles.mesocycles.microcycles.trainings'
])->find($id);
```

### Competition & Results
```php
use App\Models\Competition;
use App\Models\Prova;
use App\Models\Result;

// Get competition with all races and results
$competition = Competition::with([
    'provas.results.atleta',
    'provas.escalao'
])->find($id);

// Get athlete results
$results = Result::where('atleta_id', $athleteId)
    ->with(['prova.competition', 'splits'])
    ->orderBy('created_at', 'desc')
    ->get();

// Get best result for an athlete in a race type
$bestResult = Result::where('atleta_id', $athleteId)
    ->whereHas('prova', function($q) {
        $q->where('estilo', 'livre')
          ->where('distancia_m', 100);
    })
    ->orderBy('tempo_oficial')
    ->first();
```

### Financial Operations
```php
use App\Models\Invoice;
use App\Models\Movement;

// Get unpaid invoices
$unpaid = Invoice::where('estado_pagamento', 'pendente')
    ->with(['socio', 'items'])
    ->orderBy('data_vencimento')
    ->get();

// Get member financial summary
$member = User::with([
    'invoices' => function($q) {
        $q->where('estado_pagamento', 'pendente');
    },
    'movements',
    'convocationMovements'
])->find($id);

// Get total revenue for a period
$revenue = Invoice::where('estado_pagamento', 'pago')
    ->whereBetween('data_pagamento', [$start, $end])
    ->sum('valor_pago');
```

### Convocations
```php
use App\Models\ConvocationGroup;
use App\Models\ConvocationAthlete;

// Create a convocation with athletes
$group = ConvocationGroup::create([
    'evento_id' => $eventId,
    'criado_por' => auth()->id(),
    'data_criacao' => now(),
    'atletas_ids' => $athleteIds,
]);

// Add athletes to convocation
foreach ($athletes as $athlete) {
    ConvocationAthlete::create([
        'convocatoria_grupo_id' => $group->id,
        'atleta_id' => $athlete->id,
        'provas' => ['100m livre', '200m costas'],
    ]);
}

// Get pending convocations
$pending = ConvocationAthlete::where('atleta_id', $athleteId)
    ->where('confirmado', false)
    ->with(['convocationGroup.evento'])
    ->get();
```

### Athlete Sports Data
```php
use App\Models\AthleteSportsData;

// Get athlete with complete sports profile
$athlete = User::with([
    'athleteSportsData.escalao',
    'results.prova',
    'competitionRegistrations'
])->find($id);

// Get athletes with expiring medical certificates
$expiring = AthleteSportsData::whereBetween('validade_atestado', [
    now(),
    now()->addMonth()
])->with('atleta')->get();
```

### Presence & Attendance
```php
use App\Models\Presence;

// Mark training attendance
Presence::create([
    'atleta_id' => $athleteId,
    'treino_id' => $trainingId,
    'data' => now(),
    'tipo' => 'treino',
    'presente' => true,
]);

// Get attendance statistics
$stats = Presence::where('atleta_id', $athleteId)
    ->whereBetween('data', [$start, $end])
    ->selectRaw('
        COUNT(*) as total,
        SUM(CASE WHEN presente = true THEN 1 ELSE 0 END) as presencas,
        SUM(CASE WHEN presente = false THEN 1 ELSE 0 END) as faltas
    ')
    ->first();
```

### Products & Sales
```php
use App\Models\Product;
use App\Models\Sale;

// Get available products
$products = Product::where('ativo', true)
    ->where('stock', '>', 0)
    ->get();

// Create a sale
$sale = Sale::create([
    'produto_id' => $productId,
    'comprador_id' => $buyerId,
    'vendedor_id' => auth()->id(),
    'quantidade' => 2,
    'preco_unitario' => $product->preco,
    'total' => $product->preco * 2,
    'data' => now(),
    'metodo_pagamento' => 'dinheiro',
]);

// Update stock
$product->decrement('stock', 2);
```

### Communications
```php
use App\Models\Communication;

// Send communication to multiple members
$communication = Communication::create([
    'titulo' => 'Aviso Importante',
    'mensagem' => 'Mensagem...',
    'tipo' => 'email',
    'destinatarios_ids' => $memberIds,
    'remetente_id' => auth()->id(),
    'data_envio' => now(),
    'estado' => 'enviado',
]);

// Get athlete communications
$messages = Communication::whereJsonContains('destinatarios_ids', $userId)
    ->orderBy('data_envio', 'desc')
    ->get();
```

### News & Content
```php
use App\Models\NewsItem;

// Get featured news
$featured = NewsItem::where('destaque', true)
    ->where('data_publicacao', '<=', now())
    ->with('autor')
    ->orderBy('data_publicacao', 'desc')
    ->take(5)
    ->get();

// Get news by category
$news = NewsItem::whereJsonContains('categorias', 'competicoes')
    ->orderBy('data_publicacao', 'desc')
    ->paginate(10);
```

## Common Query Patterns

### Eager Loading (N+1 Prevention)
```php
// Instead of this (N+1 problem):
$events = Event::all();
foreach ($events as $event) {
    echo $event->criador->name; // Queries for each event
}

// Do this:
$events = Event::with('criador')->get();
foreach ($events as $event) {
    echo $event->criador->name; // No extra queries
}
```

### Conditional Relationships
```php
// Load only specific related data
$user = User::with([
    'invoices' => fn($q) => $q->where('estado_pagamento', 'pendente'),
    'results' => fn($q) => $q->orderBy('tempo_oficial')->limit(10),
])->find($id);
```

### Counting Relationships
```php
// Get events with convocation count
$events = Event::withCount('convocations')
    ->having('convocations_count', '>', 0)
    ->get();
```

### JSON Query Operators
```php
// Query JSON fields
$users = User::whereJsonContains('tipo_membro', 'atleta')->get();
$users = User::whereJsonLength('escalao', '>', 0)->get();
```

## Model Methods to Add (Optional)

### User Model Helpers
```php
// In User.php
public function isAthlete(): bool
{
    return in_array('atleta', $this->tipo_membro ?? []);
}

public function isCoach(): bool
{
    return in_array('treinador', $this->tipo_membro ?? []);
}

public function hasActiveMembership(): bool
{
    return $this->estado === 'ativo';
}
```

### Event Model Helpers
```php
// In Event.php
public function isUpcoming(): bool
{
    return $this->data_inicio > now();
}

public function isPast(): bool
{
    return $this->data_fim < now();
}

public function scopePublished($query)
{
    return $query->where('estado', 'publicado');
}
```

## Testing Models

```php
// In tests
use App\Models\User;
use App\Models\Event;

$user = User::factory()->create();
$event = Event::factory()->create(['criado_por' => $user->id]);

$this->assertDatabaseHas('events', ['titulo' => $event->titulo]);
```
