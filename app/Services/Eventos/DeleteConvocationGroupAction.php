<?php

namespace App\Services\Eventos;

use App\Models\ConvocationAthlete;
use App\Models\ConvocationGroup;
use App\Models\Movement;
use App\Models\MovementItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteConvocationGroupAction
{
    public const BLOCK_MESSAGE = 'Esta convocatória já possui liquidação, conciliação ou documento financeiro associado e os dados com impacto financeiro não podem ser alterados diretamente.';

    public function __construct(
        private readonly ConvocationGroupFinancialGuardService $guardService,
    ) {
    }

    public function execute(ConvocationGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            $lockedGroup = ConvocationGroup::query()->lockForUpdate()->findOrFail($group->id);

            $movement = null;
            if ($lockedGroup->movimento_id) {
                $movement = Movement::query()->find($lockedGroup->movimento_id);
            }

            if (!$movement) {
                $movement = Movement::query()
                    ->where('origem_tipo', 'convocation_group')
                    ->where('origem_id', $lockedGroup->id)
                    ->orderByDesc('created_at')
                    ->first();
            }

            if ($movement && !$this->guardService->canDelete($lockedGroup)) {
                throw ValidationException::withMessages([
                    'convocation_group' => self::BLOCK_MESSAGE,
                ]);
            }

            if ($movement) {
                MovementItem::query()->where('movimento_id', $movement->id)->delete();
                $movement->delete();
            }

            ConvocationAthlete::query()->where('convocatoria_grupo_id', $lockedGroup->id)->delete();
            $lockedGroup->delete();
        });
    }
}
