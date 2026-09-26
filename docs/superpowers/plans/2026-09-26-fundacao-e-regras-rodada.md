# Fundação e Serviço de Regras da Rodada — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Montar o esqueleto do projeto (Composer + PHPUnit + PSR-4) e implementar, via TDD, o Serviço de Regras da Rodada — o núcleo puro que decide promoções da lista de espera e multas a partir do estado da rodada e do horário atual.

**Architecture:** Aplicação PHP 8.1+ sem framework. Este plano cobre apenas o domínio puro da rodada: value objects imutáveis (enums + classes `readonly`) descrevem o estado; um único serviço `ServicoRegrasRodada::decidir(EstadoRodada, DateTimeImmutable): list<Acao>` retorna uma lista de ações (`AcaoPromover`, `AcaoAplicarMulta`) sem tocar em banco, e-mail ou I/O. Persistência e wiring ficam para planos seguintes; manter o serviço puro é o que o torna testável e reutilizável tanto pelo cron quanto pelas páginas (rede de segurança idempotente).

**Tech Stack:** PHP 8.1+, Composer (autoload PSR-4), PHPUnit 10. Sem dependências de runtime (o serviço é PHP puro da biblioteca padrão).

**Spec:** `docs/superpowers/specs/2026-09-26-gestao-pelada-design.md` (§3.3 ciclo semanal, §3.4 multas, §5 arquitetura/isolamento, §9 testes).

## Global Constraints

