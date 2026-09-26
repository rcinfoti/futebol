# Persistência e Processamento da Rodada — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ligar o núcleo puro do Plano 1 a um banco: carregar o `EstadoRodada` do banco, rodar o `ServicoRegrasRodada` e persistir as ações (promover na lista, aplicar multa com o valor devido), tudo testado sem servidor via SQLite em memória e fakes.

**Architecture:** O domínio ganha uma interface `RepositorioRodada` (porta) e um orquestrador `ProcessadorRodada` que não conhece SQL — ele é testado com um fake em memória. Uma implementação `RepositorioRodadaPdo` fala com o banco via um wrapper `Database` (PDO). O valor da multa é o devido semanal, calculado por `CalculadoraDevido` (domínio puro). O schema de produção é MySQL (`migrations/001_schema.sql`, aplicado manualmente no cPanel por ora); os testes de integração criam um schema SQLite equivalente em memória.

**Tech Stack:** PHP 8.1+, PDO (pdo_mysql em produção, pdo_sqlite nos testes), PHPUnit 10. Sem ORM.

**Spec:** `docs/superpowers/specs/2026-09-26-gestao-pelada-design.md` (§4 modelo de dados, §5 arquitetura/isolamento, §7 financeiro — devido semanal).

## Global Constraints

- **PHP:** `>=8.1`. Namespaces: `RcInfoti\Pelada\Rodada\`, `RcInfoti\Pelada\Financeiro\`, `RcInfoti\Pelada\Infra\` → `src/`.
- **Não alterar o contrato do Plano 1:** `ServicoRegrasRodada::decidir`, `EstadoRodada`, `Inscricao`, enums `Tipo`/`StatusInscricao` e as `Acao` permanecem como estão. Enums são puros (sem backing); a conversão para/de string do banco fica no repositório.
- **Determinismo:** nenhum `now()`/`time()` dentro de repositório ou orquestrador — o instante entra sempre como `DateTimeImmutable $agora`.
- **SQL portável entre MySQL e SQLite:** placeholders `?`, sem funções de data específicas de dialeto nas queries (calcular ano em PHP), `EXISTS(...)` para o flag `pagou`. Escrita que muta várias tabelas roda em transação.
- **Dinheiro:** `float` com 2 casas nesta fase (valores da pelada). DECIMAL(10,2) no banco. (Migração para centavos inteiros é uma consideração futura do Plano 3; não fazer agora.)
- **Mapeamento de strings do banco:** tipo `linha|goleiro`; status `confirmado|espera|desistiu`; booleanos como `0/1`.
- **Deploy:** schema de produção em `migrations/001_schema.sql` (MySQL), aplicado manualmente no cPanel nesta fase; runner automatizado fica para um plano de deploy futuro.

## Review Focus

- **`carregarEstado` de rodada sem inscrições:** deve devolver `EstadoRodada` com `inscricoes` vazio, sem erro. — Task 5 (`test_carrega_estado_sem_inscricoes`).
- **`pagou` derivado de pagamento não confirmado:** um pagamento com `confirmado = 0` NÃO marca `pagou = true`. — Task 5 (`test_pagou_so_com_pagamento_confirmado`).
- **`festa_quitada_ano` de ano diferente do jogo:** só zera a parte da festa quando o ano quitado é o ano da rodada. — Task 3 e Task 5 (`test_multa_ignora_festa_quitada_de_outro_ano`).
- **Idempotência da aplicação:** rodar `processar` duas vezes não promove de novo quem já é confirmado nem gera multa duplicada (a segunda passada vê `multa_aplicada = 1` e o confirmado já fora da espera). — Task 6 (`test_processar_e_idempotente`).
- **Atomicidade da multa:** `aplicarMulta` grava a linha em `multas`, incrementa `saldo_pendente` e marca `multa_aplicada` — os três juntos, em transação. — Task 5 (`test_aplicar_multa_persiste_tudo`).

---

### Task 1: Wrapper de banco (Database) + config

**Files:**
- Create: `src/Infra/Database.php`
- Create: `config/config.example.php`
- Test: `tests/Infra/DatabaseTest.php`

**Interfaces:**
- Consumes: nada do domínio.
- Produces: `final class Database` com:
  - `__construct(PDO $pdo)`
  - `public function pdo(): PDO`
  - `public static function fromConfig(array $config): self` — monta um PDO MySQL a partir de `['host','porta','banco','usuario','senha']` com `PDO::ATTR_ERRMODE => EXCEPTION` e `PDO::ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC`.
  - Os testes injetam um PDO SQLite (`new PDO('sqlite::memory:')`) direto no construtor.

- [ ] **Step 1: Escrever o teste que falha `tests/Infra/DatabaseTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Infra;

use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Infra\Database;

final class DatabaseTest extends TestCase
{
    public function test_expoe_o_pdo_injetado(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $db = new Database($pdo);

        $this->assertSame($pdo, $db->pdo());
    }

