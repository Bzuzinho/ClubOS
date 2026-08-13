<?php

namespace App\Services\Desportivo;

use App\Models\Training;
use App\Models\TrainingPlanVersion;

final class TrainingSessionReadinessService
{
    public function evaluate(Training $training, array $latestVersionsByPlan = []): array
    {
        if ($training->isCompleted()) return $this->closed('completed', 'Sessão concluída e fechada para alterações de preparação.');
        if ($training->isCancelled()) return $this->closed('cancelled', 'Sessão cancelada; o histórico permanece disponível.');

        $checks = [];
        $hasGlobalContent = $training->training_plan_version_id !== null || filled($training->instrucao) || $training->series->isNotEmpty();
        $groupsHaveContent = $training->sessionGroups->isNotEmpty()
            && $training->sessionGroups->every(fn ($assignment): bool => $hasGlobalContent || $assignment->training_plan_version_id !== null || filled($assignment->instruction));
        $contentReady = $training->sessionGroups->isEmpty() ? $hasGlobalContent : ($hasGlobalContent || $groupsHaveContent);
        $checks[] = $this->check('content', $contentReady, 'Conteúdo técnico', 'Existe plano, snapshot ou instrução para executar.', 'Falta conteúdo técnico na sessão ou num ou mais grupos.');
        $checks[] = $this->check('participants', $training->athleteRecords->isNotEmpty(), 'Participantes', 'Atletas preparados para a ocorrência.', 'A sessão ainda não tem atletas preparados.');
        $checks[] = $this->check('coach', $training->responsavel_id !== null, 'Treinador', 'Treinador responsável definido.', 'Falta definir treinador responsável.');
        $checks[] = $this->check('location', $training->sports_venue_id !== null && $training->sports_pool_id !== null, 'Local e piscina/área', 'Local e piscina/área definidos.', 'Falta local ou piscina/área canónica.');

        $lanesReady = $training->sessionGroups->isEmpty() ? $training->sports_pool_id !== null : $training->sessionGroups->every(fn ($assignment): bool => $assignment->lanes->isNotEmpty());
        $checks[] = $this->check('lanes', $lanesReady, 'Pistas', $training->sessionGroups->isEmpty() ? 'Sessão sem grupos; piscina/área suficiente.' : 'Todos os grupos têm pistas atribuídas.', 'Há grupos sem pistas atribuídas.');

        $conflicts = collect($training->schedule_conflicts_snapshot ?? []);
        $decisionConflict = $conflicts->first(fn ($issue): bool => in_array((string) ($issue['severity'] ?? ''), ['blocker','decision_required'], true));
        $warningConflict = $conflicts->first(fn ($issue): bool => (string) ($issue['severity'] ?? '') === 'warn');
        if ($decisionConflict) $checks[] = ['key' => 'conflicts','status' => 'decision','label' => 'Conflitos','message' => (string) ($decisionConflict['message'] ?? 'Existe uma decisão operacional pendente.')];
        elseif ($warningConflict) $checks[] = ['key' => 'conflicts','status' => 'attention','label' => 'Conflitos','message' => (string) ($warningConflict['message'] ?? 'Existe um aviso de agenda.')];
        else $checks[] = ['key' => 'conflicts','status' => 'ok','label' => 'Conflitos','message' => 'Sem conflitos registados.'];

        $newer = $this->newerGlobalVersion($training, $latestVersionsByPlan);
        $checks[] = $newer
            ? ['key' => 'plan_version','status' => 'attention','label' => 'Versão do plano','message' => "Existe a versão {$newer->version}; a sessão mantém explicitamente a versão atual até decisão do treinador."]
            : ['key' => 'plan_version','status' => 'ok','label' => 'Versão do plano','message' => 'A sessão usa a versão mais recente disponível ou não usa plano versionado.'];

        $outdatedGroup = $training->sessionGroups->first(function ($assignment) use ($latestVersionsByPlan): bool {
            $current = $assignment->planVersion;
            if (! $current) return false;
            $latest = $latestVersionsByPlan[(string) $current->training_plan_id] ?? null;
            return $latest && (int) $latest->version > (int) $current->version;
        });
        if ($outdatedGroup) $checks[] = ['key' => 'group_plan_version','status' => 'attention','label' => 'Versão por grupo','message' => 'Existe pelo menos um grupo com nova versão de plano disponível; a alteração deve ser explícita no Planeamento.'];

        $checks[] = $training->session_status !== 'published'
            ? ['key' => 'publication','status' => 'attention','label' => 'Publicação','message' => 'A sessão está em rascunho.']
            : ['key' => 'publication','status' => 'ok','label' => 'Publicação','message' => 'Sessão publicada.'];

        $status = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'decision')
            ? 'decision'
            : (collect($checks)->contains(fn (array $check): bool => $check['status'] === 'attention') ? 'attention' : 'ready');

        return ['status' => $status,'checks' => $checks,'latest_plan_version' => $newer ? ['id' => (string) $newer->id,'version' => (int) $newer->version,'name' => $newer->nome_snapshot] : null];
    }

    private function check(string $key, bool $ok, string $label, string $okMessage, string $attentionMessage): array
    {
        return ['key' => $key,'status' => $ok ? 'ok' : 'attention','label' => $label,'message' => $ok ? $okMessage : $attentionMessage];
    }

    private function newerGlobalVersion(Training $training, array $latestVersionsByPlan): ?TrainingPlanVersion
    {
        $current = $training->planVersion;
        if (! $current) return null;
        $latest = $latestVersionsByPlan[(string) $current->training_plan_id] ?? null;
        return $latest && (int) $latest->version > (int) $current->version ? $latest : null;
    }

    private function closed(string $status, string $message): array
    {
        return ['status' => 'closed','closed_reason' => $status,'checks' => [['key' => 'lifecycle','status' => 'closed','label' => 'Lifecycle','message' => $message]],'latest_plan_version' => null];
    }
}