- **PHP:** mínimo `>=8.1` (usa enums e propriedades `readonly`). Alvo de deploy: cPanel PHP + MySQL.
- **Namespace raiz:** `RcInfoti\Pelada\` → `src/` (PSR-4). Testes: `RcInfoti\Pelada\Tests\` → `tests/`.
- **Sem framework e sem dependência de runtime** neste plano; PHPUnit é dependência apenas de desenvolvimento (`require-dev`).
- **Idioma:** identificadores de domínio em português (nomes do negócio: `Rodada`, `Inscricao`, `promover`), seguindo a spec.
- **Serviço de regras é puro:** nenhuma chamada a banco, arquivo, e-mail, `time()`/`now()` implícito — o "agora" entra sempre como parâmetro `DateTimeImmutable`. Isso garante testes determinísticos.
- **Separação linha/goleiro é independente** (§3.3): cada tipo tem seu próprio limite e sua própria lista de espera.
- **Janelas de regra (§3.3):** antes de `viraRegraEm` → promoção **FIFO** (por ordem de chegada, ignora pagamento); a partir de `viraRegraEm` (inclusive) → **"pago primeiro"** (só promove quem pagou, respeitando a ordem entre eles).
- **Multa (§3.3/§3.4):** desistência com `desistiuEm >= prazoMultaEm` gera `AcaoAplicarMulta`; nunca reaplicar se `multaAplicada == true`.

## Review Focus

- **Lista de espera vazia ou sem vaga:** decidir deve retornar `[]` sem erro (não promover ninguém). — coberto na Task 3 (`test_nao_promove_quando_nao_ha_vaga`).
- **Menos candidatos que vagas:** promover todos os candidatos disponíveis e parar, sem erro nem promoções fantasma. — coberto na Task 3 (`test_promove_todos_quando_vagas_sobram`).
- **Instante exato da virada (`agora == viraRegraEm`):** já vale "pago primeiro" (comparação `<` para FIFO, portanto `>=` cai em pago-primeiro). — coberto na Task 4 (`test_no_instante_exato_da_virada_ja_vale_pago_primeiro`).
- **Desistência exatamente no prazo (`desistiuEm == prazoMultaEm`):** gera multa (limite inclusivo `>=`). — coberto na Task 6 (`test_multa_no_instante_exato_do_prazo`).
- **Reprocessamento (idempotência):** rodar `decidir` de novo sobre o estado já atualizado não deve duplicar promoções nem multas. — coberto na Task 6 (`test_nao_reaplica_multa_ja_aplicada`) e pelo desenho (promoções recalculadas a partir de `Confirmado`).

---

### Task 1: Bootstrap do projeto e harness de testes

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `tests/BootstrapTest.php`
- (já existe) `.gitignore` — contém `/vendor/`

**Interfaces:**
- Consumes: nada.
- Produces: autoload PSR-4 `RcInfoti\Pelada\` (src) e `RcInfoti\Pelada\Tests\` (tests); comando `composer test` que roda o PHPUnit.

- [ ] **Step 1: Criar `composer.json`**

```json
{
    "name": "rcinfoti/pelada",
    "description": "Sistema de gestão de peladas de futebol",
    "type": "project",
    "require": {
        "php": ">=8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "RcInfoti\\Pelada\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "RcInfoti\\Pelada\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit"
    },
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 2: Criar `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Instalar dependências**

Run: `composer install`
Expected: cria `vendor/` e `composer.lock` (PHPUnit 10 instalado). Requer internet.

- [ ] **Step 4: Criar teste de fumaça `tests/BootstrapTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests;

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase
{
    public function test_ambiente_de_testes_funciona(): void
    {
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 5: Rodar para confirmar que passa**

Run: `composer test`
Expected: PASS — 1 teste, 1 asserção, "OK".

- [ ] **Step 6: Adicionar `.phpunit.cache` e `composer.lock` ao versionamento conforme necessário e commitar**

Antes de commitar, acrescente `/.phpunit.cache/` ao `.gitignore` (linha nova). Commite `composer.lock` (fixa versões).

```bash
printf '/.phpunit.cache/\n' >> .gitignore
git add composer.json composer.lock phpunit.xml tests/BootstrapTest.php .gitignore
git commit -m "chore: bootstrap composer + phpunit"
```

---

### Task 2: Enums, value objects e ações do domínio

**Files:**
- Create: `src/Rodada/Tipo.php`
- Create: `src/Rodada/StatusInscricao.php`
- Create: `src/Rodada/Inscricao.php`
- Create: `src/Rodada/EstadoRodada.php`
- Create: `src/Rodada/Acao.php`
- Create: `src/Rodada/AcaoPromover.php`
- Create: `src/Rodada/AcaoAplicarMulta.php`
- Test: `tests/Rodada/ValueObjectsTest.php`

**Interfaces:**
- Consumes: autoload da Task 1.
- Produces (usados por todas as tasks seguintes):
  - `enum Tipo { case Linha; case Goleiro; }`
  - `enum StatusInscricao { case Confirmado; case Espera; case Desistiu; }`
  - `final class Inscricao` com construtor:
    `__construct(int $jogadorId, Tipo $tipo, StatusInscricao $status, int $ordem, bool $pagou = false, ?DateTimeImmutable $desistiuEm = null, bool $multaAplicada = false)` — todas propriedades `public readonly`.
  - `final class EstadoRodada` com construtor:
    `__construct(DateTimeImmutable $viraRegraEm, DateTimeImmutable $prazoMultaEm, int $limiteLinha, int $limiteGoleiro, array $inscricoes)` — `array` é `list<Inscricao>`, todas `public readonly`.
  - `interface Acao {}`
  - `final class AcaoPromover implements Acao` com `public readonly int $jogadorId`.
  - `final class AcaoAplicarMulta implements Acao` com `public readonly int $jogadorId`.

- [ ] **Step 1: Escrever o teste que falha `tests/Rodada/ValueObjectsTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\AcaoAplicarMulta;
use RcInfoti\Pelada\Rodada\AcaoPromover;
use RcInfoti\Pelada\Rodada\EstadoRodada;
use RcInfoti\Pelada\Rodada\Inscricao;
use RcInfoti\Pelada\Rodada\StatusInscricao;
use RcInfoti\Pelada\Rodada\Tipo;

final class ValueObjectsTest extends TestCase
{
    public function test_inscricao_guarda_seus_dados(): void
    {
        $i = new Inscricao(7, Tipo::Goleiro, StatusInscricao::Espera, 3, pagou: true);

        $this->assertSame(7, $i->jogadorId);
        $this->assertSame(Tipo::Goleiro, $i->tipo);
        $this->assertSame(StatusInscricao::Espera, $i->status);
        $this->assertSame(3, $i->ordem);
        $this->assertTrue($i->pagou);
        $this->assertNull($i->desistiuEm);
        $this->assertFalse($i->multaAplicada);
    }

    public function test_estado_rodada_guarda_config_e_inscricoes(): void
    {
        $vira = new DateTimeImmutable('2026-01-07 12:00');
        $prazo = new DateTimeImmutable('2026-01-08 16:00');
        $estado = new EstadoRodada($vira, $prazo, 20, 4, [
            new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
        ]);

        $this->assertSame($vira, $estado->viraRegraEm);
        $this->assertSame($prazo, $estado->prazoMultaEm);
        $this->assertSame(20, $estado->limiteLinha);
        $this->assertSame(4, $estado->limiteGoleiro);
        $this->assertCount(1, $estado->inscricoes);
    }

    public function test_acoes_carregam_jogador_e_sao_acao(): void
    {
        $promover = new AcaoPromover(5);
        $multa = new AcaoAplicarMulta(9);

        $this->assertSame(5, $promover->jogadorId);
        $this->assertSame(9, $multa->jogadorId);
        $this->assertInstanceOf(\RcInfoti\Pelada\Rodada\Acao::class, $promover);
        $this->assertInstanceOf(\RcInfoti\Pelada\Rodada\Acao::class, $multa);
    }
}
```

- [ ] **Step 2: Rodar para verificar que falha**

Run: `composer test`
Expected: FAIL — "Class ... not found" (`Tipo`, `Inscricao` etc. ainda não existem).

- [ ] **Step 3: Criar `src/Rodada/Tipo.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

enum Tipo
{
    case Linha;
    case Goleiro;
}
```

- [ ] **Step 4: Criar `src/Rodada/StatusInscricao.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

enum StatusInscricao
{
    case Confirmado;
    case Espera;
    case Desistiu;
}
```

- [ ] **Step 5: Criar `src/Rodada/Inscricao.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class Inscricao
{
    public function __construct(
        public readonly int $jogadorId,
        public readonly Tipo $tipo,
        public readonly StatusInscricao $status,
        public readonly int $ordem,
        public readonly bool $pagou = false,
        public readonly ?DateTimeImmutable $desistiuEm = null,
        public readonly bool $multaAplicada = false,
    ) {
    }
}
```

- [ ] **Step 6: Criar `src/Rodada/EstadoRodada.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class EstadoRodada
{
    /** @param list<Inscricao> $inscricoes */
    public function __construct(
        public readonly DateTimeImmutable $viraRegraEm,
        public readonly DateTimeImmutable $prazoMultaEm,
        public readonly int $limiteLinha,
        public readonly int $limiteGoleiro,
        public readonly array $inscricoes,
    ) {
    }
}
```

- [ ] **Step 7: Criar `src/Rodada/Acao.php`, `AcaoPromover.php`, `AcaoAplicarMulta.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

interface Acao
{
}
```

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

final class AcaoPromover implements Acao
{
    public function __construct(public readonly int $jogadorId)
    {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

final class AcaoAplicarMulta implements Acao
{
    public function __construct(public readonly int $jogadorId)
    {
    }
}
```

- [ ] **Step 8: Rodar para verificar que passa**

Run: `composer test`
Expected: PASS — testes de value objects verdes.

- [ ] **Step 9: Commit**

```bash
git add src/Rodada tests/Rodada/ValueObjectsTest.php
git commit -m "feat(rodada): value objects, enums e acoes do dominio"
```

---

### Task 3: Promoção FIFO (linha) — antes da virada

**Files:**
- Create: `src/Rodada/ServicoRegrasRodada.php`
- Test: `tests/Rodada/ServicoRegrasRodadaTest.php`

**Interfaces:**
- Consumes: `EstadoRodada`, `Inscricao`, `Tipo`, `StatusInscricao`, `AcaoPromover` da Task 2.
- Produces: `final class ServicoRegrasRodada` com `public function decidir(EstadoRodada $estado, DateTimeImmutable $agora): array` retornando `list<Acao>`. Nesta task só processa promoções de **linha** por ordem de chegada (FIFO), sem olhar pagamento nem horário.

- [ ] **Step 1: Escrever o teste que falha `tests/Rodada/ServicoRegrasRodadaTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\AcaoPromover;
use RcInfoti\Pelada\Rodada\EstadoRodada;
use RcInfoti\Pelada\Rodada\Inscricao;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Rodada\StatusInscricao;
use RcInfoti\Pelada\Rodada\Tipo;

final class ServicoRegrasRodadaTest extends TestCase
{
    private function servico(): ServicoRegrasRodada
    {
        return new ServicoRegrasRodada();
    }

    public function test_promove_por_ordem_de_chegada_antes_da_virada(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00'); // antes da virada
        $estado = new EstadoRodada(
            viraRegraEm: new DateTimeImmutable('2026-01-07 12:00'),
            prazoMultaEm: new DateTimeImmutable('2026-01-08 16:00'),
            limiteLinha: 2,
            limiteGoleiro: 1,
            inscricoes: [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 3, pagou: true),
                new Inscricao(3, Tipo::Linha, StatusInscricao::Espera, 2, pagou: false),
            ],
        );

        // 1 vaga (limite 2, 1 confirmado). FIFO -> menor ordem = jogador 3 (ordem 2),
        // mesmo sem ter pago. O jogador 2 (ordem 3) fica.
        $this->assertEquals([new AcaoPromover(3)], $this->servico()->decidir($estado, $agora));
    }

    public function test_nao_promove_quando_nao_ha_vaga(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            1,
            1,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 2),
            ],
        );

        $this->assertSame([], $this->servico()->decidir($estado, $agora));
    }

    public function test_promove_todos_quando_vagas_sobram(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            5, // muitas vagas
            1,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Espera, 2),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 1),
            ],
        );

        // 5 vagas, 2 na espera -> promove os dois, em ordem de chegada.
        $this->assertEquals(
            [new AcaoPromover(2), new AcaoPromover(1)],
            $this->servico()->decidir($estado, $agora),
        );
    }
}
```

- [ ] **Step 2: Rodar para verificar que falha**

Run: `composer test`
Expected: FAIL — "Class RcInfoti\Pelada\Rodada\ServicoRegrasRodada not found".

- [ ] **Step 3: Implementar `src/Rodada/ServicoRegrasRodada.php` (só linha, FIFO)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class ServicoRegrasRodada
{
    /** @return list<Acao> */
    public function decidir(EstadoRodada $estado, DateTimeImmutable $agora): array
    {
        return $this->promocoes($estado, Tipo::Linha, $agora);
    }

    /** @return list<AcaoPromover> */
    private function promocoes(EstadoRodada $estado, Tipo $tipo, DateTimeImmutable $agora): array
    {
        $limite = $tipo === Tipo::Linha ? $estado->limiteLinha : $estado->limiteGoleiro;

        $confirmados = 0;
        $espera = [];
        foreach ($estado->inscricoes as $inscricao) {
            if ($inscricao->tipo !== $tipo) {
                continue;
            }
            if ($inscricao->status === StatusInscricao::Confirmado) {
                $confirmados++;
            } elseif ($inscricao->status === StatusInscricao::Espera) {
                $espera[] = $inscricao;
            }
        }

        $vagas = $limite - $confirmados;
        if ($vagas <= 0) {
            return [];
        }

        usort($espera, static fn (Inscricao $a, Inscricao $b): int => $a->ordem <=> $b->ordem);

        $acoes = [];
        foreach (array_slice($espera, 0, $vagas) as $inscricao) {
            $acoes[] = new AcaoPromover($inscricao->jogadorId);
        }

        return $acoes;
    }
}
```

