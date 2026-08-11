import { useMemo, useState } from 'react';
import { User } from '@/types';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
import { FileUpload } from '@/Components/FileUpload';
import { FileHeart, Loader2 } from 'lucide-react';

interface MemberMedicalDocumentCardProps {
  user: User;
  canEdit: boolean;
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export function MemberMedicalDocumentCard({ user, canEdit }: MemberMedicalDocumentCardProps) {
  const member = user as any;
  const [date, setDate] = useState(member.data_atestado_medico || '');
  const [file, setFile] = useState<string | string[]>(member.arquivo_atestado_medico || '');
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const endpoint = useMemo(() => route('membros.documents.medical.update', { member: user.id }), [user.id]);

  const save = async () => {
    setSaving(true);
    setMessage(null);
    setError(null);

    try {
      const normalizedFile = Array.isArray(file) ? (file[0] ?? '') : file;
      const response = await fetch(endpoint, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({
          date: date || null,
          file: normalizedFile || null,
        }),
      });
      const body = await response.json().catch(() => ({}));
      if (!response.ok) {
        const validation = body?.errors ? Object.values(body.errors).flat().join(' ') : null;
        throw new Error(validation || body?.message || `Erro ao guardar (${response.status}).`);
      }

      setDate(body.data?.date || '');
      setFile(body.data?.file || '');
      setMessage(body.message || 'Atestado médico atualizado.');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Não foi possível guardar o atestado médico.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="p-3 space-y-3">
      <div className="flex items-start gap-2">
        <FileHeart className="mt-0.5 h-4 w-4 shrink-0" />
        <div>
          <h4 className="text-sm font-semibold">Atestado médico — documento de Membros</h4>
          <p className="text-[11px] text-muted-foreground">
            O ficheiro e os seus metadados pertencem a Membros/Documentos. O Desportivo mantém apenas as limitações e o estado operacional necessários à prática desportiva.
          </p>
        </div>
      </div>

      <div className="grid gap-3 md:grid-cols-2">
        <div>
          <Label htmlFor="member-medical-certificate-date" className="text-xs">Data do atestado</Label>
          <Input
            id="member-medical-certificate-date"
            type="date"
            className="mt-1 h-8 text-xs"
            value={date}
            onChange={(event) => setDate(event.target.value)}
            disabled={!canEdit || saving}
          />
        </div>

        <div>
          <Label className="text-xs">Ficheiro</Label>
          <div className="mt-1">
            <FileUpload
              value={file}
              onChange={setFile}
              disabled={!canEdit || saving}
              accept=".pdf,.doc,.docx,image/*"
              placeholder="Carregar atestado médico"
              maxSizeMB={5}
            />
          </div>
        </div>
      </div>

      {canEdit && (
        <div className="flex justify-end">
          <Button type="button" size="sm" className="h-8 text-xs" onClick={() => void save()} disabled={saving}>
            {saving && <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />}
            Guardar documento
          </Button>
        </div>
      )}

      {message && <div className="rounded-md border border-emerald-200 bg-emerald-50 p-2 text-xs text-emerald-800">{message}</div>}
      {error && <div className="rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-800">{error}</div>}

      {member.informacoes_medicas && (
        <div className="rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900">
          Existe informação clínica legacy preservada. Ela não é apresentada aqui nem convertida automaticamente em instrução para treinadores.
        </div>
      )}
    </Card>
  );
}