    public function test_pdo_executa_consulta_simples(): void
    {
        $db = new Database(new PDO('sqlite::memory:'));

        $valor = $db->pdo()->query('SELECT 1 AS um')->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('1', (string) $valor['um']);
    }
}
```

- [ ] **Step 2: Rodar para verificar que falha**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test -- --filter DatabaseTest`
Expected: FAIL — "Class RcInfoti\Pelada\Infra\Database not found".

- [ ] **Step 3: Implementar `src/Infra/Database.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Infra;

use PDO;

final class Database
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array{host:string,porta:int,banco:string,usuario:string,senha:string} $config */
    public static function fromConfig(array $config): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['porta'],
            $config['banco'],
        );

        $pdo = new PDO($dsn, $config['usuario'], $config['senha'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return new self($pdo);
    }
}
```

- [ ] **Step 4: Criar `config/config.example.php`**

```php
<?php

declare(strict_types=1);

// Copie para config/config.local.php (fora do versionamento) e preencha.
return [
    'db' => [
        'host' => 'localhost',
        'porta' => 3306,
        'banco' => 'rcinfoti_pelada',
        'usuario' => 'rcinfoti_pelada',
        'senha' => '',
    ],
];
```

- [ ] **Step 5: Rodar para verificar que passa**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test -- --filter DatabaseTest`
Expected: PASS — 2 testes verdes.

- [ ] **Step 6: Commit**

```bash
git add src/Infra/Database.php config/config.example.php tests/Infra/DatabaseTest.php
git commit -m "feat(infra): wrapper PDO Database + config de exemplo"
```

---

### Task 2: Schema — migração MySQL + espelho SQLite para testes

**Files:**
- Create: `migrations/001_schema.sql` (MySQL, produção)
- Create: `tests/Infra/SchemaSqlite.php` (helper de teste)
- Test: `tests/Infra/SchemaSqliteTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `SchemaSqlite::criar(PDO $pdo): void` que cria, em SQLite, as tabelas usadas pelos repositórios: `peladas`, `jogadores`, `rodadas`, `inscricoes`, `multas`, `pagamentos`, com as colunas que as queries consomem. O `001_schema.sql` é a fonte da verdade de produção (MySQL) e deve ter as mesmas tabelas/colunas.

**Nota de risco (drift):** os testes rodam contra o SQLite; a divergência do `001_schema.sql` (MySQL) não é pega automaticamente. Revisar os dois arquivos juntos. Nomes de coluna DEVEM ser idênticos aos usados nas queries da Task 5.

- [ ] **Step 1: Escrever o teste que falha `tests/Infra/SchemaSqliteTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Infra;

use PDO;
use PHPUnit\Framework\TestCase;

final class SchemaSqliteTest extends TestCase
{
    public function test_cria_todas_as_tabelas_do_dominio(): void
    {
        $pdo = new PDO('sqlite::memory:');
        SchemaSqlite::criar($pdo);

        $tabelas = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach (['inscricoes', 'jogadores', 'multas', 'pagamentos', 'peladas', 'rodadas'] as $t) {
            $this->assertContains($t, $tabelas, "faltou a tabela {$t}");
        }
    }

    public function test_inscricoes_tem_as_colunas_usadas_pelas_queries(): void
    {
        $pdo = new PDO('sqlite::memory:');
        SchemaSqlite::criar($pdo);

        $colunas = $pdo->query('PRAGMA table_info(inscricoes)')->fetchAll(PDO::FETCH_ASSOC);
        $nomes = array_column($colunas, 'name');

        foreach (['rodada_id', 'jogador_id', 'tipo', 'status', 'ordem', 'desistiu_em', 'multa_aplicada'] as $c) {
            $this->assertContains($c, $nomes, "faltou a coluna inscricoes.{$c}");
        }
    }
}
```

- [ ] **Step 2: Rodar para verificar que falha**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test -- --filter SchemaSqliteTest`
Expected: FAIL — "Class ...SchemaSqlite not found".

- [ ] **Step 3: Criar `tests/Infra/SchemaSqlite.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Infra;

use PDO;

final class SchemaSqlite
{
    public static function criar(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE peladas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            slug TEXT NOT NULL,
            limite_linha INTEGER NOT NULL DEFAULT 20,
            limite_goleiro INTEGER NOT NULL DEFAULT 4,
            valor_futebol REAL NOT NULL DEFAULT 15.00,
            valor_festa_semana REAL NOT NULL DEFAULT 5.00,
            valor_festa_ano REAL NOT NULL DEFAULT 220.00,
            ativa INTEGER NOT NULL DEFAULT 1
        )');

        $pdo->exec("CREATE TABLE jogadores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pelada_id INTEGER NOT NULL,
            nome TEXT NOT NULL,
            email TEXT,
            telefone TEXT,
            tipo TEXT NOT NULL DEFAULT 'linha',
            pin_hash TEXT,
            saldo_pendente REAL NOT NULL DEFAULT 0,
            festa_quitada_ano INTEGER,
            ativo INTEGER NOT NULL DEFAULT 1
        )");

        $pdo->exec("CREATE TABLE rodadas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pelada_id INTEGER NOT NULL,
            data_jogo TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'aberta',
            abre_em TEXT,
            vira_regra_em TEXT NOT NULL,
            prazo_multa_em TEXT NOT NULL
        )");

