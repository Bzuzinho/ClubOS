import { ModuleTabsList } from '@/Components/layout/ModuleTabsList';
import { useState, FormEventHandler } from 'react';
import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleTabbedContentClass, moduleTabsClass, moduleViewportClass } from '@/lib/module-layout';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/Components/ui/radio-group';
import { Tabs, TabsContent, TabsTrigger } from '@/Components/ui/tabs';
import { toast } from 'sonner';
import { PersonalTab } from '@/Components/Members/Tabs/PersonalTab';
import { FinancialTab } from '@/Components/Members/Tabs/FinancialTab';
import { ConfigurationTab } from '@/Components/Members/Tabs/ConfigurationTab';
import { SportsTab } from '@/Components/Members/Tabs/SportsTab';

interface User {
    id?: string;
    numero_socio?: string;
    nome_completo: string;
    email_utilizador?: string;
    foto_perfil?: string;
    estado: string;
    tipo_membro: string[];
    data_nascimento: string;
    perfil: string;
    sexo: string;
    rgpd: boolean;
    consentimento: boolean;
    afiliacao: boolean;
    declaracao_de_transporte: boolean;
    password?: string;
    [key: string]: any;
}

interface Props {
    allUsers: User[];
    userTypes: any[];
    ageGroups: any[];
    guardians?: User[];
    monthlyFees?: any[];
    costCenters?: any[];
}

export default function Create({ allUsers, userTypes, ageGroups, guardians, monthlyFees = [], costCenters = [] }: Props) {
    const [user, setUser] = useState<User>({
        nome_completo: '',
        email_utilizador: '',
        sexo: 'masculino',
        perfil: 'atleta',
        estado: 'ativo',
        tipo_membro: [],
        data_nascimento: '',
        rgpd: false,
        consentimento: false,
        afiliacao: false,
        declaracao_de_transporte: false,
        password: '',
    });
    const [hasChanges, setHasChanges] = useState(false);

    const handleChange = (field: any, value: any) => {
        setUser(prev => ({ ...prev, [field]: value }));
        setHasChanges(true);
    };

    const handleSave: FormEventHandler = (e) => {
        e.preventDefault();
        router.post(route('membros.store'), user, {
            onSuccess: () => {
                toast.success('Membro criado com sucesso!');
            },
            onError: (errors) => {
                toast.error('Erro ao criar membro');
                console.error(errors);
            }
        });
    };

    const handleCancel = () => {
        if (hasChanges) {
            if (window.confirm('Tem alterações não guardadas. Deseja sair sem guardar?')) {
                router.visit(route('membros.index'));
            }
        } else {
            router.visit(route('membros.index'));
        }
    };

    const showSportsTab = user.tipo_membro?.includes('atleta') || false;

    return (
        <AuthenticatedLayout
            fullWidth
            header={
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-2">
                        <Button variant="ghost" size="icon" onClick={handleCancel} className="h-8 w-8">
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                        </Button>
                        <div>
                            <h1 className="text-base sm:text-lg font-semibold tracking-tight">
                                Novo Membro
                            </h1>
                            <p className="text-muted-foreground text-xs">
                                Nº de Sócio: (Auto)
                            </p>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" onClick={handleCancel} className="h-8 text-xs">
                            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            Cancelar
                        </Button>
                        <Button size="sm" onClick={handleSave} className="h-8 text-xs">
                            <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                            </svg>
                            Criar Membro
                        </Button>
                    </div>
                </div>
            }
        >
            <Head title="Novo Membro" />

            <div className={moduleViewportClass}>
            <Card className="flex min-h-0 flex-1 flex-col p-2 sm:p-3">
                <Tabs defaultValue="personal" className={moduleTabsClass}>
                    <ModuleTabsList label="Secções disponíveis">
                            <TabsTrigger value="personal" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
                                Pessoal
                            </TabsTrigger>
                            <TabsTrigger value="financial" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
                                Financeiro
                            </TabsTrigger>
                            {showSportsTab && (
                                <TabsTrigger value="sports" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
                                    Desportivo
                                </TabsTrigger>
                            )}
                            <TabsTrigger value="configuration" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">
                                Configuração
                            </TabsTrigger>
                        </ModuleTabsList>

                    <TabsContent value="personal" className={`${moduleTabbedContentClass} space-y-2 bg-white p-0 rounded-lg`}>
                        <PersonalTab 
                            user={user}
                            onChange={handleChange}
                            isAdmin={true}
                            allUsers={allUsers}
                        />
                    </TabsContent>

                    <TabsContent value="financial" className={`${moduleTabbedContentClass} space-y-2 bg-white p-0 rounded-lg`}>
                        <FinancialTab 
                            user={user}
                            onChange={handleChange}
                            isAdmin={true}
                            monthlyFees={monthlyFees}
                            costCenters={costCenters}
                        />
                    </TabsContent>

                    {showSportsTab && (
                        <TabsContent value="sports" className={`${moduleTabbedContentClass} space-y-2 bg-white p-0 rounded-lg`}>
                            <SportsTab 
                                user={user as any}
                                onChange={handleChange}
                                isAdmin={true}
                            />
                        </TabsContent>
                    )}

                    <TabsContent value="configuration" className={`${moduleTabbedContentClass} space-y-2 bg-white p-0 rounded-lg`}>
                        <ConfigurationTab 
                            user={user}
                            onChange={handleChange}
                            isAdmin={true}
                            isCreating={true}
                        />
                    </TabsContent>
                </Tabs>
            </Card>
            </div>

            {hasChanges && (
                <div className="fixed bottom-2 right-2 sm:bottom-4 sm:right-4 bg-accent text-accent-foreground p-2 rounded-lg shadow-lg border">
                    <p className="text-xs font-medium">Alterações não guardadas</p>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