- [ ] **Step 4: Rodar para verificar que passa**

Run: `composer test`
Expected: PASS — os três testes de promoção FIFO verdes.

- [ ] **Step 5: Commit**

```bash
git add src/Rodada/ServicoRegrasRodada.php tests/Rodada/ServicoRegrasRodadaTest.php
git commit -m "feat(rodada): promocao FIFO da lista de espera (linha)"
```

---

### Task 4: Janela "pago primeiro" a partir da virada

**Files:**
- Modify: `src/Rodada/ServicoRegrasRodada.php` (método `promocoes`)
- Test: `tests/Rodada/ServicoRegrasRodadaTest.php` (novos testes)

**Interfaces:**
- Consumes: idem Task 3.
- Produces: comportamento de `promocoes` passa a depender de `$agora`: se `$agora < $estado->viraRegraEm` → FIFO (todos da espera); senão → apenas `pagou == true`, mantendo a ordem. Assinatura de `decidir` inalterada.

- [ ] **Step 1: Escrever os testes que falham (adicionar à classe existente)**

```php
    public function test_apos_virada_promove_apenas_quem_pagou(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 13:00'); // após a virada
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            2,
            1,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 2, pagou: false),
                new Inscricao(3, Tipo::Linha, StatusInscricao::Espera, 3, pagou: true),
            ],
        );

        // 1 vaga. Ignora jogador 2 (não pagou) mesmo tendo ordem menor;
        // promove jogador 3 (pagou).
        $this->assertEquals([new AcaoPromover(3)], $this->servico()->decidir($estado, $agora));
    }

    public function test_no_instante_exato_da_virada_ja_vale_pago_primeiro(): void
    {
        $vira = new DateTimeImmutable('2026-01-07 12:00');
        $estado = new EstadoRodada(
            $vira,
            new DateTimeImmutable('2026-01-08 16:00'),
            2,
            1,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 2, pagou: false),
            ],
        );

        // agora == viraRegraEm -> já é pago primeiro; ninguém pagou -> nada.
        $this->assertSame([], $this->servico()->decidir($estado, $vira));
    }

    public function test_pago_primeiro_respeita_ordem_entre_quem_pagou(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 13:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            2,
            1,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 5, pagou: true),
                new Inscricao(3, Tipo::Linha, StatusInscricao::Espera, 4, pagou: true),
            ],
        );

        // 1 vaga; entre os que pagaram, menor ordem = jogador 3 (ordem 4).
        $this->assertEquals([new AcaoPromover(3)], $this->servico()->decidir($estado, $agora));
    }
```

