import { User } from '@/types';
import { Label } from '@/Components/ui/label';
import { Input } from '@/Components/ui/input';
import { Switch } from '@/Components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Calendar } from '@/Components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { format } from 'date-fns';
import { pt } from 'date-fns/locale';
import { FileUpload } from '@/Components/FileUpload';
import { useAgeGroups } from '@/hooks/useAgeGroups';
import { Printer } from 'lucide-react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/Components/ui/dialog';
import { useState } from 'react';

interface DadosDesportivosTabProps {
  user: User;
  onChange: (field: keyof User, value: any) => void;
  isAdmin: boolean;
}

const AUTOMATIC_AGE_GROUP_VALUE = '__automatic__';

export function DadosDesportivosTab({ user, onChange, isAdmin }: DadosDesportivosTabProps) {
  const { data: ageGroups = [], isLoading } = useAgeGroups();
  const [showCardPreview, setShowCardPreview] = useState(false);
  const sportsUser = user as any;
  const explicitAgeGroup = Array.isArray(sportsUser.escalao) && sportsUser.escalao.length > 0
    ? sportsUser.escalao[0]
    : sportsUser.escalao_id || undefined;
  const manualOverride = Boolean(sportsUser.escalao_manual_override);
  const selectedEscalao = manualOverride && explicitAgeGroup
    ? explicitAgeGroup
    : AUTOMATIC_AGE_GROUP_VALUE;
  const calculatedAgeGroup = ageGroups.find((group) => group.id === sportsUser.escalao_calculado_id);

  const ageGroupHint = manualOverride
    ? calculatedAgeGroup
      ? `Override manual. Pelo cálculo automático seria ${calculatedAgeGroup.nome}.`
      : 'Override manual. O escalão automático continua a ser calculado para comparação.'
    : calculatedAgeGroup
      ? `Calculado automaticamente: ${calculatedAgeGroup.nome}.`
      : 'Será calculado pela data de nascimento ao guardar.';

  const handleAgeGroupChange = (value: string) => {
    if (value === AUTOMATIC_AGE_GROUP_VALUE) {
      onChange('escalao_manual_override' as keyof User, false);
      onChange('escalao', []);
      onChange('escalao_id' as keyof User, null);
      return;
    }

    onChange('escalao_manual_override' as keyof User, true);
    onChange('escalao', [value]);
    onChange('escalao_id' as keyof User, value);
  };

  const handlePrintCard = () => {
    if (user.cartao_federacao) {
      const printWindow = window.open('', '_blank');
      if (printWindow) {
        printWindow.document.write(`
          <!DOCTYPE html>
          <html>
            <head>
              <title>Cartão de Federação - ${user.nome_completo}</title>
              <style>
                body { margin: 0; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
                img { max-width: 100%; height: auto; }
                @media print { body { padding: 0; } }
              </style>
            </head>
            <body>
              <img src="${user.cartao_federacao}" alt="Cartão de Federação" />
            </body>
          </html>
        `);
        printWindow.document.close();
        printWindow.focus();
        setTimeout(() => printWindow.print(), 250);
      }
    }
  };

  return (
    <div className="space-y-1">
      <div className="grid grid-cols-1 lg:grid-cols-4 gap-1">
        <Card className="p-2">
          <div className="flex items-center justify-between">
            <div>
              <Label htmlFor="ativo_desportivo" className="text-xs">Atividade desportiva</Label>
              <p className="text-[10px] text-muted-foreground">Ativo para treinos e competição</p>
            </div>
            <Switch
              id="ativo_desportivo"
              checked={Boolean(user.ativo_desportivo)}
              onCheckedChange={(checked) => onChange('ativo_desportivo', checked)}
              disabled={!isAdmin}
            />
          </div>
        </Card>

        <Card className="p-2">
          <Label htmlFor="num_federacao" className="text-xs">Nº de Federação</Label>
          <Input
            id="num_federacao"
            value={user.num_federacao || ''}
            onChange={(e) => onChange('num_federacao', e.target.value)}
            disabled={!isAdmin}
            placeholder="Número"
            className="h-7 text-xs bg-white mt-1"
          />
        </Card>

        <Card className="p-2">
          <Label htmlFor="numero_pmb" className="text-xs">Número PMB</Label>
          <Input
            id="numero_pmb"
            value={user.numero_pmb || ''}
            onChange={(e) => onChange('numero_pmb', e.target.value)}
            disabled={!isAdmin}
            placeholder="Número"
            className="h-7 text-xs bg-white mt-1"
          />
        </Card>

        <Card className="p-2">
          <Label htmlFor="escalao" className="text-xs">Escalão oficial</Label>
          <Select
            value={selectedEscalao}
            onValueChange={handleAgeGroupChange}
            disabled={!isAdmin || isLoading}
          >
            <SelectTrigger id="escalao" className="h-7 text-xs bg-white mt-1">
              <SelectValue placeholder={isLoading ? 'A carregar...' : 'Selecionar'} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={AUTOMATIC_AGE_GROUP_VALUE}>Automático (pela idade)</SelectItem>
              {ageGroups
                .filter(group => group.ativo)
                .map((group) => (
                  <SelectItem key={group.id} value={group.id}>
                    {group.nome} ({group.idade_minima}-{group.idade_maxima}a)
                  </SelectItem>
                ))}
              {!isLoading && ageGroups.filter(group => group.ativo).length === 0 && (
                <SelectItem value="__no_age_groups__" disabled>Nenhum escalão configurado</SelectItem>
              )}
            </SelectContent>
          </Select>
          <p className="mt-1 text-[10px] leading-tight text-muted-foreground">{ageGroupHint}</p>
        </Card>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-1">
        <Card className="p-2">
          <h3 className="text-xs font-semibold mb-1.5">Cartão Federação</h3>
          {user.cartao_federacao && (
            <div
              className="w-full h-16 border-2 border-border rounded-lg overflow-hidden cursor-pointer hover:border-primary transition-colors my-1"
              onClick={() => setShowCardPreview(true)}
            >
              <img src={user.cartao_federacao} alt="Cartão" className="w-full h-full object-contain" />
            </div>
          )}
          <FileUpload
            value={user.cartao_federacao || ''}
            onChange={(value) => onChange('cartao_federacao', value)}
            disabled={!isAdmin}
            accept="image/*"
            placeholder="Carregar imagem"
            maxSizeMB={5}
          />
        </Card>

        <Card className="p-2">
          <h3 className="text-xs font-semibold mb-1.5">Inscrição</h3>
          <div className="space-y-1.5">
            <div>
              <Label htmlFor="data_inscricao" className="text-xs">Data</Label>
              <Popover>
                <PopoverTrigger asChild>
                  <Button variant="outline" className="w-full justify-start text-left font-normal h-7 text-xs bg-white mt-1" disabled={!isAdmin}>
                    {user.data_inscricao ? format(new Date(user.data_inscricao), 'PPP', { locale: pt }) : 'Selecionar data'}
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-auto p-0" align="start">
                  <Calendar
                    mode="single"
                    selected={user.data_inscricao ? new Date(user.data_inscricao) : undefined}
                    onSelect={(date) => onChange('data_inscricao', date ? format(date, 'yyyy-MM-dd') : '')}
                    disabled={(date) => date > new Date()}
                    initialFocus
                  />
                </PopoverContent>
              </Popover>
            </div>

            <div>
              <Label htmlFor="inscricao" className="text-xs">Documento</Label>
              <FileUpload
                value={user.inscricao || ''}
                onChange={(value) => onChange('inscricao', value)}
                disabled={!isAdmin}
                accept=".pdf,.doc,.docx,image/*"
                placeholder="Carregar ficheiro"
              />
            </div>
          </div>
        </Card>

        <Card className="p-2">
          <h3 className="text-xs font-semibold mb-1.5">Atestado Médico</h3>
          <div className="space-y-1.5">
            <div>
              <Label htmlFor="data_atestado_medico" className="text-xs">Data</Label>
              <Popover>
                <PopoverTrigger asChild>
                  <Button variant="outline" className="w-full justify-start text-left font-normal h-7 text-xs bg-white mt-1" disabled={!isAdmin}>
                    {user.data_atestado_medico ? format(new Date(user.data_atestado_medico), 'PPP', { locale: pt }) : 'Selecionar data'}
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-auto p-0" align="start">
                  <Calendar
                    mode="single"
                    selected={user.data_atestado_medico ? new Date(user.data_atestado_medico) : undefined}
                    onSelect={(date) => onChange('data_atestado_medico', date ? format(date, 'yyyy-MM-dd') : '')}
                    disabled={(date) => date > new Date()}
                    initialFocus
                  />
                </PopoverContent>
              </Popover>
            </div>

            <div>
              <Label htmlFor="arquivo_atestado_medico" className="text-xs">Documento</Label>
              <FileUpload
                value={user.arquivo_atestado_medico || []}
                onChange={(value) => onChange('arquivo_atestado_medico', value)}
                disabled={!isAdmin}
                accept=".pdf,.doc,.docx,image/*"
                placeholder="Carregar ficheiro"
              />
            </div>
          </div>
        </Card>
      </div>

      <Dialog open={showCardPreview} onOpenChange={setShowCardPreview}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle className="text-sm">Cartão de Federação - {user.nome_completo}</DialogTitle>
            <DialogDescription>Visualização do cartão de federação</DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            {user.cartao_federacao && (
              <div className="flex justify-center">
                <img src={user.cartao_federacao} alt="Cartão" className="max-w-full h-auto rounded-lg" />
              </div>
            )}
            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setShowCardPreview(false)} className="h-7 text-xs">
                Fechar
              </Button>
              <Button size="sm" onClick={handlePrintCard} className="h-7 text-xs">
                <Printer className="mr-1" size={14} />
                Imprimir
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
