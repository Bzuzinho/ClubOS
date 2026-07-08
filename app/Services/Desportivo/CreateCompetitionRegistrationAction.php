<?php

namespace App\Services\Desportivo;

use App\Models\CompetitionRegistration;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Prova;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCompetitionRegistrationAction
{
    public function execute(array $validatedData): CompetitionRegistration
    {
        return DB::transaction(function () use ($validatedData): CompetitionRegistration {
            $registration = CompetitionRegistration::query()->create([
                'prova_id' => $validatedData['prova_id'],
                'user_id' => $validatedData['user_id'],
                'estado' => $validatedData['estado'] ?? 'inscrito',
                'valor_inscricao' => $validatedData['valor_inscricao'] ?? null,
            ]);

            $prova = Prova::query()
                ->with('competition.evento')
                ->find($registration->prova_id);

            if (!$prova) {
                throw ValidationException::withMessages([
                    'prova_id' => 'A prova indicada nao foi encontrada.',
                ]);
            }

            $event = $prova->competition?->evento;
            $effectiveValue = $this->resolveEffectiveValue($registration, $event?->taxa_inscricao);

            if ($effectiveValue <= 0.0) {
                return $registration->fresh(['prova.competition', 'athlete']);
            }

            $emissionDate = now();
            $dueDate = $this->addBusinessDays($emissionDate->copy(), 8);
            $description = $this->resolveDescription($event?->titulo, $prova);

            $invoice = Invoice::query()->create([
                'user_id' => $registration->user_id,
                'data_fatura' => $emissionDate->toDateString(),
                'mes' => $emissionDate->format('Y-m'),
                'data_emissao' => $emissionDate->toDateString(),
                'data_vencimento' => $dueDate->toDateString(),
                'valor_total' => $effectiveValue,
                'valor_pago' => 0,
                'valor_em_aberto' => $effectiveValue,
                'oculta' => false,
                'estado_pagamento' => 'pendente',
                'centro_custo_id' => $event?->centro_custo_id,
                'tipo' => 'inscricao',
                'origem_tipo' => 'competition_registration',
                'origem_id' => $registration->id,
                'observacoes' => $description,
            ]);

            InvoiceItem::query()->create([
                'fatura_id' => $invoice->id,
                'descricao' => $description,
                'valor_unitario' => $effectiveValue,
                'quantidade' => 1,
                'imposto_percentual' => 0,
                'total_linha' => $effectiveValue,
                'centro_custo_id' => $event?->centro_custo_id,
            ]);

            $registration->update(['fatura_id' => $invoice->id]);

            return $registration->fresh(['prova.competition', 'athlete', 'fatura.items']);
        });
    }

    private function resolveEffectiveValue(CompetitionRegistration $registration, mixed $eventFee): float
    {
        if ($registration->valor_inscricao !== null) {
            return $this->normalizeAmount((float) $registration->valor_inscricao);
        }

        if ($eventFee !== null) {
            return $this->normalizeAmount((float) $eventFee);
        }

        return 0.0;
    }

    private function normalizeAmount(float $amount): float
    {
        return round(max(0, $amount), 2);
    }

    private function resolveDescription(?string $eventTitle, Prova $prova): string
    {
        if (filled($eventTitle)) {
            return 'Inscricao em prova - '.$eventTitle;
        }

        $proofLabel = trim(implode(' ', array_filter([
            $prova->estilo,
            $prova->distancia_m ? $prova->distancia_m.'m' : null,
            $prova->genero,
        ])));

        return $proofLabel !== ''
            ? 'Inscricao em prova - '.$proofLabel
            : 'Inscricao em prova';
    }

    private function addBusinessDays(Carbon $date, int $days): Carbon
    {
        $added = 0;

        while ($added < $days) {
            $date->addDay();
            if ($date->isWeekend()) {
                continue;
            }

            $added += 1;
        }

        return $date;
    }
}
