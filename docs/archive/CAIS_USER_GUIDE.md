# 🏊 Cais Tab - Guia Visual de Uso

## Antes vs Depois

### ❌ ANTES (Single Training View)
```
┌─ Selector Dropdown ─────────────────────┐
│ Select treino: [Data · #0001         ▼] │
│ Modo rápido: [Toggle]                   │
└─────────────────────────────────────────┘

┌──────────────────────┬──────────────────────┐
│ Resumo do Treino     │ Lista de Atletas     │
│ #0001 · Natação      │ Expandir (3 atletas) │
│ Hora: 10:00 - 11:00  │                      │
│ Local: Piscina       │ [P] João             │
│ Escalão: Adultos     │ [A] Maria            │
│ ...                  │ [D] Pedro            │
└──────────────────────┴──────────────────────┘
```

### ✅ DEPOIS (Multi-Training Card Grid)
```
┌─────────────────────────────────────────────────────────────────┐
│ Treinos Agendados   │         Cards Grid (até 3 colunas)        │
├──────────────────────┼──────────────┬──────────────┬──────────────┤
│ Hoje:                │              │              │              │
│ ☑ #0001             │ ┌─────────────┐ ┌─────────────┐ ┌─────────┐ │
│ ☑ #0002             │ │  #0001 ✕   │ │  #0002 ✕   │ │ #0003 ✕ │ │
│ ☐ #0003             │ ├─────────────┤ ├─────────────┤ ├─────────┤ │
│                     │ │ RESUMO      │ │ RESUMO      │ │ RESUMO  │ │
│ Próximos:           │ │ - Data      │ │ - Data      │ │ - Data  │ │
│ ☐ #0004             │ │ - Hora      │ │ - Hora      │ │ - Hora  │ │
│ ☐ #0005             │ │ - Local     │ │ - Local     │ │ - Local │ │
│                     │ │ - Escalões  │ │ - Escalões  │ │ - Esc.  │ │
│                     │ ├─────────────┤ ├─────────────┤ ├─────────┤ │
│                     │ │ SÉRIES (2000m│ │ SÉRIES (1500│ │SÉRIES   │ │
│                     │ │ - Aquec.    │ │ - Princip.  │ │- Tech.  │ │
│                     │ │ - Principal │ │ - Soltar    │ │- Cool.  │ │
│                     │ ├─────────────┤ ├─────────────┤ ├─────────┤ │
│                     │ │ PRESENCAS ▼ │ │ PRESENCAS ▼ │ │PRESENCAS│ │
│                     │ │ P 2  A 1    │ │ P 3  A 0    │ │P 1|A 0  │ │
│                     │ └─────────────┘ └─────────────┘ └─────────┘ │
└──────────────────────┴──────────────┴──────────────┴──────────────┘
```

---

## 🎮 Como Usar (Passo a Passo)

### 1️⃣ Escolher Treinos
```
Na barra lateral à esquerda:
- Vê "Hoje" com treinos de hoje
- Vê "Próximos" com treinos futuros
- ☑ Marca checkboxes dos treinos que quer ver
- Máximo recomendado: 3 treinos (preenche a tela)
```

### 2️⃣ Ver Detalhes do Treino
```
Para cada treino selecionado aparece um CARD com:

📋 RESUMO DO TREINO (Card 1)
   • Data em formato YYYY-MM-DD
   • Hora início - hora fim
   • Local (ex: Piscina Principal)
   • Escalões elegíveis (P1, P2, S1, etc.)
   • Volume planeado em metros

🏊 SÉRIES E DISTÂNCIA (Card 2)
   • Lista de séries com scroll
   • Cada série mostra:
     - Descrição (ex: "Aquecimento")
     - Distância total (m)
     - Repetições (ex: 6x)
     - Estilo (Livre, Costas, Peito, Estibordo)
     - Observações
   • Badge com TOTAL de distância

👥 PRESENCAS (Card 3 - Expansível)
   Colapsado mostra:     | Expandido mostra:
   • P 5 (verde)         | • [Select] Adicionar +
   • A 2 (vermelho)      | • Lista de atletas:
   • D 1 (amarelo)       |   ☐ João      [Trash]
                         |   ☐ Maria     [Trash]
                         |   ☐ Pedro     [Trash]
```