        $pdo->exec("CREATE TABLE inscricoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rodada_id INTEGER NOT NULL,
            jogador_id INTEGER NOT NULL,
            tipo TEXT NOT NULL,
            status TEXT NOT NULL,
            ordem INTEGER NOT NULL,
            confirmado_em TEXT,
            desistiu_em TEXT,
            multa_aplicada INTEGER NOT NULL DEFAULT 0,
            UNIQUE (rodada_id, jogador_id)
        )");

        $pdo->exec("CREATE TABLE multas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pelada_id INTEGER NOT NULL,
            jogador_id INTEGER NOT NULL,
            rodada_id INTEGER NOT NULL,
            valor REAL NOT NULL,
            status TEXT NOT NULL DEFAULT 'pendente',
            motivo TEXT,
            criado_em TEXT NOT NULL,
            quitado_em TEXT
        )");

        $pdo->exec("CREATE TABLE pagamentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pelada_id INTEGER NOT NULL,
            jogador_id INTEGER NOT NULL,
            rodada_id INTEGER,
            categoria TEXT NOT NULL,
            escopo TEXT NOT NULL,
            valor REAL NOT NULL,
            forma TEXT NOT NULL,
            comprovante_arquivo TEXT,
            confirmado INTEGER NOT NULL DEFAULT 0,
            criado_em TEXT NOT NULL
        )");
    }
}
```

- [ ] **Step 4: Criar `migrations/001_schema.sql` (MySQL — mesma estrutura)**

```sql
-- Schema de produção (MySQL 8 / cPanel). Aplicar via phpMyAdmin ou linha de comando.
CREATE TABLE peladas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    dia_jogo TINYINT NOT NULL,
    hora_jogo TIME NOT NULL,
    abre_dia TINYINT NOT NULL,
    abre_hora TIME NOT NULL,
    vira_regra_dia TINYINT NOT NULL,
    vira_regra_hora TIME NOT NULL,
    prazo_multa_dia TINYINT NOT NULL,
    prazo_multa_hora TIME NOT NULL,
    limite_linha INT NOT NULL DEFAULT 20,
    limite_goleiro INT NOT NULL DEFAULT 4,
    valor_futebol DECIMAL(10,2) NOT NULL DEFAULT 15.00,
    valor_festa_semana DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    valor_festa_ano DECIMAL(10,2) NOT NULL DEFAULT 220.00,
    festa_inicio DATE NULL,
    festa_fim DATE NULL,
    ativa TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_peladas_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE jogadores (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pelada_id INT UNSIGNED NOT NULL,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(160) NULL,
    telefone VARCHAR(40) NULL,
    tipo ENUM('linha','goleiro') NOT NULL DEFAULT 'linha',
    pin_hash VARCHAR(255) NULL,
    saldo_pendente DECIMAL(10,2) NOT NULL DEFAULT 0,
    festa_quitada_ano SMALLINT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_jogadores_pelada (pelada_id),
    CONSTRAINT fk_jogadores_pelada FOREIGN KEY (pelada_id) REFERENCES peladas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE rodadas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pelada_id INT UNSIGNED NOT NULL,
    data_jogo DATE NOT NULL,
    status ENUM('aberta','fechada','encerrada') NOT NULL DEFAULT 'aberta',
    abre_em DATETIME NULL,
    vira_regra_em DATETIME NOT NULL,
    prazo_multa_em DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rodadas_pelada (pelada_id),
    CONSTRAINT fk_rodadas_pelada FOREIGN KEY (pelada_id) REFERENCES peladas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inscricoes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rodada_id INT UNSIGNED NOT NULL,
    jogador_id INT UNSIGNED NOT NULL,
    tipo ENUM('linha','goleiro') NOT NULL,
    status ENUM('confirmado','espera','desistiu') NOT NULL,
    ordem INT NOT NULL,
    confirmado_em DATETIME NULL,
    desistiu_em DATETIME NULL,
    multa_aplicada TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uk_inscricao (rodada_id, jogador_id),
    KEY idx_inscricoes_rodada (rodada_id),
    KEY idx_inscricoes_jogador (jogador_id),
    CONSTRAINT fk_inscricoes_rodada FOREIGN KEY (rodada_id) REFERENCES rodadas(id),
    CONSTRAINT fk_inscricoes_jogador FOREIGN KEY (jogador_id) REFERENCES jogadores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE multas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pelada_id INT UNSIGNED NOT NULL,
    jogador_id INT UNSIGNED NOT NULL,
    rodada_id INT UNSIGNED NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    status ENUM('pendente','paga') NOT NULL DEFAULT 'pendente',
    motivo VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL,
    quitado_em DATETIME NULL,
    KEY idx_multas_jogador (jogador_id),
    CONSTRAINT fk_multas_pelada FOREIGN KEY (pelada_id) REFERENCES peladas(id),
    CONSTRAINT fk_multas_jogador FOREIGN KEY (jogador_id) REFERENCES jogadores(id),
    CONSTRAINT fk_multas_rodada FOREIGN KEY (rodada_id) REFERENCES rodadas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pagamentos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pelada_id INT UNSIGNED NOT NULL,
    jogador_id INT UNSIGNED NOT NULL,
    rodada_id INT UNSIGNED NULL,
    categoria ENUM('futebol','festa') NOT NULL,
    escopo ENUM('semana','ano') NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    forma ENUM('pix','dinheiro') NOT NULL,
    comprovante_arquivo VARCHAR(255) NULL,
    confirmado TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL,
    KEY idx_pagamentos_jogador (jogador_id),
    KEY idx_pagamentos_rodada (rodada_id),
    CONSTRAINT fk_pagamentos_pelada FOREIGN KEY (pelada_id) REFERENCES peladas(id),
    CONSTRAINT fk_pagamentos_jogador FOREIGN KEY (jogador_id) REFERENCES jogadores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 5: Rodar para verificar que passa**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test -- --filter SchemaSqliteTest`
Expected: PASS — 2 testes verdes.

- [ ] **Step 6: Commit**

```bash
git add migrations/001_schema.sql tests/Infra/SchemaSqlite.php tests/Infra/SchemaSqliteTest.php
git commit -m "feat(infra): schema MySQL (001) + espelho SQLite para testes"
```

---

### Task 3: CalculadoraDevido (domínio puro)

**Files:**
- Create: `src/Financeiro/CalculadoraDevido.php`
- Test: `tests/Financeiro/CalculadoraDevidoTest.php`

**Interfaces:**
- Consumes: `Tipo` (Plano 1).
- Produces: `final class CalculadoraDevido` com `__construct(float $valorFutebol, float $valorFestaSemana)` e `devidoSemanal(Tipo $tipo, bool $festaQuitada): float`.
  - Linha: `valorFutebol + (festaQuitada ? 0 : valorFestaSemana)`.
  - Goleiro: `festaQuitada ? 0 : valorFestaSemana` (goleiro não paga futebol).

- [ ] **Step 1: Escrever o teste que falha `tests/Financeiro/CalculadoraDevidoTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Financeiro;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Financeiro\CalculadoraDevido;
use RcInfoti\Pelada\Rodada\Tipo;

final class CalculadoraDevidoTest extends TestCase
{
    private function calc(): CalculadoraDevido
    {
        return new CalculadoraDevido(15.00, 5.00);
    }

    public function test_linha_paga_futebol_mais_festa(): void
    {
        $this->assertSame(20.00, $this->calc()->devidoSemanal(Tipo::Linha, festaQuitada: false));
    }

    public function test_goleiro_paga_so_festa(): void
    {
        $this->assertSame(5.00, $this->calc()->devidoSemanal(Tipo::Goleiro, festaQuitada: false));
    }

    public function test_festa_quitada_zera_a_parte_da_festa(): void
    {
        $this->assertSame(15.00, $this->calc()->devidoSemanal(Tipo::Linha, festaQuitada: true));
        $this->assertSame(0.00, $this->calc()->devidoSemanal(Tipo::Goleiro, festaQuitada: true));
    }
}
```

- [ ] **Step 2: Rodar para verificar que falha**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test -- --filter CalculadoraDevidoTest`
Expected: FAIL — "Class ...CalculadoraDevido not found".

- [ ] **Step 3: Implementar `src/Financeiro/CalculadoraDevido.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use RcInfoti\Pelada\Rodada\Tipo;

final class CalculadoraDevido
{
    public function __construct(
        private readonly float $valorFutebol,
        private readonly float $valorFestaSemana,
    ) {
    }

    public function devidoSemanal(Tipo $tipo, bool $festaQuitada): float
    {
        $futebol = $tipo === Tipo::Linha ? $this->valorFutebol : 0.0;
        $festa = $festaQuitada ? 0.0 : $this->valorFestaSemana;

        return $futebol + $festa;
    }
}
```

- [ ] **Step 4: Rodar para verificar que passa**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test -- --filter CalculadoraDevidoTest`
Expected: PASS — 3 testes verdes.

- [ ] **Step 5: Commit**

```bash
git add src/Financeiro/CalculadoraDevido.php tests/Financeiro/CalculadoraDevidoTest.php
git commit -m "feat(financeiro): CalculadoraDevido do devido semanal"
```

---

### Task 4: RepositorioRodada (porta) + fake + ProcessadorRodada

**Files:**
- Create: `src/Rodada/RepositorioRodada.php` (interface)
- Create: `src/Rodada/ProcessadorRodada.php`
- Create: `tests/Rodada/RepositorioRodadaEmMemoria.php` (fake de teste)
- Test: `tests/Rodada/ProcessadorRodadaTest.php`

**Interfaces:**
- Consumes: `EstadoRodada`, `ServicoRegrasRodada`, `AcaoPromover`, `AcaoAplicarMulta` (Plano 1).
- Produces:
  - `interface RepositorioRodada`:
    - `carregarEstado(int $rodadaId): EstadoRodada`
    - `promover(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void`
    - `aplicarMulta(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void`
  - `final class ProcessadorRodada(RepositorioRodada $repo, ServicoRegrasRodada $regras)` com `processar(int $rodadaId, DateTimeImmutable $agora): void` — carrega o estado, chama `decidir`, e para cada ação chama `promover`/`aplicarMulta`.
  - `RepositorioRodadaEmMemoria` (fake, em `tests/`) usado por este e por outros testes de orquestração.

- [ ] **Step 1: Criar o fake `tests/Rodada/RepositorioRodadaEmMemoria.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use RcInfoti\Pelada\Rodada\EstadoRodada;
use RcInfoti\Pelada\Rodada\RepositorioRodada;

final class RepositorioRodadaEmMemoria implements RepositorioRodada
{
    /** @var list<int> */
    public array $promovidos = [];
    /** @var list<int> */
    public array $multados = [];

    public function __construct(private EstadoRodada $estado)
    {
    }

    public function carregarEstado(int $rodadaId): EstadoRodada
    {
        return $this->estado;
    }

    public function promover(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $this->promovidos[] = $jogadorId;
    }

    public function aplicarMulta(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $this->multados[] = $jogadorId;
    }
}
```

- [ ] **Step 2: Escrever o teste que falha `tests/Rodada/ProcessadorRodadaTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\EstadoRodada;
use RcInfoti\Pelada\Rodada\Inscricao;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Rodada\StatusInscricao;
use RcInfoti\Pelada\Rodada\Tipo;

final class ProcessadorRodadaTest extends TestCase
{
    public function test_aplica_promocao_e_multa_decididas_pelo_servico(): void
    {
        $agora = new DateTimeImmutable('2026-01-08 18:00'); // após prazo -> multa; sem promoção
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            limiteLinha: 20,
            limiteGoleiro: 4,
            inscricoes: [
                new Inscricao(
                    1,
                    Tipo::Linha,
                    StatusInscricao::Desistiu,
                    1,
                    desistiuEm: new DateTimeImmutable('2026-01-08 17:00'),
                ),
            ],
        );
        $repo = new RepositorioRodadaEmMemoria($estado);

        (new ProcessadorRodada($repo, new ServicoRegrasRodada()))->processar(99, $agora);

        $this->assertSame([], $repo->promovidos);
        $this->assertSame([1], $repo->multados);
    }

    public function test_promove_da_espera_quando_ha_vaga(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00'); // antes da virada
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            limiteLinha: 2,
            limiteGoleiro: 1,
            inscricoes: [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 2),
            ],
        );
        $repo = new RepositorioRodadaEmMemoria($estado);

        (new ProcessadorRodada($repo, new ServicoRegrasRodada()))->processar(99, $agora);

        $this->assertSame([2], $repo->promovidos);
        $this->assertSame([], $repo->multados);
    }
}
```

- [ ] **Step 3: Rodar para verificar que falha**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test -- --filter ProcessadorRodadaTest`
Expected: FAIL — "Class ...RepositorioRodada not found" / "...ProcessadorRodada not found".

