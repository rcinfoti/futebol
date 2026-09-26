# Financeiro — Pagamentos e Caixa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar o dinheiro da pelada — registrar pagamentos (futebol/festa, Pix/dinheiro, comprovante), confirmá-los gerando automaticamente um movimento de entrada no caixa, lançar saídas (gastos), calcular saldo e extrato, e um resumo financeiro mensal por jogador — tudo testado sem servidor via SQLite em memória.

**Architecture:** Continua o backend puro/testável dos Planos 1 e 2. `movimentos_caixa` é a **fonte única da verdade do caixa** (§7): toda entrada de dinheiro nasce de um pagamento confirmado, vinculada por `pagamento_id` (UNIQUE), o que torna a confirmação idempotente e impede dupla contagem. Saídas (gastos) são movimentos sem `pagamento_id`. Três repositórios PDO focados — `RepositorioPagamentoPdo` (registrar/confirmar), `RepositorioCaixaPdo` (saída/saldo/extrato) e `RelatorioFinanceiroPdo` (resumo mensal) — mais value objects imutáveis. A `CalculadoraDevido` (Plano 2) permanece o único cálculo puro. Sem UI, sem cron: esta fase entrega apenas a mecânica de dinheiro, coberta por testes de integração contra `sqlite::memory:`.

**Tech Stack:** PHP 8.1+, PDO (pdo_mysql em produção, pdo_sqlite nos testes), PHPUnit 10. Sem ORM.

**Spec:** `docs/superpowers/specs/2026-09-26-gestao-pelada-design.md` (§3.2 financeiro, §3.4 multas/pendências, §4 modelo de dados, §7 financeiro — cálculo e caixa).

## Global Constraints

- **PHP:** `>=8.1`. Namespaces: `RcInfoti\Pelada\Financeiro\`, `RcInfoti\Pelada\Infra\` → `src/` (PSR-4). Testes: `RcInfoti\Pelada\Tests\` → `tests/`.
- **Não alterar contratos dos Planos 1 e 2:** `ServicoRegrasRodada`, `EstadoRodada`, `Inscricao`, `RepositorioRodadaPdo`, `CalculadoraDevido`, enums `Tipo`/`StatusInscricao` e as tabelas existentes permanecem como estão. Este plano só **acrescenta** (nova tabela `movimentos_caixa`, novas classes em `src/Financeiro/`).
- **Determinismo:** nenhum `now()`/`time()` dentro de repositório — o instante entra sempre como `DateTimeImmutable $agora` (e `DateTimeImmutable $ocorridoEm` para saídas retroativas).
- **SQL portável entre MySQL e SQLite:** placeholders `?`; sem funções de data específicas de dialeto (calcular limites de mês em PHP e comparar `>= ? AND < ?`); `COALESCE(SUM(CASE WHEN ...))` para somas condicionais. Escrita que muta mais de uma tabela roda em transação.
- **Enums puros (sem backing), como no Plano 1:** a conversão para/de string do banco fica no repositório. Em `INSERT`, usar literais de string na SQL (`'entrada'`, `'saida'`, `'pagamento'`) exatamente como o Plano 2 já faz com `'confirmado'`/`'pendente'`.
- **Dinheiro:** `float` com 2 casas nesta fase; `DECIMAL(10,2)` no banco. (Migração para centavos inteiros continua sendo consideração futura — **não** fazer agora.)
- **Fonte única do caixa:** o saldo é sempre derivado de `movimentos_caixa`. Uma entrada só existe atrelada a um pagamento confirmado (`pagamento_id` UNIQUE); confirmar duas vezes **não** cria uma segunda entrada.
- **Mapeamento de strings do banco:** categoria de pagamento `futebol|festa`; escopo `semana|ano`; forma `pix|dinheiro`; tipo de movimento `entrada|saida`; booleanos como `0/1`.
- **Deploy:** schema de produção acrescido em `migrations/002_movimentos_caixa.sql` (MySQL), aplicado manualmente no cPanel nesta fase; runner automatizado fica para um plano de deploy futuro.

## Review Focus

- **Confirmar um pagamento já confirmado (idempotência):** a segunda chamada de `confirmar` é um no-op — não cria segunda entrada nem dobra o saldo. — coberto na Task 3 (`test_confirmar_e_idempotente`) e Task 5 (`test_saldo_nao_dobra_ao_confirmar_duas_vezes`).
- **Pagamento não confirmado não é dinheiro:** um pagamento apenas registrado (`confirmado = 0`) **não** entra no caixa nem no resumo mensal. — coberto na Task 3 (`test_registrar_nao_mexe_no_caixa`) e Task 6 (`test_resumo_ignora_pagamento_nao_confirmado`).
- **Quitação da festa do ano marca o jogador:** confirmar um pagamento `festa`/`ano` grava `festa_quitada_ano` com o ano de `$agora`; um pagamento `festa`/`semana` **não** marca. — coberto na Task 3 (`test_confirmar_festa_ano_marca_festa_quitada` e `test_confirmar_festa_semana_nao_marca`).
- **Saldo sem movimentos / só com saídas:** caixa sem linhas devolve `0.0` (não `null`/erro); só com saídas devolve negativo. — coberto na Task 5 (`test_saldo_vazio_e_zero`, `test_saldo_com_so_saidas_fica_negativo`).
- **Extrato com limites inclusivos e período vazio:** movimentos exatamente em `inicio` e em `fim` aparecem; período sem movimentos devolve `[]`. — coberto na Task 5 (`test_extrato_inclui_os_limites`, `test_extrato_periodo_vazio`).

---

### Task 1: Tabela `movimentos_caixa` (schema MySQL + espelho SQLite)

**Files:**
- Create: `migrations/002_movimentos_caixa.sql`
- Modify: `tests/Infra/SchemaSqlite.php` (acrescentar a tabela ao espelho)
- Modify: `tests/Infra/SchemaSqliteTest.php` (asserção da nova tabela/colunas)

**Interfaces:**
- Consumes: tabelas `peladas` e `pagamentos` (chaves estrangeiras).
- Produces: tabela `movimentos_caixa` com colunas `id, pelada_id, tipo, categoria, valor, descricao, pagamento_id, ocorrido_em, criado_em` e `UNIQUE(pagamento_id)`. Presente tanto no MySQL de produção quanto no espelho SQLite dos testes.

- [ ] **Step 1: Escrever a asserção que falha em `tests/Infra/SchemaSqliteTest.php`**

Acrescentar este método à classe existente:

```php
    public function test_movimentos_caixa_existe_com_colunas(): void
    {
        $pdo = new PDO('sqlite::memory:');
        SchemaSqlite::criar($pdo);

        $tabelas = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('movimentos_caixa', $tabelas, 'faltou a tabela movimentos_caixa');

        $colunas = $pdo->query('PRAGMA table_info(movimentos_caixa)')->fetchAll(PDO::FETCH_ASSOC);
        $nomes = array_column($colunas, 'name');
        foreach (['pelada_id', 'tipo', 'categoria', 'valor', 'descricao', 'pagamento_id', 'ocorrido_em', 'criado_em'] as $c) {
            $this->assertContains($c, $nomes, "faltou a coluna movimentos_caixa.{$c}");
        }
    }