- [ ] **Step 2: Rodar para verificar que falha**

Run: `composer test`
Expected: FAIL — `test_apos_virada_promove_apenas_quem_pagou` promove jogador 2 (código atual é FIFO puro) em vez de jogador 3.

- [ ] **Step 3: Modificar `promocoes` para respeitar a janela**

Substitua o bloco `usort(...)` + `$acoes` do método `promocoes` por:

```php
        usort($espera, static fn (Inscricao $a, Inscricao $b): int => $a->ordem <=> $b->ordem);

        $antesDaVirada = $agora < $estado->viraRegraEm;
        $candidatos = $antesDaVirada
            ? $espera
            : array_values(array_filter($espera, static fn (Inscricao $i): bool => $i->pagou));

        $acoes = [];
        foreach (array_slice($candidatos, 0, $vagas) as $inscricao) {
            $acoes[] = new AcaoPromover($inscricao->jogadorId);
        }

        return $acoes;
```

- [ ] **Step 4: Rodar para verificar que passa**

Run: `composer test`
Expected: PASS — todos os testes (FIFO + pago primeiro) verdes.

- [ ] **Step 5: Commit**

```bash
git add src/Rodada/ServicoRegrasRodada.php tests/Rodada/ServicoRegrasRodadaTest.php
git commit -m "feat(rodada): janela pago-primeiro a partir da virada"
```

