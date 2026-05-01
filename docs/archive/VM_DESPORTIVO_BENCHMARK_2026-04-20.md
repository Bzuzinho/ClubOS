# Benchmark VM Desportivo - 2026-04-20

## Contexto

- Ambiente: VM `bscn-vnic-prod`
- App path: `/var/www/clubmanager`
- Objetivo: validar na VM se a refatoracao do modulo Desportivo reduziu o tempo de resposta

## Benchmark de controller

Comando executado:

```bash
sudo -u www-data -H bash -lc 'cd /var/www/clubmanager && php artisan perf:modules --runs=5'
```

Resultados do modulo `desportivo`:

- Run principal: `cold_ms=1841.09`, `warm_avg_ms=102.08`, `speedup=18.0x`
- Repeticao 1: `cold_ms=1823.40`, `warm_avg_ms=100.80`, `speedup=18.1x`
- Repeticao 2: `cold_ms=1825.99`, `warm_avg_ms=103.94`, `speedup=17.6x`

Leitura:

- Valor frio estavel na VM em torno de `1.82s` a `1.84s`
- Valor quente estavel na VM em torno de `101ms` a `104ms`
- Comparando com a referencia anterior reportada de cerca de `6787ms` cold, a reducao no cold start e de aproximadamente `73%`

## Benchmark HTTP real

Fluxo executado:

1. Criacao de um utilizador temporario de benchmark
2. Login HTTP real em `https://bscn.pt/login`
3. Medicao autenticada de `https://bscn.pt/desportivo`
4. Remocao do utilizador temporario

Resultados:

- Run 1: `code=200`, `total=4.308672s`, `starttransfer=4.308059s`, `size=70310`
- Run 2: `code=200`, `total=2.430869s`, `starttransfer=2.430246s`, `size=70310`
- Run 3: `code=200`, `total=2.483573s`, `starttransfer=2.483110s`, `size=70310`
- Run 4: `code=200`, `total=2.339402s`, `starttransfer=2.338797s`, `size=70310`
- Run 5: `code=200`, `total=2.473227s`, `starttransfer=2.472774s`, `size=70310`

Leitura:

- Primeiro request HTTPS autenticado: cerca de `4.31s`
- Requests seguintes: cerca de `2.34s` a `2.48s`
- O endpoint responde com `200` e payload HTML de cerca de `70KB`

## Ajuste operacional feito

Foi adicionado hardening ao deploy em `bin/deploy-vm.sh` para normalizar ownership e permissoes de `storage` e `bootstrap/cache` apos o deploy backend, evitando deriva de permissoes nos caminhos gravaveis do Laravel.

## Segunda iteracao - correcao de latencia global

Depois da primeira medicao, ficou claro que o problema nao era apenas do modulo Desportivo. Os pedidos quentes de `membros` e `desportivo` estavam ambos em cerca de `2.3s` a `2.4s`, o que apontava para um gargalo comum.

Diagnostico confirmado:

- A VM abre ligacoes TCP ao Neon em `us-east-1` com latencia de cerca de `100ms`
- Havia custo repetido em middleware global e access control partilhado por todas as paginas Inertia
- O modulo `Membros` ainda fazia varios `count()` remotos desnecessarios para construir stats

Otimizacoes aplicadas:

1. Memoizacao de `Schema::hasTable(...)` em `UserTypeAccessControlService`
2. Cache de `accessControl` por utilizador nas props partilhadas do Inertia
3. Cache de `communicationAlerts` por utilizador nas props partilhadas do Inertia
4. Calculo dos stats de `Membros` a partir da colecao de membros ja carregada em cache, em vez de varios `count()` remotos

### Medicao HTTP real na VM depois da correcao

Resultados autenticados via HTTPS depois do deploy backend das otimizacoes:

- `dashboard` warm: `1.33s` a `1.39s`
- `membros` warm: `1.85s` a `1.87s`
- `desportivo` warm: `1.93s` a `1.95s`
- `eventos` warm: `1.86s` a `2.07s`

Comparacao direta com a medicao anterior:

- `membros` warm: de `2.34s` a `2.36s` para `1.85s` a `1.87s`
- `desportivo` warm: de `2.34s` a `2.48s` para `1.93s` a `1.95s`
- `dashboard` warm: de `1.83s` para `1.33s` a `1.39s`

Leitura:

- O problema sentido no browser era real
- A refatoracao do Desportivo melhorou o custo especifico do modulo, mas estava mascarada por latencia estrutural comum do stack
- Depois da correcao global, a melhoria passou a ficar visivel tambem no pedido completo
- Ainda existe um piso de latencia relevante porque a base de dados continua remota e com RTT de cerca de `100ms` por ida

## Terceira iteracao - cache cross-request do access control

Foi identificado que os warm requests ainda faziam varias queries repetidas de access control e permission checks em cada pedido, apesar de o modulo principal ja estar otimizado.

Otimizacoes adicionais aplicadas:

1. `UserTypeAccessControlService` registado como singleton por request
2. Memoizacao estatica para tabelas, tipo de utilizador resolvido, settings e permission nodes
3. Cache cross-request para `currentUserAccess`, `userTypeSettings`, `resolved_user_type` e `permission_node`
4. `clubSettings` passa a ser cacheado mesmo quando nao existe registo na tabela

### Perfil interno depois da terceira iteracao

- `desportivo` warm: `322.86ms`, `2 queries`, `194.07ms` de SQL
- `membros` warm: `117.24ms`, `2 queries`, `193.10ms` de SQL

### Medicao HTTPS real depois da terceira iteracao

Resultados autenticados via HTTPS no nginx da VM:

- `membros`: `1.46s`, `1.11s`, `2.80s`, `1.28s`
- `desportivo`: `1.40s`, `1.26s`, `1.24s`, `1.26s`

Leitura final:

- `desportivo` ficou de forma consistente em cerca de `1.24s` a `1.40s` no pedido HTTPS real
- Face ao comportamento percebido de `4s` a `6s`, a reducao ficou claramente abaixo de metade
- `membros` tambem baixou de forma substancial, embora ainda apresente um outlier ocasional