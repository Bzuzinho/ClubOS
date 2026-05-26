export type MemberImportGroup = 'pessoal' | 'financeiro' | 'desportivo' | 'configuracao';

export interface MemberImportFieldDefinition {
  key: string;
  label: string;
  group: MemberImportGroup;
  required?: boolean;
  help?: string;
  aliases?: string[];
}

export const MEMBER_IMPORT_GROUP_LABELS: Record<MemberImportGroup, string> = {
  pessoal: 'Pessoal',
  financeiro: 'Financeiro',
  desportivo: 'Desportivo',
  configuracao: 'Configuração',
};

export const MEMBER_IMPORT_FIELDS: MemberImportFieldDefinition[] = [
  { key: 'nome_completo', label: 'Nome completo', group: 'pessoal', required: true, aliases: ['nome completo', 'nome', 'full name'] },
  { key: 'numero_socio', label: 'Número de sócio', group: 'pessoal', aliases: ['numero socio', 'numero', 'n socio', 'member number'] },
  { key: 'data_nascimento', label: 'Data de nascimento', group: 'pessoal', aliases: ['data nascimento', 'nascimento', 'birth date'] },
  { key: 'sexo', label: 'Sexo', group: 'pessoal', aliases: ['genero', 'gender'] },
  { key: 'estado', label: 'Estado', group: 'pessoal', aliases: ['status'] },
  { key: 'tipo_membro', label: 'Tipo de membro', group: 'pessoal', aliases: ['tipo', 'tipos', 'member type'] },
  { key: 'nif', label: 'NIF', group: 'pessoal' },
  { key: 'cc', label: 'Cartão de cidadão', group: 'pessoal', aliases: ['cartao cidadao', 'bi'] },
  { key: 'morada', label: 'Morada', group: 'pessoal', aliases: ['endereco', 'address'] },
  { key: 'codigo_postal', label: 'Código postal', group: 'pessoal', aliases: ['cp', 'postal code'] },
  { key: 'localidade', label: 'Localidade', group: 'pessoal', aliases: ['cidade', 'local'] },
  { key: 'nacionalidade', label: 'Nacionalidade', group: 'pessoal' },
  { key: 'estado_civil', label: 'Estado civil', group: 'pessoal' },
  { key: 'contacto_telefonico', label: 'Telefone', group: 'pessoal', aliases: ['telefone', 'telemovel', 'contacto'] },
  { key: 'email_secundario', label: 'Email secundário', group: 'pessoal', aliases: ['email secundario', 'email alternativo'] },
  { key: 'numero_irmaos', label: 'Número de irmãos', group: 'pessoal', aliases: ['numero irmaos', 'irmaos'] },
  { key: 'ocupacao', label: 'Ocupação', group: 'pessoal', aliases: ['profissao'] },
  { key: 'empresa', label: 'Empresa', group: 'pessoal' },
  { key: 'escola', label: 'Escola', group: 'pessoal' },
  { key: 'tipo_mensalidade', label: 'Tipo de mensalidade', group: 'financeiro', aliases: ['mensalidade', 'fee'] },
  { key: 'centro_custo', label: 'Centro de custo', group: 'financeiro', aliases: ['centro custo', 'cost center'] },
  { key: 'ativo_desportivo', label: 'Ativo desportivo', group: 'desportivo', aliases: ['ativo', 'ativo atleta'] },
  { key: 'num_federacao', label: 'Número de federação', group: 'desportivo', aliases: ['federacao', 'num federacao'] },
  { key: 'numero_pmb', label: 'Número PMB', group: 'desportivo', aliases: ['pmb'] },
  { key: 'escalao', label: 'Escalão', group: 'desportivo', aliases: ['escalao atleta', 'age group'] },
  { key: 'data_inscricao', label: 'Data de inscrição', group: 'desportivo', aliases: ['inscricao data', 'join date'] },
  { key: 'data_atestado_medico', label: 'Data do atestado médico', group: 'desportivo', aliases: ['atestado', 'medical date'] },
  { key: 'informacoes_medicas', label: 'Informações médicas', group: 'desportivo', aliases: ['observacoes medicas', 'medical info'] },
  { key: 'email_utilizador', label: 'Email de autenticação', group: 'configuracao', aliases: ['email login', 'email utilizador', 'login email'] },
  { key: 'perfil', label: 'Perfil', group: 'configuracao', aliases: ['role'] },
  { key: 'rgpd', label: 'Consentimento RGPD', group: 'configuracao' },
  { key: 'data_rgpd', label: 'Data RGPD', group: 'configuracao' },
  { key: 'consentimento', label: 'Consentimento imagens/transporte', group: 'configuracao', aliases: ['consentimento'] },
  { key: 'data_consentimento', label: 'Data consentimento', group: 'configuracao' },
  { key: 'afiliacao', label: 'Afiliação', group: 'configuracao' },
  { key: 'data_afiliacao', label: 'Data afiliação', group: 'configuracao' },
  { key: 'declaracao_de_transporte', label: 'Declaração de transporte', group: 'configuracao', aliases: ['declaracao transporte', 'transporte'] },
];

export function normalizeImportHeader(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');
}

export function inferImportMapping(headers: string[]): Record<string, string> {
  const headerIndex = new Map(headers.map((header) => [normalizeImportHeader(header), header]));
  const mapping: Record<string, string> = {};

  for (const field of MEMBER_IMPORT_FIELDS) {
    const candidates = [field.key, ...(field.aliases ?? [])].map(normalizeImportHeader);
    const match = candidates.find((candidate) => headerIndex.has(candidate));
    if (match) {
      mapping[field.key] = headerIndex.get(match)!;
    }
  }

  return mapping;
}