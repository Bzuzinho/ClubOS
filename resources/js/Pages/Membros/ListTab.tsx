import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ListBullets, Plus, SquaresFour, Trash, Users as UsersIcon } from '@phosphor-icons/react';

import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/Components/ui/alert-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { MemberImportDialog } from '@/Components/Members/MemberImportDialog';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { getMemberTypeLabel, getStatusColor, getStatusLabel } from '@/lib/user-helpers';

interface User {
    id: string;
    numero_socio: string;
    nome_completo: string;
    email_utilizador?: string;
    foto_perfil?: string;
    estado: string;
    tipo_membro: string[];
}

interface Props {
    members: User[];
    membersPagination?: MembersPagination;
    filters?: MemberFilters;
    userTypes: any[];
}

interface MemberFilters {
    search?: string;
    status?: string;
    sports_status?: 'ativo' | 'inativo' | null;
}

interface MembersPagination {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number | null;
    to: number | null;
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
}

export default function MembrosListTab({ members, membersPagination, filters, userTypes }: Props) {
    const [searchTerm, setSearchTerm] = useState('');
    const [statusFilter, setStatusFilter] = useState<string>('all');
    const [sportsStatusFilter, setSportsStatusFilter] = useState<string>(filters?.sports_status || 'all');
    const [typeFilter, setTypeFilter] = useState<string>('all');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [userToDelete, setUserToDelete] = useState<User | null>(null);
    const [viewMode, setViewMode] = useState<'card' | 'table'>('card');

    useEffect(() => {
        setSportsStatusFilter(filters?.sports_status || 'all');
    }, [filters?.sports_status]);

    const filteredUsers = useMemo(() => {
        return members.filter((user) => {
            const matchesSearch =
                (user.nome_completo || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                (user.numero_socio || '').toLowerCase().includes(searchTerm.toLowerCase()) ||
                (user.email_utilizador || '').toLowerCase().includes(searchTerm.toLowerCase());

            const matchesStatus = statusFilter === 'all' || user.estado === statusFilter;
            const tipoMembro = Array.isArray(user.tipo_membro) ? user.tipo_membro : [];
            const matchesType = typeFilter === 'all' || tipoMembro.includes(typeFilter);

            return matchesSearch && matchesStatus && matchesType;
        });
    }, [members, searchTerm, statusFilter, typeFilter]);

    const getInitials = useCallback((name: string) => {
        if (!name) {
            return '?';
        }

        return name
            .split(' ')
            .map((part) => part[0])
            .slice(0, 2)
            .join('')
            .toUpperCase();
    }, []);

    const handleDeleteClick = useCallback((user: User, event: React.MouseEvent) => {
        event.preventDefault();
        event.stopPropagation();
        setUserToDelete(user);
        setDeleteDialogOpen(true);
    }, []);

    const handleSportsStatusChange = useCallback((value: string) => {
        setSportsStatusFilter(value);

        const params = Object.fromEntries(new URLSearchParams(window.location.search).entries()) as Record<string, string>;
        params.tab = 'list';
        delete params.page;

        if (value === 'all') {
            delete params.sports_status;
        } else {
            params.sports_status = value;
        }

        router.get(route('membros.index'), params, {
            only: ['members', 'membersPagination', 'filters'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }, []);

    const confirmDelete = useCallback(() => {
        if (!userToDelete) {
            return;
        }

        router.delete(route('membros.destroy', userToDelete.id), {
            onSuccess: () => {
                setDeleteDialogOpen(false);
                setUserToDelete(null);
            },
        });
    }, [userToDelete]);

    return (
        <div className="space-y-2.5">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-xs text-muted-foreground">
                    {membersPagination
                        ? `${membersPagination.from ?? 0}-${membersPagination.to ?? 0} de ${membersPagination.total} membros`
                        : `${filteredUsers.length} de ${members.length} membros`}
                </p>

                <div className="flex items-center gap-1.5">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 text-xs"
                        onClick={() => window.location.assign(route('membros.import.template'))}
                    >
                        Modelo
                    </Button>
                    <MemberImportDialog />
                    <Button
                        variant={viewMode === 'card' ? 'default' : 'outline'}
                        size="sm"
                        className="h-8 w-8 p-0"
                        onClick={() => setViewMode('card')}
                    >
                        <SquaresFour size={14} weight={viewMode === 'card' ? 'fill' : 'regular'} />
                    </Button>
                    <Button
                        variant={viewMode === 'table' ? 'default' : 'outline'}
                        size="sm"
                        className="h-8 w-8 p-0"
                        onClick={() => setViewMode('table')}
                    >
                        <ListBullets size={14} weight={viewMode === 'table' ? 'fill' : 'regular'} />
                    </Button>
                    <Link href={route('membros.create')}>
                        <Button size="sm" className="h-8 text-xs">
                            <Plus size={14} weight="bold" className="mr-1" />
                            Novo Membro
                        </Button>
                    </Link>
                </div>
            </div>

            <Card className="p-2.5">
                <div className="flex flex-col gap-2 sm:flex-row">
                    <div className="relative flex-1">
                        <Input
                            placeholder="Pesquisar por nome, nº sócio ou email..."
                            value={searchTerm}
                            onChange={(event) => setSearchTerm(event.target.value)}
                            className="h-8 text-[11px]"
                        />
                    </div>
                    <Select value={statusFilter} onValueChange={setStatusFilter}>
                        <SelectTrigger className="w-full sm:w-[140px] h-8 text-[11px]">
                            <SelectValue placeholder="Estado" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos os Estados</SelectItem>
                            <SelectItem value="ativo">Ativo</SelectItem>
                            <SelectItem value="inativo">Inativo</SelectItem>
                            <SelectItem value="suspenso">Suspenso</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={sportsStatusFilter} onValueChange={handleSportsStatusChange}>
                        <SelectTrigger className="w-full sm:w-[160px] h-8 text-[11px]">
                            <SelectValue placeholder="Estado desportivo" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos os estados desportivos</SelectItem>
                            <SelectItem value="ativo">Ativo</SelectItem>
                            <SelectItem value="inativo">Não ativo</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={typeFilter} onValueChange={setTypeFilter}>
                        <SelectTrigger className="w-full sm:w-[140px] h-8 text-[11px]">
                            <SelectValue placeholder="Tipo" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Todos os Tipos</SelectItem>
                            {userTypes && userTypes.length > 0
                                ? userTypes.map((tipo: any) => {
                                      const typeMapping: Record<string, string> = {
                                          Atleta: 'atleta',
                                          'Encarregado de Educação': 'encarregado_educacao',
                                          Treinador: 'treinador',
                                          Dirigente: 'dirigente',
                                          'Sócio': 'socio',
                                          'Funcionário': 'funcionario',
                                      };
                                      const tipoNome = tipo?.nome || '';
                                      const tipoValue =
                                          typeMapping[tipoNome] ||
                                          tipoNome
                                              .toLowerCase()
                                              .replace(/\s+/g, '_')
                                              .replace(/ç/g, 'c')
                                              .replace(/ã/g, 'a');

                                      return (
                                          <SelectItem key={tipo.id} value={tipoValue}>
                                              {tipoNome}
                                          </SelectItem>
                                      );
                                  })
                                : null}
                        </SelectContent>
                    </Select>
                </div>
                <p className="mt-2 text-[11px] text-muted-foreground">
                    Nesta lista mostramos o tipo de utilizador. O perfil e permissões são geridos na tab Configuração da ficha do membro.
                </p>
            </Card>

            {filteredUsers.length > 0 ? (
                viewMode === 'card' ? (
                    <div className="grid gap-2.5 grid-cols-1 sm:grid-cols-2 lg:grid-cols-5">
                        {filteredUsers.map((user) => (
                            <Link key={user.id} href={route('membros.show', user.id)}>
                                <Card className="p-2.5 cursor-pointer transition-all hover:shadow-lg hover:border-primary/50 h-full">
                                    <div className="flex items-start gap-2">
                                        <Avatar className="h-9 w-9 flex-shrink-0">
                                            <AvatarImage src={user.foto_perfil} />
                                            <AvatarFallback className="bg-primary/10 text-primary font-semibold text-[10px]">
                                                {getInitials(user.nome_completo)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <div className="flex-1 min-w-0">
                                            <h3 className="font-semibold text-[13px] truncate">{user.nome_completo || 'Sem nome'}</h3>
                                            <p className="text-[10px] text-muted-foreground">Nº {user.numero_socio || '-'}</p>
                                            <div className="flex flex-wrap gap-1 mt-1">
                                                <Badge variant="outline" className={`${getStatusColor(user.estado)} text-[10px] px-1.5 py-0`}>
                                                    {getStatusLabel(user.estado)}
                                                </Badge>
                                                {Array.isArray(user.tipo_membro) && user.tipo_membro.slice(0, 2).map((type, index) => (
                                                    <Badge key={index} variant="secondary" className="text-[10px] px-1.5 py-0">
                                                        {getMemberTypeLabel(type)}
                                                    </Badge>
                                                ))}
                                                {Array.isArray(user.tipo_membro) && user.tipo_membro.length > 2 ? (
                                                    <Badge variant="secondary" className="text-[10px] px-1.5 py-0">
                                                        +{user.tipo_membro.length - 2}
                                                    </Badge>
                                                ) : null}
                                            </div>
                                        </div>
                                        <div className="flex flex-col gap-1">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="h-7 w-7 p-0 hover:bg-destructive/10 hover:text-destructive"
                                                onClick={(event) => handleDeleteClick(user, event)}
                                            >
                                                <Trash size={14} />
                                            </Button>
                                        </div>
                                    </div>
                                </Card>
                            </Link>
                        ))}
                    </div>
                ) : (
                    <Card className="p-0 overflow-hidden">
                        <div className="w-full overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-16">Nº Sócio</TableHead>
                                        <TableHead className="flex-1 min-w-[200px]">Nome</TableHead>
                                        <TableHead className="hidden md:table-cell min-w-[180px]">Email</TableHead>
                                        <TableHead className="hidden lg:table-cell w-24">Estado</TableHead>
                                        <TableHead className="hidden xl:table-cell flex-1 min-w-[180px]">Tipo</TableHead>
                                        <TableHead className="w-20 text-right">Ação</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {filteredUsers.map((user) => (
                                        <TableRow key={user.id}>
                                            <TableCell className="text-xs font-medium">{user.numero_socio || '-'}</TableCell>
                                            <TableCell className="max-w-[200px]">
                                                <Link href={route('membros.show', user.id)} className="font-medium hover:underline truncate block">
                                                    {user.nome_completo || 'Sem nome'}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="hidden md:table-cell text-xs text-muted-foreground max-w-[180px] truncate">{user.email_utilizador || '-'}</TableCell>
                                            <TableCell className="hidden lg:table-cell">
                                                <Badge variant="outline" className={`${getStatusColor(user.estado)} text-[10px] px-1.5 py-0`}>
                                                    {getStatusLabel(user.estado)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="hidden xl:table-cell">
                                                <div className="flex flex-wrap gap-1">
                                                    {Array.isArray(user.tipo_membro) && user.tipo_membro.length > 0 ? (
                                                        user.tipo_membro.slice(0, 2).map((type, index) => (
                                                            <Badge key={index} variant="secondary" className="text-[10px] px-1.5 py-0">
                                                                {getMemberTypeLabel(type)}
                                                            </Badge>
                                                        ))
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">-</span>
                                                    )}
                                                    {Array.isArray(user.tipo_membro) && user.tipo_membro.length > 2 ? (
                                                        <Badge variant="secondary" className="text-[10px] px-1.5 py-0">
                                                            +{user.tipo_membro.length - 2}
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-1">
                                                    <Link href={route('membros.show', user.id)}>
                                                        <Button variant="outline" size="sm" className="h-7 px-2 text-xs">Ver</Button>
                                                    </Link>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-7 w-7 p-0 hover:bg-destructive/10 hover:text-destructive"
                                                        onClick={(event) => handleDeleteClick(user, event)}
                                                    >
                                                        <Trash size={14} />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </Card>
                )
            ) : (
                <Card className="p-8">
                    <div className="text-center">
                        <UsersIcon size={40} className="mx-auto text-muted-foreground mb-3" weight="duotone" />
                        <h3 className="text-base font-semibold mb-1.5">Nenhum membro encontrado</h3>
                        <p className="text-muted-foreground mb-3 text-xs">
                            Ajuste os filtros de estado, estado desportivo ou tipo, ou pesquise por nome, número de sócio e email.
                        </p>
                        <Link href={route('membros.create')}>
                            <Button size="sm" className="h-8 text-xs">
                                <Plus size={14} weight="bold" className="mr-1.5" />
                                Criar Primeiro Membro
                            </Button>
                        </Link>
                    </div>
                </Card>
            )}

            <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Confirmar eliminação</AlertDialogTitle>
                        <AlertDialogDescription>
                            Tem a certeza que deseja apagar o membro <strong>{userToDelete?.nome_completo}</strong>?
                            Esta ação não pode ser revertida.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel onClick={() => setUserToDelete(null)}>Cancelar</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmDelete} className="bg-destructive hover:bg-destructive/90">
                            Apagar
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {membersPagination && membersPagination.last_page > 1 ? (
                <div className="flex flex-wrap items-center justify-end gap-1.5">
                    {membersPagination.links.map((link, index) => (
                        <Button
                            key={`${link.label}-${index}`}
                            type="button"
                            variant={link.active ? 'default' : 'outline'}
                            size="sm"
                            className="h-8 min-w-8 px-2 text-xs"
                            disabled={!link.url}
                            onClick={() => {
                                if (link.url) {
                                    router.visit(link.url, { preserveScroll: true, preserveState: true });
                                }
                            }}
                        >
                            {link.label.replace('&laquo;', '‹').replace('&raquo;', '›')}
                        </Button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}
