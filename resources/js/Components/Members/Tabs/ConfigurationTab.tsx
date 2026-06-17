import { router } from '@inertiajs/react';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import { Switch } from '@/Components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Calendar } from '@/Components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Card } from '@/Components/ui/card';
import { format } from 'date-fns';
import { pt } from 'date-fns/locale';
import { toast } from 'sonner';
import { FileUpload } from '@/Components/FileUpload';
import { Lock, ShieldCheck, FileCheck } from 'lucide-react';
import { useState } from 'react';

interface ConfigurationTabProps {
  user: any;
  onChange: (field: string, value: any) => void;
  isAdmin: boolean;
  isCreating?: boolean;
}

export function ConfigurationTab({ user, onChange, isAdmin, isCreating = false }: ConfigurationTabProps) {
  const [isSendingAccess, setIsSendingAccess] = useState(false);

  const handlePasswordReset = () => {
    if (!user?.id) {
      toast.error('Guarde primeiro o membro antes de enviar o acesso.');
      return;
    }

    if (!user?.email_utilizador) {
      toast.error('Preencha o email de autenticação antes de enviar o acesso.');
      return;
    }

    setIsSendingAccess(true);

    router.post(route('membros.send-access-email', user.id), {
      email_utilizador: user.email_utilizador,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        toast.success('Email de acesso enviado com sucesso!', {
          description: `Foi enviado um link para definir a palavra-passe para ${user.email_utilizador}`,
        });
      },
      onError: (errors) => {
        toast.error((errors.email_utilizador as string) || 'Não foi possível enviar o email de acesso.');
      },
      onFinish: () => setIsSendingAccess(false),
    });
  };

  return (
    <div className="space-y-1">
      <Card className="p-2">
        <p className="text-xs text-muted-foreground">
          Esta tab gere Perfil e parâmetros de configuração (RGPD, afiliação, transporte e documentos). O Tipo de utilizador é definido em Dados Pessoais.
        </p>
      </Card>

      {/* Acesso à Plataforma */}
      <Card className="p-2">
        <h3 className="text-xs font-semibold mb-1.5 flex items-center gap-1">
          <Lock size={14} />
          Acesso à Plataforma
        </h3>
        <div className="space-y-1">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-1">
            <div>
              <Label htmlFor="email_utilizador" className="text-xs">Email de Autenticação</Label>
              <Input
                id="email_utilizador"
                type="email"
                value={user.email_utilizador}
                onChange={(e) => onChange('email_utilizador', e.target.value)}
                disabled={!isAdmin}
                placeholder="email@exemplo.com"
                className="h-7 text-xs mt-1"
              />
            </div>

            <div>
              <Label htmlFor="perfil" className="text-xs">Perfil de Autorização</Label>
              <Select
                value={user.perfil}
                onValueChange={(value) => onChange('perfil', value)}
                disabled={!isAdmin}
              >
                <SelectTrigger id="perfil" className="h-7 text-xs mt-1">
                  <SelectValue placeholder="Selecionar" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="admin">Administrador</SelectItem>
                  <SelectItem value="encarregado">Encarregado</SelectItem>
                  <SelectItem value="atleta">Atleta</SelectItem>
                  <SelectItem value="staff">Staff</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          <p className="text-xs text-muted-foreground">
            Perfil controla acesso e permissões. Ajustes aqui impactam visibilidade de áreas administrativas.
          </p>

          {isCreating && (
            <div>
              <Label htmlFor="password" className="text-xs">Password *</Label>
              <Input
                id="password"
                type="password"
                value={user.password || ''}
                onChange={(e) => onChange('password', e.target.value)}
                disabled={!isAdmin}
                placeholder="Mínimo 8 caracteres"
                className="h-7 text-xs mt-1"
              />
            </div>
          )}

          {isAdmin && !isCreating && (
            <Button variant="outline" size="sm" onClick={handlePasswordReset} className="w-full h-7 text-xs" disabled={isSendingAccess || !user?.email_utilizador}>
              <svg className="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
              </svg>
              {isSendingAccess ? 'A enviar...' : 'Enviar link de acesso'}
            </Button>
          )}
        </div>
      </Card>

      {/* RGPD */}
      <Card className="p-2">
        <h3 className="text-xs font-semibold mb-1.5 flex items-center gap-1">
          <ShieldCheck size={14} />
          RGPD e Consentimentos
        </h3>
        <div className="space-y-1">
          <div className="flex items-center justify-between p-1 border rounded bg-slate-50">
            <div>
              <Label htmlFor="rgpd" className="text-xs">Consentimento RGPD</Label>
              <p className="text-xs text-muted-foreground leading-tight">Tratamento de dados pessoais</p>
            </div>
            <Switch
              id="rgpd"
              checked={user.rgpd}
              onCheckedChange={(checked) => onChange('rgpd', checked)}
              disabled={!isAdmin}
            />
          </div>

          {user.rgpd && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-1 pl-1 border-l border-primary/20">
              <div>
                <Label htmlFor="data_rgpd" className="text-xs">Data RGPD</Label>
                <Popover>
                  <PopoverTrigger asChild>
                    <Button 
                      variant="outline" 
                      className="w-full justify-start text-left font-normal h-7 text-xs mt-1"
                      disabled={!isAdmin}
                    >
                      {user.data_rgpd 
                        ? format(new Date(user.data_rgpd), 'dd/MM/yyyy', { locale: pt })
                        : 'Selecionar data'
                      }
                    </Button>
                  </PopoverTrigger>
                  <PopoverContent className="w-auto p-0" align="start">
                    <Calendar
                      mode="single"
                      selected={user.data_rgpd ? new Date(user.data_rgpd) : undefined}
                      onSelect={(date) => onChange('data_rgpd', date ? format(date, 'yyyy-MM-dd') : '')}
                      disabled={(date) => date > new Date()}
                      initialFocus
                    />
                  </PopoverContent>
                </Popover>
              </div>

              <div>
                <Label htmlFor="arquivo_rgpd" className="text-xs">Arquivo RGPD</Label>
                <div className="mt-1">
                  <FileUpload
                    value={user.arquivo_rgpd || ''}
                    onChange={(value) => onChange('arquivo_rgpd', value)}
                    disabled={!isAdmin}
                    accept=".pdf,.doc,.docx,image/*"
                    placeholder="Nenhum ficheiro"
                  />
                </div>
              </div>
            </div>
          )}

          <div className="flex items-center justify-between p-1 border rounded mt-1">
            <div>
              <Label htmlFor="consentimento" className="text-xs">Consentimento Imagens/Transporte</Label>
              <p className="text-xs text-muted-foreground leading-tight">Uso de imagens e transporte</p>
            </div>
            <Switch
              id="consentimento"
              checked={user.consentimento}
              onCheckedChange={(checked) => onChange('consentimento', checked)}
              disabled={!isAdmin}
            />
          </div>

          {user.consentimento && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-1 pl-1 border-l border-primary/20">
              <div>
                <Label htmlFor="data_consentimento" className="text-xs">Data Consentimento</Label>
                <Popover>
                  <PopoverTrigger asChild>
                    <Button 
                      variant="outline" 
                      className="w-full justify-start text-left font-normal h-7 text-xs mt-1"
                      disabled={!isAdmin}
                    >
                      {user.data_consentimento 
                        ? format(new Date(user.data_consentimento), 'dd/MM/yyyy', { locale: pt })
                        : 'Selecionar data'
                      }
                    </Button>
                  </PopoverTrigger>
                  <PopoverContent className="w-auto p-0" align="start">
                    <Calendar
                      mode="single"
                      selected={user.data_consentimento ? new Date(user.data_consentimento) : undefined}
                      onSelect={(date) => onChange('data_consentimento', date ? format(date, 'yyyy-MM-dd') : '')}
                      disabled={(date) => date > new Date()}
                      initialFocus
                    />
                  </PopoverContent>
                </Popover>
              </div>

              <div>
                <Label htmlFor="arquivo_consentimento" className="text-xs">Arquivo Consentimento</Label>
                <div className="mt-1">
                  <FileUpload
                    value={user.arquivo_consentimento || ''}
                    onChange={(value) => onChange('arquivo_consentimento', value)}
                    disabled={!isAdmin}
                    accept=".pdf,.doc,.docx,image/*"
                    placeholder="Nenhum ficheiro"
                  />
                </div>
              </div>
            </div>
          )}
        </div>
      </Card>

      {/* Documentação */}
      <Card className="p-2">
        <h3 className="text-xs font-semibold mb-1.5 flex items-center gap-1">
          <FileCheck size={14} />
          Documentação
        </h3>
        <div className="space-y-1">
          <div className="flex items-center justify-between p-1 border rounded bg-slate-50">
            <div>
              <Label htmlFor="afiliacao" className="text-xs">Afiliação</Label>
              <p className="text-xs text-muted-foreground leading-tight">Documento de afiliação</p>
            </div>
            <Switch
              id="afiliacao"
              checked={user.afiliacao}
              onCheckedChange={(checked) => onChange('afiliacao', checked)}
              disabled={!isAdmin}
            />
          </div>

          {user.afiliacao && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-1 pl-1 border-l border-primary/20">
              <div>
                <Label htmlFor="data_afiliacao" className="text-xs">Data Afiliação</Label>
                <Popover>
                  <PopoverTrigger asChild>
                    <Button 
                      variant="outline" 
                      className="w-full justify-start text-left font-normal h-7 text-xs mt-1"
                      disabled={!isAdmin}
                    >
                      {user.data_afiliacao 
                        ? format(new Date(user.data_afiliacao), 'dd/MM/yyyy', { locale: pt })
                        : 'Selecionar data'
                      }
                    </Button>
                  </PopoverTrigger>
                  <PopoverContent className="w-auto p-0" align="start">
                    <Calendar
                      mode="single"
                      selected={user.data_afiliacao ? new Date(user.data_afiliacao) : undefined}
                      onSelect={(date) => onChange('data_afiliacao', date ? format(date, 'yyyy-MM-dd') : '')}
                      disabled={(date) => date > new Date()}
                      initialFocus
                    />
                  </PopoverContent>
                </Popover>
              </div>

              <div>
                <Label htmlFor="arquivo_afiliacao" className="text-xs">Arquivo Afiliação</Label>
                <div className="mt-1">
                  <FileUpload
                    value={user.arquivo_afiliacao || ''}
                    onChange={(value) => onChange('arquivo_afiliacao', value)}
                    disabled={!isAdmin}
                    accept=".pdf,.doc,.docx,image/*"
                    placeholder="Nenhum ficheiro"
                  />
                </div>
              </div>
            </div>
          )}

          <div className="flex items-center justify-between p-1 border rounded mt-1">
            <div>
              <Label htmlFor="declaracao_de_transporte" className="text-xs">Declaração de Transporte</Label>
              <p className="text-xs text-muted-foreground leading-tight">Autorização para transporte</p>
            </div>
            <Switch
              id="declaracao_de_transporte"
              checked={user.declaracao_de_transporte}
              onCheckedChange={(checked) => onChange('declaracao_de_transporte', checked)}
              disabled={!isAdmin}
            />
          </div>

          {user.declaracao_de_transporte && (
            <div className="pl-1 border-l border-primary/20">
              <Label htmlFor="declaracao_transporte" className="text-xs">Arquivo Declaração</Label>
              <div className="mt-1">
                <FileUpload
                  value={user.declaracao_transporte || ''}
                  onChange={(value) => onChange('declaracao_transporte', value)}
                  disabled={!isAdmin}
                  accept=".pdf,.doc,.docx,image/*"
                  placeholder="Nenhum ficheiro"
                />
              </div>
            </div>
          )}
        </div>
      </Card>
    </div>
  );
}
