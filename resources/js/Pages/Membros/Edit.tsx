import { ModuleTabsList } from '@/Components/layout/ModuleTabsList';
import { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { moduleScrollableContentClass, moduleViewportClass } from '@/lib/module-layout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { Switch } from '@/Components/ui/switch';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Tabs, TabsContent, TabsTrigger } from '@/Components/ui/tabs';
import { ArrowLeft, Save, X } from 'lucide-react';
import { getInitials } from '@/lib/user-helpers';

interface User {
    id: string;
    numero_socio?: string | null;
    nome_completo?: string | null;
    name?: string;
    email?: string;
    email_utilizador?: string | null;
    foto_perfil?: string | null;
    estado?: string;
    tipo_membro?: string[] | null;
    data_nascimento?: string | null;
    menor?: boolean;
    sexo?: string | null;
    nif?: string | null;
    morada?: string | null;
    codigo_postal?: string | null;
    localidade?: string | null;
    telefone?: string | null;
    telemovel?: string | null;
    contacto_emergencia_nome?: string | null;
    contacto_emergencia_telefone?: string | null;
    perfil?: string;
    rgpd?: boolean;
    consentimento?: boolean;
    afiliacao?: boolean;
    declaracao_de_transporte?: boolean;
    tipo_mensalidade?: string | null;
    num_federacao?: string | null;
    cartao_federacao?: string | null;
    numero_pmb?: string | null;
    data_inscricao?: string | null;
    data_atestado_medico?: string | null;
    informacoes_medicas?: string | null;
    ativo_desportivo?: boolean;
    escalao?: string[] | null;
    escalao_id?: string | null;
}

interface Props {
    member: User;
    userTypes?: any[];
    ageGroups?: any[];
    guardians?: any[];
    monthlyFees?: Array<{ id: string; designacao: string; valor: number; ativo?: boolean }>;
}

// ✅ Helper para formatar datas yyyy-MM-dd
const formatDateForInput = (dateString: string | null | undefined): string => {
    if (!dateString) return '';
    return dateString.split('T')[0];
};

const toNumber = (value: unknown, fallback = 0): number => {
    if (typeof value === 'number' && !Number.isNaN(value)) return value;
    if (typeof value === 'string' && value.trim() !== '') {
        const parsed = Number(value);
        return Number.isNaN(parsed) ? fallback : parsed;
    }
    return fallback;
};

export default function MembrosEdit({
    member,
    userTypes = [],
    ageGroups = [],
    guardians = [],
    monthlyFees = [],
}: Props) {
    const { data, setData, put, processing, errors, clearErrors } = useForm({
        numero_socio: member.numero_socio || '',
        nome_completo: member.nome_completo || '',
        data_nascimento: formatDateForInput(member.data_nascimento),
        sexo: member.sexo || 'masculino',
        menor: member.menor || false,
        tipo_membro: Array.isArray(member.tipo_membro) ? member.tipo_membro : [],
        estado: member.estado || 'ativo',
        nif: member.nif || '',
        morada: member.morada || '',
        codigo_postal: member.codigo_postal || '',
        localidade: member.localidade || '',
        telefone: member.telefone || '',
        telemovel: member.telemovel || '',
        email: member.email || '',
        contacto_emergencia_nome: member.contacto_emergencia_nome || '',
        contacto_emergencia_telefone: member.contacto_emergencia_telefone || '',
        email_utilizador: member.email_utilizador || '',
        perfil: member.perfil || 'user',
        password: '',
        tipo_mensalidade: member.tipo_mensalidade || '',
        num_federacao: member.num_federacao || '',
        cartao_federacao: member.cartao_federacao || '',
        numero_pmb: member.numero_pmb || '',
        data_inscricao: formatDateForInput(member.data_inscricao),
        data_atestado_medico: formatDateForInput(member.data_atestado_medico),
        informacoes_medicas: member.informacoes_medicas || '',
        ativo_desportivo: member.ativo_desportivo || false,
        escalao: Array.isArray(member.escalao)
            ? member.escalao
            : member.escalao_id
                ? [member.escalao_id]
                : [],
        escalao_id: member.escalao_id || (Array.isArray(member.escalao) && member.escalao.length > 0 ? member.escalao[0] : ''),
        rgpd: member.rgpd || false,
        consentimento: member.consentimento || false,
        afiliacao: member.afiliacao || false,
        declaracao_de_transporte: member.declaracao_de_transporte || false,
    });

    const isAtleta = data.tipo_membro.includes('atleta');

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        
        clearErrors();
        
        console.log('📤 Submitting update for member:', member.id);
        console.log('📦 Data being sent:', data);
        
        put(route('membros.update', member.id), {
            preserveScroll: true,
            onSuccess: (page) => {
                console.log('✅ Update successful', page);
            },
            onError: (errors) => {
                console.error('❌ Update failed with errors:', errors);
            },
            onFinish: () => {
                console.log('🏁 Request finished');
            },
        });
    };

    const toggleTipoMembro = (tipo: string) => {
        const current = [...data.tipo_membro];
        const index = current.indexOf(tipo);
        
        if (index > -1) {
            current.splice(index, 1);
        } else {
            current.push(tipo);
        }
        
        setData('tipo_membro', current);
    };

    return (
        <AuthenticatedLayout
            fullWidth
            header={
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link href={route('membros.show', member.id)}>
                            <Button variant="ghost" size="sm">
                                <ArrowLeft className="h-4 w-4 mr-2" />
                                Voltar
                            </Button>
                        </Link>
                        <div>
                            <h1 className="text-2xl font-bold text-gray-800 dark:text-gray-100">
                                Editar Membro
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {member.nome_completo || member.name} - #{member.numero_socio || 'N/A'}
                            </p>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Link href={route('membros.show', member.id)}>
                            <Button variant="outline">
                                <X className="h-4 w-4 mr-2" />
                                Cancelar
                            </Button>
                        </Link>
                        <Button onClick={handleSubmit} disabled={processing}>
                            <Save className="h-4 w-4 mr-2" />
                            {processing ? 'A guardar...' : 'Guardar'}
                        </Button>
                    </div>
                </div>
            }
        >
            <Head title={`Editar ${member.nome_completo || member.name}`} />

            <div className={moduleViewportClass}>
                <div className={`${moduleScrollableContentClass} max-w-7xl mx-auto sm:px-6 lg:px-8 py-6`}>
                    <form onSubmit={handleSubmit} className="space-y-6">
                        {/* Exibir erros de validação */}
                        {Object.keys(errors).length > 0 && (
                            <Card className="border-red-500 bg-red-50">
                                <CardContent className="pt-6">
                                    <h3 className="text-red-500 font-semibold mb-2">Erros de Validação:</h3>
                                    <ul className="list-disc list-inside text-sm text-red-500">
                                        {Object.entries(errors).map(([field, error]) => (
                                            <li key={field}><strong>{field}:</strong> {error}</li>
                                        ))}
                                    </ul>
                                </CardContent>
                            </Card>
                        )}

                        {/* Header Card */}
                        <Card>
                            <CardContent className="pt-6">
                                <div className="flex items-start gap-6">
                                    <Avatar className="h-24 w-24">
                                        <AvatarImage src={member.foto_perfil || undefined} />
                                        <AvatarFallback className="text-2xl">
                                            {getInitials(member.nome_completo || member.name)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="flex-1 space-y-4">
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="space-y-2">
                                                <Label htmlFor="nome_completo">
                                                    Nome Completo <span className="text-red-500">*</span>
                                                </Label>
                                                <Input
                                                    id="nome_completo"
                                                    value={data.nome_completo}
                                                    onChange={(e) => setData('nome_completo', e.target.value)}
                                                    required
                                                />
                                                {errors.nome_completo && (
                                                    <p className="text-sm text-red-500">{errors.nome_completo}</p>
                                                )}
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="numero_socio">Número de Sócio</Label>
                                                <Input
                                                    id="numero_socio"
                                                    value={data.numero_socio}
                                                    onChange={(e) => setData('numero_socio', e.target.value)}
                                                />
                                            </div>
                                        </div>

                                        {/* Tipo de Membro */}
                                        <div className="space-y-2">
                                            <Label>Tipo de Membro</Label>
                                            <div className="flex flex-wrap gap-2">
                                                {['atleta', 'encarregado_educacao', 'socio', 'staff', 'treinador', 'dirigente'].map((tipo) => (
                                                    <Button
                                                        key={tipo}
                                                        type="button"
                                                        variant={data.tipo_membro.includes(tipo) ? 'default' : 'outline'}
                                                        size="sm"
                                                        onClick={() => toggleTipoMembro(tipo)}
                                                    >
                                                        {tipo === 'encarregado_educacao' ? 'Encarregado' : tipo.charAt(0).toUpperCase() + tipo.slice(1)}
                                                    </Button>
                                                ))}
                                            </div>
                                        </div>

                                        {/* Estado */}
                                        <div className="space-y-2">
                                            <Label htmlFor="estado">Estado</Label>
                                            <Select value={data.estado} onValueChange={(value) => setData('estado', value)}>
                                                <SelectTrigger id="estado">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="ativo">Ativo</SelectItem>
                                                    <SelectItem value="inativo">Inativo</SelectItem>
                                                    <SelectItem value="suspenso">Suspenso</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Tabs */}
                        <Tabs defaultValue="pessoal" className="w-full">
                            <ModuleTabsList label="Secções disponíveis">
                                <TabsTrigger value="pessoal" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">Dados Pessoais</TabsTrigger>
                                <TabsTrigger value="financeiro" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">Financeiro</TabsTrigger>
                                {isAtleta && <TabsTrigger value="desportivo" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">Desportivo</TabsTrigger>}
                                <TabsTrigger value="configuracao" className="text-xs px-2 py-1.5 whitespace-normal leading-tight text-center min-h-8">Configuração</TabsTrigger>
                            </ModuleTabsList>

                            {/* TAB: Dados Pessoais */}
                            <TabsContent value="pessoal" className="space-y-4">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Informações Pessoais</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="space-y-2">
                                                <Label htmlFor="data_nascimento">Data de Nascimento</Label>
                                                <Input
                                                    id="data_nascimento"
                                                    type="date"
                                                    value={data.data_nascimento}
                                                    onChange={(e) => setData('data_nascimento', e.target.value)}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="sexo">Sexo</Label>
                                                <Select value={data.sexo} onValueChange={(value) => setData('sexo', value)}>
                                                    <SelectTrigger id="sexo">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="masculino">Masculino</SelectItem>
                                                        <SelectItem value="feminino">Feminino</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="nif">NIF</Label>
                                                <Input
                                                    id="nif"
                                                    value={data.nif}
                                                    onChange={(e) => setData('nif', e.target.value)}
                                                />
                                            </div>
                                            <div className="flex items-center space-x-2 pt-8">
                                                <Switch
                                                    id="menor"
                                                    checked={data.menor}
                                                    onCheckedChange={(checked) => setData('menor', checked)}
                                                />
                                                <Label htmlFor="menor">É menor de idade</Label>
                                            </div>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="morada">Morada</Label>
                                            <Textarea
                                                id="morada"
                                                value={data.morada}
                                                onChange={(e) => setData('morada', e.target.value)}
                                                rows={2}
                                            />
                                        </div>

                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="space-y-2">
                                                <Label htmlFor="codigo_postal">Código Postal</Label>
                                                <Input
                                                    id="codigo_postal"
                                                    value={data.codigo_postal}
                                                    onChange={(e) => setData('codigo_postal', e.target.value)}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="localidade">Localidade</Label>
                                                <Input
                                                    id="localidade"
                                                    value={data.localidade}
                                                    onChange={(e) => setData('localidade', e.target.value)}
                                                />
                                            </div>
                                        </div>

                                        <div className="pt-4 border-t">
                                            <h3 className="text-sm font-semibold mb-4">Contactos</h3>
                                            <div className="grid grid-cols-2 gap-4">
                                                <div className="space-y-2">
                                                    <Label htmlFor="email">Email</Label>
                                                    <Input
                                                        id="email"
                                                        type="email"
                                                        value={data.email}
                                                        onChange={(e) => setData('email', e.target.value)}
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="telefone">Telefone</Label>
                                                    <Input
                                                        id="telefone"
                                                        value={data.telefone}
                                                        onChange={(e) => setData('telefone', e.target.value)}
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="telemovel">Telemóvel</Label>
                                                    <Input
                                                        id="telemovel"
                                                        value={data.telemovel}
                                                        onChange={(e) => setData('telemovel', e.target.value)}
                                                    />
                                                </div>
                                            </div>
                                        </div>

                                        <div className="pt-4 border-t">
                                            <h3 className="text-sm font-semibold mb-4">Contacto de Emergência</h3>
                                            <div className="grid grid-cols-2 gap-4">
                                                <div className="space-y-2">
                                                    <Label htmlFor="contacto_emergencia_nome">Nome</Label>
                                                    <Input
                                                        id="contacto_emergencia_nome"
                                                        value={data.contacto_emergencia_nome}
                                                        onChange={(e) => setData('contacto_emergencia_nome', e.target.value)}
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="contacto_emergencia_telefone">Telefone</Label>
                                                    <Input
                                                        id="contacto_emergencia_telefone"
                                                        value={data.contacto_emergencia_telefone}
                                                        onChange={(e) => setData('contacto_emergencia_telefone', e.target.value)}
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* TAB: Financeiro */}
                            <TabsContent value="financeiro" className="space-y-4">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Informações Financeiras</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="tipo_mensalidade">Tipo de Mensalidade</Label>
                                            <Select
                                                value={data.tipo_mensalidade}
                                                onValueChange={(value) => setData('tipo_mensalidade', value)}
                                            >
                                                <SelectTrigger id="tipo_mensalidade">
                                                    <SelectValue placeholder="Selecionar tipo de mensalidade" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {monthlyFees.filter((fee) => fee.ativo !== false).length === 0 ? (
                                                        <div className="px-2 py-4 text-center text-xs text-muted-foreground">
                                                            Nenhuma mensalidade configurada. Configure em Configurações → Financeiro.
                                                        </div>
                                                    ) : (
                                                        monthlyFees
                                                            .filter((fee) => fee.ativo !== false)
                                                            .map((fee) => (
                                                                <SelectItem key={fee.id} value={fee.id}>
                                                                    {fee.designacao} - €{toNumber(fee.valor).toFixed(2)}
                                                                </SelectItem>
                                                            ))
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </CardContent>
                                </Card>
                            </TabsContent>

                            {/* TAB: Desportivo */}
                            {isAtleta && (
                                <TabsContent value="desportivo" className="space-y-4">
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>Dados Desportivos</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <div className="grid grid-cols-2 gap-4">
                                                <div className="space-y-2">
                                                    <Label htmlFor="num_federacao">Nº Federação</Label>
                                                    <Input
                                                        id="num_federacao"
                                                        value={data.num_federacao}
                                                        onChange={(e) => setData('num_federacao', e.target.value)}
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="cartao_federacao">Cartão Federação</Label>
                                                    <Input
                                                        id="cartao_federacao"
                                                        value={data.cartao_federacao}
                                                        onChange={(e) => setData('cartao_federacao', e.target.value)}
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="numero_pmb">Nº PMB</Label>
                                                    <Input
                                                        id="numero_pmb"
                                                        value={data.numero_pmb}
                                                        onChange={(e) => setData('numero_pmb', e.target.value)}
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="escalao">Escalão</Label>
                                                    <Select
                                                        value={data.escalao?.[0] || data.escalao_id || ''}
                                                        onValueChange={(value) => {
                                                            setData('escalao', value ? [value] : []);
                                                            setData('escalao_id', value || '');
                                                        }}
                                                    >
                                                        <SelectTrigger id="escalao">
                                                            <SelectValue placeholder="Selecionar escalão" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {ageGroups.map((group) => (
                                                                <SelectItem key={group.id} value={group.id}>
                                                                    {group.nome}
                                                                </SelectItem>
                                                            ))}
                                                        </SelectContent>
                                                    </Select>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="data_inscricao">Data de Inscrição</Label>
                                                    <Input
                                                        id="data_inscricao"
                                                        type="date"
                                                        value={data.data_inscricao}
                                                        onChange={(e) => setData('data_inscricao', e.target.value)}
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="data_atestado_medico">Atestado Médico</Label>
                                                    <Input
                                                        id="data_atestado_medico"
                                                        type="date"
                                                        value={data.data_atestado_medico}
                                                        onChange={(e) => setData('data_atestado_medico', e.target.value)}
                                                    />
                                                </div>
                                                <div className="flex items-center space-x-2 pt-8">
                                                    <Switch
                                                        id="ativo_desportivo"
                                                        checked={data.ativo_desportivo}
                                                        onCheckedChange={(checked) => setData('ativo_desportivo', checked)}
                                                    />
                                                    <Label htmlFor="ativo_desportivo">Ativo Desportivo</Label>
                                                </div>
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="informacoes_medicas">Informações Médicas</Label>
                                                <Textarea
                                                    id="informacoes_medicas"
                                                    value={data.informacoes_medicas}
                                                    onChange={(e) => setData('informacoes_medicas', e.target.value)}
                                                    rows={3}
                                                />
                                            </div>
                                        </CardContent>
                                    </Card>
                                </TabsContent>
                            )}

                            {/* TAB: Configuração */}
                            <TabsContent value="configuracao" className="space-y-4">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>Acesso à Plataforma</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="space-y-2">
                                                <Label htmlFor="email_utilizador">Email de Autenticação</Label>
                                                <Input
                                                    id="email_utilizador"
                                                    type="email"
                                                    value={data.email_utilizador}
                                                    onChange={(e) => setData('email_utilizador', e.target.value)}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="perfil">Perfil</Label>
                                                <Select value={data.perfil} onValueChange={(value) => setData('perfil', value)}>
                                                    <SelectTrigger id="perfil">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="admin">Administrador</SelectItem>
                                                        <SelectItem value="encarregado">Encarregado</SelectItem>
                                                        <SelectItem value="atleta">Atleta</SelectItem>
                                                        <SelectItem value="staff">Staff</SelectItem>
                                                        <SelectItem value="user">Utilizador</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-2 col-span-2">
                                                <Label htmlFor="password">Nova Password (deixe em branco para não alterar)</Label>
                                                <Input
                                                    id="password"
                                                    type="password"
                                                    value={data.password}
                                                    onChange={(e) => setData('password', e.target.value)}
                                                    placeholder="••••••••"
                                                />
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>RGPD e Consentimentos</CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="flex items-center justify-between">
                                            <Label htmlFor="rgpd">RGPD</Label>
                                            <Switch
                                                id="rgpd"
                                                checked={data.rgpd}
                                                onCheckedChange={(checked) => setData('rgpd', checked)}
                                            />
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <Label htmlFor="consentimento">Consentimento</Label>
                                            <Switch
                                                id="consentimento"
                                                checked={data.consentimento}
                                                onCheckedChange={(checked) => setData('consentimento', checked)}
                                            />
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <Label htmlFor="afiliacao">Afiliação</Label>
                                            <Switch
                                                id="afiliacao"
                                                checked={data.afiliacao}
                                                onCheckedChange={(checked) => setData('afiliacao', checked)}
                                            />
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <Label htmlFor="declaracao_de_transporte">Declaração de Transporte</Label>
                                            <Switch
                                                id="declaracao_de_transporte"
                                                checked={data.declaracao_de_transporte}
                                                onCheckedChange={(checked) => setData('declaracao_de_transporte', checked)}
                                            />
                                        </div>
                                    </CardContent>
                                </Card>
                            </TabsContent>
                        </Tabs>

                        {/* Action Buttons */}
                        <div className="flex items-center justify-end gap-4">
                            <Link href={route('membros.show', member.id)}>
                                <Button type="button" variant="outline">
                                    Cancelar
                                </Button>
                            </Link>
                            <Button type="submit" disabled={processing}>
                                <Save className="h-4 w-4 mr-2" />
                                {processing ? 'A guardar...' : 'Guardar Alterações'}
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