- [ ] **Step 4: Criar `src/Rodada/RepositorioRodada.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

interface RepositorioRodada
{
    public function carregarEstado(int $rodadaId): EstadoRodada;

    public function promover(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void;

    public function aplicarMulta(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void;
}
```

- [ ] **Step 5: Criar `src/Rodada/ProcessadorRodada.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class ProcessadorRodada
{
    public function __construct(
        private readonly RepositorioRodada $repo,
        private readonly ServicoRegrasRodada $regras,
    ) {
    }

    public function processar(int $rodadaId, DateTimeImmutable $agora): void
    {
        $estado = $this->repo->carregarEstado($rodadaId);

        foreach ($this->regras->decidir($estado, $agora) as $acao) {
            if ($acao instanceof AcaoPromover) {
                $this->repo->promover($rodadaId, $acao->jogadorId, $agora);
            } elseif ($acao instanceof AcaoAplicarMulta) {
                $this->repo->aplicarMulta($rodadaId, $acao->jogadorId, $agora);
            }
        }
    }
}
```

- [ ] **Step 6: Rodar para verificar que passa**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test -- --filter ProcessadorRodadaTest`
Expected: PASS — 2 testes verdes.

- [ ] **Step 7: Commit**

```bash
git add src/Rodada/RepositorioRodada.php src/Rodada/ProcessadorRodada.php tests/Rodada/RepositorioRodadaEmMemoria.php tests/Rodada/ProcessadorRodadaTest.php
git commit -m "feat(rodada): porta RepositorioRodada + ProcessadorRodada (fake em memoria)"
```

---

### Task 5: RepositorioRodadaPdo — implementação real (testada contra SQLite)

**Files:**
- Create: `src/Rodada/RepositorioRodadaPdo.php`
- Test: `tests/Rodada/RepositorioRodadaPdoTest.php`

**Interfaces:**
- Consumes: `RepositorioRodada`, `EstadoRodada`, `Inscricao`, `Tipo`, `StatusInscricao` (Plano 1/Task 4); `CalculadoraDevido` (Task 3); `SchemaSqlite` (Task 2, teste).
- Produces: `final class RepositorioRodadaPdo(PDO $pdo)` implementando `RepositorioRodada`:
  - `carregarEstado`: junta `rodadas`+`peladas` (limites e datas) e monta as `Inscricao` a partir de `inscricoes`, com `pagou = EXISTS(pagamento confirmado do jogador na rodada)` e `multaAplicada` de `multa_aplicada`.
  - `promover`: `UPDATE inscricoes SET status='confirmado', confirmado_em=?`.
  - `aplicarMulta`: em transação — calcula o devido (via `CalculadoraDevido` com os valores da pelada; `festaQuitada` = `festa_quitada_ano` igual ao ano de `data_jogo`), insere em `multas`, incrementa `jogadores.saldo_pendente` e marca `inscricoes.multa_aplicada=1`.

- [ ] **Step 1: Escrever o teste que falha `tests/Rodada/RepositorioRodadaPdoTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\StatusInscricao;
use RcInfoti\Pelada\Rodada\Tipo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioRodadaPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro, valor_futebol, valor_festa_semana)
            VALUES (1, 'Quinta', 'quinta', 2, 1, 15.00, 5.00)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo, saldo_pendente, festa_quitada_ano)
            VALUES (10, 1, 'Ana', 'linha', 0, NULL), (11, 1, 'Bia', 'linha', 0, NULL), (12, 1, 'Cadu', 'goleiro', 0, NULL)");
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
    }

    private function repo(): RepositorioRodadaPdo
    {
        return new RepositorioRodadaPdo($this->pdo);
    }

    private function inscrever(int $jogadorId, string $tipo, string $status, int $ordem): void
    {
        $this->pdo->prepare('INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem) VALUES (5, ?, ?, ?, ?)')
            ->execute([$jogadorId, $tipo, $status, $ordem]);
    }

    public function test_carrega_estado_sem_inscricoes(): void
    {
        $estado = $this->repo()->carregarEstado(5);

        $this->assertSame(2, $estado->limiteLinha);
        $this->assertSame(1, $estado->limiteGoleiro);
        $this->assertEquals(new DateTimeImmutable('2026-01-07 12:00:00'), $estado->viraRegraEm);
        $this->assertSame([], $estado->inscricoes);
    }

    public function test_carrega_inscricoes_ordenadas_com_tipo_e_status(): void
    {
        $this->inscrever(11, 'linha', 'espera', 2);
        $this->inscrever(10, 'linha', 'confirmado', 1);

        $estado = $this->repo()->carregarEstado(5);

        $this->assertCount(2, $estado->inscricoes);
        $this->assertSame(10, $estado->inscricoes[0]->jogadorId); // ordem 1 primeiro
        $this->assertSame(Tipo::Linha, $estado->inscricoes[0]->tipo);
        $this->assertSame(StatusInscricao::Confirmado, $estado->inscricoes[0]->status);
        $this->assertSame(StatusInscricao::Espera, $estado->inscricoes[1]->status);
    }

    public function test_pagou_so_com_pagamento_confirmado(): void
    {
        $this->inscrever(10, 'linha', 'espera', 1);
        $this->inscrever(11, 'linha', 'espera', 2);
        // Ana (10): pagamento confirmado. Bia (11): pagamento não confirmado.
        $this->pdo->exec("INSERT INTO pagamentos (pelada_id, jogador_id, rodada_id, categoria, escopo, valor, forma, confirmado, criado_em)
            VALUES (1, 10, 5, 'futebol', 'semana', 15, 'pix', 1, '2026-01-06 10:00:00'),
                   (1, 11, 5, 'futebol', 'semana', 15, 'pix', 0, '2026-01-06 10:00:00')");

        $estado = $this->repo()->carregarEstado(5);
        $porId = [];
        foreach ($estado->inscricoes as $i) {
            $porId[$i->jogadorId] = $i;
        }

        $this->assertTrue($porId[10]->pagou);
        $this->assertFalse($porId[11]->pagou);
    }

    public function test_promover_confirma_a_inscricao(): void
    {
        $this->inscrever(11, 'linha', 'espera', 2);

        $this->repo()->promover(5, 11, new DateTimeImmutable('2026-01-07 09:00:00'));

        $status = $this->pdo->query('SELECT status FROM inscricoes WHERE jogador_id = 11')->fetchColumn();
        $this->assertSame('confirmado', $status);
    }

    public function test_aplicar_multa_persiste_tudo(): void
    {
        $this->inscrever(10, 'linha', 'desistiu', 1);

        $this->repo()->aplicarMulta(5, 10, new DateTimeImmutable('2026-01-08 17:00:00'));

        // multa gravada com o devido de linha (15 + 5 = 20)
        $multa = $this->pdo->query('SELECT valor, status, jogador_id, rodada_id FROM multas')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(20.0, (float) $multa['valor']);
        $this->assertSame('pendente', $multa['status']);
        $this->assertSame('10', (string) $multa['jogador_id']);
        // saldo_pendente incrementado
        $saldo = $this->pdo->query('SELECT saldo_pendente FROM jogadores WHERE id = 10')->fetchColumn();
        $this->assertSame(20.0, (float) $saldo);
        // flag marcada
        $flag = $this->pdo->query('SELECT multa_aplicada FROM inscricoes WHERE jogador_id = 10')->fetchColumn();
        $this->assertSame('1', (string) $flag);
    }

    public function test_multa_ignora_festa_quitada_de_outro_ano(): void
    {
        // Cadu (goleiro) quitou a festa em 2025, mas a rodada é de 2026 -> festa ainda devida (5).
        $this->pdo->exec('UPDATE jogadores SET festa_quitada_ano = 2025 WHERE id = 12');
        $this->inscrever(12, 'goleiro', 'desistiu', 1);

        $this->repo()->aplicarMulta(5, 12, new DateTimeImmutable('2026-01-08 17:00:00'));

        $valor = $this->pdo->query('SELECT valor FROM multas WHERE jogador_id = 12')->fetchColumn();
        $this->assertSame(5.0, (float) $valor); // goleiro paga só a festa da semana
    }
}
```

- [ ] **Step 2: Rodar para verificar que falha**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test -- --filter RepositorioRodadaPdoTest`
Expected: FAIL — "Class ...RepositorioRodadaPdo not found".

