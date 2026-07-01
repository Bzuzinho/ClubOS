<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use App\Models\Event;
use App\Models\User;
use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\ConvocationAthlete;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Support\Collection;

class ConvocationGroup extends Model
{
    use HasUuids;

    protected $table = 'convocation_groups';

    protected $fillable = [
        'id',  // ✅ IMPORTANTE: Precisa estar aqui para updateOrCreate respeitar o ID
        'evento_id',
        'data_criacao',
        'criado_por',
        'atletas_ids',
        'hora_encontro',
        'local_encontro',
        'observacoes',
        'tipo_custo',
        'valor_por_salto',
        'valor_por_estafeta',
        'valor_inscricao_unitaria',
        'valor_inscricao_calculado',
        'movimento_id',
        'centro_custo_id',
    ];

    protected $casts = [
        'atletas_ids' => 'array',
        'data_criacao' => 'datetime',
        'valor_por_salto' => 'decimal:2',
        'valor_por_estafeta' => 'decimal:2',
        'valor_inscricao_unitaria' => 'decimal:2',
        'valor_inscricao_calculado' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::created(function (ConvocationGroup $group) {
            if ($group->movimento_id) {
                return;
            }

            $group->refreshFinancialMovement();
        });

        static::updated(function (ConvocationGroup $group) {
            if ($group->wasChanged('movimento_id') && !$group->wasChanged([
                'evento_id',
                'atletas_ids',
                'hora_encontro',
                'local_encontro',
                'observacoes',
                'tipo_custo',
                'valor_por_salto',
                'valor_por_estafeta',
                'valor_inscricao_unitaria',
                'valor_inscricao_calculado',
                'centro_custo_id',
            ])) {
                return;
            }

            if (!$group->wasChanged([
                'evento_id',
                'atletas_ids',
                'hora_encontro',
                'local_encontro',
                'observacoes',
                'tipo_custo',
                'valor_por_salto',
                'valor_por_estafeta',
                'valor_inscricao_unitaria',
                'valor_inscricao_calculado',
                'centro_custo_id',
            ])) {
                return;
            }

            $group->refreshFinancialMovement();
        });
    }

    public function refreshFinancialMovement(): void
    {
        $event = Event::find($this->evento_id);
        if (!$event) {
            return;
        }

        $athleteIds = $this->resolveAthleteIds();

        $emissao = $this->created_at
            ? Carbon::parse($this->created_at)
            : ($this->data_criacao ? Carbon::parse($this->data_criacao) : Carbon::now());

        $vencimento = $event->data_inicio
            ? Carbon::parse($event->data_inicio)
            : $emissao->copy();

        // Load tipo_config to determine movement type
        $eventTypeConfig = $event->tipoConfig ?? null;
        $geraTaxa = $eventTypeConfig ? (bool) $eventTypeConfig->gera_taxa : false;
        $isProva = strtolower((string) ($event->tipo ?? '')) === 'prova';
        $movementType = ($geraTaxa || $isProva) ? 'inscricao' : 'outro';

        $totalAggregate = 0;
        $athleteCount = 0;
        $movementItemsData = [];

        foreach ($athleteIds as $athleteId) {
            $user = User::find($athleteId);
            if (!$user) {
                continue;
            }

            $provaCount = self::getProvasCount($this->id, $athleteId);
            $estafetaCount = self::getEstafetasCount($this->id, $athleteId);
            $valor = self::calcularCusto($this, $event, $provaCount, $estafetaCount);
            if ($valor <= 0) {
                continue;
            }

            $valorLinha = abs($valor);
            $movementItemsData[] = [
                'descricao' => sprintf(
                    '%s - %s',
                    app(MemberIdentityDisplayResolver::class)->displayName($user),
                    (string) $event->titulo,
                ),
                'valor_unitario' => $valorLinha,
                'quantidade' => 1,
                'imposto_percentual' => 0,
                'total_linha' => $valorLinha,
                'centro_custo_id' => $event->centro_custo_id,
            ];

            $totalAggregate += $valorLinha;
            $athleteCount += 1;
        }

        if ($this->movimento_id) {
            MovementItem::where('movimento_id', $this->movimento_id)->delete();
            Movement::where('id', $this->movimento_id)->delete();
            $this->updateQuietly(['movimento_id' => null]);
        }

        if ($totalAggregate <= 0) {
            return;
        }

        // Create movement with DESPESA classification, always negative value
        $aggregateMovement = Movement::create([
            'user_id' => null,
            'nome_manual' => "Convocatoria {$event->titulo}",
            'classificacao' => 'despesa',
            'data_emissao' => $emissao->toDateString(),
            'data_vencimento' => $vencimento->toDateString(),
            'valor_total' => -abs($totalAggregate),
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $event->centro_custo_id,
            'tipo' => $movementType,
            'origem_tipo' => 'evento',
            'origem_id' => $event->id,
            'observacoes' => "Convocatoria ({$athleteCount} atletas)",
        ]);

        foreach ($movementItemsData as $itemData) {
            MovementItem::create([
                ...$itemData,
                'movimento_id' => $aggregateMovement->id,
            ]);
        }

        $this->updateQuietly(['movimento_id' => $aggregateMovement->id]);
    }

    private function resolveAthleteIds(): Collection
    {
        $athleteIds = collect($this->atletas_ids ?? [])
            ->filter(fn ($athleteId) => is_string($athleteId) && $athleteId !== '')
            ->unique()
            ->values();

        if ($athleteIds->isNotEmpty()) {
            return $athleteIds;
        }

        return ConvocationAthlete::where('convocatoria_grupo_id', $this->id)
            ->pluck('atleta_id')
            ->filter(fn ($athleteId) => is_string($athleteId) && $athleteId !== '')
            ->unique()
            ->values();
    }

    private static function getProvasCount(string $groupId, string $athleteId): int
    {
        $athlete = ConvocationAthlete::where('convocatoria_grupo_id', $groupId)
            ->where('atleta_id', $athleteId)
            ->first();

        if (!$athlete || !is_array($athlete->provas)) {
            return 0;
        }

        return count($athlete->provas);
    }

    private static function getEstafetasCount(string $groupId, string $athleteId): int
    {
        $athlete = ConvocationAthlete::where('convocatoria_grupo_id', $groupId)
            ->where('atleta_id', $athleteId)
            ->first();

        if (!$athlete) {
            return 0;
        }

        return (int) ($athlete->estafetas ?? 0);
    }

    private static function calcularCusto(
        ConvocationGroup $group,
        Event $event,
        int $provaCount,
        int $estafetaCount
    ): float
    {
        $base = (float) ($group->valor_inscricao_unitaria ?? $event->taxa_inscricao ?? 0);
        $porProva = (float) ($event->custo_inscricao_por_prova ?? 0);
        $porSalto = (float) ($group->valor_por_salto ?? $event->custo_inscricao_por_salto ?? 0);
        $porEstafeta = (float) ($group->valor_por_estafeta ?? $event->custo_inscricao_estafeta ?? 0);

        return $base + ($porProva * $provaCount) + ($porSalto * $provaCount) + ($porEstafeta * $estafetaCount);
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function convocationAthletes(): HasMany
    {
        return $this->hasMany(ConvocationAthlete::class, 'convocatoria_grupo_id');
    }

    public function convocationMovements(): HasMany
    {
        return $this->hasMany(ConvocationMovement::class, 'convocatoria_grupo_id');
    }
}
