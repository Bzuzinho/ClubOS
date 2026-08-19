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
        'convocatorias' => '/desportivo/convocatorias',
    ];

    /** @var list<string> */
    private const LEGACY_PLANNING_MUTATION_SEGMENTS = ['epocas', 'macrociclos', 'mesociclos'];

    public function handle(Request $request, Closure $next): Response
    {
        $firstSegment = (string) ($request->segment(1) ?? '');
        $target = self::LEGACY_PREFIXES[$firstSegment] ?? null;

        if ($target !== null) {
            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                return redirect($target, 302)
                    ->with('warning', 'Este fluxo desportivo foi substituído pela estrutura canónica do módulo Desportivo.');
            }
            abort(410, 'Este endpoint desportivo legacy está encerrado. Utilize o fluxo canónico do módulo Desportivo.');
        }

        if ($firstSegment === 'desportivo'
            && $request->segment(2) === null
            && $request->query('tab') === 'atletas'
            && ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
            return redirect('/desportivo/atletas', 302);
        }

        if ($firstSegment === 'desportivo'
            && $request->segment(2) === 'presencas') {
            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                $target = '/desportivo/cais';
                if ($request->filled('training_id')) {
                    $target .= '?training_id='.rawurlencode((string) $request->query('training_id'));
                }

                return redirect($target, 302)
                    ->with('warning', 'Presenças passou a ser gerido no Cais.');
            }

            abort(410, 'O fluxo legacy de Presenças está encerrado. Utilize o Cais.');
        }

        if ($firstSegment === 'desportivo'
            && in_array((string) ($request->segment(2) ?? ''), self::LEGACY_PLANNING_MUTATION_SEGMENTS, true)
            && ! ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
            abort(410, 'Este endpoint de planeamento legacy está encerrado. Utilize a workspace canónica de Planeamento.');
        }

        if ($this->isLegacyTrainingMutation($request)) {
            abort(410, 'Este endpoint de Treinos legacy está encerrado. Utilize Planeamento/Treinos canónicos.');
        }

        return $next($request);
    }

    private function isLegacyTrainingMutation(Request $request): bool
    {
        if ($request->segment(1) !== 'desportivo' || $request->segment(2) !== 'treinos') {
            return false;
        }

        $third = $request->segment(3);
        $fourth = $request->segment(4);
        $fifth = $request->segment(5);

        if ($third === null) {
            return $request->isMethod('POST');
        }

        if ($fourth === null) {
            return $request->isMethod('PUT') || $request->isMethod('DELETE');
        }

        if (in_array($fourth, ['agendar', 'duplicar'], true)) {
            return $request->isMethod('POST');
        }

        if ($fourth === 'presencas') {
            return $request->isMethod('PUT');
        }

        if ($fourth === 'atletas' && $fifth === null) {
            return $request->isMethod('POST');
        }

        if ($fourth === 'atletas' && $fifth !== null) {
            return $request->isMethod('DELETE');
        }

        return false;
    }
}