### 3️⃣ Gerenciar Presenças
```
EXPANDIR O CARD DE PRESENCAS:
1. Clica na aba "Presencas" ou no chevron (▼)
2. Aparece lista com todos os atletas

ADICIONAR ATLETA:
1. Select [Adicionar atleta...]
2. Escolhe um atleta não adicionado
3. Clica botão [+]
4. Atleta aparece na lista com status "A" (Ausente)

MUDAR STATUS
(Cicla: P → A → D → P):
   Clica no ícone ao lado do nome:
   • P (verde) = Presente
   • A (vermelho) = Ausente  
   • D (amarelo) = Dispensado

REMOVER ATLETA:
   Clica no ícone Trash (🗑️) vermelho
   Atleta desaparece imediatamente
```

### 4️⃣ Fechar Card
```
No canto superior direito de cada card:
   Clica no X
   Card desaparece imediatamente
   Treino é removido do grid
```

---

## 🎨 Legenda de Cores

| Símbolo | Cor | Significado |
|---------|-----|------------|
| **P** | 🟢 Verde | Presente |
| **A** | 🔴 Vermelho | Ausente |
| **D** | 🟡 Amarelo | Dispensado (justificado/atestado) |

---

## 📱 Responsividade

| Tamanho | Colunas | Exemplo |
|---------|---------|---------|
| 📱 Celular (< 768px) | 1 | Cards empilhados |
| 📱 Tablet (768px) | 2 | 2 cards lado a lado |
| 💻 Desktop (> 1024px) | 3 | 3 cards lado a lado |

---

## ⌨️ Atalhos (Planeado para Futuro)
```
[ESC]    = Fechar card (não implementado ainda)
[↑↓↓→]   = Navegar entre cards (planeado)
[Enter]  = Adicionar atleta (planeado)
```

---

## 🎯 Casos de Uso

### Cenário 1: Treino Único (Habitual)
```
1. Clica checkbox de 1 treino
2. Vê o card na tela toda
3. Expande presencas
4. Adiciona/remove atletas conforme chegam
5. Atualiza status P/A/D
6. Muito responsivo e intuitivo
```

### Cenário 2: Comparar 2 Treinos
```
1. Clica checkboxes de 2 treinos
2. Vê side-by-side em desktop
3. Compara séries, escalões, presencas
4. Gerencia presencas independentemente
5. Útil para planeamento
```

### Cenário 3: Sessão Multi-Grupo (Avançado)
```
1. Clube tem 3 grupos em simultâneo
2. Treinador 1 marca #0001 (P1)
3. Treinador 2 marca #0002 (P2)
4. Treinador 3 marca #0003 (S1)
5. Vê todos 3 cards lado a lado
6. Gerencia presencas do seu grupo
7. Sistema permeia real-time
```

---

## ⚙️ Configuração Backend

O sistema espera que cada `Training` tenha:

```json
{
  "id": "uuid",
  "numero_treino": "#0001",
  "data": "2026-03-17",
  "hora_inicio": "10:00",
  "hora_fim": "11:00",
  "local": "Piscina Principal",
  "tipo_treino": "Natação",
  "volume_planeado_m": 2000,
  "escaloes": ["Adultos", "P1"],
  "series": [
    {
      "id": "uuid",
      "descricao_texto": "Aquecimento",
      "distancia_total_m": 400,
      "repeticoes": "2x",
      "estilo": "Livre",
      "observacoes": "Normal"
    }
  ],
  "presencas_grupo": [
    {
      "id": "uuid",
      "user_id": "user-uuid",
      "nome_atleta": "João Silva",
      "estado": "presente"
    }
  ]
}
```

---

## 🚀 Performance

- **Carregamento**: Lista carrega com todos os treinos inicialmente
- **Seleção**: Instantâneo (estado React local)
- **Renderização**: Grid eficiente com keys
- **Atualizações**: Preserva scroll e estado local durante updates
- **Múltiplos Cards**: Até 5-10 cards sem lag (testado)

---

## 🐛 Troubleshooting

| Problema | Solução |
|----------|---------|
| Card não aparece | Clicar checkbox novamente ou refresh |
| Atleta não aparece na lista | Verificar se já foi adicionado ou se tem escalão compatível |
| Status não atualiza | Revistar browser ou verificar conexão |
| Cards fora de alinhamento | Resize browser ou verificar responsividade |
| Select dropdown vazio | Todos atletas já foram adicionados |

---

**Versão: 2026-03-17**
**Status: Pronto para Produção** ✅
