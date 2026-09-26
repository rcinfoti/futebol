# Fundação Web + Login por PIN + Tela do Jogador — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar a primeira fatia usável da aplicação web: um kernel HTTP mínimo (Request/Response/Roteador) sem framework, sessão + CSRF, login do jogador por PIN, e a **tela única do jogador** (situação na rodada, quanto deve, confirmar/não vou, avisar pagamento) — tudo ligado ao backend pronto dos Planos 1–4.

**Architecture:** MVC leve próprio (spec §5). O núcleo é testável porque controllers recebem um `Request` (VO) e devolvem um `Response` (VO) — sem tocar em `$_GET`/`$_SERVER`/`echo` direto; superglobais e `session_start()` ficam num único ponto de glue (`public/index.php` + `SessaoPhp`). PIN é `password_hash`/`password_verify` sobre `jogadores.pin_hash`. A situação do jogador é um **read-model** (`ConsultaPainelJogador`) montado a partir da rodada aberta atual + `CalculadoraDevido` (Plano 3) + `saldo_pendente`. Confirmar/desistir é um serviço de domínio novo (`ServicoPresenca`) testado contra SQLite. As views são templates PHP renderizados para string; o service worker/manifest dão o mínimo de PWA (spec §8). Tudo mobile-first e em português, foco em simplicidade.

**Tech Stack:** PHP 8.1+, PDO, sessões nativas, `password_hash` (bcrypt/argon), PHPUnit 10. Sem framework, sem dependência de runtime nova. CSS inline/estático (sem build).

**Spec:** `docs/superpowers/specs/2026-09-26-gestao-pelada-design.md` (§5 arquitetura/segurança, §8 telas — aba do jogador, §2 perfis, §11 login por PIN).

## Global Constraints

- **PHP:** `>=8.1`. Namespaces: `RcInfoti\Pelada\Web\`, `RcInfoti\Pelada\Acesso\`, `RcInfoti\Pelada\Presenca\`, `RcInfoti\Pelada\Painel\` → `src/` (PSR-4). Testes: `RcInfoti\Pelada\Tests\` → `tests/`.
- **Não alterar contratos dos Planos 1–4.** Este plano só **acrescenta** e **consome** (via `CalculadoraDevido`, `RepositorioPagamentoPdo`, tabelas existentes).
- **Controllers puros de I/O:** recebem `Request` e devolvem `Response`; nenhum `echo`, `header()`, `$_GET`, `$_POST`, `$_SESSION`, `exit` dentro deles. Superglobais só em `Request::daGlobais()`, `Response::enviar()`, `SessaoPhp` e `public/index.php`.
- **Determinismo:** o "agora" entra como `DateTimeImmutable $agora` nos serviços/consultas; só `public/index.php` materializa `new DateTimeImmutable('now')` (com `date_default_timezone_set('America/Sao_Paulo')`, como o cron do Plano 4).
- **Segurança (spec §5):** senha/PIN com `password_hash()`; CSRF em todo POST (token na sessão, `hash_equals`); prepared statements (já é o padrão); escape de saída com `htmlspecialchars()` em toda interpolação nas views; regenerar id de sessão no login.
- **PIN:** numérico, tratado como string ao hashear; nunca armazenar/logar o PIN em claro.
- **SQL portável MySQL/SQLite:** placeholders `?`; sem funções de dialeto; escrita multi-tabela em transação.
- **Uploads:** comprovante (opcional) validado por tipo/tamanho e gravado em `/uploads` (fora da raiz web, já no `.gitignore`) com nome não adivinhável; a gravação física é glue.
- **Idioma/UX:** português, mobile-first, textos curtos, cores de status (verde/amarelo/vermelho), botão grande de confirmação (spec §8).

## Review Focus

- **Acesso sem sessão:** abrir o painel (ou postar confirmar/pagamento) sem estar logado redireciona para o login, nunca vaza dados nem executa a ação. — coberto na Task 7 (`test_painel_sem_sessao_redireciona`, `test_confirmar_sem_sessao_redireciona`).
- **CSRF inválido:** POST com token ausente/errado é rejeitado (não confirma, não registra pagamento). — coberto na Task 7 (`test_confirmar_com_csrf_invalido_rejeita`).
- **PIN errado / jogador inexistente:** `autenticar` devolve `false` sem exceção; login não cria sessão. — coberto na Task 4 (`test_pin_errado_falha`, `test_jogador_sem_pin_falha`) e Task 7 (`test_login_com_pin_errado_nao_autentica`).
- **Confirmar quando a lista está cheia:** o jogador entra como **espera** (não confirmado), respeitando o limite do seu tipo. — coberto na Task 5 (`test_confirma_como_espera_quando_lotado`).
- **Re-confirmar / desistir e voltar:** confirmar duas vezes não duplica inscrição; desistir e confirmar de novo reativa a inscrição sem criar outra linha. — coberto na Task 5 (`test_confirmar_e_idempotente`, `test_reconfirmar_apos_desistir`).

---

### Task 1: Kernel HTTP — `Request` e `Response`

**Files:**
- Create: `src/Web/Request.php`
- Create: `src/Web/Response.php`
- Test: `tests/Web/RequestTest.php`
- Test: `tests/Web/ResponseTest.php`

**Interfaces:**
- Consumes: nada (PHP puro).
- Produces:
  - `final class Request` (readonly): `__construct(string $metodo, string $caminho, array $query = [], array $post = [])`; getters implícitos via propriedades públicas; `entrada(string $chave, ?string $default = null): ?string` lê de `post` (fallback `query`).
  - `final class Response` (readonly): `__construct(int $status, string $corpo, array $cabecalhos = [])`; `static html(string $corpo, int $status = 200): self`; `static redirecionar(string $para, int $status = 302): self` (cabeçalho `Location`).

- [ ] **Step 1: Escrever `tests/Web/ResponseTest.php` (falha)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Web\Response;

final class ResponseTest extends TestCase
{
    public function test_html_tem_status_200_e_corpo(): void
    {
        $r = Response::html('<h1>oi</h1>');
        $this->assertSame(200, $r->status);
        $this->assertSame('<h1>oi</h1>', $r->corpo);
    }

    public function test_redirecionar_usa_location_e_302(): void
    {
        $r = Response::redirecionar('/entrar');
        $this->assertSame(302, $r->status);
        $this->assertSame('/entrar', $r->cabecalhos['Location']);
    }
}
```

- [ ] **Step 2: Escrever `tests/Web/RequestTest.php` (falha)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Web\Request;

