import { useEffect, useMemo, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowUUpLeft,
    CaretDown,
    Check,
    EnvelopeSimple,
    Eye,
    EyeSlash,
    PaperPlaneTilt,
    Trash,
} from '@phosphor-icons/react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';

export interface MemberOption {
    id: string;
    numero_socio?: string;
    nome_completo?: string;
    name?: string;
    email_utilizador?: string;
    email?: string;
}

interface ReplyTo {
    id: string;
    subject: string;
    sender_name: string;
}

export interface ReceivedCommunication {
    recipient_entry_id: string | null;
    message_id: string;
    folder: 'received';
    subject: string;
    message: string;
    type: CommunicationType;
    created_at: string | null;
    is_read: boolean;
    read_at: string | null;
    sender: {
        id: string | null;
        name: string;
        email?: string | null;
    };
    source?: 'internal' | 'alert';
    alert_id?: string | null;
    link?: string | null;
    reply_to: ReplyTo | null;
}

interface SentRecipient {
    id: string;
    name: string;
    email?: string | null;
    is_read: boolean;
    read_at: string | null;
    deleted_at: string | null;
}

export interface SentCommunication {
    message_id: string;
    folder: 'sent';
    subject: string;
    message: string;
    type: CommunicationType;
    created_at: string | null;
    recipient_count: number;
    recipients: SentRecipient[];
    reply_to: ReplyTo | null;
}

type CommunicationType = 'info' | 'warning' | 'success' | 'error';
export type MailboxFolder = 'received' | 'sent';
type CommunicationItem = ReceivedCommunication | SentCommunication;

interface PageProps {
    auth: {
        user?: {
            id: string | number;
        };
    };
}

interface Props {
    members: MemberOption[];
    communications: {
        received: ReceivedCommunication[];
        sent: SentCommunication[];
    };
    initialFolder?: MailboxFolder;
    initialMessageId?: string | null;
    readOnly?: boolean;
    ownerLabel?: string;
    context?: 'member' | 'portal';
}

const typeOptions: Array<{ value: CommunicationType; label: string }> = [
    { value: 'info', label: 'Informação' },
    { value: 'warning', label: 'Aviso' },
    { value: 'success', label: 'Sucesso' },
    { value: 'error', label: 'Erro' },
];

const typeBadgeClassName: Record<CommunicationType, string> = {
    info: 'bg-sky-100 text-sky-800 border-sky-200',
    warning: 'bg-amber-100 text-amber-800 border-amber-200',
    success: 'bg-emerald-100 text-emerald-800 border-emerald-200',
    error: 'bg-rose-100 text-rose-800 border-rose-200',
};

function formatDateTime(value?: string | null) {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(date);
}

function memberDisplayName(member: MemberOption) {
    return member.nome_completo || member.name || 'Utilizador sem nome';
}

function subjectForReply(subject: string) {
    return subject.toLowerCase().startsWith('re:') ? subject : `Re: ${subject}`;
}

function sourceLabel(item: CommunicationItem): string {
    if (item.folder === 'sent') {
        return 'Mensagem interna';
    }

    return item.source === 'alert' ? 'Alerta' : 'Mensagem interna';
}

