<?php

declare(strict_types=1);

namespace App\Services\Members;

use Illuminate\Support\Facades\Schema;

final class MemberPersonalDataColumnService
{
    /** @var array<string, bool>|null */
    private static ?array $columns = null;

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    public function existing(array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            fn (string $column): bool => $this->has($column),
        ));
    }

    public function relationSelectForFiscalData(): string
    {
        $columns = $this->existing([
            'id',
            'user_id',
            'nome_completo',
            'nif',
            'morada',
            'codigo_postal',
            'localidade',
            'contacto',
            'email_secundario',
            'telemovel',
            'contacto_telefonico',
        ]);

        return 'dadosPessoais:'.implode(',', $columns);
    }

    public function has(string $column): bool
    {
        if (self::$columns === null) {
            self::$columns = [];

            if (! Schema::hasTable('dados_pessoais')) {
                return false;
            }

            foreach (Schema::getColumnListing('dados_pessoais') as $name) {
                self::$columns[$name] = true;
            }
        }

        return isset(self::$columns[$column]);
    }
}