final class RequestTest extends TestCase
{
    public function test_entrada_le_do_post_com_fallback_na_query(): void
    {
        $req = new Request('POST', '/entrar', query: ['a' => 'q'], post: ['b' => 'p']);
        $this->assertSame('p', $req->entrada('b'));
        $this->assertSame('q', $req->entrada('a'));
        $this->assertSame('x', $req->entrada('inexistente', 'x'));
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Web/RequestTest.php tests/Web/ResponseTest.php`
Expected: FAIL — classes não existem.

- [ ] **Step 4: Criar `src/Web/Response.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class Response
{
    /** @param array<string,string> $cabecalhos */
    public function __construct(
        public readonly int $status,
        public readonly string $corpo,
        public readonly array $cabecalhos = [],
    ) {
    }

    public static function html(string $corpo, int $status = 200): self
    {
        return new self($status, $corpo, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirecionar(string $para, int $status = 302): self
    {
        return new self($status, '', ['Location' => $para]);
    }
}
```

- [ ] **Step 5: Criar `src/Web/Request.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class Request
{
    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $caminho,
        public readonly array $query = [],
        public readonly array $post = [],
    ) {
    }

    public function entrada(string $chave, ?string $default = null): ?string
    {
        $valor = $this->post[$chave] ?? $this->query[$chave] ?? $default;

        return $valor === null ? null : (string) $valor;
    }

    public static function daGlobais(): self
    {
        $caminho = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $caminho,
            $_GET,
            $_POST,
        );
    }
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Web/RequestTest.php tests/Web/ResponseTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Web/Request.php src/Web/Response.php tests/Web/RequestTest.php tests/Web/ResponseTest.php
git commit -m "feat(web): kernel HTTP minimo (Request + Response)"
```

---

### Task 2: `Roteador`

**Files:**
- Create: `src/Web/Roteador.php`
- Test: `tests/Web/RoteadorTest.php`

**Interfaces:**
- Consumes: `Request`, `Response` (Task 1).
- Produces: `final class Roteador`: `get(string $padrao, callable $handler): void`; `post(string $padrao, callable $handler): void`; `despachar(Request $req): Response`. O `$handler` tem assinatura `fn(Request $req, array $params): Response`. Padrões suportam segmentos `{nome}` (capturados em `$params`). Sem rota casada → `Response` 404.

- [ ] **Step 1: Escrever `tests/Web/RoteadorTest.php` (falha)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Web\Request;
use RcInfoti\Pelada\Web\Response;
use RcInfoti\Pelada\Web\Roteador;

final class RoteadorTest extends TestCase
{
    public function test_casa_rota_get_simples(): void
    {
        $r = new Roteador();
        $r->get('/', fn (Request $req, array $p): Response => Response::html('home'));

        $resp = $r->despachar(new Request('GET', '/'));
        $this->assertSame('home', $resp->corpo);
    }

    public function test_captura_parametro_de_caminho(): void
    {
        $r = new Roteador();
        $r->get('/j/{slug}', fn (Request $req, array $p): Response => Response::html('oi ' . $p['slug']));

        $resp = $r->despachar(new Request('GET', '/j/ana'));
        $this->assertSame('oi ana', $resp->corpo);
    }

    public function test_metodo_diferente_nao_casa(): void
    {
        $r = new Roteador();
        $r->get('/entrar', fn (Request $req, array $p): Response => Response::html('form'));

        $resp = $r->despachar(new Request('POST', '/entrar'));
        $this->assertSame(404, $resp->status);
    }

    public function test_sem_rota_devolve_404(): void
    {
        $resp = (new Roteador())->despachar(new Request('GET', '/nada'));
        $this->assertSame(404, $resp->status);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Web/RoteadorTest.php`
Expected: FAIL — classe não existe.

- [ ] **Step 3: Criar `src/Web/Roteador.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class Roteador
{
    /** @var list<array{metodo:string,regex:string,nomes:list<string>,handler:callable}> */
    private array $rotas = [];

    public function get(string $padrao, callable $handler): void
    {
        $this->registrar('GET', $padrao, $handler);
    }

    public function post(string $padrao, callable $handler): void
    {
        $this->registrar('POST', $padrao, $handler);
    }

    public function despachar(Request $req): Response
    {
        foreach ($this->rotas as $rota) {
            if ($rota['metodo'] !== $req->metodo) {
                continue;
            }
            if (preg_match($rota['regex'], $req->caminho, $m) === 1) {
                $params = [];
                foreach ($rota['nomes'] as $nome) {
                    $params[$nome] = $m[$nome];
                }

                return ($rota['handler'])($req, $params);
            }
        }

        return Response::html('Não encontrado', 404);
    }

    private function registrar(string $metodo, string $padrao, callable $handler): void
    {
        $nomes = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$nomes): string {
            $nomes[] = $m[1];

            return '(?P<' . $m[1] . '>[^/]+)';
        }, $padrao);

        $this->rotas[] = [
            'metodo' => $metodo,
            'regex' => '#^' . $regex . '$#',
            'nomes' => $nomes,
            'handler' => $handler,
        ];
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Web/RoteadorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Web/Roteador.php tests/Web/RoteadorTest.php
git commit -m "feat(web): Roteador com parametros de caminho e 404"
```

---

### Task 3: Sessão (porta + fake) e CSRF

**Files:**
- Create: `src/Web/Sessao.php`
- Create: `src/Web/SessaoPhp.php` (glue — usa `$_SESSION`)
- Create: `tests/Web/SessaoMemoria.php` (fake de teste)
- Create: `src/Web/Csrf.php`
- Test: `tests/Web/CsrfTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `interface Sessao`: `get(string $chave): mixed`; `set(string $chave, mixed $valor): void`; `remove(string $chave): void`; `regenerar(): void`.
  - `final class SessaoPhp implements Sessao` — sobre `$_SESSION`/`session_regenerate_id` (glue).
  - `final class SessaoMemoria implements Sessao` (teste) — array em memória.
  - `final class Csrf`: `__construct(Sessao $sessao)`; `token(): string` (gera e guarda em `_csrf` se ausente); `valido(?string $enviado): bool` (`hash_equals`, false se ausente).

- [ ] **Step 1: Escrever `tests/Web/CsrfTest.php` (falha)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Web\Csrf;

final class CsrfTest extends TestCase
{
    public function test_token_e_estavel_na_sessao(): void
    {
        $csrf = new Csrf(new SessaoMemoria());
        $t1 = $csrf->token();
        $t2 = $csrf->token();

        $this->assertNotSame('', $t1);
        $this->assertSame($t1, $t2); // mesmo token durante a sessão
    }

    public function test_valida_token_correto_e_rejeita_o_resto(): void
    {
        $csrf = new Csrf(new SessaoMemoria());
        $token = $csrf->token();

        $this->assertTrue($csrf->valido($token));
        $this->assertFalse($csrf->valido('errado'));
        $this->assertFalse($csrf->valido(null));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Web/CsrfTest.php`
Expected: FAIL — `Csrf`/`SessaoMemoria` não existem.

- [ ] **Step 3: Criar `src/Web/Sessao.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

interface Sessao
{
    public function get(string $chave): mixed;

    public function set(string $chave, mixed $valor): void;

    public function remove(string $chave): void;

    public function regenerar(): void;
}
```

- [ ] **Step 4: Criar o fake `tests/Web/SessaoMemoria.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use RcInfoti\Pelada\Web\Sessao;

final class SessaoMemoria implements Sessao
{
    /** @var array<string,mixed> */
    private array $dados = [];

    public function get(string $chave): mixed
    {
        return $this->dados[$chave] ?? null;
    }

    public function set(string $chave, mixed $valor): void
    {
        $this->dados[$chave] = $valor;
    }

    public function remove(string $chave): void
    {
        unset($this->dados[$chave]);
    }

    public function regenerar(): void
    {
        // no-op em memória
    }
}
```

- [ ] **Step 5: Criar `src/Web/SessaoPhp.php` (glue)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class SessaoPhp implements Sessao
{
    public function get(string $chave): mixed
    {
        return $_SESSION[$chave] ?? null;
    }

    public function set(string $chave, mixed $valor): void
    {
        $_SESSION[$chave] = $valor;
    }

    public function remove(string $chave): void
    {
        unset($_SESSION[$chave]);
    }

    public function regenerar(): void
    {
        session_regenerate_id(true);
    }
}
```

- [ ] **Step 6: Criar `src/Web/Csrf.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class Csrf
{
    public function __construct(private readonly Sessao $sessao)
    {
    }

    public function token(): string
    {
        $token = $this->sessao->get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->sessao->set('_csrf', $token);
        }

        return $token;
    }

    public function valido(?string $enviado): bool
    {
        $token = $this->sessao->get('_csrf');

        return is_string($token) && $enviado !== null && hash_equals($token, $enviado);
    }
}
```

- [ ] **Step 7: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Web/CsrfTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Web/Sessao.php src/Web/SessaoPhp.php tests/Web/SessaoMemoria.php src/Web/Csrf.php tests/Web/CsrfTest.php
git commit -m "feat(web): sessao (porta + fake) e CSRF token"
```

---

### Task 4: Login por PIN — `ServicoAcessoPin`

**Files:**
- Create: `src/Acesso/ServicoAcessoPin.php`
- Test: `tests/Acesso/ServicoAcessoPinTest.php`

**Interfaces:**
- Consumes: tabela `jogadores` (coluna `pin_hash`); `SchemaSqlite`.
- Produces: `final class ServicoAcessoPin`: `__construct(PDO $pdo)`; `definirPin(int $jogadorId, string $pin): void` (grava `password_hash($pin, PASSWORD_DEFAULT)` em `pin_hash`); `autenticar(int $jogadorId, string $pin): bool` (`password_verify`; `false` se jogador inexistente ou sem `pin_hash`).

- [ ] **Step 1: Escrever `tests/Acesso/ServicoAcessoPinTest.php` (falha)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Acesso;

use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class ServicoAcessoPinTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (10, 1, 'Ana', 'linha')");
    }