- [ ] **Step 3: Implementar `src/Rodada/RepositorioRodadaPdo.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;
use PDO;
use RcInfoti\Pelada\Financeiro\CalculadoraDevido;

final class RepositorioRodadaPdo implements RepositorioRodada
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function carregarEstado(int $rodadaId): EstadoRodada
    {
        $cab = $this->pdo->prepare(
            'SELECT r.vira_regra_em, r.prazo_multa_em, p.limite_linha, p.limite_goleiro
             FROM rodadas r JOIN peladas p ON p.id = r.pelada_id
             WHERE r.id = ?'
        );
        $cab->execute([$rodadaId]);
        $r = $cab->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            throw new \RuntimeException("Rodada {$rodadaId} não encontrada.");
        }

        $stmt = $this->pdo->prepare(
            'SELECT i.jogador_id, i.tipo, i.status, i.ordem, i.desistiu_em, i.multa_aplicada,
                    EXISTS(
                        SELECT 1 FROM pagamentos pg
                        WHERE pg.rodada_id = i.rodada_id AND pg.jogador_id = i.jogador_id AND pg.confirmado = 1
                    ) AS pagou
             FROM inscricoes i
             WHERE i.rodada_id = ?
             ORDER BY i.ordem'
        );
        $stmt->execute([$rodadaId]);

        $inscricoes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $inscricoes[] = new Inscricao(
                (int) $row['jogador_id'],
                $this->tipo($row['tipo']),
                $this->status($row['status']),
                (int) $row['ordem'],
                pagou: (bool) $row['pagou'],
                desistiuEm: $row['desistiu_em'] !== null ? new DateTimeImmutable($row['desistiu_em']) : null,
                multaAplicada: (bool) $row['multa_aplicada'],
            );
        }

        return new EstadoRodada(
            new DateTimeImmutable($r['vira_regra_em']),
            new DateTimeImmutable($r['prazo_multa_em']),
            (int) $r['limite_linha'],
            (int) $r['limite_goleiro'],
            $inscricoes,
        );
    }

    public function promover(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE inscricoes SET status = 'confirmado', confirmado_em = ?
             WHERE rodada_id = ? AND jogador_id = ?"
        );
        $stmt->execute([$agora->format('Y-m-d H:i:s'), $rodadaId, $jogadorId]);
    }

    public function aplicarMulta(int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void
    {
        $dados = $this->pdo->prepare(
            'SELECT r.pelada_id, r.data_jogo, p.valor_futebol, p.valor_festa_semana,
                    j.tipo, j.festa_quitada_ano
             FROM rodadas r
             JOIN peladas p ON p.id = r.pelada_id
             JOIN jogadores j ON j.id = ?
             WHERE r.id = ?'
        );
        $dados->execute([$jogadorId, $rodadaId]);
        $d = $dados->fetch(PDO::FETCH_ASSOC);
        if ($d === false) {
            throw new \RuntimeException("Dados para multa não encontrados (rodada {$rodadaId}, jogador {$jogadorId}).");
        }

        $anoJogo = (int) (new DateTimeImmutable($d['data_jogo']))->format('Y');
        $festaQuitada = $d['festa_quitada_ano'] !== null && (int) $d['festa_quitada_ano'] === $anoJogo;

        $calc = new CalculadoraDevido((float) $d['valor_futebol'], (float) $d['valor_festa_semana']);
        $valor = $calc->devidoSemanal($this->tipo($d['tipo']), $festaQuitada);

        $this->pdo->beginTransaction();
        try {
            $insere = $this->pdo->prepare(
                "INSERT INTO multas (pelada_id, jogador_id, rodada_id, valor, status, motivo, criado_em)
                 VALUES (?, ?, ?, ?, 'pendente', 'desistência após prazo', ?)"
            );
            $insere->execute([
                (int) $d['pelada_id'],
                $jogadorId,
                $rodadaId,
                $valor,
                $agora->format('Y-m-d H:i:s'),
            ]);

            $this->pdo->prepare('UPDATE jogadores SET saldo_pendente = saldo_pendente + ? WHERE id = ?')
                ->execute([$valor, $jogadorId]);

            $this->pdo->prepare('UPDATE inscricoes SET multa_aplicada = 1 WHERE rodada_id = ? AND jogador_id = ?')
                ->execute([$rodadaId, $jogadorId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function tipo(string $valor): Tipo
    {
        return match ($valor) {
            'linha' => Tipo::Linha,
            'goleiro' => Tipo::Goleiro,
            default => throw new \InvalidArgumentException("Tipo inválido: {$valor}"),
        };
    }

    private function status(string $valor): StatusInscricao
    {
        return match ($valor) {
            'confirmado' => StatusInscricao::Confirmado,
            'espera' => StatusInscricao::Espera,
            'desistiu' => StatusInscricao::Desistiu,
            default => throw new \InvalidArgumentException("Status inválido: {$valor}"),
        };
    }
}
```

