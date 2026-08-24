import type { CompetitionResult } from '@/types/sports';
import { getSportsResource } from './sportsApiClient';
import axios from 'axios';

interface BackendCompetitionResult {
  id: string;
  prova?: string;
  tempo_oficial?: number | null;
  tempo?: string | null;
  classificacao?: number | null;
  colocacao?: number | null;
  posicao?: number | null;
  pontos_fina?: number | null;
  desqualificado?: boolean;
  user_id?: string;
  user_nome?: string;
  competition_id?: string;
  competition_nome?: string;
  observacoes?: string | null;
  created_at?: string;
  updated_at?: string;
}

function normalizeCompetitionResult(row: BackendCompetitionResult): CompetitionResult {
  return {
    id: row.id,
    prova: row.prova ?? '',
    tempo: row.tempo_oficial != null ? String(row.tempo_oficial) : row.tempo ?? null,
    classificacao: row.posicao ?? row.classificacao ?? row.colocacao ?? null,
    event: row.competition_id
      ? { id: row.competition_id, titulo: row.competition_nome ?? '' }
      : undefined,
    athlete: row.user_id
      ? { nome_completo: row.user_nome ?? '' }
      : undefined,
  };
}

/** List all competition results */
export async function getCompetitionResults(): Promise<CompetitionResult[]> {
  const payload = await getSportsResource<BackendCompetitionResult[]>('/api/desportivo/competition-results', []);
  return Array.isArray(payload) ? payload.map(normalizeCompetitionResult) : [];
}

/** Get a single competition result by ID */
export async function getCompetitionResult(id: string): Promise<BackendCompetitionResult | null> {
  try {
    const response = await axios.get<BackendCompetitionResult>(`/api/desportivo/competition-results/${id}`);
    return response.data;
  } catch {
    return null;
  }
}

/** Create a new competition result */
export interface CreateCompetitionResultPayload {
  prova_id: string;
  user_id: string;
  tempo_oficial: number | null;
  posicao?: number | null;
  pontos_fina?: number | null;
  desclassificado?: boolean;
  observacoes?: string | null;
}

export async function createCompetitionResult(payload: CreateCompetitionResultPayload): Promise<BackendCompetitionResult | null> {
  try {
    const response = await axios.post<BackendCompetitionResult>('/api/desportivo/competition-results', payload);
    return response.data;
  } catch (error) {
    console.error('Error creating competition result:', error);
    return null;
  }
}

/** Update a competition result */
export interface UpdateCompetitionResultPayload {
  tempo_oficial?: number | null;
  posicao?: number | null;
  pontos_fina?: number | null;
  desclassificado?: boolean;
  observacoes?: string | null;
}

export async function updateCompetitionResult(
  id: string,
  payload: UpdateCompetitionResultPayload
): Promise<BackendCompetitionResult | null> {
  try {
    const response = await axios.put<BackendCompetitionResult>(`/api/desportivo/competition-results/${id}`, payload);
    return response.data;
  } catch (error) {
    console.error('Error updating competition result:', error);
    return null;
  }
}

/** Delete a competition result */
export async function deleteCompetitionResult(id: string): Promise<boolean> {
  try {
    await axios.delete(`/api/desportivo/competition-results/${id}`);
    return true;
  } catch (error) {
    console.error('Error deleting competition result:', error);
    return false;
  }
}
