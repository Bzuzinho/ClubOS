// User helper functions

export function generateMemberNumber(existingUsers: any[]): string {
  const currentYear = new Date().getFullYear();
  
  if (!existingUsers || !Array.isArray(existingUsers)) {
    return `${currentYear}-0000`;
  }
  
  const yearPrefix = `${currentYear}-`;
  const yearNumbers = existingUsers
    .map(u => u?.numero_socio)
    .filter(n => n && typeof n === 'string' && n.startsWith(yearPrefix))
    .map(n => parseInt(n.split('-')[1] || '0', 10))
    .filter(n => !isNaN(n) && n >= 0);

  if (yearNumbers.length > 0) {
    const nextYearNumber = Math.max(...yearNumbers) + 1;
    return `${currentYear}-${nextYearNumber.toString().padStart(4, '0')}`;
  }

  const numericNumbers = existingUsers
    .map(u => u?.numero_socio)
    .map(n => (typeof n === 'number' ? n : parseInt(n || '0', 10)))
    .filter(n => !isNaN(n) && n >= 0);

  const nextNumber = numericNumbers.length > 0
    ? Math.max(...numericNumbers) + 1
    : 0;

  return `${currentYear}-${nextNumber.toString().padStart(4, '0')}`;
}

export function createEmptyUser(): any {
  return {
    nome_completo: '',
    data_nascimento: '',
    menor: false,
    sexo: 'masculino',
    tipo_membro: [],
    estado: 'ativo',
    perfil: 'atleta',
    rgpd: false,
    consentimento: false,
    afiliacao: false,
    declaracao_de_transporte: false,
    email_utilizador: '',
    ativo_desportivo: false,
  };
}

export function getUserDisplayName(user: any): string {
  return user?.nome_completo || user?.name || 'Sem nome';
}

export function getUserAge(birthDate: string): number | null {
  if (!birthDate) return null;
  const birth = new Date(birthDate);
  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  const monthDiff = today.getMonth() - birth.getMonth();
  if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
    age--;
  }
  return age;
}

export function isMinor(birthDate: string): boolean {
  const age = getUserAge(birthDate);
  return age !== null && age < 18;
}

export function getStatusColor(status: string): string {
  switch (status?.toLowerCase()) {
    case 'ativo':
    case 'active':
      return 'bg-green-100 text-green-800 border-green-200';
    case 'inativo':
    case 'inactive':
      return 'bg-gray-100 text-gray-800 border-gray-200';
    case 'suspenso':
    case 'suspended':
      return 'bg-red-100 text-red-800 border-red-200';
    default:
      return 'bg-gray-100 text-gray-800 border-gray-200';
  }
}

export function getStatusLabel(status: string): string {
  if (!status) return 'Desconhecido';
  switch (status.toLowerCase()) {
    case 'ativo':
    case 'active':
      return 'Ativo';
    case 'inativo':
    case 'inactive':
      return 'Inativo';
    case 'suspenso':
    case 'suspended':
      return 'Suspenso';
    default:
      return status;
  }
}

export function getMemberTypeLabel(type: string): string {
  const fallbackLabels: Record<string, string> = {
    atleta: 'Atleta',
    encarregado_educacao: 'Encarregado de Educação',
    treinador: 'Treinador',
    dirigente: 'Dirigente',
    funcionario: 'Funcionário',
    socio: 'Sócio',
  };
  return fallbackLabels[type] || type;
}

export function getEscalaoName(escalaoId: string, ageGroups: any[]): string {
  const escalao = ageGroups.find((e: any) => e.id === escalaoId);
  return escalao?.name || escalaoId;
}

export function getEscaloesNames(escalaoIds: string[] = [], ageGroups: any[]): string {
  return escalaoIds
    .map(id => getEscalaoName(id, ageGroups))
    .join(', ');
}

export function getInitials(name?: string | null): string {
  if (!name) return '';
  return name
    .split(' ')
    .map(part => part[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
}