- [ ] **Step 4: Rodar para verificar que passa**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test -- --filter RepositorioRodadaPdoTest`
Expected: PASS — 6 testes verdes.

- [ ] **Step 5: Rodar a suíte inteira**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test`
Expected: PASS — toda a suíte (Plano 1 + Plano 2) verde.

- [ ] **Step 6: Commit**

```bash
git add src/Rodada/RepositorioRodadaPdo.php tests/Rodada/RepositorioRodadaPdoTest.php
git commit -m "feat(rodada): RepositorioRodadaPdo (carregar estado, promover, aplicar multa)"
```

---

### Task 6: Idempotência ponta-a-ponta (Processador + repositório PDO)

**Files:**
- Test: `tests/Rodada/ProcessarRodadaIntegracaoTest.php`

**Interfaces:**
- Consumes: `ProcessadorRodada` (Task 4), `RepositorioRodadaPdo` (Task 5), `ServicoRegrasRodada` (Plano 1), `SchemaSqlite` (Task 2).
- Produces: nada novo — teste de integração que exercita o fluxo real (Processador + PDO/SQLite) e trava a idempotência.

- [ ] **Step 1: Escrever o teste `tests/Rodada/ProcessarRodadaIntegracaoTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class ProcessarRodadaIntegracaoTest extends TestCase
{
    public function test_processar_e_idempotente(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($pdo);
        $pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro) VALUES (1, 'Quinta', 'quinta', 2, 1)");
        $pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, tipo) VALUES (10, 1, 'Ana', 'linha'), (11, 1, 'Bia', 'linha')");
        $pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        // 1 confirmado + 1 na espera; limite de linha = 2 -> uma vaga.
        $pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem)
            VALUES (5, 10, 'linha', 'confirmado', 1), (5, 11, 'linha', 'espera', 2)");

        $proc = new ProcessadorRodada(new RepositorioRodadaPdo($pdo), new ServicoRegrasRodada());
        $agora = new DateTimeImmutable('2026-01-07 10:00:00'); // antes da virada -> FIFO

        $proc->processar(5, $agora);
        $proc->processar(5, $agora); // segunda passada não deve mudar nada

        $confirmados = (int) $pdo->query("SELECT COUNT(*) FROM inscricoes WHERE rodada_id = 5 AND status = 'confirmado'")->fetchColumn();
        $this->assertSame(2, $confirmados); // Bia subiu uma única vez
    }
}
```

