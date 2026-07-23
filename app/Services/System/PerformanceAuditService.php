<?php

namespace App\Services\System;

use Illuminate\Support\Facades\File;

class PerformanceAuditService
{
    /**
     * @param  array{route?: string|null, only_critical?: bool}  $options
     * @return array<string, mixed>
     */
    public function audit(array $options = []): array
    {
        $findings = [];

        $this->inspectInertiaSharedProps($findings);
        $this->inspectMembersController($findings);
        $this->inspectAccessControl($findings);
        $this->inspectSlowRequestLogging($findings);

        if ((bool) ($options['only_critical'] ?? false)) {
            $findings = array_values(array_filter(
                $findings,
                static fn (array $finding): bool => in_array($finding['severity'] ?? null, ['critical', 'warning'], true)
            ));
        }

        if ($findings === []) {
            $findings[] = [
                'severity' => 'info',
                'code' => 'performance_audit_clean',
                'message' => 'No critical static performance finding was detected.',
                'recommendation' => 'keep_slow_request_logging_available_for_production_sampling',
                'actionable' => false,
            ];
        }

        return [
            'summary' => [
                'total_findings' => count($findings),
                'critical_count' => collect($findings)->where('severity', 'critical')->count(),
                'warning_count' => collect($findings)->where('severity', 'warning')->count(),
                'info_count' => collect($findings)->where('severity', 'info')->count(),
                'performance_log_enabled' => (bool) config('clubos.performance.log_enabled', false),
                'slow_request_threshold_ms' => (int) config('clubos.performance.slow_request_threshold_ms', 1000),
                'slow_query_threshold_ms' => (int) config('clubos.performance.slow_query_threshold_ms', 200),
            ],
            'findings' => $findings,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function inspectInertiaSharedProps(array &$findings): void
    {
        $path = app_path('Http/Middleware/HandleInertiaRequests.php');
        $contents = $this->read($path);

        if (str_contains($contents, "'communicationMembers' => \$this->sharedCommunicationMembers(\$user)")) {
            $findings[] = [
                'severity' => 'critical',
                'code' => 'inertia_shared_props_too_heavy',
                'message' => 'Shared Inertia props load all communication members for every authenticated page.',
                'file' => $path,
                'recommendation' => 'load_communication_members_only_on_communication_surfaces',
                'actionable' => true,
            ];
        }

        if (str_contains($contents, 'sharedCommunicationMembers') && str_contains($contents, 'shouldShareCommunicationMembers')) {
            $findings[] = [
                'severity' => 'info',
                'code' => 'large_payload_candidate',
                'message' => 'Communication member options are gated to communication surfaces instead of every page.',
                'file' => $path,
                'recommendation' => 'monitor_payload_size_with_slow_request_logging',
                'actionable' => false,
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function inspectMembersController(array &$findings): void
    {
        $path = app_path('Http/Controllers/MembrosController.php');
        $contents = $this->read($path);

        if (preg_match("/Cache::remember\\('membros:list'.*?->get\\(\\)/s", $contents) === 1) {
            $findings[] = [
                'severity' => 'critical',
                'code' => 'member_index_missing_pagination',
                'message' => 'Members index appears to cache/load the full members collection.',
                'file' => $path,
                'recommendation' => 'paginate_member_index_and_limit_selected_columns',
                'actionable' => true,
            ];
        }

        if (str_contains($contents, '->paginate($perPage)')) {
            $findings[] = [
                'severity' => 'info',
                'code' => 'member_index_uses_pagination',
                'message' => 'Members index uses server-side pagination.',
                'file' => $path,
                'recommendation' => 'no_action_needed_member_index_paginated',
                'actionable' => false,
            ];
        }

        if (str_contains($contents, "->with(['dadosPessoais:id,user_id,nome_completo', 'userTypes:id,codigo,nome'])")) {
            $findings[] = [
                'severity' => 'info',
                'code' => 'member_index_minimal_eager_loading',
                'message' => 'Members index eager-loads only the canonical name and user type relations needed by the list.',
                'file' => $path,
                'recommendation' => 'no_action_needed_member_index_minimal_eager_loading',
                'actionable' => false,
            ];
        }

        if (str_contains($contents, 'shouldLoadMemberCommunications')) {
            $findings[] = [
                'severity' => 'info',
                'code' => 'member_show_communications_loaded_lazily',
                'message' => 'Member show loads internal communication feeds only when the communications tab or partial prop is requested.',
                'file' => $path,
                'recommendation' => 'no_action_needed_member_show_communications_lazy',
                'actionable' => false,
            ];
        } else {
            $findings[] = [
                'severity' => 'warning',
                'code' => 'member_show_payload_too_heavy',
                'message' => 'Member show may load internal communication feeds during the initial profile request.',
                'file' => $path,
                'recommendation' => 'load_member_communications_only_when_requested',
                'actionable' => true,
            ];
        }

        $legacyPayloadSection = $this->section($contents, 'private function legacyUserPayloadForMemberWrite', 'private function hasFinancialDataPayload');
        if (str_contains($legacyPayloadSection, "'estado_civil'")) {
            $findings[] = [
                'severity' => 'warning',
                'code' => 'member_update_estado_civil_mapping_issue',
                'message' => 'Member update still attempts to include civil status in the legacy users payload.',
                'file' => $path,
                'recommendation' => 'persist_estado_civil_only_in_dados_pessoais',
                'actionable' => true,
            ];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function inspectAccessControl(array &$findings): void
    {
        $path = app_path('Services/AccessControl/UserTypeAccessControlService.php');
        $contents = $this->read($path);

        if (! str_contains($contents, 'access_control:current_user_access:')) {
            $findings[] = [
                'severity' => 'warning',
                'code' => 'permission_resolution_not_cached',
                'message' => 'Current user access resolution does not appear to be cached.',
                'file' => $path,
                'recommendation' => 'cache_permission_and_menu_resolution_per_user',
                'actionable' => true,
            ];

            return;
        }

        $findings[] = [
            'severity' => 'info',
            'code' => 'menu_modules_cached',
            'message' => 'Current user access/menu resolution has per-user cache keys.',
            'file' => $path,
            'recommendation' => 'no_action_needed_menu_modules_cached',
            'actionable' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function inspectSlowRequestLogging(array &$findings): void
    {
        if (! (bool) config('clubos.performance.log_enabled', false)) {
            $findings[] = [
                'severity' => 'info',
                'code' => 'slow_request_logging_disabled',
                'message' => 'Slow request logging is disabled in the current environment.',
                'recommendation' => 'enable_temporarily_with_CLUBOS_PERFORMANCE_LOG_for_production_sampling',
                'actionable' => false,
            ];
        }
    }

    private function read(string $path): string
    {
        return File::exists($path) ? File::get($path) : '';
    }

    private function section(string $contents, string $start, string $end): string
    {
        $startPosition = strpos($contents, $start);
        if ($startPosition === false) {
            return '';
        }

        $endPosition = strpos($contents, $end, $startPosition);
        if ($endPosition === false) {
            return substr($contents, $startPosition);
        }

        return substr($contents, $startPosition, $endPosition - $startPosition);
    }
}
