# Spec de Design — Sistema de Gestão de Peladas

- **Data:** 2026-09-26
- **Autor:** Rogério Chagas (rogerschagas@gmail.com)
- **Status:** Aprovada — pronta para planejamento de implementação
- **Destino de deploy:** cPanel em `www.rcinfoti.com.br/futebol`
- **Local de desenvolvimento:** `~/Projetos/futebol` (fora do Google Drive)

---

## 1. Objetivo e contexto

Sistema web para o Rogério (e mais até 4 organizadores) gerenciarem peladas de
futebol Fut7 (5 na linha + 1 no gol). O sistema controla:

- **Múltiplas peladas** independentes (ex: "Peladão de Quinta", "Pelada de
  Sábado"), cada uma isolada com seus próprios jogadores, rodadas, caixa e
  relatórios.
- **Confirmação de presença semanal** por link, com **lista de espera** e
  **regras automáticas por horário**.
- **Financeiro**: cobrança semanal, caixa (entradas × saídas), multas por
  desistência, e relatório mensal por jogador.

O sistema deve ser **PWA** (instalável em qualquer plataforma), **responsivo**
e **muito intuitivo** — com atenção especial na experiência do jogador no link
de confirmação, que precisa ser óbvia mesmo para quem não tem familiaridade com
tecnologia.

### Fora de escopo (v1)

- Integração automática com gateway Pix (cobrança/baixa automática). Pagamento é
  registrado manualmente, com opção de anexar comprovante.
- App nativo em loja (Play Store / App Store) — o PWA cobre a instalação.
- Escalação/sorteio de times, estatísticas de jogo (gols, cartões).

---

## 2. Perfis de acesso

| Perfil | O que faz |
|--------|-----------|
| **Super admin** (Rogério) | Cria/edita peladas, gerencia organizadores, acessa todas as peladas, configura valores e prazos. |
| **Organizador** (até 4 + o admin) | Login próprio. Gerencia jogadores, presenças, pagamentos, caixa e relatórios das peladas às quais está vinculado. |
| **Jogador** | Pré-cadastrado (nome, e-mail, telefone) com senha/PIN. Acessa pelo link, confirma presença da semana, vê a própria situação (pagamentos, pendências, posição na lista/espera). |

**Regra de visibilidade:** um organizador só enxerga as peladas às quais foi
vinculado (relação N:N entre organizadores e peladas). O super admin vê todas.

---

## 3. Regras de negócio

### 3.1 Formato e limites

- Fut7: **5 na linha + 1 no gol** em campo.
- Por rodada (semana): até **20 jogadores de linha** e até **4 goleiros**
  confirmados. Excedentes vão para a **lista de espera** por ordem de chegada.
- Limites são **configuráveis por pelada** (default 20/4).

### 3.2 Financeiro

- **Jogador de linha:** R$ 15,00/semana (futebol) + R$ 5,00/semana (festa/resenha).
- **Goleiro:** **não paga** os R$ 15,00. Paga **apenas** os R$ 5,00 (festa).
- **Festa (resenha):** qualquer jogador pode **quitar a temporada inteira de uma
  vez por R$ 220,00** OU pagar **R$ 5,00 por semana**. (R$ 220 = valor anual da
  festa, **configurável por pelada** — é um valor fixo, não precisa ser igual a
  nº de semanas × R$ 5.)
- **Temporada da festa:** vai de **janeiro até o fim de novembro** (dezembro
  fora). O ano de quitação (`festa_quitada_ano`) segue esse período. Datas de
  início/fim da temporada são **configuráveis por pelada** (default: 01/jan a
  30/nov).
- Todos os valores (15 / 5 / 220 / limites) são **configuráveis por pelada**.
- **Formas de pagamento:** Pix (com registro/anexo de comprovante) ou dinheiro.
  Registro é **manual** pelo organizador ou marcado pelo jogador para validação.

### 3.3 Ciclo semanal de confirmação (o coração do sistema)

Cada pelada tem um **dia de jogo** e prazos configuráveis **relativos a esse
dia**. Exemplo default para a Pelada de Quinta (jogo na quinta):

1. **Abertura** (ex: domingo) — a rodada abre e jogadores confirmam pelo link,
   registrados por **ordem de chegada**.
2. **Lista cheia** (20 linha / 4 goleiro) — novos confirmados entram na **lista
   de espera** na sequência.
3. **Janela FIFO — da abertura até quarta 11:59:** se um confirmado desiste,
   **sobe automaticamente o 1º da lista de espera** (ordem de chegada).
4. **Janela "pago primeiro" — quarta 12:00 até quinta 16:00:** ao vagar uma
   vaga, sobe **quem já pagou** (Pix com comprovante OU marcado como dinheiro),
   respeitando a ordem entre os que pagaram. Se ninguém na espera pagou, a vaga
   fica aberta aguardando.
5. **Multa — desistência após quinta 16:00:** dispara **e-mail** informando a
   multa. O valor pago **não é devolvido**. Se o jogador **não havia pagado**, o
   valor vira **pendência no cadastro dele** (`saldo_pendente`) até quitar.

**Observações:**
- Os horários exatos (dia de abertura, virada da regra, prazo da multa) são
  **campos configuráveis por pelada**, para servir a peladas em qualquer dia.
- A separação linha/goleiro é independente: a lista de linha pode estar cheia
  enquanto ainda há vaga de goleiro, e vice-versa. Cada tipo tem sua própria
  lista de espera.

### 3.4 Multas e pendências

- Uma multa gera um registro em `multas` e incrementa o `saldo_pendente` do
  jogador (quando ele ainda não havia pagado).
- O `saldo_pendente` aparece no cadastro do jogador e na aba dele, e persiste
  entre rodadas até ser quitado (pagamento que zera a multa).

---

## 4. Modelo de dados (MySQL)

Todas as tabelas de domínio têm `pelada_id` para isolamento multi-pelada
(exceto `usuarios`, que é global, e a tabela de vínculo).

- **`peladas`**: `id`, `nome`, `slug`, `dia_jogo` (0–6), `hora_jogo`,
  `abre_dia`/`abre_hora`, `vira_regra_dia`/`vira_regra_hora`,
  `prazo_multa_dia`/`prazo_multa_hora`, `limite_linha` (20), `limite_goleiro`
  (4), `valor_futebol` (15.00), `valor_festa_semana` (5.00),
  `valor_festa_ano` (220.00), `festa_inicio` (default 01/jan),
  `festa_fim` (default 30/nov), `ativa`, timestamps.
- **`usuarios`**: `id`, `nome`, `email`, `senha_hash`, `papel`
  (super_admin | organizador), timestamps.
- **`pelada_organizadores`**: `pelada_id`, `usuario_id` (vínculo N:N).
- **`jogadores`**: `id`, `pelada_id`, `nome`, `email`, `telefone`, `tipo`
  (linha | goleiro), `pin_hash` (PIN numérico, redefinível), `saldo_pendente` (decimal),
  `festa_quitada_ano` (bool/ano), `ativo`, timestamps.
- **`rodadas`**: `id`, `pelada_id`, `data_jogo`, `status`
  (aberta | fechada | encerrada), `abre_em`, `vira_regra_em`, `prazo_multa_em`
  (calculados na criação a partir da config da pelada), timestamps.
- **`inscricoes`**: `id`, `rodada_id`, `jogador_id`, `tipo` (linha | goleiro),
  `status` (confirmado | espera | desistiu), `ordem` (chegada),
  `confirmado_em`, `desistiu_em`.
- **`pagamentos`**: `id`, `pelada_id`, `jogador_id`, `rodada_id` (nullable p/
  avulso/festa anual), `categoria` (futebol | festa),
  `escopo` (semana | ano), `valor`, `forma` (pix | dinheiro),
  `comprovante_arquivo` (nullable), `confirmado` (bool), `criado_em`.
- **`multas`**: `id`, `pelada_id`, `jogador_id`, `rodada_id`, `valor`, `status`
  (pendente | paga), `motivo`, `criado_em`, `quitado_em`.
- **`movimentos_caixa`**: `id`, `pelada_id`, `tipo` (entrada | saida),
  `origem` (pagamento | gasto | ajuste), `pagamento_id` (nullable), `descricao`,
  `valor`, `data`, `criado_em`. (Entradas de pagamento são geradas ao confirmar
  o pagamento; ver §7.)

### Índices e integridade
- `inscricoes` único por (`rodada_id`, `jogador_id`).
- Índices em `pelada_id`, `rodada_id`, `jogador_id` para relatórios.
- Chaves estrangeiras com `ON DELETE` apropriado (restrict para histórico
  financeiro; cascade só onde seguro).

---

## 5. Arquitetura da aplicação

- **Stack:** PHP (8.x) + MySQL, sem framework pesado — estrutura MVC leve
  própria (roteador simples, camada de dados via PDO com prepared statements,
  views em templates). Objetivo: rodar em qualquer cPanel sem build server.
- **Organização de pastas** (dentro de `/futebol`):
  - `public/` — raiz web (index.php front controller, assets, manifest PWA,
    service worker).
  - `src/` — lógica (models, controllers, serviços de regras).
  - `config/` — configuração de banco e app (segredos fora do controle de
    versão; usar `config.local.php` ou `.env`).
  - `migrations/` — scripts SQL versionados de criação/alteração de schema.
  - `cron/` — script(s) chamados pelo Cron Job do cPanel.
- **Segurança:**
  - Senhas com `password_hash()`; sessões seguras; proteção CSRF nos forms;
    prepared statements (anti SQL injection); escape de saída (anti XSS).
  - Upload de comprovante validado (tipo/tamanho), guardado fora da raiz web ou
    com nomes não adivinháveis.
  - Rate limit básico no login/confirmação do jogador.
- **Isolamento (unidades):**
  - **Serviço de Regras da Rodada** — única responsabilidade: dado o estado de
    uma rodada e o horário atual, decidir promoções da lista de espera e multas.
    Testável isoladamente (entra estado + horário, sai lista de ações). É o
    núcleo e será coberto por testes.
  - **Serviço Financeiro** — calcula devidos, registra pagamentos, atualiza
    caixa e pendências.
  - **Camada de acesso** — autenticação/autorização por perfil.

---

## 6. Automação (cron + e-mail)

- **Cron Job do cPanel** chama `cron/processar.php` periodicamente (ex: a cada
  5–10 min). O script:
  1. Cria a próxima rodada quando chega o horário de abertura.
  2. Aplica promoções pendentes da lista de espera conforme a janela de regra
     vigente.
  3. Dispara e-mails de multa para desistências após o prazo.
  4. Fecha/encerra rodadas passadas.
- **Rede de segurança:** as mesmas regras são reavaliadas quando um organizador
  ou jogador abre a página relevante (idempotente — não duplica ações).
- **E-mail:** via `mail()` do cPanel ou SMTP autenticado (configurável).
  Templates: aviso de multa, confirmação de vaga (subiu da espera), lembrete
  opcional.

---

## 7. Financeiro — cálculo e caixa

- **Devido por jogador na rodada:** linha → R$ 15 + R$ 5; goleiro → R$ 5
  (se a festa do ano já estiver quitada, não cobra o R$ 5 da semana: linha paga
  só R$ 15 e goleiro R$ 0 naquela semana).
- **Caixa da pelada:** saldo = Σ entradas − Σ saídas. Entradas vêm de
  pagamentos confirmados; saídas são gastos lançados (campo, bola, etc.).
  Decisão: `movimentos_caixa` é a fonte única da verdade do caixa; ao confirmar
  um pagamento, gera-se automaticamente um movimento de entrada vinculado
  (`pagamento_id`), evitando dupla contagem.
- **Relatórios:**
  - **Mensal por jogador:** por mês, total pago de **futebol** e de **festa**,
    + pendências em aberto.
  - **Rodada:** confirmados, lista de espera, pagos × não pagos, faltantes.
  - **Caixa:** extrato de entradas/saídas e saldo por período.

---

## 8. Telas / UX

**PWA responsivo, mobile-first.** Manifest + service worker para instalação e
funcionamento básico offline (leitura em cache).

### Área administrativa (admin/organizador)
1. **Login**
2. **Painel da pelada** — rodada atual (confirmados/espera), saldo do caixa,
   pendências, atalhos.
3. **Jogadores** — listar/cadastrar/editar, ver situação e pendências.
4. **Rodadas & confirmações** — ver/gerir lista da semana, promover manualmente,
   marcar desistência, aplicar/estornar multa.
5. **Pagamentos** — registrar pagamento (futebol/festa, Pix/dinheiro,
   comprovante), confirmar pagamentos marcados por jogadores.
6. **Caixa & gastos** — lançar saídas, ver extrato/saldo.
7. **Relatórios** — mensal por jogador, rodada, caixa.
8. **Configuração da pelada** — valores, limites, dia/horários das regras.
9. **Peladas & organizadores** (super admin) — criar peladas, vincular
   organizadores.

### Aba do jogador (link público, foco máximo em simplicidade)
- **Entrar** — escolhe o nome (ou link/e-mail) + senha/PIN.
- **Tela única e clara:** "Você vai jogar quinta?" com botão grande
  **CONFIRMAR** / **NÃO VOU**.
- Mostra visualmente: **sua situação** (confirmado ✅ / na espera nº X /
  fora), **quanto você deve** essa semana, botão para **avisar pagamento**
  (informar Pix/dinheiro + anexar comprovante), e **suas pendências**.
- Linguagem simples, ícones, cores de status (verde/amarelo/vermelho), textos
  curtos. Nada de jargão.

---

## 9. Testes

- **Serviço de Regras da Rodada** é o alvo principal de testes automatizados
  (TDD): cenários das janelas FIFO, "pago primeiro", multa após prazo,
  separação linha/goleiro, promoções encadeadas, idempotência do cron.
- **Serviço Financeiro:** cálculo de devido, geração de movimento de caixa,
  pendências de multa.
- Testes de integração das rotas principais (login, confirmação, pagamento).

---

## 10. Deploy no cPanel

- Subir arquivos para `public_html/futebol` (ou apontar o subdiretório).
- Criar banco MySQL + usuário no cPanel; rodar migrations.
- Configurar `config.local.php`/`.env` com credenciais.
- Configurar **Cron Job** apontando para `cron/processar.php`.
- Configurar conta de e-mail/SMTP.
- Testar PWA (HTTPS obrigatório para service worker — confirmar SSL do domínio).

---

## 11. Decisões resolvidas (2026-09-26)

- **Temporada da festa:** de **janeiro a fim de novembro** (dezembro fora); datas
  configuráveis por pelada (default 01/jan–30/nov). R$ 220 é valor anual fixo
  configurável, desacoplado do nº de semanas.
- **Acesso do jogador:** **PIN numérico** (redefinível pelo organizador) — teclado
  numérico no celular, mais simples para quem tem pouca familiaridade com tech.
- **Comprovantes de pagamento:** **guardados como arquivo no servidor** (fora da
  raiz web ou com nome não adivinhável; tipo/tamanho validados).
