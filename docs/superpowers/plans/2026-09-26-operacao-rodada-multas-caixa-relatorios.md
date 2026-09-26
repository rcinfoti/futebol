# Plano 8 — Operação da rodada, multas, caixa e relatórios

- **Data:** 2026-09-26
- **Spec:** §3.3 (ciclo), §3.4 (multas), §7 (caixa/relatórios), §8 telas 4–7
- **Branch:** `feat/operacao-e-financeiro`
- **Baseline:** 181 testes / 420 asserções
- **Status:** concluído — 225 testes / 531 asserções + smoke HTTP + revisão visual (Playwright, 430px)

## Achados que entram como correção (antes das telas)

1. **Cobrança em dobro na multa.** `RepositorioRodadaPdo::aplicarMulta` sempre soma o valor em `saldo_pendente`,
   mesmo quando o jogador **já pagou** a rodada. Spec §3.3.5: se pagou, o valor não é devolvido e **não** vira
   pendência; só vira pendência se não pagou. → multa de quem pagou nasce `paga` (coberta pelo pagamento), sem
   mexer no saldo; o e-mail continua saindo.
2. **Multa pra quem estava na espera.** A regra multa qualquer `desistiu` após o prazo, inclusive quem nunca teve
   vaga. Spec: multa é pra **confirmado** que desiste. → desistir da espera = sair da fila (inscrição removida).
3. **Multa recebida não entra no caixa.** `quitar` baixa a pendência, mas o dinheiro some da contabilidade
   (`movimentos_caixa` é a fonte única da verdade, spec §7). → `receber` = quitar + entrada no caixa, atômico.
4. **Sem como anular multa indevida** (spec §8.4 "aplicar/estornar multa"). → status `cancelada` (migration 006),
   só a partir de `pendente`; o cron não manda e-mail de multa cancelada.

## Tarefas

- [x] **T1** Migration `006_multa_cancelada.sql`.
- [x] **T2** Fix 1 — `aplicarMulta` respeita quem já pagou.
- [x] **T3** Fix 2 — `ServicoPresenca::desistir` da espera remove a inscrição.
- [x] **T4** `RepositorioMultaPdo`: `receber` (quitar + caixa), `cancelar`, `listarDoJogador`; `NotificadorMulta` ignora canceladas.
- [x] **T5** `Admin\GestaoRodada`: adicionar jogador (confirma em nome dele), promover da espera (pode passar do limite —
      decisão do organizador), marcar desistência. Tudo escopado pela pelada; reprocessa a rodada depois.
- [x] **T6** Organizador registra pagamento recebido em mãos (registra + confirma na hora; valores default da pelada).
- [x] **T7** Caixa: `lancarEntrada` (ajuste/saldo inicial), `excluirManual` (só gasto/ajuste — nunca entrada de pagamento
      ou multa, que têm origem própria); tela com saldo, lançamentos e extrato mensal.
- [x] **T8** Relatório mensal por jogador (tela + CSV).
- [x] **T9** Refactor: `AdminBaseController` (guarda/flash/página) compartilhado por `AdminController` e `AdminFinanceiroController`.
- [x] **T10** Views + rotas + smoke.

## Achado na revisão visual

5. **Painel do organizador não reavaliava a rodada** (spec §6 vale pra organizador *e* jogador): vaga livre
   com gente na espera ficava parada até o cron. → `GestaoRodada::reprocessar` ao abrir o painel.

## Decisões registradas

- Multa "coberta" usa pagamento **confirmado** da rodada, de qualquer valor. Quem pagou só parte (ex.: R$ 15 de R$ 20)
  fica com a multa `paga`, sem pendência da diferença. Simples e a favor do jogador; revisitar se virar problema.
- Relatório mensal soma só `pagamentos`; multas recebidas aparecem no **caixa** (categoria `multa`), não no relatório por jogador.

## Deploy desta fatia

1. Rodar `migrations/006_multa_cancelada.sql`.
2. Nada mais: sem config nova.

## Fora de escopo (Plano 9)

Upload de comprovante, configuração da pelada, CRUD de peladas/organizadores pela web.
