export type CaisStatus = 'presente' | 'ausente' | 'dispensado' | 'atrasado';
export type QuickType = 'behavior' | 'material';
export type ViewMode = 'list' | 'cards';

export interface MetricDefinition {
  id: string;
  code: string;
  name: string;
  input_type: 'text' | 'number' | 'choice';
  unit?: string | null;
  options: string[];
  quick_action: boolean;
}

export interface RegisterMetric extends MetricDefinition {
  value?: string | null;
}

export interface AthleteRegister {
  behavior?: string | null;
  material?: string | null;
  technical_note?: string | null;
  advice?: string | null;
  metrics: RegisterMetric[];
}

export interface CaisAthlete {
  id: string;
  training_athlete_id: string;
  name: string;
  status: CaisStatus;
  lane?: string | null;
  group?: string | null;
  register: AthleteRegister;
}

export interface TrainingLine {
  id: string;
  repeticoes: number;
  distancia_m: number;
  exercicio?: string | null;
  zona?: string | null;
  estilo?: string | null;
  intervalo?: string | null;
  saida?: string | null;
  timing_mode: string;
}

export interface TrainingBlock {
  name: string;
  rounds: number;
  volume_m: number;
  series: TrainingLine[];
}

export interface SessionOption {
  id: string;
  number?: string | null;
  date?: string | null;
  start_time?: string | null;
  end_time?: string | null;
  training_type?: string | null;
  volume_m: number;
  label: string;
}

export interface SelectedSession extends SessionOption {
  status: string;
  coach?: string | null;
  venue?: string | null;
  pool?: string | null;
  pool_length_m?: string | number | null;
  blocks: TrainingBlock[];
  athletes: CaisAthlete[];
  occurrences: Array<{ id: string; type: string; reason: string; recorded_at?: string | null; recorded_by?: string | null }>;
}

export interface CaisPageProps {
  date: string;
  sessions: SessionOption[];
  selectedSession?: SelectedSession | null;
  metricDefinitions: MetricDefinition[];
}