    private function servico(): ServicoAcessoPin
    {
        return new ServicoAcessoPin($this->pdo);
    }

    public function test_pin_correto_autentica(): void
    {
        $s = $this->servico();
        $s->definirPin(10, '1234');

        $this->assertTrue($s->autenticar(10, '1234'));
    }

    public function test_pin_errado_falha(): void
    {
        $s = $this->servico();
        $s->definirPin(10, '1234');

        $this->assertFalse($s->autenticar(10, '0000'));
    }

    public function test_jogador_sem_pin_falha(): void
    {
        $this->assertFalse($this->servico()->autenticar(10, '1234'));
    }

    public function test_jogador_inexistente_falha(): void
    {
        $this->assertFalse($this->servico()->autenticar(999, '1234'));
    }

    public function test_pin_nao_e_guardado_em_claro(): void
    {
        $s = $this->servico();
        $s->definirPin(10, '1234');

        $hash = $this->pdo->query('SELECT pin_hash FROM jogadores WHERE id = 10')->fetchColumn();
        $this->assertNotSame('1234', $hash);
        $this->assertTrue(password_verify('1234', (string) $hash));
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Acesso/ServicoAcessoPinTest.php`
Expected: FAIL — classe não existe.

- [ ] **Step 3: Criar `src/Acesso/ServicoAcessoPin.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Acesso;

use PDO;

final class ServicoAcessoPin
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function definirPin(int $jogadorId, string $pin): void
    {
        $this->pdo->prepare('UPDATE jogadores SET pin_hash = ? WHERE id = ?')
            ->execute([password_hash($pin, PASSWORD_DEFAULT), $jogadorId]);
    }

    public function autenticar(int $jogadorId, string $pin): bool
    {
        $stmt = $this->pdo->prepare('SELECT pin_hash FROM jogadores WHERE id = ?');
        $stmt->execute([$jogadorId]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) && $hash !== '' && password_verify($pin, $hash);
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Acesso/ServicoAcessoPinTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Acesso/ServicoAcessoPin.php tests/Acesso/ServicoAcessoPinTest.php
git commit -m "feat(acesso): login por PIN (definir + autenticar via password_hash)"
```

---

### Task 5: Presença — `ServicoPresenca` (confirmar / desistir)

**Files:**
- Create: `src/Presenca/ServicoPresenca.php`
- Test: `tests/Presenca/ServicoPresencaTest.php`

**Interfaces:**
- Consumes: tabelas `jogadores`, `rodadas`, `peladas`, `inscricoes`; `SchemaSqlite`.
- Produces: `final class ServicoPresenca`: `__construct(PDO $pdo)`;
  - `confirmar(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void` — em transação: se o jogador não tem inscrição, insere com a próxima `ordem` global da rodada; define `status = 'confirmado'` se o nº de confirmados do **tipo do jogador** for menor que o limite (linha/goleiro), senão `'espera'`; grava `confirmado_em`. Se já existe inscrição `confirmado`/`espera`, é no-op. Se existe `desistiu`, reativa (recalcula status, limpa `desistiu_em`) mantendo a `ordem` original.
  - `desistir(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void` — marca a inscrição do jogador como `'desistiu'` com `desistiu_em = agora`; sem inscrição, no-op.

- [ ] **Step 1: Escrever `tests/Presenca/ServicoPresencaTest.php` (falha)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Presenca;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class ServicoPresencaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        // limite de linha = 1 para exercitar a espera
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro) VALUES (1, 'Quinta', 'quinta', 1, 1)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES
            (10, 1, 'Ana', 'linha'), (11, 1, 'Bia', 'linha'), (12, 1, 'Cadu', 'goleiro')");
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
    }

    private function servico(): ServicoPresenca
    {
        return new ServicoPresenca($this->pdo);
    }

    private function inscricao(int $jogadorId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inscricoes WHERE rodada_id = 5 AND jogador_id = ?');
        $stmt->execute([$jogadorId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function test_primeiro_confirmado_entra_como_confirmado(): void
    {
        $this->servico()->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00'));

        $i = $this->inscricao(10);
        $this->assertSame('confirmado', $i['status']);
        $this->assertSame('linha', $i['tipo']);
        $this->assertSame(1, (int) $i['ordem']);
    }

    public function test_confirma_como_espera_quando_lotado(): void
    {
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00')); // ocupa a única vaga de linha
        $s->confirmar(5, 11, new DateTimeImmutable('2026-01-05 10:05:00')); // lotado → espera

        $this->assertSame('confirmado', $this->inscricao(10)['status']);
        $this->assertSame('espera', $this->inscricao(11)['status']);
        $this->assertSame(2, (int) $this->inscricao(11)['ordem']);
    }

    public function test_goleiro_tem_limite_proprio(): void
    {
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00')); // linha ocupa vaga de linha
        $s->confirmar(5, 12, new DateTimeImmutable('2026-01-05 10:05:00')); // goleiro tem sua própria vaga

        $this->assertSame('confirmado', $this->inscricao(12)['status']);
    }

    public function test_confirmar_e_idempotente(): void
    {
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00'));
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:10:00'));

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inscricoes WHERE rodada_id = 5 AND jogador_id = 10')->fetchColumn());
    }

    public function test_desistir_marca_status_e_data(): void
    {
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00'));
        $s->desistir(5, 10, new DateTimeImmutable('2026-01-08 17:00:00'));

        $i = $this->inscricao(10);
        $this->assertSame('desistiu', $i['status']);
        $this->assertSame('2026-01-08 17:00:00', $i['desistiu_em']);
    }

    public function test_reconfirmar_apos_desistir(): void
    {
        $s = $this->servico();
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-05 10:00:00'));
        $s->desistir(5, 10, new DateTimeImmutable('2026-01-06 09:00:00'));
        $s->confirmar(5, 10, new DateTimeImmutable('2026-01-06 10:00:00'));

        $i = $this->inscricao(10);
        $this->assertSame('confirmado', $i['status']);
        $this->assertNull($i['desistiu_em']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM inscricoes WHERE rodada_id = 5 AND jogador_id = 10')->fetchColumn());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Presenca/ServicoPresencaTest.php`
Expected: FAIL — classe não existe.

- [ ] **Step 3: Criar `src/Presenca/ServicoPresenca.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Presenca;

use DateTimeImmutable;
use PDO;

final class ServicoPresenca
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function confirmar(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $this->pdo->beginTransaction();
        try {
            $ctx = $this->contexto($rodadaId, $jogadorId);
            $atual = $this->statusAtual($rodadaId, $jogadorId);

            if ($atual === 'confirmado' || $atual === 'espera') {
                $this->pdo->commit();

                return; // já está dentro
            }

            $status = $this->temVaga($rodadaId, $ctx['tipo'], $ctx['limite']) ? 'confirmado' : 'espera';
            $quando = $agora->format('Y-m-d H:i:s');

            if ($atual === 'desistiu') {
                $this->pdo->prepare(
                    'UPDATE inscricoes SET status = ?, confirmado_em = ?, desistiu_em = NULL
                     WHERE rodada_id = ? AND jogador_id = ?'
                )->execute([$status, $quando, $rodadaId, $jogadorId]);
            } else {
                $ordem = (int) $this->pdo->query(
                    'SELECT COALESCE(MAX(ordem), 0) + 1 FROM inscricoes WHERE rodada_id = ' . $rodadaId
                )->fetchColumn();

                $this->pdo->prepare(
                    'INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem, confirmado_em)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$rodadaId, $jogadorId, $ctx['tipo'], $status, $ordem, $quando]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function desistir(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $this->pdo->prepare(
            "UPDATE inscricoes SET status = 'desistiu', desistiu_em = ?
             WHERE rodada_id = ? AND jogador_id = ?"
        )->execute([$agora->format('Y-m-d H:i:s'), $rodadaId, $jogadorId]);
    }

    /** @return array{tipo:string,limite:int} */
    private function contexto(int $rodadaId, int $jogadorId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT j.tipo, p.limite_linha, p.limite_goleiro
             FROM rodadas r
             JOIN peladas p ON p.id = r.pelada_id
             JOIN jogadores j ON j.id = ?
             WHERE r.id = ?'
        );
        $stmt->execute([$jogadorId, $rodadaId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            throw new \RuntimeException("Contexto de presença não encontrado (rodada {$rodadaId}, jogador {$jogadorId}).");
        }

        return [
            'tipo' => (string) $r['tipo'],
            'limite' => $r['tipo'] === 'goleiro' ? (int) $r['limite_goleiro'] : (int) $r['limite_linha'],
        ];
    }

    private function statusAtual(int $rodadaId, int $jogadorId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM inscricoes WHERE rodada_id = ? AND jogador_id = ?');
        $stmt->execute([$rodadaId, $jogadorId]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    private function temVaga(int $rodadaId, string $tipo, int $limite): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM inscricoes WHERE rodada_id = ? AND tipo = ? AND status = 'confirmado'"
        );
        $stmt->execute([$rodadaId, $tipo]);

        return (int) $stmt->fetchColumn() < $limite;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Presenca/ServicoPresencaTest.php`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte inteira**

Run: `composer test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Presenca/ServicoPresenca.php tests/Presenca/ServicoPresencaTest.php
git commit -m "feat(presenca): confirmar (com espera por limite) e desistir"
```

---

### Task 6: Read-model do painel — `ConsultaPainelJogador`

**Files:**
- Create: `src/Painel/PainelJogador.php`
- Create: `src/Painel/ConsultaPainelJogador.php`
- Test: `tests/Painel/ConsultaPainelJogadorTest.php`

**Interfaces:**
- Consumes: tabelas `jogadores`, `peladas`, `rodadas`, `inscricoes`, `pagamentos`; `CalculadoraDevido` (Plano 3); `SchemaSqlite`.
- Produces:
  - `final class PainelJogador` (readonly VO): `__construct(string $nome, string $tipo, ?int $rodadaId, ?string $dataJogo, string $situacao, float $devidoSemana, float $saldoPendente)`. `situacao` ∈ `confirmado|espera|desistiu|fora|sem_rodada`.
  - `final class ConsultaPainelJogador`: `__construct(PDO $pdo)`; `montar(int $jogadorId, DateTimeImmutable $agora): PainelJogador` — usa a rodada **aberta** mais próxima da pelada do jogador; `situacao` vem da inscrição (ou `fora` se não inscrito, `sem_rodada` se não há rodada aberta); `devidoSemana` via `CalculadoraDevido` (considerando `festa_quitada_ano` do ano do jogo); `saldoPendente` do cadastro.

- [ ] **Step 1: Escrever `tests/Painel/ConsultaPainelJogadorTest.php` (falha)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Painel;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Painel\ConsultaPainelJogador;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class ConsultaPainelJogadorTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, valor_futebol, valor_festa_semana) VALUES (1, 'Quinta', 'quinta', 15.00, 5.00)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo, saldo_pendente) VALUES (10, 1, 'Ana', 'linha', 30.00)");
    }

    private function consulta(): ConsultaPainelJogador
    {
        return new ConsultaPainelJogador($this->pdo);
    }

    public function test_sem_rodada_aberta(): void
    {
        $p = $this->consulta()->montar(10, new DateTimeImmutable('2026-01-05 10:00:00'));

        $this->assertSame('Ana', $p->nome);
        $this->assertSame('sem_rodada', $p->situacao);
        $this->assertNull($p->rodadaId);
        $this->assertSame(30.0, $p->saldoPendente);
    }

    public function test_fora_quando_ha_rodada_mas_nao_inscrito(): void
    {
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");

        $p = $this->consulta()->montar(10, new DateTimeImmutable('2026-01-05 10:00:00'));

        $this->assertSame('fora', $p->situacao);
        $this->assertSame(5, $p->rodadaId);
        $this->assertSame('2026-01-08', $p->dataJogo);
        $this->assertSame(20.0, $p->devidoSemana); // linha: 15 + 5
    }

    public function test_confirmado_reflete_inscricao(): void
    {
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem) VALUES (5, 10, 'linha', 'confirmado', 1)");

        $p = $this->consulta()->montar(10, new DateTimeImmutable('2026-01-05 10:00:00'));

        $this->assertSame('confirmado', $p->situacao);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Painel/ConsultaPainelJogadorTest.php`
Expected: FAIL — classes não existem.

- [ ] **Step 3: Criar o VO `src/Painel/PainelJogador.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Painel;

final class PainelJogador
{
    public function __construct(
        public readonly string $nome,
        public readonly string $tipo,
        public readonly ?int $rodadaId,
        public readonly ?string $dataJogo,
        public readonly string $situacao,
        public readonly float $devidoSemana,
        public readonly float $saldoPendente,
    ) {
    }
}
```

- [ ] **Step 4: Criar `src/Painel/ConsultaPainelJogador.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Painel;

use DateTimeImmutable;
use PDO;
use RcInfoti\Pelada\Financeiro\CalculadoraDevido;
use RcInfoti\Pelada\Rodada\Tipo;

final class ConsultaPainelJogador
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function montar(int $jogadorId, DateTimeImmutable $agora): PainelJogador
    {
        $jog = $this->pdo->prepare(
            'SELECT j.nome, j.tipo, j.saldo_pendente, j.festa_quitada_ano, j.pelada_id,
                    p.valor_futebol, p.valor_festa_semana
             FROM jogadores j JOIN peladas p ON p.id = j.pelada_id
             WHERE j.id = ?'
        );
        $jog->execute([$jogadorId]);
        $j = $jog->fetch(PDO::FETCH_ASSOC);
        if ($j === false) {
            throw new \RuntimeException("Jogador {$jogadorId} não encontrado.");
        }

        // Rodada aberta mais próxima da pelada do jogador.
        $rod = $this->pdo->prepare(
            "SELECT id, data_jogo FROM rodadas
             WHERE pelada_id = ? AND status = 'aberta'
             ORDER BY data_jogo LIMIT 1"
        );
        $rod->execute([(int) $j['pelada_id']]);
        $r = $rod->fetch(PDO::FETCH_ASSOC);

        $rodadaId = $r === false ? null : (int) $r['id'];
        $dataJogo = $r === false ? null : (string) $r['data_jogo'];

        $situacao = 'sem_rodada';
        if ($rodadaId !== null) {
            $ins = $this->pdo->prepare('SELECT status FROM inscricoes WHERE rodada_id = ? AND jogador_id = ?');
            $ins->execute([$rodadaId, $jogadorId]);
            $status = $ins->fetchColumn();
            $situacao = $status === false ? 'fora' : (string) $status;
        }

        $anoJogo = $dataJogo !== null ? (int) (new DateTimeImmutable($dataJogo))->format('Y') : (int) $agora->format('Y');
        $festaQuitada = $j['festa_quitada_ano'] !== null && (int) $j['festa_quitada_ano'] === $anoJogo;

        $calc = new CalculadoraDevido((float) $j['valor_futebol'], (float) $j['valor_festa_semana']);
        $tipo = $j['tipo'] === 'goleiro' ? Tipo::Goleiro : Tipo::Linha;

        return new PainelJogador(
            (string) $j['nome'],
            (string) $j['tipo'],
            $rodadaId,
            $dataJogo,
            $situacao,
            $calc->devidoSemanal($tipo, $festaQuitada),
            (float) $j['saldo_pendente'],
        );
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Painel/ConsultaPainelJogadorTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Painel/PainelJogador.php src/Painel/ConsultaPainelJogador.php tests/Painel/ConsultaPainelJogadorTest.php
git commit -m "feat(painel): read-model da situacao do jogador (ConsultaPainelJogador)"
```

---

### Task 7: Controller do jogador — `PainelJogadorController`

**Files:**
- Create: `src/Web/PainelJogadorController.php`
- Test: `tests/Web/PainelJogadorControllerTest.php`

**Interfaces:**
- Consumes: `Request`/`Response`/`Sessao`/`Csrf` (Tasks 1–3), `ServicoAcessoPin` (Task 4), `ServicoPresenca` (Task 5), `ConsultaPainelJogador` (Task 6), `RepositorioPagamentoPdo` + enums (Plano 3); `SchemaSqlite`, `SessaoMemoria` (testes).
- Produces: `final class PainelJogadorController`: `__construct(Sessao $sessao, Csrf $csrf, ServicoAcessoPin $acesso, ServicoPresenca $presenca, ConsultaPainelJogador $consulta, RepositorioPagamentoPdo $pagamentos)`. Métodos que recebem `Request` e devolvem `Response`:
  - `autenticar(Request $req): Response` — valida CSRF + `autenticar(jogadorId, pin)`; sucesso → regenera sessão, grava `jogador_id`, redireciona `/`; falha → redireciona `/entrar?erro=1`.
  - `painel(Request $req): Response` — sem `jogador_id` na sessão → redireciona `/entrar`; senão renderiza a tela do jogador (via `montarHtml`, glue).
  - `confirmar(Request $req): Response` / `naoVou(Request $req): Response` — exigem sessão + CSRF; chamam `ServicoPresenca`; redirecionam `/`.
  - `avisarPagamento(Request $req): Response` — exige sessão + CSRF; registra pagamento (`RepositorioPagamentoPdo::registrar`); redireciona `/`.
  - `sair(Request $req): Response` — remove `jogador_id`; redireciona `/entrar`.

  (A renderização HTML de fato vive na Task 8; aqui os testes checam status/redirecionamento e efeitos, não o corpo das telas.)

- [ ] **Step 1: Escrever `tests/Web/PainelJogadorControllerTest.php` (falha)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Painel\ConsultaPainelJogador;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Web\Csrf;
use RcInfoti\Pelada\Web\PainelJogadorController;
use RcInfoti\Pelada\Web\Request;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class PainelJogadorControllerTest extends TestCase
{
    private PDO $pdo;
    private SessaoMemoria $sessao;
    private Csrf $csrf;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro) VALUES (1, 'Quinta', 'quinta', 20, 4)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (10, 1, 'Ana', 'linha')");
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        $this->sessao = new SessaoMemoria();
        $this->csrf = new Csrf($this->sessao);
        (new ServicoAcessoPin($this->pdo))->definirPin(10, '1234');
    }

    private function controller(): PainelJogadorController
    {
        return new PainelJogadorController(
            $this->sessao,
            $this->csrf,
            new ServicoAcessoPin($this->pdo),
            new ServicoPresenca($this->pdo),
            new ConsultaPainelJogador($this->pdo),
            new RepositorioPagamentoPdo($this->pdo),
        );
    }

    public function test_login_com_pin_correto_cria_sessao_e_redireciona(): void
    {
        $req = new Request('POST', '/entrar', post: ['jogador_id' => '10', 'pin' => '1234', '_csrf' => $this->csrf->token()]);
        $resp = $this->controller()->autenticar($req);

        $this->assertSame(302, $resp->status);
        $this->assertSame('/', $resp->cabecalhos['Location']);
        $this->assertSame(10, $this->sessao->get('jogador_id'));
    }

    public function test_login_com_pin_errado_nao_autentica(): void
    {
        $req = new Request('POST', '/entrar', post: ['jogador_id' => '10', 'pin' => '0000', '_csrf' => $this->csrf->token()]);
        $resp = $this->controller()->autenticar($req);

        $this->assertSame(302, $resp->status);
        $this->assertSame('/entrar?erro=1', $resp->cabecalhos['Location']);
        $this->assertNull($this->sessao->get('jogador_id'));
    }

    public function test_painel_sem_sessao_redireciona(): void
    {
        $resp = $this->controller()->painel(new Request('GET', '/'));
        $this->assertSame(302, $resp->status);
        $this->assertSame('/entrar', $resp->cabecalhos['Location']);
    }

    public function test_confirmar_sem_sessao_redireciona(): void
    {
        $resp = $this->controller()->confirmar(new Request('POST', '/confirmar'));
        $this->assertSame('/entrar', $resp->cabecalhos['Location']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inscricoes')->fetchColumn());
    }

    public function test_confirmar_com_csrf_invalido_rejeita(): void
    {
        $this->sessao->set('jogador_id', 10);
        $resp = $this->controller()->confirmar(new Request('POST', '/confirmar', post: ['_csrf' => 'errado']));

        $this->assertSame(400, $resp->status);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inscricoes')->fetchColumn());
    }

    public function test_confirmar_logado_e_com_csrf_insere_inscricao(): void
    {
        $this->sessao->set('jogador_id', 10);
        $resp = $this->controller()->confirmar(new Request('POST', '/confirmar', post: ['_csrf' => $this->csrf->token()]));

        $this->assertSame(302, $resp->status);
        $this->assertSame('confirmado', $this->pdo->query('SELECT status FROM inscricoes WHERE rodada_id = 5 AND jogador_id = 10')->fetchColumn());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Web/PainelJogadorControllerTest.php`
Expected: FAIL — classe não existe.

- [ ] **Step 3: Criar `src/Web/PainelJogadorController.php`**

O `agora` é criado por método (`new DateTimeImmutable('now')`) porque é o ponto de entrada da requisição; a lógica pura já recebeu `$agora` nos serviços/consulta. A renderização final (`montarHtml`) é preenchida na Task 8; aqui devolve um HTML mínimo para os testes de status passarem.

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

use DateTimeImmutable;
use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Financeiro\CategoriaPagamento;
use RcInfoti\Pelada\Financeiro\EscopoPagamento;
use RcInfoti\Pelada\Financeiro\FormaPagamento;
use RcInfoti\Pelada\Financeiro\Pagamento;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Painel\ConsultaPainelJogador;
use RcInfoti\Pelada\Presenca\ServicoPresenca;

final class PainelJogadorController
{
    public function __construct(
        private readonly Sessao $sessao,
        private readonly Csrf $csrf,
        private readonly ServicoAcessoPin $acesso,
        private readonly ServicoPresenca $presenca,
        private readonly ConsultaPainelJogador $consulta,
        private readonly RepositorioPagamentoPdo $pagamentos,
    ) {
    }

    public function autenticar(Request $req): Response
    {
        if (!$this->csrf->valido($req->entrada('_csrf'))) {
            return Response::html('CSRF inválido', 400);
        }

        $jogadorId = (int) ($req->entrada('jogador_id') ?? '0');
        $pin = $req->entrada('pin') ?? '';

        if (!$this->acesso->autenticar($jogadorId, $pin)) {
            return Response::redirecionar('/entrar?erro=1');
        }

        $this->sessao->regenerar();
        $this->sessao->set('jogador_id', $jogadorId);

        return Response::redirecionar('/');
    }

    public function painel(Request $req): Response
    {
        $jogadorId = $this->jogadorLogado();
        if ($jogadorId === null) {
            return Response::redirecionar('/entrar');
        }

        $painel = $this->consulta->montar($jogadorId, new DateTimeImmutable('now'));

        return Response::html($this->montarHtml($painel));
    }

    public function confirmar(Request $req): Response
    {
        return $this->comAcaoDeRodada($req, function (int $rodadaId, int $jogadorId): void {
            $this->presenca->confirmar($rodadaId, $jogadorId, new DateTimeImmutable('now'));
        });
    }

    public function naoVou(Request $req): Response
    {
        return $this->comAcaoDeRodada($req, function (int $rodadaId, int $jogadorId): void {
            $this->presenca->desistir($rodadaId, $jogadorId, new DateTimeImmutable('now'));
        });
    }

    public function avisarPagamento(Request $req): Response
    {
        $guard = $this->exigirSessaoECsrf($req);
        if ($guard !== null) {
            return $guard;
        }

        $jogadorId = (int) $this->jogadorLogado();
        $painel = $this->consulta->montar($jogadorId, new DateTimeImmutable('now'));

        $categoria = $req->entrada('categoria') === 'festa' ? CategoriaPagamento::Festa : CategoriaPagamento::Futebol;
        $forma = $req->entrada('forma') === 'dinheiro' ? FormaPagamento::Dinheiro : FormaPagamento::Pix;
        $valor = (float) ($req->entrada('valor') ?? '0');

        $this->pagamentos->registrar(
            new Pagamento(
                $this->consulta->peladaId($jogadorId),
                $jogadorId,
                $categoria,
                EscopoPagamento::Semana,
                $valor,
                $forma,
                rodadaId: $painel->rodadaId,
            ),
            new DateTimeImmutable('now'),
        );

        return Response::redirecionar('/');
    }

    public function sair(Request $req): Response
    {
        $this->sessao->remove('jogador_id');

        return Response::redirecionar('/entrar');
    }

    private function comAcaoDeRodada(Request $req, callable $acao): Response
    {
        $guard = $this->exigirSessaoECsrf($req);
        if ($guard !== null) {
            return $guard;
        }

        $jogadorId = (int) $this->jogadorLogado();
        $painel = $this->consulta->montar($jogadorId, new DateTimeImmutable('now'));
        if ($painel->rodadaId !== null) {
            $acao($painel->rodadaId, $jogadorId);
        }

        return Response::redirecionar('/');
    }

    private function exigirSessaoECsrf(Request $req): ?Response
    {
        if ($this->jogadorLogado() === null) {
            return Response::redirecionar('/entrar');
        }
        if (!$this->csrf->valido($req->entrada('_csrf'))) {
            return Response::html('CSRF inválido', 400);
        }

        return null;
    }

    private function jogadorLogado(): ?int
    {
        $id = $this->sessao->get('jogador_id');

        return is_int($id) ? $id : null;
    }

    private function montarHtml(\RcInfoti\Pelada\Painel\PainelJogador $p): string
    {
        // Placeholder mínimo para os testes de status; a view real (Vista) chega na Task 8.
        return '<!doctype html><title>Pelada</title><main>'
            . htmlspecialchars($p->nome) . ' — ' . htmlspecialchars($p->situacao) . '</main>';
    }
}
```

`avisarPagamento` obtém o `pelada_id` do jogador por `ConsultaPainelJogador::peladaId` (acrescentado abaixo). O teste `test_avisar_pagamento_registra` cobre esse caminho — adicione-o à classe de teste antes de implementar:

```php
    public function test_avisar_pagamento_registra(): void
    {
        $this->sessao->set('jogador_id', 10);
        $req = new Request('POST', '/avisar-pagamento', post: [
            '_csrf' => $this->csrf->token(),
            'categoria' => 'futebol',
            'forma' => 'pix',
            'valor' => '15',
        ]);

        $resp = $this->controller()->avisarPagamento($req);

        $this->assertSame(302, $resp->status);
        $row = $this->pdo->query('SELECT categoria, valor, confirmado FROM pagamentos WHERE jogador_id = 10')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('futebol', $row['categoria']);
        $this->assertSame(15.0, (float) $row['valor']);
        $this->assertSame(0, (int) $row['confirmado']); // aviso do jogador entra não confirmado
    }
```

E acrescente a `ConsultaPainelJogador`:

```php
    public function peladaId(int $jogadorId): int
    {
        $stmt = $this->pdo->prepare('SELECT pelada_id FROM jogadores WHERE id = ?');
        $stmt->execute([$jogadorId]);

        return (int) $stmt->fetchColumn();
    }
```

- [ ] **Step 4: Rodar e ver falhar** (inclua `test_avisar_pagamento_registra`)

Run: `./vendor/bin/phpunit tests/Web/PainelJogadorControllerTest.php`
Expected: FAIL — classe não existe.

- [ ] **Step 5: Implementar** o controller (com a simplificação do `peladaId`, sem os métodos redundantes) e o `peladaId` em `ConsultaPainelJogador`.

- [ ] **Step 6: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Web/PainelJogadorControllerTest.php`
Expected: PASS.

- [ ] **Step 7: Rodar a suíte inteira**

Run: `composer test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Web/PainelJogadorController.php src/Painel/ConsultaPainelJogador.php tests/Web/PainelJogadorControllerTest.php
git commit -m "feat(web): PainelJogadorController (login PIN, confirmar/nao-vou, avisar pagamento, CSRF)"
```

---

### Task 8: Views + front controller + PWA (glue/design)

**REQUIRED SUB-SKILL ao executar esta task:** carregue **frontend-design** antes de escrever HTML/CSS — a tela do jogador é a cara do produto (spec §8: botão grande CONFIRMAR/NÃO VOU, cores de status verde/amarelo/vermelho, mobile-first, textos curtos, ícones).

**Files:**
- Create: `src/Web/vistas/layout.php` (template base + CSS mobile-first)
- Create: `src/Web/vistas/entrar.php` (login: seleção de jogador + PIN)
- Create: `src/Web/vistas/painel.php` (tela única do jogador)
- Create: `src/Web/Vista.php` (renderizador mínimo de template → string)
- Modify: `src/Web/PainelJogadorController.php` (`montarHtml`/`entrar` usam `Vista`)
- Create: `public/index.php` (front controller — glue)
- Create: `public/manifest.webmanifest` (PWA)
- Create: `public/sw.js` (service worker mínimo — cache de leitura)
- Test: `tests/Web/VistaTest.php`

**Interfaces:**
- Consumes: `Request`, `Response`, `Roteador`, `SessaoPhp`, `Csrf`, `PainelJogadorController`, `Database` (Plano 2), `PainelJogador`.
- Produces:
  - `final class Vista`: `__construct(string $diretorio)`; `render(string $arquivo, array $dados = []): string` — inclui o template PHP num escopo isolado com `extract($dados)` e devolve o buffer; escapar é responsabilidade do template (`htmlspecialchars`). (Testável.)
  - `src/Web/vistas/*.php` — templates (glue de apresentação).
  - `public/index.php` — monta `Database`, `SessaoPhp` (com `session_start()`), `Csrf`, o controller e o `Roteador` com as rotas (`GET /entrar`, `POST /entrar`, `GET /`, `POST /confirmar`, `POST /nao-vou`, `POST /avisar-pagamento`, `POST /sair`), despacha `Request::daGlobais()` e chama `Response::enviar()` (glue).
  - `public/manifest.webmanifest`, `public/sw.js` — PWA mínimo.

- [ ] **Step 1: Escrever `tests/Web/VistaTest.php` (falha)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Web;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Web\Vista;

final class VistaTest extends TestCase
{
    public function test_renderiza_template_com_dados(): void
    {
        $dir = sys_get_temp_dir() . '/vistas_' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/oi.php', '<p><?= htmlspecialchars($nome) ?></p>');

        $html = (new Vista($dir))->render('oi', ['nome' => 'Ana & Bia']);

        $this->assertSame('<p>Ana &amp; Bia</p>', $html);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar** — `./vendor/bin/phpunit tests/Web/VistaTest.php` → FAIL (classe não existe).

- [ ] **Step 3: Criar `src/Web/Vista.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

final class Vista
{
    public function __construct(private readonly string $diretorio)
    {
    }

    /** @param array<string,mixed> $dados */
    public function render(string $arquivo, array $dados = []): string
    {
        $caminho = $this->diretorio . '/' . $arquivo . '.php';
        extract($dados, EXTR_SKIP);
        ob_start();
        require $caminho;

        return (string) ob_get_clean();
    }
}
```

- [ ] **Step 4: Rodar e ver passar** — `./vendor/bin/phpunit tests/Web/VistaTest.php` → PASS.

- [ ] **Step 5: Criar os templates** `src/Web/vistas/layout.php`, `entrar.php`, `painel.php` seguindo o frontend-design (mobile-first; um `<main>` estreito centrado; botão grande de confirmar; badge de status colorido — verde `confirmado`, amarelo `espera`, vermelho `desistiu`/`fora`; `htmlspecialchars` em toda saída dinâmica; link do `manifest` e registro do `sw.js`). Ligar `PainelJogadorController::montarHtml`/`entrar` para usar `Vista`. (Conteúdo de apresentação — sem teste unitário; a lógica que os alimenta já está coberta pelas Tasks 6–7.)

- [ ] **Step 6: Criar `public/index.php`** (front controller — glue):

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Infra\Database;
use RcInfoti\Pelada\Painel\ConsultaPainelJogador;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Web\Csrf;
use RcInfoti\Pelada\Web\PainelJogadorController;
use RcInfoti\Pelada\Web\Request;
use RcInfoti\Pelada\Web\Roteador;
use RcInfoti\Pelada\Web\SessaoPhp;

date_default_timezone_set('America/Sao_Paulo');
session_start();

$config = require __DIR__ . '/../config/config.local.php';
$pdo = Database::fromConfig($config['db'])->pdo();

$sessao = new SessaoPhp();
$controller = new PainelJogadorController(
    $sessao,
    new Csrf($sessao),
    new ServicoAcessoPin($pdo),
    new ServicoPresenca($pdo),
    new ConsultaPainelJogador($pdo),
    new RepositorioPagamentoPdo($pdo),
);

$r = new Roteador();
$r->get('/entrar', fn (Request $req, array $p) => $controller->entrar($req));
$r->post('/entrar', fn (Request $req, array $p) => $controller->autenticar($req));
$r->get('/', fn (Request $req, array $p) => $controller->painel($req));
$r->post('/confirmar', fn (Request $req, array $p) => $controller->confirmar($req));
$r->post('/nao-vou', fn (Request $req, array $p) => $controller->naoVou($req));
$r->post('/avisar-pagamento', fn (Request $req, array $p) => $controller->avisarPagamento($req));
$r->post('/sair', fn (Request $req, array $p) => $controller->sair($req));

$r->despachar(Request::daGlobais())->enviar();
```

- [ ] **Step 7: Acrescentar `Response::enviar()` e `PainelJogadorController::entrar()`**

Em `src/Web/Response.php`, adicionar (glue de saída):

```php
    public function enviar(): void
    {
        http_response_code($this->status);
        foreach ($this->cabecalhos as $nome => $valor) {
            header($nome . ': ' . $valor);
        }
        echo $this->corpo;
    }
```

Em `PainelJogadorController`, `entrar(Request $req): Response` renderiza a view de login (lista de jogadores da pelada + campo PIN + token CSRF) via `Vista`. A lista de jogadores é leitura simples (glue).

- [ ] **Step 8: Criar `public/manifest.webmanifest` e `public/sw.js`** (PWA mínimo: nome, ícones, `display: standalone`; service worker que faz cache-first de assets estáticos e network-first das páginas). Registrar o `sw.js` no `layout.php`.

- [ ] **Step 9: Rodar a suíte inteira**

Run: `composer test`
Expected: PASS (as views/glue não quebram os testes; a lógica coberta segue verde).

- [ ] **Step 10: Fumaça manual (opcional, recomendado)** — subir `php -S localhost:8000 -t public` com um `config.local.php` apontando para um MySQL/SQLite de teste e conferir login + confirmar na tela. (Verificação manual; não faz parte da suíte.)

- [ ] **Step 11: Commit**

```bash
git add src/Web/Vista.php src/Web/vistas/ src/Web/Response.php src/Web/PainelJogadorController.php public/ tests/Web/VistaTest.php
git commit -m "feat(web): views (login + tela do jogador), front controller e PWA minimo"
```

---

## Notas de encerramento

- Ao final, `composer test` soma os testes novos aos 78 existentes.
- **Fronteira não testada (glue):** `public/index.php`, `SessaoPhp`, `Response::enviar()`, os templates em `src/Web/vistas/`, `public/sw.js`/`manifest`. É I/O real e apresentação; toda a lógica que eles acionam está coberta por `Roteador`, `Csrf`, `ServicoAcessoPin`, `ServicoPresenca`, `ConsultaPainelJogador` e `PainelJogadorController`.
- **PIN inicial:** nesta fatia o PIN é definido por `ServicoAcessoPin::definirPin` (usado em seed/teste). A tela do organizador para (re)definir PIN vem na área admin (fatia seguinte).
- **Deploy:** apontar o subdomínio/subdiretório para `public/` como raiz web; HTTPS obrigatório para o service worker (spec §10). `config.local.php` com `db`.
- **Fora de escopo (fatias seguintes):** área administrativa (painel do organizador, jogadores, pagamentos a confirmar, caixa, relatórios); upload físico de comprovante com validação completa; multi-pelada por slug no login; refino visual/PWA offline avançado.
