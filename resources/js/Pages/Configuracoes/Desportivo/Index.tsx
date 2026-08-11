import { router } from '@inertiajs/react';
import { ArrowSquareOut, GearSix } from '@phosphor-icons/react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';

interface Props {
    embedded?: boolean;
}

export default function ConfiguracoesDesportivoIndex({ embedded = false }: Props) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <GearSix size={18} />
                    Configuração Desportiva
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-sm text-muted-foreground">
                    A configuração técnica deixou de pertencer às Configurações globais do ClubOS.
                    Estados, tipos de treino, intensidades, ausências, tipos de piscina, tipos de prova
                    e limitações operacionais são agora geridos pelo próprio módulo Desportivo.
                </p>
                <Button
                    type="button"
                    variant={embedded ? 'default' : 'outline'}
                    className="gap-2"
                    onClick={() => router.get(route('desportivo.configuracao.index'))}
                >
                    Abrir Configuração Desportiva
                    <ArrowSquareOut size={16} />
                </Button>
            </CardContent>
        </Card>
    );
}