- [ ] **Step 2: Rodar para verificar que passa**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test -- --filter ProcessarRodadaIntegracaoTest`
Expected: PASS. (A promoção vira `confirmado`; na segunda passada não há mais ninguém em `espera`, então nada muda — idempotente.)

- [ ] **Step 3: Rodar a suíte inteira**

Run: `PATH="/opt/homebrew/bin:$PATH" composer test`
Expected: PASS — suíte inteira verde.

- [ ] **Step 4: Commit**

```bash
git add tests/Rodada/ProcessarRodadaIntegracaoTest.php
git commit -m "test(rodada): idempotencia ponta-a-ponta do processamento (PDO/SQLite)"
```

---

## Notas para os próximos planos

- **Drift de schema:** `001_schema.sql` (MySQL) e `SchemaSqlite` (testes) precisam andar juntos. Um plano de deploy futuro pode adicionar um runner de migrations e/ou gerar o SQLite a partir do mesmo modelo.
- **Plano 3 (financeiro)** constrói sobre `CalculadoraDevido`: registrar pagamentos (Pix/dinheiro, comprovante em arquivo), gerar `movimentos_caixa`, quitar multas (zera `saldo_pendente`), relatórios. A tabela `movimentos_caixa` (§4) ainda não foi criada — entra no Plano 3.
- **`pagou` na promoção:** hoje = existe pagamento confirmado para a rodada. Se o Plano 3 refinar a regra (ex.: só conta se cobre o devido), ajustar a query de `carregarEstado`.
- **Relógio:** o instante continua injetado como parâmetro; se um `ClockInterface` for introduzido, os pontos de entrada (cron, páginas) passam a recebê-lo.