```

- [ ] **Step 2: Rodar o teste e ver falhar**

Run: `./vendor/bin/phpunit --filter test_movimentos_caixa_existe_com_colunas`
Expected: FAIL — "faltou a tabela movimentos_caixa".

- [ ] **Step 3: Acrescentar a tabela ao espelho SQLite em `tests/Infra/SchemaSqlite.php`**

Adicionar, ao final do método `criar()` (depois do `CREATE TABLE pagamentos`):

```php
        $pdo->exec("CREATE TABLE movimentos_caixa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pelada_id INTEGER NOT NULL,
            tipo TEXT NOT NULL,
            categoria TEXT NOT NULL,
            valor REAL NOT NULL,
            descricao TEXT,
            pagamento_id INTEGER UNIQUE,
            ocorrido_em TEXT NOT NULL,
            criado_em TEXT NOT NULL
        )");
```

- [ ] **Step 4: Criar o schema de produção `migrations/002_movimentos_caixa.sql`**

```sql
-- Caixa: fonte única da verdade do saldo (MySQL 8 / cPanel).
-- Entradas nascem de pagamentos confirmados (pagamento_id UNIQUE => sem dupla contagem);
-- saídas (gastos) têm pagamento_id NULL.
CREATE TABLE movimentos_caixa (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pelada_id INT UNSIGNED NOT NULL,
    tipo ENUM('entrada','saida') NOT NULL,
    categoria VARCHAR(60) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    descricao VARCHAR(255) NULL,
    pagamento_id INT UNSIGNED NULL,
    ocorrido_em DATETIME NOT NULL,
    criado_em DATETIME NOT NULL,
    UNIQUE KEY uk_mov_pagamento (pagamento_id),
    KEY idx_mov_pelada (pelada_id),
    CONSTRAINT fk_mov_pelada FOREIGN KEY (pelada_id) REFERENCES peladas(id),
    CONSTRAINT fk_mov_pagamento FOREIGN KEY (pagamento_id) REFERENCES pagamentos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 5: Rodar o teste e ver passar**

Run: `./vendor/bin/phpunit --filter test_movimentos_caixa_existe_com_colunas`
Expected: PASS.

- [ ] **Step 6: Rodar a suíte inteira (nada quebrou)**

Run: `composer test`
Expected: PASS (todos os testes anteriores + o novo).

- [ ] **Step 7: Commit**

```bash
git add migrations/002_movimentos_caixa.sql tests/Infra/SchemaSqlite.php tests/Infra/SchemaSqliteTest.php
git commit -m "feat(financeiro): tabela movimentos_caixa (schema MySQL + espelho SQLite)"
```

---

### Task 2: Value objects de pagamento + enums + `registrar`

**Files:**
- Create: `src/Financeiro/CategoriaPagamento.php`
- Create: `src/Financeiro/EscopoPagamento.php`
- Create: `src/Financeiro/FormaPagamento.php`
- Create: `src/Financeiro/Pagamento.php`
- Create: `src/Financeiro/RepositorioPagamentoPdo.php`
- Test: `tests/Financeiro/RepositorioPagamentoPdoTest.php`

**Interfaces:**
- Consumes: tabela `pagamentos` (Plano 2), `SchemaSqlite` (testes).
- Produces:
  - `enum CategoriaPagamento { case Futebol; case Festa; }`
  - `enum EscopoPagamento { case Semana; case Ano; }`
  - `enum FormaPagamento { case Pix; case Dinheiro; }`
  - `final class Pagamento` (readonly VO): `__construct(int $peladaId, int $jogadorId, CategoriaPagamento $categoria, EscopoPagamento $escopo, float $valor, FormaPagamento $forma, ?int $rodadaId = null, ?string $comprovanteArquivo = null)`.
  - `final class RepositorioPagamentoPdo`: `__construct(PDO $pdo)`; `registrar(Pagamento $pagamento, DateTimeImmutable $agora): int` — insere sempre com `confirmado = 0` e devolve o `id` gerado. **Registrar nunca toca no caixa** (isso é papel de `confirmar`, Task 3).

- [ ] **Step 1: Escrever o teste que falha `tests/Financeiro/RepositorioPagamentoPdoTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Financeiro;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Financeiro\CategoriaPagamento;
use RcInfoti\Pelada\Financeiro\EscopoPagamento;
use RcInfoti\Pelada\Financeiro\FormaPagamento;
use RcInfoti\Pelada\Financeiro\Pagamento;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioPagamentoPdoTest extends TestCase
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

    private function repo(): RepositorioPagamentoPdo
    {
        return new RepositorioPagamentoPdo($this->pdo);
    }

    public function test_registrar_insere_pagamento_nao_confirmado_e_devolve_id(): void
    {
        $id = $this->repo()->registrar(
            new Pagamento(
                peladaId: 1,
                jogadorId: 10,
                categoria: CategoriaPagamento::Futebol,
                escopo: EscopoPagamento::Semana,
                valor: 15.00,
                forma: FormaPagamento::Pix,
                rodadaId: 5,
                comprovanteArquivo: 'comp/abc.jpg',
            ),
            new DateTimeImmutable('2026-01-05 10:00:00'),
        );

        $this->assertGreaterThan(0, $id);

        $row = $this->pdo->query('SELECT * FROM pagamentos WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('futebol', $row['categoria']);
        $this->assertSame('semana', $row['escopo']);
        $this->assertSame('pix', $row['forma']);
        $this->assertSame('comp/abc.jpg', $row['comprovante_arquivo']);
        $this->assertSame(15.0, (float) $row['valor']);
        $this->assertSame(0, (int) $row['confirmado']); // registrar NÃO confirma
        $this->assertSame('2026-01-05 10:00:00', $row['criado_em']);
    }

    public function test_registrar_aceita_dinheiro_festa_ano_sem_rodada_nem_comprovante(): void
    {
        $id = $this->repo()->registrar(
            new Pagamento(
                peladaId: 1,
                jogadorId: 10,
                categoria: CategoriaPagamento::Festa,
                escopo: EscopoPagamento::Ano,
                valor: 220.00,
                forma: FormaPagamento::Dinheiro,
            ),
            new DateTimeImmutable('2026-01-05 10:00:00'),
        );

        $row = $this->pdo->query('SELECT * FROM pagamentos WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('festa', $row['categoria']);
        $this->assertSame('ano', $row['escopo']);
        $this->assertSame('dinheiro', $row['forma']);
        $this->assertNull($row['rodada_id']);
        $this->assertNull($row['comprovante_arquivo']);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Financeiro/RepositorioPagamentoPdoTest.php`
Expected: FAIL — classes `CategoriaPagamento`/`Pagamento`/`RepositorioPagamentoPdo` não existem.

- [ ] **Step 3: Criar os enums**

`src/Financeiro/CategoriaPagamento.php`:

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

enum CategoriaPagamento
{
    case Futebol;
    case Festa;
}
```

`src/Financeiro/EscopoPagamento.php`:

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

enum EscopoPagamento
{
    case Semana;
    case Ano;
}
```

`src/Financeiro/FormaPagamento.php`:

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

enum FormaPagamento
{
    case Pix;
    case Dinheiro;
}
```

- [ ] **Step 4: Criar o VO `src/Financeiro/Pagamento.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

final class Pagamento
{
    public function __construct(
        public readonly int $peladaId,
        public readonly int $jogadorId,
        public readonly CategoriaPagamento $categoria,
        public readonly EscopoPagamento $escopo,
        public readonly float $valor,
        public readonly FormaPagamento $forma,
        public readonly ?int $rodadaId = null,
        public readonly ?string $comprovanteArquivo = null,
    ) {
    }
}
```

- [ ] **Step 5: Criar `src/Financeiro/RepositorioPagamentoPdo.php` com `registrar`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use DateTimeImmutable;
use PDO;

final class RepositorioPagamentoPdo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrar(Pagamento $pagamento, DateTimeImmutable $agora): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pagamentos
                (pelada_id, jogador_id, rodada_id, categoria, escopo, valor, forma, comprovante_arquivo, confirmado, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([
            $pagamento->peladaId,
            $pagamento->jogadorId,
            $pagamento->rodadaId,
            $this->categoriaSql($pagamento->categoria),
            $this->escopoSql($pagamento->escopo),
            $pagamento->valor,
            $this->formaSql($pagamento->forma),
            $pagamento->comprovanteArquivo,
            $agora->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function categoriaSql(CategoriaPagamento $c): string
    {
        return match ($c) {
            CategoriaPagamento::Futebol => 'futebol',
            CategoriaPagamento::Festa => 'festa',
        };
    }

    private function escopoSql(EscopoPagamento $e): string
    {
        return match ($e) {
            EscopoPagamento::Semana => 'semana',
            EscopoPagamento::Ano => 'ano',
        };
    }

    private function formaSql(FormaPagamento $f): string
    {
        return match ($f) {
            FormaPagamento::Pix => 'pix',
            FormaPagamento::Dinheiro => 'dinheiro',
        };
    }
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Financeiro/RepositorioPagamentoPdoTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Financeiro/CategoriaPagamento.php src/Financeiro/EscopoPagamento.php src/Financeiro/FormaPagamento.php src/Financeiro/Pagamento.php src/Financeiro/RepositorioPagamentoPdo.php tests/Financeiro/RepositorioPagamentoPdoTest.php
git commit -m "feat(financeiro): registrar pagamento (VO + enums + RepositorioPagamentoPdo)"
```

---

### Task 3: Confirmar pagamento → entrada no caixa (idempotente) + festa quitada

**Files:**
- Modify: `src/Financeiro/RepositorioPagamentoPdo.php` (acrescentar `confirmar`)
- Modify: `tests/Financeiro/RepositorioPagamentoPdoTest.php` (novos testes)

**Interfaces:**
- Consumes: `registrar` (Task 2); tabelas `pagamentos`, `movimentos_caixa`, `jogadores`.
- Produces: `RepositorioPagamentoPdo::confirmar(int $pagamentoId, DateTimeImmutable $agora): void` — em transação: reivindica o pagamento com `UPDATE ... WHERE id = ? AND confirmado = 0` (idempotente); se a reivindicação venceu, insere **uma** entrada em `movimentos_caixa` (`tipo='entrada'`, `categoria='pagamento'`, `pagamento_id` = id, `valor` = valor do pagamento) e, se o pagamento for `festa`/`ano`, grava `jogadores.festa_quitada_ano` com o ano de `$agora`. Se a reivindicação não afetou linhas (já confirmado ou inexistente), é no-op.

- [ ] **Step 1: Escrever os testes que falham (acrescentar à classe existente)**

```php
    public function test_confirmar_gera_entrada_no_caixa_vinculada(): void
    {
        $repo = $this->repo();
        $id = $repo->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-01-05 10:00:00'),
        );

        $repo->confirmar($id, new DateTimeImmutable('2026-01-06 09:00:00'));

        $pg = $this->pdo->query('SELECT confirmado FROM pagamentos WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $pg['confirmado']);

        $mov = $this->pdo->query('SELECT * FROM movimentos_caixa WHERE pagamento_id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('entrada', $mov['tipo']);
        $this->assertSame('pagamento', $mov['categoria']);
        $this->assertSame(15.0, (float) $mov['valor']);
        $this->assertSame(1, (int) $mov['pelada_id']);
        $this->assertSame('2026-01-06 09:00:00', $mov['ocorrido_em']);
    }

    public function test_registrar_nao_mexe_no_caixa(): void
    {
        $this->repo()->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-01-05 10:00:00'),
        );

        $qtd = (int) $this->pdo->query('SELECT COUNT(*) FROM movimentos_caixa')->fetchColumn();
        $this->assertSame(0, $qtd);
    }

    public function test_confirmar_e_idempotente(): void
    {
        $repo = $this->repo();
        $id = $repo->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-01-05 10:00:00'),
        );

        $repo->confirmar($id, new DateTimeImmutable('2026-01-06 09:00:00'));
        $repo->confirmar($id, new DateTimeImmutable('2026-01-06 09:05:00')); // segunda vez: no-op

        $qtd = (int) $this->pdo->query('SELECT COUNT(*) FROM movimentos_caixa WHERE pagamento_id = ' . $id)->fetchColumn();
        $this->assertSame(1, $qtd); // apenas uma entrada
    }

    public function test_confirmar_pagamento_inexistente_e_noop(): void
    {
        $this->repo()->confirmar(999, new DateTimeImmutable('2026-01-06 09:00:00'));

        $qtd = (int) $this->pdo->query('SELECT COUNT(*) FROM movimentos_caixa')->fetchColumn();
        $this->assertSame(0, $qtd);
    }

    public function test_confirmar_festa_ano_marca_festa_quitada(): void
    {
        $repo = $this->repo();
        $id = $repo->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Festa, EscopoPagamento::Ano, 220.00, FormaPagamento::Dinheiro),
            new DateTimeImmutable('2026-02-01 10:00:00'),
        );

        $repo->confirmar($id, new DateTimeImmutable('2026-02-02 09:00:00'));

        $ano = $this->pdo->query('SELECT festa_quitada_ano FROM jogadores WHERE id = 10')->fetchColumn();
        $this->assertSame(2026, (int) $ano);
    }

    public function test_confirmar_festa_semana_nao_marca(): void
    {
        $repo = $this->repo();
        $id = $repo->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Festa, EscopoPagamento::Semana, 5.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-02-01 10:00:00'),
        );

        $repo->confirmar($id, new DateTimeImmutable('2026-02-02 09:00:00'));

        $ano = $this->pdo->query('SELECT festa_quitada_ano FROM jogadores WHERE id = 10')->fetchColumn();
        $this->assertNull($ano);
    }
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Financeiro/RepositorioPagamentoPdoTest.php`
Expected: FAIL — método `confirmar` não existe.

- [ ] **Step 3: Implementar `confirmar` em `RepositorioPagamentoPdo`**

Acrescentar o método (logo após `registrar`):

```php
    public function confirmar(int $pagamentoId, DateTimeImmutable $agora): void
    {
        $this->pdo->beginTransaction();
        try {
            // Reivindica o pagamento de forma atômica: o UPDATE guardado trava a
            // linha e só "vence" se ainda não fora confirmado. Sem linhas afetadas
            // (já confirmado ou inexistente) => no-op idempotente.
            $marca = $this->pdo->prepare(
                'UPDATE pagamentos SET confirmado = 1 WHERE id = ? AND confirmado = 0'
            );
            $marca->execute([$pagamentoId]);
            if ($marca->rowCount() === 0) {
                $this->pdo->commit();

                return;
            }

            $busca = $this->pdo->prepare(
                'SELECT pelada_id, jogador_id, categoria, escopo, valor FROM pagamentos WHERE id = ?'
            );
            $busca->execute([$pagamentoId]);
            $p = $busca->fetch(PDO::FETCH_ASSOC);

            // Entrada de caixa vinculada (UNIQUE(pagamento_id) impede dupla contagem).
            $this->pdo->prepare(
                "INSERT INTO movimentos_caixa
                    (pelada_id, tipo, categoria, valor, descricao, pagamento_id, ocorrido_em, criado_em)
                 VALUES (?, 'entrada', 'pagamento', ?, NULL, ?, ?, ?)"
            )->execute([
                (int) $p['pelada_id'],
                (float) $p['valor'],
                $pagamentoId,
                $agora->format('Y-m-d H:i:s'),
                $agora->format('Y-m-d H:i:s'),
            ]);

            // Quitar a festa do ano marca o jogador (usa o ano de $agora).
            if ($p['categoria'] === 'festa' && $p['escopo'] === 'ano') {
                $this->pdo->prepare('UPDATE jogadores SET festa_quitada_ano = ? WHERE id = ?')
                    ->execute([(int) $agora->format('Y'), (int) $p['jogador_id']]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Financeiro/RepositorioPagamentoPdoTest.php`
Expected: PASS (todos os testes da classe).

- [ ] **Step 5: Commit**

```bash
git add src/Financeiro/RepositorioPagamentoPdo.php tests/Financeiro/RepositorioPagamentoPdoTest.php
git commit -m "feat(financeiro): confirmar pagamento gera entrada de caixa idempotente + festa quitada"
```

---

### Task 4: Lançar saída de caixa (gasto)

**Files:**
- Create: `src/Financeiro/RepositorioCaixaPdo.php`
- Test: `tests/Financeiro/RepositorioCaixaPdoTest.php`

**Interfaces:**
- Consumes: tabela `movimentos_caixa`; `SchemaSqlite` (testes).
- Produces: `final class RepositorioCaixaPdo`: `__construct(PDO $pdo)`; `lancarSaida(int $peladaId, float $valor, string $categoria, string $descricao, DateTimeImmutable $ocorridoEm, DateTimeImmutable $agora): int` — insere um movimento `tipo='saida'` com `pagamento_id` NULL e devolve o `id`. (`saldo` e `extrato` chegam na Task 5.)

- [ ] **Step 1: Escrever o teste que falha `tests/Financeiro/RepositorioCaixaPdoTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Financeiro;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Financeiro\RepositorioCaixaPdo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioCaixaPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
    }

    private function repo(): RepositorioCaixaPdo
    {
        return new RepositorioCaixaPdo($this->pdo);
    }

    public function test_lancar_saida_insere_movimento_de_saida(): void
    {
        $id = $this->repo()->lancarSaida(
            peladaId: 1,
            valor: 80.00,
            categoria: 'campo',
            descricao: 'Aluguel do campo',
            ocorridoEm: new DateTimeImmutable('2026-01-08 21:00:00'),
            agora: new DateTimeImmutable('2026-01-09 08:00:00'),
        );

        $this->assertGreaterThan(0, $id);

        $row = $this->pdo->query('SELECT * FROM movimentos_caixa WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('saida', $row['tipo']);
        $this->assertSame('campo', $row['categoria']);
        $this->assertSame(80.0, (float) $row['valor']);
        $this->assertSame('Aluguel do campo', $row['descricao']);
        $this->assertNull($row['pagamento_id']);
        $this->assertSame('2026-01-08 21:00:00', $row['ocorrido_em']);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Financeiro/RepositorioCaixaPdoTest.php`
Expected: FAIL — classe `RepositorioCaixaPdo` não existe.

- [ ] **Step 3: Criar `src/Financeiro/RepositorioCaixaPdo.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use DateTimeImmutable;
use PDO;

final class RepositorioCaixaPdo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function lancarSaida(
        int $peladaId,
        float $valor,
        string $categoria,
        string $descricao,
        DateTimeImmutable $ocorridoEm,
        DateTimeImmutable $agora,
    ): int {
        $this->pdo->prepare(
            "INSERT INTO movimentos_caixa
                (pelada_id, tipo, categoria, valor, descricao, pagamento_id, ocorrido_em, criado_em)
             VALUES (?, 'saida', ?, ?, ?, NULL, ?, ?)"
        )->execute([
            $peladaId,
            $categoria,
            $valor,
            $descricao,
            $ocorridoEm->format('Y-m-d H:i:s'),
            $agora->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Financeiro/RepositorioCaixaPdoTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Financeiro/RepositorioCaixaPdo.php tests/Financeiro/RepositorioCaixaPdoTest.php
git commit -m "feat(financeiro): lancar saida de caixa (gasto)"
```

---

### Task 5: Saldo + extrato do caixa (com `MovimentoCaixa` e `TipoMovimento`)

**Files:**
- Create: `src/Financeiro/TipoMovimento.php`
- Create: `src/Financeiro/MovimentoCaixa.php`
- Modify: `src/Financeiro/RepositorioCaixaPdo.php` (acrescentar `saldo` e `extrato`)
- Modify: `tests/Financeiro/RepositorioCaixaPdoTest.php` (novos testes)

**Interfaces:**
- Consumes: `lancarSaida` (Task 4); `RepositorioPagamentoPdo::registrar`/`confirmar` (Tasks 2–3) para semear entradas; tabela `movimentos_caixa`.
- Produces:
  - `enum TipoMovimento { case Entrada; case Saida; }`
  - `final class MovimentoCaixa` (readonly VO): `__construct(int $id, TipoMovimento $tipo, string $categoria, float $valor, ?string $descricao, ?int $pagamentoId, DateTimeImmutable $ocorridoEm)`.
  - `RepositorioCaixaPdo::saldo(int $peladaId): float` — Σ entradas − Σ saídas (0.0 quando não há linhas).
  - `RepositorioCaixaPdo::extrato(int $peladaId, DateTimeImmutable $inicio, DateTimeImmutable $fim): array` — `list<MovimentoCaixa>`, limites inclusivos, ordenado por `ocorrido_em, id`.

- [ ] **Step 1: Escrever os testes que falham (acrescentar à classe `RepositorioCaixaPdoTest`)**

Adicionar os `use` no topo do arquivo de teste:

```php
use RcInfoti\Pelada\Financeiro\CategoriaPagamento;
use RcInfoti\Pelada\Financeiro\EscopoPagamento;
use RcInfoti\Pelada\Financeiro\FormaPagamento;
use RcInfoti\Pelada\Financeiro\MovimentoCaixa;
use RcInfoti\Pelada\Financeiro\Pagamento;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Financeiro\TipoMovimento;
```

E os métodos de teste:

```php
    private function confirmarPagamento(float $valor, DateTimeImmutable $quando): void
    {
        $this->pdo->exec("INSERT INTO jogadores (pelada_id, nome, tipo) VALUES (1, 'Ana', 'linha')");
        $jogadorId = (int) $this->pdo->lastInsertId();

        $pag = new RepositorioPagamentoPdo($this->pdo);
        $id = $pag->registrar(
            new Pagamento(1, $jogadorId, CategoriaPagamento::Futebol, EscopoPagamento::Semana, $valor, FormaPagamento::Pix),
            $quando,
        );
        $pag->confirmar($id, $quando);
    }

    public function test_saldo_vazio_e_zero(): void
    {
        $this->assertSame(0.0, $this->repo()->saldo(1));
    }

    public function test_saldo_com_so_saidas_fica_negativo(): void
    {
        $this->repo()->lancarSaida(1, 80.00, 'campo', 'Campo', new DateTimeImmutable('2026-01-08 21:00:00'), new DateTimeImmutable('2026-01-09 08:00:00'));

        $this->assertSame(-80.0, $this->repo()->saldo(1));
    }

    public function test_saldo_entradas_menos_saidas(): void
    {
        $this->confirmarPagamento(15.00, new DateTimeImmutable('2026-01-06 09:00:00'));
        $this->confirmarPagamento(15.00, new DateTimeImmutable('2026-01-06 09:10:00'));
        $this->repo()->lancarSaida(1, 20.00, 'bola', 'Bola nova', new DateTimeImmutable('2026-01-07 10:00:00'), new DateTimeImmutable('2026-01-07 10:00:00'));

        $this->assertSame(10.0, $this->repo()->saldo(1)); // 30 - 20
    }

    public function test_saldo_nao_dobra_ao_confirmar_duas_vezes(): void
    {
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (10, 1, 'Ana', 'linha')");
        $pag = new RepositorioPagamentoPdo($this->pdo);
        $id = $pag->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-01-06 09:00:00'),
        );
        $pag->confirmar($id, new DateTimeImmutable('2026-01-06 09:00:00'));
        $pag->confirmar($id, new DateTimeImmutable('2026-01-06 09:30:00'));

        $this->assertSame(15.0, $this->repo()->saldo(1)); // não 30
    }

    public function test_extrato_inclui_os_limites(): void
    {
        $repo = $this->repo();
        $repo->lancarSaida(1, 10.00, 'x', 'início', new DateTimeImmutable('2026-01-01 00:00:00'), new DateTimeImmutable('2026-01-01 00:00:00'));
        $repo->lancarSaida(1, 20.00, 'y', 'meio', new DateTimeImmutable('2026-01-15 12:00:00'), new DateTimeImmutable('2026-01-15 12:00:00'));
        $repo->lancarSaida(1, 30.00, 'z', 'fim', new DateTimeImmutable('2026-01-31 23:59:59'), new DateTimeImmutable('2026-01-31 23:59:59'));
        $repo->lancarSaida(1, 99.00, 'w', 'fora', new DateTimeImmutable('2026-02-01 00:00:00'), new DateTimeImmutable('2026-02-01 00:00:00'));

        $extrato = $repo->extrato(1, new DateTimeImmutable('2026-01-01 00:00:00'), new DateTimeImmutable('2026-01-31 23:59:59'));

        $this->assertCount(3, $extrato);
        $this->assertContainsOnlyInstancesOf(MovimentoCaixa::class, $extrato);
        $this->assertSame('início', $extrato[0]->descricao); // ordenado por ocorrido_em
        $this->assertSame(TipoMovimento::Saida, $extrato[0]->tipo);
        $this->assertSame('fim', $extrato[2]->descricao);
    }

    public function test_extrato_periodo_vazio(): void
    {
        $this->repo()->lancarSaida(1, 10.00, 'x', 'jan', new DateTimeImmutable('2026-01-10 00:00:00'), new DateTimeImmutable('2026-01-10 00:00:00'));

        $extrato = $this->repo()->extrato(1, new DateTimeImmutable('2026-03-01 00:00:00'), new DateTimeImmutable('2026-03-31 23:59:59'));

        $this->assertSame([], $extrato);
    }

    public function test_extrato_traz_entrada_de_pagamento_com_vinculo(): void
    {
        $this->confirmarPagamento(15.00, new DateTimeImmutable('2026-01-06 09:00:00'));

        $extrato = $this->repo()->extrato(1, new DateTimeImmutable('2026-01-01 00:00:00'), new DateTimeImmutable('2026-01-31 23:59:59'));

        $this->assertCount(1, $extrato);
        $this->assertSame(TipoMovimento::Entrada, $extrato[0]->tipo);
        $this->assertSame('pagamento', $extrato[0]->categoria);
        $this->assertNotNull($extrato[0]->pagamentoId);
    }
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Financeiro/RepositorioCaixaPdoTest.php`
Expected: FAIL — `TipoMovimento`/`MovimentoCaixa` e os métodos `saldo`/`extrato` não existem.

- [ ] **Step 3: Criar o enum `src/Financeiro/TipoMovimento.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

enum TipoMovimento
{
    case Entrada;
    case Saida;
}
```

- [ ] **Step 4: Criar o VO `src/Financeiro/MovimentoCaixa.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use DateTimeImmutable;

final class MovimentoCaixa
{
    public function __construct(
        public readonly int $id,
        public readonly TipoMovimento $tipo,
        public readonly string $categoria,
        public readonly float $valor,
        public readonly ?string $descricao,
        public readonly ?int $pagamentoId,
        public readonly DateTimeImmutable $ocorridoEm,
    ) {
    }
}
```

- [ ] **Step 5: Acrescentar `saldo` e `extrato` a `RepositorioCaixaPdo`**

```php
    public function saldo(int $peladaId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE 0 END), 0)
              - COALESCE(SUM(CASE WHEN tipo = 'saida'   THEN valor ELSE 0 END), 0) AS saldo
             FROM movimentos_caixa
             WHERE pelada_id = ?"
        );
        $stmt->execute([$peladaId]);

        return (float) $stmt->fetchColumn();
    }

    /** @return list<MovimentoCaixa> */
    public function extrato(int $peladaId, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tipo, categoria, valor, descricao, pagamento_id, ocorrido_em
             FROM movimentos_caixa
             WHERE pelada_id = ? AND ocorrido_em >= ? AND ocorrido_em <= ?
             ORDER BY ocorrido_em, id'
        );
        $stmt->execute([
            $peladaId,
            $inicio->format('Y-m-d H:i:s'),
            $fim->format('Y-m-d H:i:s'),
        ]);

        $movimentos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $movimentos[] = new MovimentoCaixa(
                (int) $r['id'],
                $r['tipo'] === 'entrada' ? TipoMovimento::Entrada : TipoMovimento::Saida,
                (string) $r['categoria'],
                (float) $r['valor'],
                $r['descricao'] !== null ? (string) $r['descricao'] : null,
                $r['pagamento_id'] !== null ? (int) $r['pagamento_id'] : null,
                new DateTimeImmutable($r['ocorrido_em']),
            );
        }

        return $movimentos;
    }
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Financeiro/RepositorioCaixaPdoTest.php`
Expected: PASS (todos os testes da classe).

- [ ] **Step 7: Commit**

```bash
git add src/Financeiro/TipoMovimento.php src/Financeiro/MovimentoCaixa.php src/Financeiro/RepositorioCaixaPdo.php tests/Financeiro/RepositorioCaixaPdoTest.php
git commit -m "feat(financeiro): saldo e extrato do caixa (MovimentoCaixa + TipoMovimento)"
```

---

### Task 6: Resumo financeiro mensal por jogador

**Files:**
- Create: `src/Financeiro/ResumoMensalJogador.php`
- Create: `src/Financeiro/RelatorioFinanceiroPdo.php`
- Test: `tests/Financeiro/RelatorioFinanceiroPdoTest.php`

**Interfaces:**
- Consumes: `RepositorioPagamentoPdo::registrar`/`confirmar` (Tasks 2–3); tabelas `jogadores`, `pagamentos`.
- Produces:
  - `final class ResumoMensalJogador` (readonly VO): `__construct(int $jogadorId, string $nome, float $pagoFutebol, float $pagoFesta, float $pendente)`.
  - `final class RelatorioFinanceiroPdo`: `__construct(PDO $pdo)`; `resumoMensalJogador(int $peladaId, int $ano, int $mes): array` — `list<ResumoMensalJogador>`, um por jogador **ativo** da pelada (ordenado por nome), somando apenas pagamentos **confirmados** cujo `criado_em` cai no mês; `pendente` vem de `jogadores.saldo_pendente`. Jogador sem pagamento no mês aparece com zeros.

- [ ] **Step 1: Escrever o teste que falha `tests/Financeiro/RelatorioFinanceiroPdoTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Financeiro;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Financeiro\CategoriaPagamento;
use RcInfoti\Pelada\Financeiro\EscopoPagamento;
use RcInfoti\Pelada\Financeiro\FormaPagamento;
use RcInfoti\Pelada\Financeiro\Pagamento;
use RcInfoti\Pelada\Financeiro\RelatorioFinanceiroPdo;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Financeiro\ResumoMensalJogador;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RelatorioFinanceiroPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo, saldo_pendente) VALUES
            (10, 1, 'Ana', 'linha', 0),
            (11, 1, 'Bia', 'linha', 20.00)");
    }

    private function pagarConfirmado(int $jogadorId, CategoriaPagamento $cat, EscopoPagamento $esc, float $valor, string $quando): void
    {
        $repo = new RepositorioPagamentoPdo($this->pdo);
        $agora = new DateTimeImmutable($quando);
        $id = $repo->registrar(new Pagamento(1, $jogadorId, $cat, $esc, $valor, FormaPagamento::Pix), $agora);
        $repo->confirmar($id, $agora);
    }

    /** @return array<int,ResumoMensalJogador> indexado por jogadorId */
    private function porJogador(array $lista): array
    {
        $out = [];
        foreach ($lista as $r) {
            $out[$r->jogadorId] = $r;
        }

        return $out;
    }

    public function test_soma_futebol_e_festa_confirmados_do_mes(): void
    {
        $this->pagarConfirmado(10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, '2026-01-06 09:00:00');
        $this->pagarConfirmado(10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, '2026-01-13 09:00:00');
        $this->pagarConfirmado(10, CategoriaPagamento::Festa, EscopoPagamento::Semana, 5.00, '2026-01-13 09:00:00');

        $resumo = $this->porJogador((new RelatorioFinanceiroPdo($this->pdo))->resumoMensalJogador(1, 2026, 1));

        $this->assertSame(30.0, $resumo[10]->pagoFutebol);
        $this->assertSame(5.0, $resumo[10]->pagoFesta);
        $this->assertSame('Ana', $resumo[10]->nome);
    }

    public function test_jogador_sem_pagamento_aparece_com_zeros_e_pendencia(): void
    {
        $resumo = $this->porJogador((new RelatorioFinanceiroPdo($this->pdo))->resumoMensalJogador(1, 2026, 1));

        $this->assertCount(2, $resumo);
        $this->assertSame(0.0, $resumo[11]->pagoFutebol);
        $this->assertSame(0.0, $resumo[11]->pagoFesta);
        $this->assertSame(20.0, $resumo[11]->pendente); // saldo_pendente do cadastro
    }

    public function test_resumo_ignora_pagamento_nao_confirmado(): void
    {
        // registrar SEM confirmar => não conta
        (new RepositorioPagamentoPdo($this->pdo))->registrar(
            new Pagamento(1, 10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, FormaPagamento::Pix),
            new DateTimeImmutable('2026-01-06 09:00:00'),
        );

        $resumo = $this->porJogador((new RelatorioFinanceiroPdo($this->pdo))->resumoMensalJogador(1, 2026, 1));

        $this->assertSame(0.0, $resumo[10]->pagoFutebol);
    }

    public function test_resumo_ignora_pagamento_de_outro_mes(): void
    {
        $this->pagarConfirmado(10, CategoriaPagamento::Futebol, EscopoPagamento::Semana, 15.00, '2026-02-03 09:00:00');

        $resumo = $this->porJogador((new RelatorioFinanceiroPdo($this->pdo))->resumoMensalJogador(1, 2026, 1));

        $this->assertSame(0.0, $resumo[10]->pagoFutebol); // pago em fevereiro, não em janeiro
    }
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Financeiro/RelatorioFinanceiroPdoTest.php`
Expected: FAIL — `RelatorioFinanceiroPdo`/`ResumoMensalJogador` não existem.

- [ ] **Step 3: Criar o VO `src/Financeiro/ResumoMensalJogador.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

final class ResumoMensalJogador
{
    public function __construct(
        public readonly int $jogadorId,
        public readonly string $nome,
        public readonly float $pagoFutebol,
        public readonly float $pagoFesta,
        public readonly float $pendente,
    ) {
    }
}
```

- [ ] **Step 4: Criar `src/Financeiro/RelatorioFinanceiroPdo.php`**

O limite do mês é calculado em PHP (sem funções de data do dialeto): filtra `criado_em >= início do mês` e `< início do mês seguinte`.

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use DateTimeImmutable;
use PDO;

final class RelatorioFinanceiroPdo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<ResumoMensalJogador> */
    public function resumoMensalJogador(int $peladaId, int $ano, int $mes): array
    {
        $inicioMes = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
        $proximoMes = $inicioMes->modify('first day of next month');

        $stmt = $this->pdo->prepare(
            "SELECT j.id, j.nome, j.saldo_pendente,
                    COALESCE(SUM(CASE WHEN pg.categoria = 'futebol' THEN pg.valor ELSE 0 END), 0) AS pago_futebol,
                    COALESCE(SUM(CASE WHEN pg.categoria = 'festa'   THEN pg.valor ELSE 0 END), 0) AS pago_festa
             FROM jogadores j
             LEFT JOIN pagamentos pg
                    ON pg.jogador_id = j.id
                   AND pg.confirmado = 1
                   AND pg.criado_em >= ?
                   AND pg.criado_em <  ?
             WHERE j.pelada_id = ? AND j.ativo = 1
             GROUP BY j.id, j.nome, j.saldo_pendente
             ORDER BY j.nome"
        );
        $stmt->execute([
            $inicioMes->format('Y-m-d H:i:s'),
            $proximoMes->format('Y-m-d H:i:s'),
            $peladaId,
        ]);

        $resumo = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $resumo[] = new ResumoMensalJogador(
                (int) $r['id'],
                (string) $r['nome'],
                (float) $r['pago_futebol'],
                (float) $r['pago_festa'],
                (float) $r['saldo_pendente'],
            );
        }

        return $resumo;
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Financeiro/RelatorioFinanceiroPdoTest.php`
Expected: PASS.

- [ ] **Step 6: Rodar a suíte inteira**

Run: `composer test`
Expected: PASS (todos os testes dos Planos 1, 2 e 3).

- [ ] **Step 7: Commit**

```bash
git add src/Financeiro/ResumoMensalJogador.php src/Financeiro/RelatorioFinanceiroPdo.php tests/Financeiro/RelatorioFinanceiroPdoTest.php
git commit -m "feat(financeiro): resumo financeiro mensal por jogador"
```

---

## Notas de encerramento

- Ao final, `composer test` deve passar com os testes novos somados aos 34 existentes.
- O que fica para planos seguintes (fora do escopo desta fase): quitação de multas via pagamento (`multas.status = 'paga'` + zerar a parte correspondente de `saldo_pendente`); relatório de rodada (confirmados/espera/pagos × não pagos) — depende de estado de inscrições, casa melhor com o plano de UI/admin; relatório de caixa por período apresentado (o `extrato` já entrega os dados); wiring web (front controller, telas), login por PIN, upload físico do arquivo de comprovante e cron de ciclo de vida da rodada.
