<?php

namespace App\Services\Desportivo;

use App\Models\SportsModality;
use App\Models\SportsPool;
use App\Models\SportsPoolLane;
use App\Models\SportsProgram;
use App\Models\TrainingGroupMembership;
use App\Models\TrainingGroupSeason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SportsStructureService
{
    public function clubId(): string { return (string) config('clubos.sports.club_id', 'bscn'); }

    public function createModality(array $data, ?string $actorId = null): SportsModality
    {
        return SportsModality::create(['club_id'=>$this->clubId(),'code'=>$data['code'],'name'=>$data['name'],'description'=>$data['description']??null,'active'=>$data['active']??true,'created_by'=>$actorId,'updated_by'=>$actorId]);
    }

    public function updateModality(SportsModality $modality, array $data, ?string $actorId = null): SportsModality
    {
        $this->assertTenant($modality);
        if (isset($data['code']) && $data['code'] !== $modality->code && $this->modalityIsUsed($modality)) throw ValidationException::withMessages(['code'=>'O código técnico de uma modalidade utilizada é imutável.']);
        $modality->fill($data)->forceFill(['updated_by'=>$actorId])->save();
        return $modality->refresh();
    }

    public function retireModality(SportsModality $modality, ?string $actorId = null): void
    {
        $this->assertTenant($modality);
        if ($this->modalityIsUsed($modality)) { $modality->forceFill(['active'=>false,'archived_at'=>now(),'updated_by'=>$actorId])->save(); return; }
        $modality->delete();
    }

    public function createProgram(array $data, ?string $actorId = null): SportsProgram
    {
        $modality = SportsModality::forClub($this->clubId())->findOrFail($data['sports_modality_id']);
        return SportsProgram::create(['club_id'=>$this->clubId(),'sports_modality_id'=>$modality->id,'code'=>$data['code'],'name'=>$data['name'],'description'=>$data['description']??null,'active'=>$data['active']??true,'created_by'=>$actorId,'updated_by'=>$actorId]);
    }

    public function updateProgram(SportsProgram $program, array $data, ?string $actorId = null): SportsProgram
    {
        $this->assertTenant($program);
        if (isset($data['code']) && $data['code'] !== $program->code && $program->seasonPrograms()->exists()) throw ValidationException::withMessages(['code'=>'O código técnico de um programa utilizado é imutável.']);
        $program->fill($data)->forceFill(['updated_by'=>$actorId])->save();
        return $program->refresh();
    }

    public function retireProgram(SportsProgram $program, ?string $actorId = null): void
    {
        $this->assertTenant($program);
        if ($program->seasonPrograms()->exists() || TrainingGroupSeason::query()->where('sports_program_id',$program->id)->exists()) { $program->forceFill(['active'=>false,'archived_at'=>now(),'updated_by'=>$actorId])->save(); return; }
        $program->delete();
    }

    public function createPool(array $data, ?string $actorId = null): SportsPool
    {
        return SportsPool::create([...$data,'club_id'=>$this->clubId(),'created_by'=>$actorId,'updated_by'=>$actorId]);
    }

    public function addLane(SportsPool $pool, array $data, ?string $actorId = null): SportsPoolLane
    {
        $this->assertTenant($pool);
        return $pool->lanes()->create([...$data,'club_id'=>$this->clubId(),'created_by'=>$actorId,'updated_by'=>$actorId]);
    }

    public function assignMembershipWithSeasonContext(array $data, ?string $actorId = null): TrainingGroupMembership
    {
        return DB::transaction(function () use ($data, $actorId) {
            $context = TrainingGroupSeason::query()->where('club_id',$this->clubId())->with('group')->lockForUpdate()->findOrFail($data['training_group_season_id']);
            $startsAt = $data['starts_at']; $endsAt = $data['ends_at'] ?? null;
            if ((bool)($data['is_primary'] ?? true)) {
                $conflict = TrainingGroupMembership::query()
                    ->where('club_id',$this->clubId())->where('user_id',$data['user_id'])->where('is_primary',true)
                    ->whereNotNull('training_group_season_id')
                    ->whereHas('seasonContext', function ($q) use ($context) {
                        $q->where('season_id',$context->season_id)->whereHas('group', fn($g) => $g->where('sports_modality_id',$context->group->sports_modality_id));
                    })
                    ->whereDate('starts_at','<=',$endsAt ?: '9999-12-31')
                    ->where(fn($q) => $q->whereNull('ends_at')->orWhereDate('ends_at','>=',$startsAt))
                    ->exists();
                if ($conflict) throw ValidationException::withMessages(['is_primary'=>'O atleta já possui um grupo principal sobreposto nesta modalidade e época.']);
            }
            return TrainingGroupMembership::create(['club_id'=>$this->clubId(),'training_group_id'=>$context->training_group_id,'training_group_season_id'=>$context->id,'user_id'=>$data['user_id'],'is_primary'=>$data['is_primary']??true,'starts_at'=>$startsAt,'ends_at'=>$endsAt,'notes'=>$data['notes']??null,'created_by'=>$actorId]);
        }, 3);
    }

    private function modalityIsUsed(SportsModality $modality): bool
    {
        return $modality->programs()->exists() || DB::table('seasons')->where('sports_modality_id',$modality->id)->exists() || DB::table('training_groups')->where('sports_modality_id',$modality->id)->exists();
    }

    private function assertTenant(Model $model): void { if ((string)$model->getAttribute('club_id') !== $this->clubId()) abort(404); }
}