---

### Task 5: Separação independente linha × goleiro

**Files:**
- Modify: `src/Rodada/ServicoRegrasRodada.php` (método `decidir`)
- Test: `tests/Rodada/ServicoRegrasRodadaTest.php` (novos testes)

**Interfaces:**
- Consumes: idem.
- Produces: `decidir` passa a processar **os dois tipos** (`Tipo::Linha` e depois `Tipo::Goleiro`), concatenando as promoções — cada tipo com seu limite e sua lista de espera. Ordem do resultado: promoções de linha primeiro, depois de goleiro.

- [ ] **Step 1: Escrever os testes que falham (adicionar à classe)**

```php
    public function test_listas_de_linha_e_goleiro_sao_independentes(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            limiteLinha: 1,   // linha cheia
            limiteGoleiro: 1, // goleiro com vaga
            inscricoes: [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Confirmado, 1),
                new Inscricao(2, Tipo::Linha, StatusInscricao::Espera, 2),
                new Inscricao(3, Tipo::Goleiro, StatusInscricao::Espera, 1),
            ],
        );

        // Linha cheia -> jogador 2 não sobe. Goleiro com vaga -> promove jogador 3.
        $this->assertEquals([new AcaoPromover(3)], $this->servico()->decidir($estado, $agora));
    }

    public function test_promove_em_ambos_os_tipos_linha_antes_de_goleiro(): void
    {
        $agora = new DateTimeImmutable('2026-01-07 10:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            limiteLinha: 1,
            limiteGoleiro: 1,
            inscricoes: [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Espera, 1),
                new Inscricao(2, Tipo::Goleiro, StatusInscricao::Espera, 1),
            ],
        );

        // Uma vaga em cada tipo; resultado traz linha primeiro, depois goleiro.
        $this->assertEquals(
            [new AcaoPromover(1), new AcaoPromover(2)],
            $this->servico()->decidir($estado, $agora),
        );
    }
```