export default function CommunicationsTab({
    members,
    communications,
    initialFolder = 'received',
    initialMessageId = null,
    readOnly = false,
    ownerLabel,
    context = 'member',
}: Props) {
    const { auth } = usePage<PageProps & Record<string, unknown>>().props;
    const currentUserId = auth.user ? String(auth.user.id) : null;
    const [folder, setFolder] = useState<MailboxFolder>(initialFolder);
    const [viewedItem, setViewedItem] = useState<CommunicationItem | null>(null);
    const [composeOpen, setComposeOpen] = useState(false);
    const [recipientSearch, setRecipientSearch] = useState('');

    const form = useForm<{
        recipient_ids: string[];
        subject: string;
        message: string;
        type: CommunicationType;
        parent_id: string | null;
    }>({
        recipient_ids: [],
        subject: '',
        message: '',
        type: 'info',
        parent_id: null,
    });

    const mailboxItems = folder === 'received' ? communications.received : communications.sent;

    const selectableMembers = useMemo(() => {
        return members
            .filter((member) => String(member.id) !== currentUserId)
            .filter((member) => {
                const needle = recipientSearch.trim().toLowerCase();

                if (!needle) {
                    return true;
                }

                return [
                    memberDisplayName(member),
                    member.numero_socio || '',
                    member.email_utilizador || member.email || '',
                ].some((value) => value.toLowerCase().includes(needle));
            })
            .sort((left, right) => memberDisplayName(left).localeCompare(memberDisplayName(right), 'pt'));
    }, [currentUserId, members, recipientSearch]);

    useEffect(() => {
        setFolder(initialFolder);
    }, [initialFolder]);

    useEffect(() => {
        if (!initialMessageId) {
            return;
        }

        const found = mailboxItems.find((item) => item.message_id === initialMessageId);
        if (found) {
            setViewedItem(found);
        }
    }, [initialMessageId, mailboxItems]);

    const receivedCount = communications.received.length;
    const unreadCount = communications.received.filter((item) => !item.is_read).length;
    const sentCount = communications.sent.length;
    const ownerReference = ownerLabel || 'o utilizador atual';
    const reloadProps = context === 'portal'
        ? ['internalCommunications', 'communications', 'communicationAlerts', 'flash']
        : ['internalCommunications', 'communicationAlerts', 'flash'];
    const formReloadProps = [...reloadProps, 'errors'];

    const markReceivedAsRead = (item: ReceivedCommunication) => {
        if (context === 'portal') {
            router.post(route('portal.communications.read'), {
                source: item.source === 'alert' ? 'alert' : 'internal',
                alert_id: item.alert_id,
                recipient_entry_id: item.recipient_entry_id,
            }, {
                preserveScroll: true,
                preserveState: true,
                only: reloadProps,
            });
            return;
        }

        if (item.source === 'alert' && item.alert_id) {
            router.post(route('comunicacao.alerts.markRead'), {
                alert_id: item.alert_id,
            }, {
                preserveScroll: true,
                preserveState: true,
                only: reloadProps,
            });
            return;
        }

        if (!item.recipient_entry_id) {
            return;
        }

        router.post(route('membros.comunicacoes.recebidas.read', item.recipient_entry_id), {}, {
            preserveScroll: true,
            preserveState: true,
            only: reloadProps,
        });
    };

    const handleToggleRecipient = (memberId: string, checked: boolean) => {
        form.setData('recipient_ids', checked
            ? [...form.data.recipient_ids, memberId]
            : form.data.recipient_ids.filter((currentId) => currentId !== memberId));
    };

    const resetCompose = () => {
        form.reset();
        form.clearErrors();
        form.setData('type', 'info');
        form.setData('parent_id', null);
        setRecipientSearch('');
    };

    const openNewCompose = () => {
        resetCompose();
        setComposeOpen(true);
    };

    const openReplyCompose = (item: ReceivedCommunication) => {
        resetCompose();
        form.setData({
            recipient_ids: item.sender.id ? [item.sender.id] : [],
            subject: subjectForReply(item.subject),
            message: `\n\n--- Mensagem original ---\n${item.message}`,
            type: 'info',
            parent_id: item.source === 'alert' ? null : item.message_id,
        });
        setComposeOpen(true);
    };

    const submitCompose = () => {
        form.post(route(context === 'portal' ? 'portal.communications.store' : 'membros.comunicacoes.store'), {
            preserveScroll: true,
            preserveState: true,
            only: formReloadProps,
            onSuccess: () => {
                setComposeOpen(false);
                setFolder('sent');
                resetCompose();
            },
        });
    };

    const openReplyFromSent = (item: SentCommunication) => {
        const recipients = item.recipients
            .map((recipient) => recipient.id)
            .filter(Boolean);

        if (recipients.length === 0) {
            return;
        }

        resetCompose();
        form.setData({
            recipient_ids: recipients,
            subject: subjectForReply(item.subject),
            message: `\n\n--- Mensagem original ---\n${item.message}`,
            type: 'info',
            parent_id: item.message_id,
        });
        setComposeOpen(true);
    };

    const markAsUnread = (item: ReceivedCommunication) => {
        if (context === 'portal') {
            router.post(route('portal.communications.unread'), {
                source: item.source === 'alert' ? 'alert' : 'internal',
                alert_id: item.alert_id,
                recipient_entry_id: item.recipient_entry_id,
            }, {
                preserveScroll: true,
                preserveState: true,
                only: reloadProps,
            });
            return;
        }

        if (item.source === 'alert' && item.alert_id) {
            router.post(route('comunicacao.alerts.markUnread'), {
                alert_id: item.alert_id,
            }, {
                preserveScroll: true,
                preserveState: true,
                only: reloadProps,
            });
            return;
        }

        if (!item.recipient_entry_id) {
            return;
        }

        router.post(route('membros.comunicacoes.recebidas.unread', item.recipient_entry_id), {}, {
            preserveScroll: true,
            preserveState: true,
            only: reloadProps,
        });
    };

    const deleteReceived = (item: ReceivedCommunication) => {
        if (context === 'portal') {
            router.delete(route('portal.communications.received.destroy'), {
                data: {
                    source: item.source === 'alert' ? 'alert' : 'internal',
                    alert_id: item.alert_id,
                    recipient_entry_id: item.recipient_entry_id,
                },
                preserveScroll: true,
                preserveState: true,
                only: reloadProps,
                onSuccess: () => {
                    if (viewedItem?.message_id === item.message_id) {
                        setViewedItem(null);
                    }
                },
            });
            return;
        }

        if (item.source === 'alert' && item.alert_id) {
            router.delete(route('comunicacao.alerts.destroy', item.alert_id), {
                preserveScroll: true,
                preserveState: true,
                only: reloadProps,
                onSuccess: () => {
                    if (viewedItem?.message_id === item.message_id) {
                        setViewedItem(null);
                    }
                },
            });
            return;
        }

        if (!item.recipient_entry_id) {
            return;
        }

        router.delete(route('membros.comunicacoes.recebidas.destroy', item.recipient_entry_id), {
            preserveScroll: true,
            preserveState: true,
            only: reloadProps,
            onSuccess: () => {
                if (viewedItem?.message_id === item.message_id) {
                    setViewedItem(null);
                }
            },
        });
    };

    const deleteSent = (item: SentCommunication) => {
        router.delete(route(context === 'portal' ? 'portal.communications.sent.destroy' : 'membros.comunicacoes.enviadas.destroy', item.message_id), {
            preserveScroll: true,
            preserveState: true,
            only: context === 'portal' ? reloadProps : ['internalCommunications', 'flash'],
            onSuccess: () => {
                if (viewedItem?.message_id === item.message_id) {
                    setViewedItem(null);
                }
            },
        });
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant={folder === 'received' ? 'default' : 'outline'}
                        size="sm"
                        className="h-8 text-xs"
                        onClick={() => setFolder('received')}
                    >
                        <EnvelopeSimple size={14} className="mr-1.5" />
                        Recebidas
                        <span className="ml-1.5 text-[11px] opacity-80">{receivedCount}</span>
                    </Button>
                    <Button
                        variant={folder === 'sent' ? 'default' : 'outline'}
                        size="sm"
                        className="h-8 text-xs"
                        onClick={() => setFolder('sent')}
                    >
                        <PaperPlaneTilt size={14} className="mr-1.5" />
                        Enviadas
                        <span className="ml-1.5 text-[11px] opacity-80">{sentCount}</span>
                    </Button>
                    <Badge variant="outline" className="text-[11px]">
                        {unreadCount} por ler
                    </Badge>
                </div>

                {!readOnly && (
                    <Button size="sm" className="h-8 text-xs" onClick={openNewCompose}>
                        <PaperPlaneTilt size={14} className="mr-1.5" weight="fill" />
                        Nova comunicação
                    </Button>
                )}
            </div>

            <Card className="p-0 overflow-hidden">
                    <div className="border-b px-3 py-2">
                        <p className="text-xs text-muted-foreground">
                            {folder === 'received'
                                ? `Mensagens internas recebidas por ${ownerReference}.`
                                : `Mensagens internas enviadas por ${ownerReference}.`}
                        </p>
                    </div>

                    {mailboxItems.length === 0 ? (
                        <div className="p-8 text-center">
                            <EnvelopeSimple size={34} className="mx-auto mb-3 text-muted-foreground" weight="duotone" />
                            <h3 className="text-sm font-semibold">Sem comunicações nesta caixa</h3>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {folder === 'received'
                                    ? `Quando ${ownerReference} receber mensagens internas, elas aparecerão aqui.`
                                    : `As mensagens internas enviadas por ${ownerReference} aparecerão aqui.`}
                            </p>
                        </div>
                    ) : (
                        <div className="overflow-auto max-h-[420px]">
                            <div className="min-w-[700px]">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-[120px]">Tipo</TableHead>
                                            <TableHead>Assunto</TableHead>
                                            <TableHead className="w-[220px]">{folder === 'received' ? 'Remetente' : 'Destinatários'}</TableHead>
                                            <TableHead className="w-[140px]">Estado</TableHead>
                                            <TableHead className="w-[150px]">Data</TableHead>
                                            <TableHead className="sticky right-0 z-10 w-[130px] bg-background text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {mailboxItems.map((item) => {
                                            return (
                                                <TableRow
                                                    key={`${item.folder}-${item.message_id}`}
                                                    className="align-top"
                                                >
                                                    <TableCell>
                                                        <div className="space-y-1">
                                                            <Badge variant="outline" className={typeBadgeClassName[item.type]}>
                                                                {typeOptions.find((option) => option.value === item.type)?.label}
                                                            </Badge>
                                                            <Badge variant="secondary" className="text-[10px]">
                                                                {sourceLabel(item)}
                                                            </Badge>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="max-w-[320px]">
                                                        <div className="truncate text-sm font-medium">{item.subject}</div>
                                                        <div className="truncate text-xs text-muted-foreground">{item.message}</div>
                                                    </TableCell>
                                                    <TableCell className="text-xs text-muted-foreground">
                                                        {item.folder === 'received'
                                                            ? item.sender.name
                                                            : item.recipients.slice(0, 2).map((recipient) => recipient.name).join(', ') || '-'}
                                                        {item.folder === 'sent' && item.recipient_count > 2 && (
                                                            <span> +{item.recipient_count - 2}</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        {item.folder === 'received' ? (
                                                            <Badge variant={item.is_read ? 'outline' : 'secondary'} className="text-[11px]">
                                                                {item.is_read ? 'Lida' : 'Não lida'}
                                                            </Badge>
                                                        ) : (
                                                            <Badge variant="outline" className="text-[11px]">
                                                                {item.recipients.some((recipient) => recipient.is_read) ? 'Com leituras' : 'Sem leituras'}
                                                            </Badge>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="text-xs text-muted-foreground">{formatDateTime(item.created_at)}</TableCell>
                                                    <TableCell className="sticky right-0 z-10 bg-background">
                                                        <div className="flex items-center justify-end gap-1">
                                                            <Tooltip>
                                                                <TooltipTrigger asChild>
                                                                    <Button
                                                                        type="button"
                                                                        variant="outline"
                                                                        size="icon"
                                                                        className="h-7 w-7"
                                                                        onClick={() => setViewedItem(item)}
                                                                    >
                                                                        <Eye size={14} />
                                                                    </Button>
                                                                </TooltipTrigger>
                                                                <TooltipContent sideOffset={6}>Ver mensagem</TooltipContent>
                                                            </Tooltip>
                                                            {item.folder === 'received' && (
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button
                                                                            type="button"
                                                                            variant="outline"
                                                                            size="icon"
                                                                            className="h-7 w-7"
                                                                            disabled={readOnly}
                                                                            onClick={() => openReplyCompose(item)}
                                                                        >
                                                                            <ArrowUUpLeft size={14} />
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent sideOffset={6}>Responder na app</TooltipContent>
                                                                </Tooltip>
                                                            )}
                                                            <Tooltip>
                                                                <TooltipTrigger asChild>
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                                        disabled={readOnly}
                                                                        onClick={() => item.folder === 'received' ? deleteReceived(item) : deleteSent(item)}
                                                                    >
                                                                        <Trash size={14} />
                                                                    </Button>
                                                                </TooltipTrigger>
                                                                <TooltipContent sideOffset={6}>Apagar mensagem</TooltipContent>
                                                            </Tooltip>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    )}
            </Card>

            <Dialog open={viewedItem !== null} onOpenChange={(open) => !open && setViewedItem(null)}>
                <DialogContent className="sm:max-w-2xl">
                    {viewedItem && (
                        <>
                            <DialogHeader>
                                <DialogTitle className="pr-8">{viewedItem.subject}</DialogTitle>
                                <DialogDescription>
                                    {viewedItem.folder === 'received'
                                        ? `De ${viewedItem.sender.name}`
                                        : `Para ${viewedItem.recipients.slice(0, 2).map((recipient) => recipient.name).join(', ') || '-'}`}
                                    {' · '}
                                    {formatDateTime(viewedItem.created_at)}
                                </DialogDescription>
                            </DialogHeader>

                            <div className="space-y-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="outline" className={typeBadgeClassName[viewedItem.type]}>
                                        {typeOptions.find((option) => option.value === viewedItem.type)?.label}
                                    </Badge>
                                    <Badge variant="secondary" className="text-[10px]">
                                        {sourceLabel(viewedItem)}
                                    </Badge>
                                    {viewedItem.folder === 'received' && (
                                        <Badge variant={viewedItem.is_read ? 'outline' : 'secondary'}>
                                            {viewedItem.is_read ? 'Lida' : 'Não lida'}
                                        </Badge>
                                    )}
                                </div>
                                <div className="rounded-lg border bg-muted/20 p-3 text-sm whitespace-pre-wrap">{viewedItem.message}</div>
                                {viewedItem.reply_to && (
                                    <div className="rounded-lg border border-dashed p-3 text-xs text-muted-foreground">
                                        Em resposta a <span className="font-medium text-foreground">{viewedItem.reply_to.subject}</span>
                                        {' '}de {viewedItem.reply_to.sender_name}
                                    </div>
                                )}
                            </div>

                            <DialogFooter className="gap-2 sm:justify-between">
                                    <Button
                                        variant="ghost"
                                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                        disabled={readOnly}
                                        onClick={() => viewedItem.folder === 'received' ? deleteReceived(viewedItem) : deleteSent(viewedItem)}
                                    >
                                    <Trash size={14} className="mr-1.5" />
                                    Apagar
                                </Button>

                                <div className="flex flex-wrap gap-2">
                                    {viewedItem.folder === 'received' && !readOnly && (
                                        viewedItem.is_read ? (
                                            <Button variant="outline" onClick={() => markAsUnread(viewedItem)}>
                                                <EyeSlash size={14} className="mr-1.5" />
                                                Marcar não lida
                                            </Button>
                                        ) : (
                                            <Button variant="outline" onClick={() => markReceivedAsRead(viewedItem)}>
                                                <Eye size={14} className="mr-1.5" />
                                                Marcar lida
                                            </Button>
                                        )
                                    )}
                                    {viewedItem.folder === 'received' && (
                                        <Button
                                            variant="outline"
                                            disabled={readOnly}
                                            onClick={() => openReplyCompose(viewedItem)}
                                        >
                                            <ArrowUUpLeft size={14} className="mr-1.5" />
                                            Responder
                                        </Button>
                                    )}
                                </div>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={!readOnly && composeOpen} onOpenChange={setComposeOpen}>
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Nova comunicação interna</DialogTitle>
                        <DialogDescription>
                            Envie uma mensagem interna para um ou mais utilizadores dentro da aplicação.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-2">
                        <div className="grid gap-2">
                            <Label>Destinatários</Label>
                            <Popover>
                                <PopoverTrigger asChild>
                                    <Button variant="outline" className="justify-between text-sm font-normal">
                                        <span className="truncate">
                                            {form.data.recipient_ids.length === 0
                                                ? 'Selecionar utilizadores'
                                                : `${form.data.recipient_ids.length} utilizador(es) selecionado(s)`}
                                        </span>
                                        <CaretDown size={14} className="ml-2 shrink-0" />
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent align="start" className="w-[340px] p-3">
                                    <div className="space-y-3">
                                        <Input
                                            placeholder="Pesquisar destinatários..."
                                            value={recipientSearch}
                                            onChange={(event) => setRecipientSearch(event.target.value)}
                                            className="h-8 text-xs"
                                        />
                                        <ScrollArea className="h-56 pr-3">
                                            <div className="space-y-2">
                                                {selectableMembers.map((member) => {
                                                    const checked = form.data.recipient_ids.includes(String(member.id));

                                                    return (
                                                        <label key={member.id} className="flex cursor-pointer items-start gap-2 rounded-md border p-2 text-sm hover:border-primary/40">
                                                            <Checkbox
                                                                checked={checked}
                                                                onCheckedChange={(value) => handleToggleRecipient(String(member.id), value === true)}
                                                            />
                                                            <div className="min-w-0 flex-1">
                                                                <p className="truncate font-medium">{memberDisplayName(member)}</p>
                                                                <p className="truncate text-xs text-muted-foreground">
                                                                    {(member.email_utilizador || member.email || '-')}
                                                                    {member.numero_socio ? ` · Nº ${member.numero_socio}` : ''}
                                                                </p>
                                                            </div>
                                                            {checked && <Check size={14} className="shrink-0 text-primary" weight="bold" />}
                                                        </label>
                                                    );
                                                })}
                                                {selectableMembers.length === 0 && (
                                                    <p className="text-xs text-muted-foreground">Nenhum utilizador encontrado.</p>
                                                )}
                                            </div>
                                        </ScrollArea>
                                    </div>
                                </PopoverContent>
                            </Popover>
                            {form.errors.recipient_ids && <p className="text-xs text-destructive">{form.errors.recipient_ids}</p>}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-[minmax(0,1fr)_180px]">
                            <div className="grid gap-2">
                                <Label htmlFor="communication-subject">Assunto</Label>
                                <Input
                                    id="communication-subject"
                                    value={form.data.subject}
                                    onChange={(event) => form.setData('subject', event.target.value)}
                                    placeholder="Assunto da comunicação"
                                />
                                {form.errors.subject && <p className="text-xs text-destructive">{form.errors.subject}</p>}
                            </div>
                            <div className="grid gap-2">
                                <Label>Tipo</Label>
                                <Select value={form.data.type} onValueChange={(value) => form.setData('type', value as CommunicationType)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Selecionar tipo" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {typeOptions.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="communication-message">Mensagem</Label>
                            <Textarea
                                id="communication-message"
                                rows={8}
                                value={form.data.message}
                                onChange={(event) => form.setData('message', event.target.value)}
                                placeholder="Escreva a mensagem interna"
                            />
                            {form.errors.message && <p className="text-xs text-destructive">{form.errors.message}</p>}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setComposeOpen(false)}>Cancelar</Button>
                        <Button onClick={submitCompose} disabled={form.processing}>
                            {form.processing ? 'A enviar...' : 'Enviar comunicação'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}