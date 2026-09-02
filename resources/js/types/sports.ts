export interface Stats {
  athletesCount: number;
  trainings7Days: number;
  trainings30Days: number;
  km7Days: number;
  km30Days: number;
  attendanceRate?: number | null;
}

export interface BasicItem {
  id: string;
}

export interface Athlete extends BasicItem {
  nome_completo: string;
  email: string;
  estado?: string;
  tipo_membro?: string[];
  escalao?: string[];
  ativo_desportivo?: boolean;
  data_atestado_medico?: string | null;
}

export interface Training extends BasicItem {
  numero_treino?: string | null;
  data?: string | null;
  hora_inicio?: string | null;
  hora_fim?: string | null;
  local?: string | null;
  created_at?: string | null;
  epoca_id?: string | null;
  macrocycle_id?: string | null;
  mesociclo_id?: string | null;
  microciclo_id?: string | null;
  tipo_treino: string;
  volume_planeado_m?: number | null;
  descricao_treino?: string | null;
  notas_gerais?: string | null;
  escaloes?: string[] | null;
  presencas_grupo?: Array<{
    id: string;
    user_id: string;
    nome_atleta: string;
    estado: string;
  }>;
  series?: Array<{
    id: string;
    ordem?: number | null;
    descricao_texto?: string | null;
    distancia_total_m?: number | null;
    zona_intensidade?: string | null;
    estilo?: string | null;
    repeticoes?: number | null;
    intervalo?: string | null;
    observacoes?: string | null;
  }>;
}

export interface Session extends BasicItem {
  treino_id: string;
  data: string;
  fase_epoca?: string;
  objetivo_fisiologico?: string;
  objetivo_tecnico?: string;
  nota_staff?: string;
  updated_at: string;
}

export interface Attendance extends BasicItem {
  legacy_presence_id?: string | null;
  user_id: string;
  nome_atleta: string;
  status: string;
  classificacao?: string | null;
  distancia_realizada_m?: number | null;
  notas?: string | null;
}

export interface Season extends BasicItem {
  nome: string;
  ano_temporada: string;
  estado: string;
  tipo: string;
  data_inicio: string;
  data_fim: string;
}

export interface Macro extends BasicItem {
  nome: string;
  tipo: string;
  data_inicio: string;
  data_fim: string;
  epoca_id?: string | null;
  objetivo_principal?: string | null;
  objetivo_secundario?: string | null;
  escalao?: string | null;
}

export interface Mesocycle extends BasicItem {
  macrociclo_id: string;
  nome: string;
  data_inicio: string;
  data_fim: string;
  objetivo_principal?: string | null;
  objetivo_secundario?: string | null;
}

export interface Micro extends BasicItem {
  season_id: string;
  semana_label: string;
  data_inicio: string;
  data_fim: string;
  carga_prevista: number;
  foco: string;
}

export interface Competition extends BasicItem {
  titulo: string;
  data_inicio: string;
  local?: string;
  tipo: string;
}

export interface CompetitionEntry extends BasicItem {
  competition_id: string;
  athlete_id: string;
  prova?: string;
  seed_time?: string | null;
}

export interface CompetitionResult extends BasicItem {
  prova: string;
  tempo?: string | null;
  classificacao?: number | null;
  event?: { id: string; titulo: string };
  athlete?: { nome_completo: string };
}

export interface TeamResult extends BasicItem {
  competicao_id: string;
  equipa: string;
  classificacao?: number | null;
  pontos?: number | null;
}

export interface TrainingMetric extends BasicItem {
  training_id: string;
  athlete_id: string;
  metrica: string;
  valor: string;
  tempo?: string;
  observacao?: string;
}

export interface TrainingSession extends Session {}
export interface TrainingAttendance extends Attendance {}
export interface Macrocycle extends Macro {}
export interface Microcycle extends Micro {}
export type MesocyclePlan = Mesocycle;

export interface AgeGroup {
  id: string;
  nome: string;
}

export interface Event {
  id: string;
  titulo: string;
  data_inicio: string;
  data_fim?: string;
  tipo: string;
  estado: string;
  local: string;
  descricao?: string;
  criado_por?: string;
  hora_inicio?: string;
  hora_fim?: string;
  escaloes_elegiveis?: string[];
}

export interface CostCenter {
  id: string;
  nome: string;
  codigo?: string;
  ativo?: boolean;
}

export interface EventType {
  id: string;
  nome: string;
  visibilidade_default?: string;
  ativo?: boolean;
}

export interface TrainingTemplate {
  id: string;
  nome: string;
  tipo_treino: string;
  volume_planeado_m?: number;
  descricao_treino?: string;
  escaloes?: string[];
  series_linhas?: Array<{
    repeticoes: string;
    exercicio: string;
    metros: string;
    zona?: string;
  }>;
  created_at: string;
}

export interface V2SeasonPlan {
  id: string;
  nome: string;
  data_inicio: string;
  data_fim: string;
  fase: string;
  objetivo_fisiologico: string;
  objetivo_tecnico: string;
  carga_prevista: number;
}

export interface V2MicrocyclePlan {
  id: string;
  season_id: string;
  semana_label: string;
  data_inicio: string;
  data_fim: string;
  carga_prevista: number;
  foco: string;
}

export interface SessionContext {
  id: string;
  treino_id: string;
  fase_epoca?: string;
  objetivo_fisiologico?: string;
  objetivo_tecnico?: string;
  nota_staff?: string;
  updated_at: string;
}

export interface AthleteOperationalRow {
  user_id: string;
  assiduidade_percent?: number | null;
  disciplina_status?: 'ok' | 'atencao' | 'critico' | string;
  pb_label?: string | null;
  total_resultados?: number;
}

export interface CaisPerformanceRow {
  id: string;
  metrica: string;
  valor: string;
  tempo: string;
  observacao: string;
}

export type User = Athlete;
export type PresenceRow = Attendance;
export type EventResult = CompetitionResult;