- [ ] **Step 2: Rodar para verificar que falha**

Run: `composer test`
Expected: FAIL — `test_listas_de_linha_e_goleiro_sao_independentes` retorna `[]` (código atual só processa linha; goleiro é ignorado).

- [ ] **Step 3: Modificar `decidir` para percorrer os dois tipos**

```php
    /** @return list<Acao> */
    public function decidir(EstadoRodada $estado, DateTimeImmutable $agora): array
    {
        $acoes = [];
        foreach ([Tipo::Linha, Tipo::Goleiro] as $tipo) {
            foreach ($this->promocoes($estado, $tipo, $agora) as $acao) {
                $acoes[] = $acao;
            }
        }

        return $acoes;
    }
```

- [ ] **Step 4: Rodar para verificar que passa**

Run: `composer test`
Expected: PASS — promoções de linha e goleiro tratadas independentemente.

- [ ] **Step 5: Commit**

```bash
git add src/Rodada/ServicoRegrasRodada.php tests/Rodada/ServicoRegrasRodadaTest.php
git commit -m "feat(rodada): promocao independente por tipo (linha e goleiro)"
```

---

### Task 6: Multas por desistência após o prazo (idempotente)

**Files:**
- Modify: `src/Rodada/ServicoRegrasRodada.php` (adicionar `multas` e chamá-lo em `decidir`)
- Test: `tests/Rodada/ServicoRegrasRodadaTest.php` (novos testes)

**Interfaces:**
- Consumes: `AcaoAplicarMulta` da Task 2.
- Produces: `decidir` passa a acrescentar, após as promoções, uma `AcaoAplicarMulta($jogadorId)` para cada inscrição com `status == Desistiu`, `desistiuEm !== null`, `desistiuEm >= prazoMultaEm` e `multaAplicada == false`.

- [ ] **Step 1: Escrever os testes que falham (adicionar à classe)**

