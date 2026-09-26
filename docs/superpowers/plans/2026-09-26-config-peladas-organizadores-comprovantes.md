# Plano 9 — Configuração da pelada, peladas & organizadores, comprovantes

- **Data:** 2026-09-26
- **Spec:** §2 (perfis), §3.2 (financeiro/temporada), §5 (upload), §8 telas 8–9 + aba do jogador
- **Branch:** `feat/config-peladas-comprovantes`
- **Baseline:** 225 testes / 531 asserções
- **Meta:** fechar a spec v1.
- **Status:** concluído — 282 testes / 661 asserções + smoke HTTP (upload multipart real) + revisão visual

## Achados

1. **Temporada da festa ignorada.** `festa_inicio`/`festa_fim` existem no schema MySQL e nada as lê. Spec §3.2: a
   temporada vai de 01/jan a 30/nov — **em dezembro a festa semanal não é cobrada**. Hoje o painel do jogador e o
   valor da multa incluem os R$ 5 em dezembro. → `TemporadaFesta` (compara só mês/dia; default 01-01..11-30),
   usada no devido semanal e no valor da multa.
2. **Convenção de dias:** o código usa ISO-8601 (1=segunda … 7=domingo), a spec escreveu "0–6". Vale o código.
3. **Espelho SQLite incompleto:** `peladas` no SQLite não tem agenda nem temporada → acrescentadas (com defaults),
   senão a configuração não é testável.

## Decisões

- **Validação da agenda** no domínio: cada marco (abertura, virada, prazo da multa) é expresso como "minutos antes
  do jogo" (mesma aritmética do `CalendarioRodada`), e precisa valer `abre > vira > prazo ≥ 0`. Pega "prazo depois
  do jogo" e "vira antes de abrir" sem regra especial por dia.
- **Mudança de agenda vale a partir da próxima rodada** (a rodada aberta guarda as próprias datas). A tela avisa.
- **Quem configura:** organizador edita a config das peladas dele (tela 8); **criar pelada e gerir organizadores
  é só super admin** (tela 9).
- **Senha de organizador novo:** gerada (12 caracteres), mostrada uma vez, como o PIN. Todo usuário tem "Minha senha"
  (exige a senha atual).
- **Comprovante:** opcional no "avisar pagamento". Validação por conteúdo (`finfo`), não pela extensão:
  JPG/PNG/WEBP/PDF, até 5 MB. Nome aleatório (`bin2hex(random_bytes(16))`), gravado em `uploads/comprovantes`
  (fora de `public/`, bloqueado no `.htaccess`, fora do git e do rsync). Servido só pro organizador da pelada, por
  rota autenticada, com `nosniff` e nome validado por regex (sem path traversal). O `move_uploaded_file` é injetável
  pra teste.

## Tarefas

- [x] **T1** Espelho SQLite + `Financeiro\TemporadaFesta` + uso em `ConsultaPainelJogador` e `aplicarMulta`.
- [x] **T2** `Peladas\DadosPelada` (VO validado: nome, slug, agenda, limites, valores, temporada).
- [x] **T3** `Peladas\RepositorioPeladaPdo` (criar/atualizar/buscar/ativar, slug único).
- [x] **T4** Organizadores: `ServicoAcessoOrganizador` (listar, ativar, senha temporária, trocar a própria senha),
      `Autorizacao::desvincular` + `peladasDoUsuario`.
- [x] **T5** `Financeiro\ArmazemComprovantes` + `Request::arquivos` + aviso com comprovante + rota de visualização.
- [x] **T6** `AdminPeladasController` (config, peladas, organizadores, minha senha) + guarda de super admin.
- [x] **T7** Views, rotas, smoke HTTP, revisão visual.
- [x] **T8** (achado na varredura final da spec) E-mail "confirmação de vaga — subiu da espera" (§6), que nenhum
      plano tinha coberto. `promovido_em` marcado na promoção (automática ou manual); `NotificadorPromocao` envia
      pelo cron, uma vez, só se o jogador ainda estiver confirmado. Migration 007.

## Revisão visual (Playwright, 430px)

- Abas do organizador estouravam a largura com "Config" → roláveis na horizontal.
- `<input type=file>` nativo ("Escolher arquivo / nenhum arquivo") é hostil pro público da spec §8 →
  botão "📎 Anexar comprovante" que mostra o nome escolhido.

## Deploy desta fatia

1. Rodar `migrations/007_aviso_promocao.sql` (não há 00x pra config: as colunas de agenda/temporada já existiam).
2. Pasta de comprovantes: por padrão `uploads/comprovantes` dentro do projeto (bloqueada no `.htaccess`, fora do
   git e do rsync). Precisa ser gravável pelo PHP. Opcional: `comprovantes_dir` no `config.local.php` apontando
   pra fora de `public_html`.
3. cPanel → "Select PHP Version" → Options: `upload_max_filesize` e `post_max_size` ≥ 6M (o padrão costuma ser 2M;
   acima disso o jogador vê a mensagem de arquivo grande).
4. Backup: incluir `uploads/comprovantes` (não está no git).

## Cobertura da spec v1

Todos os itens das seções 2–10 implementados. Pendências conscientes: "lembrete opcional" por e-mail (§6, marcado
como opcional na própria spec) e rate limit na *confirmação* (§5) — a confirmação exige sessão autenticada por PIN,
que já tem rate limit.
