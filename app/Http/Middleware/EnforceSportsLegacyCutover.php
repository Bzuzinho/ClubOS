<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceSportsLegacyCutover
{
    /** @var array<string,string> */
    private const LEGACY_PREFIXES = [
        'equipas' => '/desportivo/estrutura',
        'membros-equipa' => '/desportivo/estrutura',
        'sessoes-formacao' => '/desportivo/treinos',
        'convocatorias' => '/desportivo/competicoes',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $firstSegment = (string) ($request->segment(1) ?? '');
        $target = self::LEGACY_PREFIXES[$firstSegment] ?? null;

        if ($target === null) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return redirect($target, 302)
                ->with('warning', 'Este fluxo desportivo foi substituído pela estrutura canónica do módulo Desportivo.');
        }

        abort(410, 'Este endpoint desportivo legacy está encerrado. Utilize o fluxo canónico do módulo Desportivo.');
    }
}