```php
    public function test_aplica_multa_para_desistencia_apos_prazo(): void
    {
        $agora = new DateTimeImmutable('2026-01-08 18:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            prazoMultaEm: new DateTimeImmutable('2026-01-08 16:00'),
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

        $this->assertEquals([new AcaoAplicarMulta(1)], $this->servico()->decidir($estado, $agora));
    }

    public function test_nao_aplica_multa_para_desistencia_antes_do_prazo(): void
    {
        $agora = new DateTimeImmutable('2026-01-08 12:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            20,
            4,
            [
                new Inscricao(
                    1,
                    Tipo::Linha,
                    StatusInscricao::Desistiu,
                    1,
                    desistiuEm: new DateTimeImmutable('2026-01-08 10:00'),
                ),
            ],
        );

        $this->assertSame([], $this->servico()->decidir($estado, $agora));
    }

    public function test_multa_no_instante_exato_do_prazo(): void
    {
        $prazo = new DateTimeImmutable('2026-01-08 16:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            $prazo,
            20,
            4,
            [
                new Inscricao(1, Tipo::Linha, StatusInscricao::Desistiu, 1, desistiuEm: $prazo),
            ],
        );

        // desistiuEm == prazoMultaEm -> multa (limite inclusivo).
        $this->assertEquals(
            [new AcaoAplicarMulta(1)],
            $this->servico()->decidir($estado, new DateTimeImmutable('2026-01-08 16:30')),
        );
    }

    public function test_nao_reaplica_multa_ja_aplicada(): void
    {
        $agora = new DateTimeImmutable('2026-01-08 18:00');
        $estado = new EstadoRodada(
            new DateTimeImmutable('2026-01-07 12:00'),
            new DateTimeImmutable('2026-01-08 16:00'),
            20,
            4,
            [
                new Inscricao(
                    1,
                    Tipo::Linha,
                    StatusInscricao::Desistiu,
                    1,
                    desistiuEm: new DateTimeImmutable('2026-01-08 17:00'),
                    multaAplicada: true,
                ),
            ],
        );

        $this->assertSame([], $this->servico()->decidir($estado, $agora));
    }
```

- [ ] **Step 2: Rodar para verificar que falha**

Run: `composer test`
Expected: FAIL — `test_aplica_multa_para_desistencia_apos_prazo` retorna `[]` (multas ainda não implementadas).

- [ ] **Step 3: Implementar as multas**

No `decidir`, após o loop de promoções e antes do `return`, acrescente:

```php
        foreach ($this->multas($estado) as $acao) {
            $acoes[] = $acao;
        }
```

E adicione o método privado:

```php
    /** @return list<AcaoAplicarMulta> */
    private function multas(EstadoRodada $estado): array
    {
        $acoes = [];
        foreach ($estado->inscricoes as $inscricao) {
            if ($inscricao->status !== StatusInscricao::Desistiu) {
                continue;
            }
            if ($inscricao->desistiuEm === null || $inscricao->multaAplicada) {
                continue;
            }
            if ($inscricao->desistiuEm >= $estado->prazoMultaEm) {
                $acoes[] = new AcaoAplicarMulta($inscricao->jogadorId);
            }
        }

        return $acoes;
    }
```

- [ ] **Step 4: Rodar para verificar que passa**

Run: `composer test`
Expected: PASS — toda a suíte verde (promoções + multas + idempotência).

- [ ] **Step 5: Commit**

```bash
git add src/Rodada/ServicoRegrasRodada.php tests/Rodada/ServicoRegrasRodadaTest.php
git commit -m "feat(rodada): multa por desistencia apos prazo (idempotente)"
```

---

## Notas para os próximos planos

- O `ServicoRegrasRodada` é puro e não conhece persistência. No **Plano 2** (schema + PDO), um repositório monta `EstadoRodada` a partir do banco e aplica as `Acao` retornadas (promover = `inscricoes.status → confirmado`; multa = insere `multas` + incrementa `saldo_pendente`, marcando `multaAplicada`).
- A idempotência do cron (§6 da spec) sai de graça: promoções são recalculadas a partir dos confirmados atuais e multas checam `multaAplicada`. Rodar `decidir` de novo sobre o estado já atualizado não duplica ações.
- Confirmar no Plano 2 se o "agora" do sistema virá de um relógio injetável (`ClockInterface`) para manter os pontos de entrada (cron e páginas) testáveis.
