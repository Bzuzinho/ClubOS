# API Endpoints - Módulo Desportivo (Sports)

Data: 2026-03-12
Status: Implementado (Step 5-6 completo)

## Endpoints da API - Base URL: `/api/desportivo/`

### Athletes (Atletas)
```
GET    /athletes                 - Lista de atletas ativos
GET    /athletes/{id}            - Detalhe de um atleta
```

**Resposta GET /athletes:**
```json
[
  {
    "id": "uuid",
    "nome_completo": "João Silva",
    "email": "joao@clubos.pt",
    "estado": "ativo",
    "escalao": ["Júnior", "100m Livre"],
    "tipo_membro": ["atleta"],
    "data_atestado_medico": "2026-03-15",
    "medico_ok": true
  }
]
```

---

### Trainings (Treinos)
```
GET    /trainings                - Lista de treinos
POST   /trainings                - Cria treino
GET    /trainings/{id}           - Detalhe de treino
PUT    /trainings/{id}           - Atualiza treino
DELETE /trainings/{id}           - Elimina treino
```

**POST /trainings - Body:**
```json
{
  "numero_treino": "TR-2026-001",
  "data": "2026-03-15",
  "tipo_treino": "técnico",
  "descricao_treino": "Técnica de nado",
  "volume_planeado_m": 2000,
  "escaloes": ["Júnior", "Iniciado"],
  "grupo": "Grupo A"
}
```

---

### Training Attendance (Cais - Presenças no Treino)
```
GET    /trainings/{trainingId}/attendance
       - Lista presenças de um treino

PUT    /trainings/{trainingId}/attendance/{athleteId}
       - Marca/atualiza presença de um atleta

POST   /trainings/{trainingId}/attendance/mark-all
       - Marca todos como presentes

POST   /trainings/{trainingId}/attendance/clear-all
       - Limpa todas as presenças
```

**PUT /trainings/{trainingId}/attendance/{athleteId} - Body:**
```json
{
  "presente": true,
  "estado": "presente",
  "volume_real_m": 1800,
  "rpe": 8,
  "observacoes_tecnicas": "Bom desempenho"
}
```

**Valores válidos para `estado`:**
- `presente`
- `ausente`
- `justificado`
- `lesionado`
- `limitado`
- `doente`

---

### Competitions (Competições)
```
GET    /competitions             - Lista de competições
POST   /competitions             - Cria competição
GET    /competitions/{id}        - Detalhe de competição
PUT    /competitions/{id}        - Atualiza competição
DELETE /competitions/{id}        - Elimina competição
```

**POST /competitions - Body:**
```json
{
  "nome": "Campeonato Nacional 2026",
  "descricao": "Provas de nado livre",
  "data_inicio": "2026-04-01",
  "data_fim": "2026-04-03",
  "local": "Piscina Municipal",
  "tipo_prova": "Campeonato"
}
```

---

### Competition Results (Resultados de Competição)
```
GET    /competition-results      - Lista de resultados
POST   /competition-results      - Cria resultado
PUT    /competition-results/{id} - Atualiza resultado
DELETE /competition-results/{id} - Elimina resultado
```

**POST /competition-results - Body:**
```json
{
  "competition_id": "uuid",
  "user_id": "uuid",
  "prova": "100m Livre",
  "tempo": "01:05.32",
  "colocacao": 1,
  "desqualificado": false
}
```

---

## Ativação dos Endpoints

### Variáveis de Ambiente

**Para usar API real (endpoints acima):**
```
VITE_SPORTS_USE_MOCK=false
VITE_SPORTS_FALLBACK_ON_ERROR=true
```

**Para usar mock (desenvolvimento):**
```
VITE_SPORTS_USE_MOCK=true
VITE_SPORTS_FALLBACK_ON_ERROR=true
```

### Como Começar

1. **Ambiente de Desenvolvimento** (com mock):
   ```bash
   # .env configurado com:
   VITE_SPORTS_USE_MOCK=true
   npm run dev
   ```

2. **Staging/Produção** (com API real):
   ```bash
   # .env configurado com:
   VITE_SPORTS_USE_MOCK=false
   VITE_SPORTS_FALLBACK_ON_ERROR=true
   npm run build
   ```

---

## Status de Implementação

| Step | Componente | Status |
|------|-----------|--------|
| 1 | Service Layer | ✅ Completo |
| 2 | Mock Data → API Calls | ✅ Completo |
| 3 | Data Hooks | ✅ Completo |
| 4 | Cais - Presenças | ✅ Completo |
| 5 | Training Module | ✅ Completo |
| 6 | Competitions Module | ✅ Completo |
| 7 | Athletes Module | ✅ Completo |
| 8 | Laravel Controllers | ✅ Completo |
| 9 | Database Migrations | ✅ Completo |
| 10 | TypeScript Types | ✅ Completo |
| 11 | End-to-End Test | ⏳ Próximo |

---

## Próximos Passos

1. Executar `php artisan migrate` para criar tabelas
2. Mudar `VITE_SPORTS_USE_MOCK=false` em staging
3. Testar endpoints manualmente com Postman/curl
4. Monitorar logs de erro em produção

