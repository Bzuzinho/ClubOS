<?php

declare(strict_types=1);

namespace App\Console\Commands\Pessoas;

use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use App\Services\Pessoas\PlatformAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

final class GrantPlatformAccessCommand extends Command
{
    protected $signature = 'people:grant-platform-access
        {user : UUID, email ou nome exato unico}
        {--by= : UUID, email ou nome exato unico do ator}
        {--notes= : Nota auditavel}
        {--dry-run : Simula sem alterar dados}
        {--json : Devolve relatorio em JSON}
        {--report-path= : Caminho para guardar payload JSON}';

    protected $description = 'Concede acesso explicito ativo a plataforma';

    public function __construct(
        private readonly PlatformAccessService $platformAccessService,
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $user = $this->resolveUser((string) $this->argument('user'));
        if (! $user instanceof User) {
            return self::FAILURE;
        }

        $actorOption = $this->option('by');
        $actor = is_string($actorOption) && trim($actorOption) !== '' ? $this->resolveUser($actorOption) : null;
        if (is_string($actorOption) && trim($actorOption) !== '' && ! $actor instanceof User) {
            return self::FAILURE;
        }

        $before = $this->platformAccessService->explainPlatformAccess($user);
        $dryRun = (bool) $this->option('dry-run');
        $applied = false;

        if (! $dryRun) {
            $this->platformAccessService->grantPlatformAccess($user, $actor, $this->notes());
            $applied = true;
        }

        $after = $this->platformAccessService->explainPlatformAccess($user);
        $payload = $this->payload($user, $before, $after, $dryRun, $applied);
        $this->writeReportIfRequested($payload);
        $this->render($payload);

        return self::SUCCESS;
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,mixed>
     */
    private function payload(User $user, array $before, array $after, bool $dryRun, bool $applied): array
    {
        return [
            'user_id' => (string) $user->id,
            'name' => $this->memberIdentityDisplayResolver->displayName($user),
            'previous_platform_access_enabled' => (bool) ($before['platform_access_enabled'] ?? false),
            'new_platform_access_enabled' => $dryRun ? true : (bool) ($after['platform_access_enabled'] ?? false),
            'dry_run' => $dryRun,
            'applied' => $applied,
            'notes' => $this->notes(),
        ];
    }

    private function resolveUser(string $value): ?User
    {
        $value = trim($value);
        $matches = Str::isUuid($value)
            ? User::query()->where('id', $value)->get()
            : User::query()->where('email', $value)->get();

        if ($matches->isEmpty()) {
            $matches = User::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower($value)])
                ->orWhereHas('dadosPessoais', fn ($query) => $query->whereRaw('lower(nome_completo) = ?', [mb_strtolower($value)]))
                ->get();
        }

        if ($matches->count() !== 1) {
            $this->error($matches->isEmpty() ? 'User not found.' : 'User name is not unique.');

            return null;
        }

        return $matches->first();
    }

    private function notes(): ?string
    {
        $notes = $this->option('notes');

        return is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function render(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));

            return;
        }

        $this->table(['Field', 'Value'], collect($payload)->map(fn (mixed $value, string $key): array => [$key, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value])->values()->all());
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeReportIfRequested(array $payload): void
    {
        $reportPathOption = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPathOption === '') {
            return;
        }

        $reportPath = str_starts_with($reportPathOption, '/') || preg_match('/^[A-Za-z]:[\/\\\\]/', $reportPathOption) === 1
            ? $reportPathOption
            : base_path($reportPathOption);

        File::ensureDirectoryExists(dirname($reportPath));
        File::put($reportPath, $this->toJson($payload));
        $this->line(sprintf('Report written to: %s', $reportPath));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
