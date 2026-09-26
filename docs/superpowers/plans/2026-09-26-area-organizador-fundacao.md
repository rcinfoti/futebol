# Plano 7 — Área do organizador: fundação (login, jogadores, painel, pagamentos)

- **Data:** 2026-09-26
- **Spec:** `docs/superpowers/specs/2026-09-26-gestao-pelada-design.md` (§2 perfis, §4 modelo, §5 segurança, §8 telas 1–5 e 9)
- **Branch:** `feat/admin-organizador`
- **Baseline:** 134 testes / 305 asserções
- **Status:** concluído — 181 testes / 420 asserções verdes + smoke HTTP (organizador → PIN → jogador → pagamento → caixa)

## Objetivo

Tirar o sistema da dependência de SQL manual: o organizador entra, vê a rodada da semana, cadastra
jogadores, gera o PIN e o link, e confirma os pagamentos que os jogadores avisaram.

## Descobertas

- `usuarios` e `pelada_organizadores` estão na spec (§4) mas **não existem em nenhuma migration**. Criadas na 005.
- `RepositorioPagamentoPdo::confirmar` não confere a pelada: a autorização fica no controller
  (pagamento precisa ser da pelada que o organizador gerencia).

## Decisões

- **Sessão:** mesmo cookie do jogador, chaves separadas (`usuario_id` × `jogador_id`); CSRF compartilhado.
- **Rate limit do organizador:** colunas `falhas_login`/`bloqueado_ate` em `usuarios` (5 falhas → 15 min).
  E-mail inexistente roda `password_verify` contra hash fictício (sem enumeração por tempo).
- **PIN:** organizador clica "Gerar novo PIN"; sistema sorteia 4 dígitos (`random_int`), mostra **uma vez** (flash) e grava só o hash.
- **Nome único por pelada** (o login do jogador é por seletor de nome).
- **Jogador não é apagado** (histórico financeiro, spec §4 restrict): só ativa/desativa.
- **Primeiro super admin:** script CLI `bin/criar-admin.php` (bloqueado na web pelo `.htaccess`).
- Todas as rotas `/admin/p/{id}/…` passam por `podeGerir(usuario, pelada)` → 403.

## Tarefas

- [x] **T1** Migration `005_usuarios_organizadores.sql` + espelho SQLite.
- [x] **T2** `Acesso\ServicoAcessoOrganizador`: `criar`, `autenticar(email, senha, agora): ?int` com bloqueio, `alterarSenha`.
- [x] **T3** `Acesso\Autorizacao`: `ehSuperAdmin`, `peladasVisiveis`, `podeGerir`, `vincular`.
- [x] **T4** `Jogadores\DadosJogador` (VO validado) + `Jogadores\RepositorioJogadorPdo` (listar/buscar/criar/atualizar/ativar, sempre escopado por pelada).
- [x] **T5** `ServicoAcessoPin::gerarPin(jogadorId): string`.
- [x] **T6** `Admin\ConsultaPainelPelada` (read-model): rodada aberta com confirmados/espera por tipo, caixa, pendências, pagamentos a confirmar.
- [x] **T7** `Web\AdminController` + flash + views (entrar, peladas, painel, jogadores, form).
- [x] **T8** `bin/criar-admin.php`, wiring no `public/index.php`, `.htaccess` bloqueando `bin/`.
- [x] **T9** Suíte verde + smoke HTTP.

## Deploy desta fatia

1. Rodar `migrations/005_usuarios_organizadores.sql` (atenção: o `UNIQUE (pelada_id, nome)` falha se já houver
   nomes repetidos na mesma pelada — renomeie antes).
2. Via SSH no servidor:
   `php bin/criar-admin.php --nome="Rogério" --email=rogerschagas@gmail.com --papel=super_admin`
3. Organizadores: `php bin/criar-admin.php --nome="..." --email=... --papel=organizador --pelada=<slug>`
4. Entrar em `https://www.rcinfoti.com.br/futebol/admin`.

## Fora de escopo (Plano 8)

Caixa & gastos (tela), relatórios, upload de comprovante, promover/desistir/estornar multa manual,
configuração da pelada, CRUD de peladas e organizadores pela web (super admin).
