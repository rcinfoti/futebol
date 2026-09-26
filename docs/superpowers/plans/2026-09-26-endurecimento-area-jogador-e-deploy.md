# Plano 6 — Endurecimento da área do jogador + deploy funcional

- **Data:** 2026-09-26
- **Spec:** `docs/superpowers/specs/2026-09-26-gestao-pelada-design.md`
- **Branch:** `feat/web-login-tela-jogador` (continuação do Plano 5)
- **Status:** concluído — 134 testes / 305 asserções verdes + smoke HTTP manual

## Motivação (achados da revisão do Plano 5)

1. **Vazamento entre peladas no login:** o seletor listava jogadores de *todas* as peladas ativas.
2. **Fila furável:** quem desistia e reconfirmava mantinha a `ordem` antiga e passava na frente da espera.
3. **Estouro de limite sob concorrência:** duas confirmações simultâneas liam a mesma vaga livre (MySQL).
4. **Sem rate limit no PIN** (spec §5) — PIN de 4 dígitos cai em força bruta.
5. **Promoção só no cron:** "Não vou" deixava a vaga parada até o próximo cron (spec §6 pede reavaliar ao abrir/agir).
6. **Deploy quebrado:** `vendor/` fora do git e não gerado no CI; repositório inteiro exposto em `public_html/futebol`
   (src, SQL, composer.lock); sem `.htaccess` (nenhuma rota fora de `/` funcionava); links/redirects absolutos
   ignorando o subdiretório `/futebol`; ícones do manifest inexistentes (PWA não instalável).
7. Menores: valor do aviso de pagamento sem validação; `sair` sem CSRF; cookie de sessão sem `httponly/samesite`.

## Tarefas

- [x] **T1** `ConsultaPainelJogador`: `jogadoresParaLogin(peladaId)`, `peladaPorSlug`, `peladasAtivas`, `pertenceAPelada`, `slugDoJogador`.
- [x] **T2** `ServicoPresenca`: reingresso recebe nova `ordem` (fim da fila); `SELECT ... FOR UPDATE` na rodada quando MySQL.
- [x] **T3** Rate limit: `migrations/004_tentativas_login.sql` + espelho SQLite; `ServicoAcessoPin` bloqueia 15 min após 5 falhas seguidas; acerto e `definirPin` zeram.
- [x] **T4** Controller: rotas `/p/{slug}/entrar` (GET/POST); `/entrar` redireciona se há 1 pelada ou mostra escolha; 404 p/ slug inválido;
      login recusa jogador de outra pelada; aviso de bloqueio; `ProcessadorRodada` roda após confirmar/não-vou e ao abrir o painel;
      valor avisado validado (0 < v ≤ 1000, aceita vírgula); `sair` com CSRF e volta pro login da pelada; `base` prefixa redirects/links.
- [x] **T5** `Request::caminhoRelativo(uri, base)` + `daGlobais(base)`.
- [x] **T6** Views: nome real da pelada, `escolher-pelada`, `nao-encontrada`, feedback de pagamento, links com `$base`.
- [x] **T7** Deploy: `.htaccess` na raiz (bloqueia tudo fora de `public/`) e em `public/` (front controller);
      workflow roda `composer test` (PHP 8.1) antes e `composer install --no-dev -o` antes do rsync; exclui `tests/`.
- [x] **T8** `index.php`: `base` vindo do config, cookie `pelada` httponly/samesite/secure (60 dias), headers básicos de segurança.
- [x] **T9** PWA: ícones 192/512/maskable, `start_url`/`scope` relativos, SW só cacheia resposta `ok` (cache `pelada-v2`).

## Checklist de deploy (primeira vez)

1. `config/config.local.php` no servidor com `db`, `base => '/futebol'`, `email_de`.
2. Rodar `migrations/001` → `004` no MySQL (phpMyAdmin).
3. Cron do cPanel: `*/5 * * * * php /home1/rcinfoti/public_html/futebol/cron/processar.php`.
4. Confirmar que o domínio tem SSL (service worker exige HTTPS).
5. Link de cada pelada para os jogadores: `https://www.rcinfoti.com.br/futebol/p/<slug>/entrar`.

## Próximo — Plano 7: área do organizador (spec §8, telas 1–9)

Pré-requisito para usar de verdade: hoje PIN, jogadores e rodadas só entram via SQL/seed.
Sugestão de fatiamento:
1. Login de organizador (`usuarios`, `password_hash`) + autorização por `pelada_organizadores` (super admin vê tudo).
2. Jogadores: CRUD + definir/redefinir PIN (usa `ServicoAcessoPin::definirPin`) + copiar link da pelada.
3. Rodada da semana: lista confirmados/espera, promover/desistir manual, estornar multa.
4. Pagamentos a confirmar (reusa `RepositorioPagamentoPdo` → entrada de caixa idempotente) + upload de comprovante (fora da raiz web).
5. Caixa & gastos + relatórios (reusa `RelatorioFinanceiroPdo`/`RepositorioCaixaPdo`).
6. Configuração da pelada + peladas & organizadores (super admin).
