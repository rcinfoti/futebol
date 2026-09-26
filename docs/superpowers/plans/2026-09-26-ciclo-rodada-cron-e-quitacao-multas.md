# Automação do Ciclo da Rodada (cron) e Quitação de Multas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Amarrar o núcleo puro dos Planos 1–2 ao tempo real: um cron que cria a próxima rodada quando chega a abertura, aplica promoções e multas (idempotente), envia e-mail de multa e fecha rodadas passadas — mais a quitação de multa (marca `paga` + reduz `saldo_pendente`), que fecha a lacuna do financeiro.

**Architecture:** O cálculo das datas da rodada a partir da agenda da pelada é domínio puro (`CalendarioRodada`, determinístico com `$agora` como parâmetro). A orquestração vive em `CicloRodada`, que compõe peças testáveis: `RepositorioCicloRodadaPdo` (criar rodada idempotente / fechar vencidas), o `ProcessadorRodada` já existente (promoções + multas), e `NotificadorMulta` (e-mail via porta `EnviadorEmail`, idempotente por flag). A quitação fica em `RepositorioMultaPdo`. Um script fino `cron/processar.php` faz o wiring com PDO real e `mail()` — a única fronteira não testada, como o `config` do Plano 2. Idempotência em toda parte: o cron roda a cada poucos minutos e as páginas reavaliam as mesmas regras (rede de segurança, §6), então nada pode duplicar rodada, promoção, multa ou e-mail.

**Tech Stack:** PHP 8.1+, PDO (pdo_mysql em produção, pdo_sqlite nos testes), PHPUnit 10, `mail()` do cPanel via porta injetável. Sem framework, sem dependência de runtime nova.

**Spec:** `docs/superpowers/specs/2026-09-26-gestao-pelada-design.md` (§3.3 ciclo semanal, §3.4 multas/pendências, §6 automação cron+e-mail, §4 modelo de dados).

## Global Constraints

- **PHP:** `>=8.1`. Namespaces: `RcInfoti\Pelada\Rodada\`, `RcInfoti\Pelada\Multa\`, `RcInfoti\Pelada\Infra\`, `RcInfoti\Pelada\Email\` → `src/` (PSR-4). Testes: `RcInfoti\Pelada\Tests\` → `tests/`.
- **Não alterar contratos dos Planos 1–3:** `ServicoRegrasRodada::decidir`, `ProcessadorRodada::processar`, `RepositorioRodada`(porta)/`RepositorioRodadaPdo`, `EstadoRodada`, as `Acao`, e as classes de `Financeiro/` permanecem como estão. Este plano só **acrescenta**.
- **Dia da semana = ISO-8601 (`date('N')`): 1=segunda … 7=domingo.** As colunas `*_dia` de `peladas` guardam esse número; as `*_hora` guardam `'HH:MM:SS'`. Todo cálculo de data usa essa convenção.
- **Determinismo:** nenhum `now()`/`time()`/`date()` implícito dentro de domínio, repositório ou orquestrador — o instante entra sempre como `DateTimeImmutable $agora`. Só o `cron/processar.php` (glue) materializa o "agora" real.
- **SQL portável entre MySQL e SQLite:** placeholders `?`; **não** usar `GREATEST`/`LEAST` (ausentes no SQLite) nem `MAX()`/`MIN()` com 2 argumentos (é agregação no MySQL) — quando precisar de clamp (`não abaixo de 0`), ler o valor e calcular em PHP com `max()`. Comparar datas como strings ISO (`'Y-m-d'`, `'Y-m-d H:i:s'`). Escrita que muta mais de uma tabela roda em transação.
- **Idempotência (não-negociável):** criar rodada é guardado por `UNIQUE(pelada_id, data_jogo)` + verificação prévia; e-mail de multa por `multas.email_enviado`; quitação por `UPDATE ... WHERE status='pendente'` guardado; promoções/multas já são idempotentes (Planos 1–2).
- **E-mail injetável:** toda lógica de notificação depende da porta `EnviadorEmail`; os testes usam um fake que registra envios. `mail()` real só aparece na implementação `EnviadorEmailMail`, não testada.
- **Dinheiro:** `float` 2 casas (como Planos 2–3). `saldo_pendente` nunca fica negativo.
- **Deploy:** migrations em `migrations/003_ciclo_e_multas.sql` (MySQL), aplicadas manualmente no cPanel; o Cron Job do cPanel aponta para `cron/processar.php` a cada 5–10 min (documentado, não automatizado aqui).

## Review Focus

- **Criar rodada é idempotente:** rodar o ciclo duas vezes na mesma janela **não** cria duas rodadas para a mesma `data_jogo` (verificação + `UNIQUE`). — coberto na Task 3 (`test_criar_e_idempotente`).
- **Antes da abertura não cria nada:** com `$agora` anterior a `abre_em`, `criarRodadaSeAberta` devolve `null` e não insere linha. — coberto na Task 3 (`test_nao_cria_antes_da_abertura`).
- **E-mail de multa não é reenviado:** a segunda passada do `NotificadorMulta` não reenvia (flag `email_enviado=1`); multa sem e-mail do jogador é pulada e tentada depois. — coberto na Task 5 (`test_nao_reenvia`, `test_pula_sem_email`).
- **Quitação idempotente e sem saldo negativo:** quitar a mesma multa duas vezes é no-op; `saldo_pendente` nunca fica abaixo de 0 mesmo se a multa for maior que o saldo. — coberto na Task 6 (`test_quitar_e_idempotente`, `test_saldo_nao_fica_negativo`).
- **Virada de semana no calendário:** `$agora` no instante exato do jogo → a rodada calculada é a de hoje; `$agora` um minuto após o jogo → é a da semana seguinte. — coberto na Task 2 (`test_no_instante_do_jogo_e_hoje`, `test_apos_o_jogo_vai_para_semana_seguinte`).

---

### Task 1: Migration 003 (UNIQUE rodada + `multas.email_enviado`) + espelho SQLite

**Files:**
- Create: `migrations/003_ciclo_e_multas.sql`
- Modify: `tests/Infra/SchemaSqlite.php` (UNIQUE em `rodadas`; coluna `email_enviado` em `multas`)
- Modify: `tests/Infra/SchemaSqliteTest.php` (asserção das mudanças)

**Interfaces:**
- Consumes: tabelas `rodadas` e `multas` (Plano 2).
- Produces: `rodadas` com `UNIQUE(pelada_id, data_jogo)`; `multas` com coluna `email_enviado` (0/1, default 0). Ambas refletidas no espelho SQLite.

- [ ] **Step 1: Escrever a asserção que falha em `tests/Infra/SchemaSqliteTest.php`** (acrescentar à classe)

```php
    public function test_multas_tem_flag_email_enviado(): void
    {
        $pdo = new PDO('sqlite::memory:');
        SchemaSqlite::criar($pdo);

        $colunas = $pdo->query('PRAGMA table_info(multas)')->fetchAll(PDO::FETCH_ASSOC);
        $nomes = array_column($colunas, 'name');
        $this->assertContains('email_enviado', $nomes, 'faltou multas.email_enviado');
    }

    public function test_rodadas_nao_aceita_data_jogo_duplicada_por_pelada(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($pdo);

        $pdo->exec("INSERT INTO rodadas (pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO rodadas (pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
    }
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit --filter 'test_multas_tem_flag_email_enviado|test_rodadas_nao_aceita_data_jogo_duplicada_por_pelada'`
Expected: FAIL — coluna ausente e segundo INSERT não lança exceção.

- [ ] **Step 3: Atualizar o espelho SQLite em `tests/Infra/SchemaSqlite.php`**

Na criação de `rodadas`, acrescentar a restrição ao final das colunas (antes do `)`):

```php
            prazo_multa_em TEXT NOT NULL,
            UNIQUE (pelada_id, data_jogo)
```

Na criação de `multas`, acrescentar a coluna antes do `UNIQUE (rodada_id, jogador_id)`:

```php
            quitado_em TEXT,
            email_enviado INTEGER NOT NULL DEFAULT 0,
```

- [ ] **Step 4: Criar `migrations/003_ciclo_e_multas.sql` (MySQL)**

```sql
-- Ciclo da rodada + notificação/quitação de multas (MySQL 8 / cPanel).
ALTER TABLE rodadas
    ADD UNIQUE KEY uk_rodada_pelada_data (pelada_id, data_jogo);

ALTER TABLE multas
    ADD COLUMN email_enviado TINYINT(1) NOT NULL DEFAULT 0 AFTER quitado_em;
```

- [ ] **Step 5: Rodar e ver passar**

Run: `./vendor/bin/phpunit --filter 'test_multas_tem_flag_email_enviado|test_rodadas_nao_aceita_data_jogo_duplicada_por_pelada'`
Expected: PASS.

- [ ] **Step 6: Rodar a suíte inteira (nada quebrou)**

Run: `composer test`
Expected: PASS (todos os testes anteriores + os novos).

- [ ] **Step 7: Commit**

```bash
git add migrations/003_ciclo_e_multas.sql tests/Infra/SchemaSqlite.php tests/Infra/SchemaSqliteTest.php
git commit -m "feat(ciclo): UNIQUE(pelada,data_jogo) em rodadas + multas.email_enviado"
```

---

### Task 2: Cálculo das datas da rodada (`AgendaPelada`, `DatasRodada`, `CalendarioRodada` — domínio puro)

**Files:**
- Create: `src/Rodada/AgendaPelada.php`
- Create: `src/Rodada/DatasRodada.php`
- Create: `src/Rodada/CalendarioRodada.php`
- Test: `tests/Rodada/CalendarioRodadaTest.php`

**Interfaces:**
- Consumes: nada (PHP puro).
- Produces:
  - `final class AgendaPelada` (readonly VO): `__construct(int $diaJogo, string $horaJogo, int $abreDia, string $abreHora, int $viraRegraDia, string $viraRegraHora, int $prazoMultaDia, string $prazoMultaHora)`; `public static function deArray(array $r): self` — monta a partir de uma linha de `peladas` (`dia_jogo`, `hora_jogo`, `abre_dia`, `abre_hora`, `vira_regra_dia`, `vira_regra_hora`, `prazo_multa_dia`, `prazo_multa_hora`).
  - `final class DatasRodada` (readonly VO): `__construct(DateTimeImmutable $dataJogo, DateTimeImmutable $abreEm, DateTimeImmutable $viraRegraEm, DateTimeImmutable $prazoMultaEm)`.
  - `final class CalendarioRodada`: `proximaRodada(AgendaPelada $agenda, DateTimeImmutable $agora): DatasRodada` — devolve as datas do próximo jogo cujo horário é `>= $agora`, com `abreEm`/`viraRegraEm`/`prazoMultaEm` recuados para os dias/horas da agenda dentro da janela de 7 dias que termina no jogo.

- [ ] **Step 1: Escrever o teste que falha `tests/Rodada/CalendarioRodadaTest.php`**

Cenário-base = exemplo da spec §3.3: jogo **quinta (N=4) 20:00**, abre **domingo (N=7) 08:00**, vira **quarta (N=3) 12:00**, prazo **quinta (N=4) 16:00**.

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\AgendaPelada;
use RcInfoti\Pelada\Rodada\CalendarioRodada;

final class CalendarioRodadaTest extends TestCase
{
    private function agenda(): AgendaPelada
    {
        // jogo quinta 20:00; abre domingo 08:00; vira quarta 12:00; prazo quinta 16:00
        return new AgendaPelada(4, '20:00:00', 7, '08:00:00', 3, '12:00:00', 4, '16:00:00');
    }

    public function test_calcula_datas_da_semana_do_jogo(): void
    {
        // segunda-feira 2026-01-05 09:00 → jogo da quinta 2026-01-08
        $datas = (new CalendarioRodada())->proximaRodada($this->agenda(), new DateTimeImmutable('2026-01-05 09:00:00'));

        $this->assertEquals(new DateTimeImmutable('2026-01-08 20:00:00'), $datas->dataJogo);
        $this->assertEquals(new DateTimeImmutable('2026-01-04 08:00:00'), $datas->abreEm);       // domingo antes
        $this->assertEquals(new DateTimeImmutable('2026-01-07 12:00:00'), $datas->viraRegraEm);  // quarta antes
        $this->assertEquals(new DateTimeImmutable('2026-01-08 16:00:00'), $datas->prazoMultaEm); // quinta, antes do jogo
    }

    public function test_no_instante_do_jogo_e_hoje(): void
    {
        // exatamente 2026-01-08 20:00 (quinta) → ainda é o jogo de hoje
        $datas = (new CalendarioRodada())->proximaRodada($this->agenda(), new DateTimeImmutable('2026-01-08 20:00:00'));

        $this->assertEquals(new DateTimeImmutable('2026-01-08 20:00:00'), $datas->dataJogo);
    }

    public function test_apos_o_jogo_vai_para_semana_seguinte(): void
    {
        // 2026-01-08 20:01 (um minuto após o jogo) → próxima quinta 2026-01-15
        $datas = (new CalendarioRodada())->proximaRodada($this->agenda(), new DateTimeImmutable('2026-01-08 20:01:00'));

        $this->assertEquals(new DateTimeImmutable('2026-01-15 20:00:00'), $datas->dataJogo);
        $this->assertEquals(new DateTimeImmutable('2026-01-11 08:00:00'), $datas->abreEm); // domingo 2026-01-11
    }

    public function test_deArray_mapeia_colunas_da_pelada(): void
    {
        $agenda = AgendaPelada::deArray([
            'dia_jogo' => 4, 'hora_jogo' => '20:00:00',
            'abre_dia' => 7, 'abre_hora' => '08:00:00',
            'vira_regra_dia' => 3, 'vira_regra_hora' => '12:00:00',
            'prazo_multa_dia' => 4, 'prazo_multa_hora' => '16:00:00',
        ]);

        $datas = (new CalendarioRodada())->proximaRodada($agenda, new DateTimeImmutable('2026-01-05 09:00:00'));
        $this->assertEquals(new DateTimeImmutable('2026-01-08 20:00:00'), $datas->dataJogo);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Rodada/CalendarioRodadaTest.php`
Expected: FAIL — classes não existem.

- [ ] **Step 3: Criar o VO `src/Rodada/AgendaPelada.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

final class AgendaPelada
{
    public function __construct(
        public readonly int $diaJogo,
        public readonly string $horaJogo,
        public readonly int $abreDia,
        public readonly string $abreHora,
        public readonly int $viraRegraDia,
        public readonly string $viraRegraHora,
        public readonly int $prazoMultaDia,
        public readonly string $prazoMultaHora,
    ) {
    }

    /** @param array<string,mixed> $r linha de `peladas` */
    public static function deArray(array $r): self
    {
        return new self(
            (int) $r['dia_jogo'],
            (string) $r['hora_jogo'],
            (int) $r['abre_dia'],
            (string) $r['abre_hora'],
            (int) $r['vira_regra_dia'],
            (string) $r['vira_regra_hora'],
            (int) $r['prazo_multa_dia'],
            (string) $r['prazo_multa_hora'],
        );
    }
}
```

- [ ] **Step 4: Criar o VO `src/Rodada/DatasRodada.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class DatasRodada
{
    public function __construct(
        public readonly DateTimeImmutable $dataJogo,
        public readonly DateTimeImmutable $abreEm,
        public readonly DateTimeImmutable $viraRegraEm,
        public readonly DateTimeImmutable $prazoMultaEm,
    ) {
    }
}
```

- [ ] **Step 5: Criar `src/Rodada/CalendarioRodada.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class CalendarioRodada
{
    public function proximaRodada(AgendaPelada $agenda, DateTimeImmutable $agora): DatasRodada
    {
        $jogo = $this->proximoDiaHora($agenda->diaJogo, $agenda->horaJogo, $agora);

        return new DatasRodada(
            $jogo,
            $this->recuarAte($jogo, $agenda->abreDia, $agenda->abreHora),
            $this->recuarAte($jogo, $agenda->viraRegraDia, $agenda->viraRegraHora),
            $this->recuarAte($jogo, $agenda->prazoMultaDia, $agenda->prazoMultaHora),
        );
    }

    /** Próximo datetime cujo dia-da-semana = $dia e hora = $hora, com valor >= $agora. */
    private function proximoDiaHora(int $dia, string $hora, DateTimeImmutable $agora): DateTimeImmutable
    {
        $hoje = $agora->setTime(0, 0, 0);
        $wd = (int) $agora->format('N');
        $delta = ($dia - $wd + 7) % 7;

        $cand = $this->comHora($hoje->modify("+{$delta} days"), $hora);
        if ($cand < $agora) {
            $cand = $this->comHora($hoje->modify('+' . ($delta + 7) . ' days'), $hora);
        }

        return $cand;
    }

    /** Recua do jogo até o dia-da-semana $dia (na janela de 7 dias que termina no jogo). */
    private function recuarAte(DateTimeImmutable $jogo, int $dia, string $hora): DateTimeImmutable
    {
        $wdJogo = (int) $jogo->format('N');
        $delta = ($wdJogo - $dia + 7) % 7;

        return $this->comHora($jogo->modify("-{$delta} days"), $hora);
    }

    private function comHora(DateTimeImmutable $d, string $hora): DateTimeImmutable
    {
        [$h, $m, $s] = array_pad(explode(':', $hora), 3, '0');

        return $d->setTime((int) $h, (int) $m, (int) $s);
    }
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Rodada/CalendarioRodadaTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Rodada/AgendaPelada.php src/Rodada/DatasRodada.php src/Rodada/CalendarioRodada.php tests/Rodada/CalendarioRodadaTest.php
git commit -m "feat(ciclo): CalendarioRodada calcula datas da rodada a partir da agenda (dominio puro)"
```

---

### Task 3: Criar a próxima rodada quando a abertura chegou (idempotente)

**Files:**
- Create: `src/Rodada/RepositorioCicloRodadaPdo.php`
- Test: `tests/Rodada/RepositorioCicloRodadaPdoTest.php`

**Interfaces:**
- Consumes: `CalendarioRodada`, `AgendaPelada` (Task 2); tabela `rodadas` com `UNIQUE(pelada_id, data_jogo)` (Task 1); `SchemaSqlite`.
- Produces: `final class RepositorioCicloRodadaPdo`: `__construct(PDO $pdo, CalendarioRodada $calendario)`; `criarRodadaSeAberta(int $peladaId, AgendaPelada $agenda, DateTimeImmutable $agora): ?int` — se já existe rodada para `(peladaId, data_jogo)`, devolve seu `id`; senão, se `abreEm <= $agora`, insere (`status='aberta'`) e devolve o novo `id`; senão devolve `null`. (`fecharRodadasVencidas` chega na Task 4.)

- [ ] **Step 1: Escrever o teste que falha `tests/Rodada/RepositorioCicloRodadaPdoTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Rodada\AgendaPelada;
use RcInfoti\Pelada\Rodada\CalendarioRodada;
use RcInfoti\Pelada\Rodada\RepositorioCicloRodadaPdo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioCicloRodadaPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
    }

    private function agenda(): AgendaPelada
    {
        return new AgendaPelada(4, '20:00:00', 7, '08:00:00', 3, '12:00:00', 4, '16:00:00');
    }

    private function repo(): RepositorioCicloRodadaPdo
    {
        return new RepositorioCicloRodadaPdo($this->pdo, new CalendarioRodada());
    }

    public function test_cria_rodada_quando_abertura_ja_passou(): void
    {
        // segunda 2026-01-05 09:00: abertura (domingo 2026-01-04 08:00) já passou
        $id = $this->repo()->criarRodadaSeAberta(1, $this->agenda(), new DateTimeImmutable('2026-01-05 09:00:00'));

        $this->assertNotNull($id);
        $row = $this->pdo->query('SELECT * FROM rodadas WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2026-01-08', $row['data_jogo']);
        $this->assertSame('aberta', $row['status']);
        $this->assertSame('2026-01-04 08:00:00', $row['abre_em']);
        $this->assertSame('2026-01-07 12:00:00', $row['vira_regra_em']);
        $this->assertSame('2026-01-08 16:00:00', $row['prazo_multa_em']);
    }

    public function test_nao_cria_antes_da_abertura(): void
    {
        // sábado 2026-01-03 09:00: abertura é domingo 2026-01-04 08:00 → ainda não abriu
        $id = $this->repo()->criarRodadaSeAberta(1, $this->agenda(), new DateTimeImmutable('2026-01-03 09:00:00'));

        $this->assertNull($id);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rodadas')->fetchColumn());
    }

    public function test_criar_e_idempotente(): void
    {
        $repo = $this->repo();
        $agora = new DateTimeImmutable('2026-01-05 09:00:00');

        $id1 = $repo->criarRodadaSeAberta(1, $this->agenda(), $agora);
        $id2 = $repo->criarRodadaSeAberta(1, $this->agenda(), $agora->modify('+10 minutes'));

        $this->assertSame($id1, $id2);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM rodadas')->fetchColumn());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Rodada/RepositorioCicloRodadaPdoTest.php`
Expected: FAIL — classe `RepositorioCicloRodadaPdo` não existe.

- [ ] **Step 3: Criar `src/Rodada/RepositorioCicloRodadaPdo.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;
use PDO;

final class RepositorioCicloRodadaPdo
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CalendarioRodada $calendario,
    ) {
    }

    public function criarRodadaSeAberta(int $peladaId, AgendaPelada $agenda, DateTimeImmutable $agora): ?int
    {
        $datas = $this->calendario->proximaRodada($agenda, $agora);
        $dataJogo = $datas->dataJogo->format('Y-m-d');

        $sel = $this->pdo->prepare('SELECT id FROM rodadas WHERE pelada_id = ? AND data_jogo = ?');
        $sel->execute([$peladaId, $dataJogo]);
        $existente = $sel->fetchColumn();
        if ($existente !== false) {
            return (int) $existente;
        }

        if ($datas->abreEm > $agora) {
            return null; // abertura ainda não chegou
        }

        $ins = $this->pdo->prepare(
            "INSERT INTO rodadas (pelada_id, data_jogo, status, abre_em, vira_regra_em, prazo_multa_em)
             VALUES (?, ?, 'aberta', ?, ?, ?)"
        );
        $ins->execute([
            $peladaId,
            $dataJogo,
            $datas->abreEm->format('Y-m-d H:i:s'),
            $datas->viraRegraEm->format('Y-m-d H:i:s'),
            $datas->prazoMultaEm->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Rodada/RepositorioCicloRodadaPdoTest.php`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte inteira**

Run: `composer test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Rodada/RepositorioCicloRodadaPdo.php tests/Rodada/RepositorioCicloRodadaPdoTest.php
git commit -m "feat(ciclo): criar proxima rodada quando a abertura chegou (idempotente)"
```

---

### Task 4: Fechar rodadas passadas

**Files:**
- Modify: `src/Rodada/RepositorioCicloRodadaPdo.php` (acrescentar `fecharRodadasVencidas`)
- Modify: `tests/Rodada/RepositorioCicloRodadaPdoTest.php` (novos testes)

**Interfaces:**
- Consumes: tabela `rodadas`.
- Produces: `RepositorioCicloRodadaPdo::fecharRodadasVencidas(int $peladaId, DateTimeImmutable $agora): int` — muda para `status='fechada'` toda rodada `aberta` da pelada cujo `data_jogo` é anterior ao dia de `$agora`; devolve quantas foram fechadas. Não mexe em rodadas já `fechada`/`encerrada` nem na do dia.

- [ ] **Step 1: Escrever os testes que falham (acrescentar à classe existente)**

```php
    private function inserirRodada(string $dataJogo, string $status): int
    {
        $this->pdo->prepare(
            "INSERT INTO rodadas (pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
             VALUES (1, ?, ?, ?, ?)"
        )->execute([$dataJogo, $status, $dataJogo . ' 12:00:00', $dataJogo . ' 16:00:00']);

        return (int) $this->pdo->lastInsertId();
    }

    public function test_fecha_rodada_com_jogo_no_passado(): void
    {
        $antiga = $this->inserirRodada('2026-01-01', 'aberta');

        $fechadas = $this->repo()->fecharRodadasVencidas(1, new DateTimeImmutable('2026-01-05 09:00:00'));

        $this->assertSame(1, $fechadas);
        $status = $this->pdo->query('SELECT status FROM rodadas WHERE id = ' . $antiga)->fetchColumn();
        $this->assertSame('fechada', $status);
    }

    public function test_nao_fecha_rodada_de_hoje_nem_futura(): void
    {
        $hoje = $this->inserirRodada('2026-01-05', 'aberta');
        $futura = $this->inserirRodada('2026-01-12', 'aberta');

        $fechadas = $this->repo()->fecharRodadasVencidas(1, new DateTimeImmutable('2026-01-05 09:00:00'));

        $this->assertSame(0, $fechadas);
        $this->assertSame('aberta', $this->pdo->query('SELECT status FROM rodadas WHERE id = ' . $hoje)->fetchColumn());
        $this->assertSame('aberta', $this->pdo->query('SELECT status FROM rodadas WHERE id = ' . $futura)->fetchColumn());
    }
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Rodada/RepositorioCicloRodadaPdoTest.php`
Expected: FAIL — método `fecharRodadasVencidas` não existe.

- [ ] **Step 3: Implementar `fecharRodadasVencidas` em `RepositorioCicloRodadaPdo`**

Acrescentar (após `criarRodadaSeAberta`):

```php
    public function fecharRodadasVencidas(int $peladaId, DateTimeImmutable $agora): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE rodadas SET status = 'fechada'
             WHERE pelada_id = ? AND status = 'aberta' AND data_jogo < ?"
        );
        $stmt->execute([$peladaId, $agora->format('Y-m-d')]);

        return $stmt->rowCount();
    }
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Rodada/RepositorioCicloRodadaPdoTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Rodada/RepositorioCicloRodadaPdo.php tests/Rodada/RepositorioCicloRodadaPdoTest.php
git commit -m "feat(ciclo): fechar rodadas com jogo no passado"
```

---

### Task 5: E-mail de multa — porta `EnviadorEmail` + `NotificadorMulta` (idempotente)

**Files:**
- Create: `src/Email/EnviadorEmail.php`
- Create: `tests/Email/EnviadorEmailFake.php`
- Create: `src/Multa/NotificadorMulta.php`
- Test: `tests/Multa/NotificadorMultaTest.php`

**Interfaces:**
- Consumes: tabelas `multas` (com `email_enviado`, Task 1) e `jogadores`; `SchemaSqlite`.
- Produces:
  - `interface EnviadorEmail`: `enviar(string $para, string $assunto, string $corpo): void`.
  - `final class EnviadorEmailFake implements EnviadorEmail` (utilitário de teste): guarda cada envio em `public array $enviados` (`['para'=>..., 'assunto'=>..., 'corpo'=>...]`).
  - `final class NotificadorMulta`: `__construct(PDO $pdo, EnviadorEmail $email)`; `notificarPendentes(int $peladaId, DateTimeImmutable $agora): int` — para cada multa da pelada com `email_enviado=0` cujo jogador tem e-mail, envia e marca `email_enviado=1`; devolve o número de e-mails enviados. Multa cujo jogador não tem e-mail é pulada (permanece `0`, para tentar depois).

- [ ] **Step 1: Escrever o teste que falha `tests/Multa/NotificadorMultaTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Multa;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Multa\NotificadorMulta;
use RcInfoti\Pelada\Tests\Email\EnviadorEmailFake;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class NotificadorMultaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, email, tipo) VALUES
            (10, 1, 'Ana', 'ana@example.com', 'linha'),
            (11, 1, 'Bia', NULL, 'linha')");
    }

    private function inserirMulta(int $id, int $jogadorId, float $valor): void
    {
        $this->pdo->prepare(
            "INSERT INTO multas (id, pelada_id, jogador_id, rodada_id, valor, status, criado_em)
             VALUES (?, 1, ?, 5, ?, 'pendente', '2026-01-08 16:00:00')"
        )->execute([$id, $jogadorId, $valor]);
    }

    public function test_envia_email_e_marca_enviado(): void
    {
        $this->inserirMulta(100, 10, 20.00);
        $fake = new EnviadorEmailFake();

        $enviados = (new NotificadorMulta($this->pdo, $fake))->notificarPendentes(1, new DateTimeImmutable('2026-01-08 16:05:00'));

        $this->assertSame(1, $enviados);
        $this->assertCount(1, $fake->enviados);
        $this->assertSame('ana@example.com', $fake->enviados[0]['para']);
        $this->assertStringContainsString('20', $fake->enviados[0]['corpo']); // valor no corpo
        $this->assertSame(1, (int) $this->pdo->query('SELECT email_enviado FROM multas WHERE id = 100')->fetchColumn());
    }

    public function test_nao_reenvia(): void
    {
        $this->inserirMulta(100, 10, 20.00);
        $fake = new EnviadorEmailFake();
        $notificador = new NotificadorMulta($this->pdo, $fake);

        $notificador->notificarPendentes(1, new DateTimeImmutable('2026-01-08 16:05:00'));
        $segunda = $notificador->notificarPendentes(1, new DateTimeImmutable('2026-01-08 16:10:00'));

        $this->assertSame(0, $segunda);
        $this->assertCount(1, $fake->enviados);
    }

    public function test_pula_sem_email(): void
    {
        $this->inserirMulta(101, 11, 15.00); // Bia não tem e-mail
        $fake = new EnviadorEmailFake();

        $enviados = (new NotificadorMulta($this->pdo, $fake))->notificarPendentes(1, new DateTimeImmutable('2026-01-08 16:05:00'));

        $this->assertSame(0, $enviados);
        $this->assertCount(0, $fake->enviados);
        // permanece 0 para ser tentada quando o e-mail for cadastrado
        $this->assertSame(0, (int) $this->pdo->query('SELECT email_enviado FROM multas WHERE id = 101')->fetchColumn());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Multa/NotificadorMultaTest.php`
Expected: FAIL — `EnviadorEmail`/`EnviadorEmailFake`/`NotificadorMulta` não existem.

- [ ] **Step 3: Criar a porta `src/Email/EnviadorEmail.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Email;

interface EnviadorEmail
{
    public function enviar(string $para, string $assunto, string $corpo): void;
}
```

- [ ] **Step 4: Criar o fake `tests/Email/EnviadorEmailFake.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Email;

use RcInfoti\Pelada\Email\EnviadorEmail;

final class EnviadorEmailFake implements EnviadorEmail
{
    /** @var list<array{para:string,assunto:string,corpo:string}> */
    public array $enviados = [];

    public function enviar(string $para, string $assunto, string $corpo): void
    {
        $this->enviados[] = ['para' => $para, 'assunto' => $assunto, 'corpo' => $corpo];
    }
}
```

- [ ] **Step 5: Criar `src/Multa/NotificadorMulta.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Multa;

use DateTimeImmutable;
use PDO;
use RcInfoti\Pelada\Email\EnviadorEmail;

final class NotificadorMulta
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EnviadorEmail $email,
    ) {
    }

    public function notificarPendentes(int $peladaId, DateTimeImmutable $agora): int
    {
        $sel = $this->pdo->prepare(
            'SELECT m.id, m.valor, j.nome, j.email
             FROM multas m
             JOIN jogadores j ON j.id = m.jogador_id
             WHERE m.pelada_id = ? AND m.email_enviado = 0
             ORDER BY m.id'
        );
        $sel->execute([$peladaId]);

        $marca = $this->pdo->prepare('UPDATE multas SET email_enviado = 1 WHERE id = ?');

        $enviados = 0;
        foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $para = $m['email'];
            if ($para === null || $para === '') {
                continue; // sem e-mail: tenta em outra passada, quando for cadastrado
            }

            $this->email->enviar($para, 'Multa da pelada', $this->corpo((string) $m['nome'], (float) $m['valor']));
            $marca->execute([(int) $m['id']]);
            $enviados++;
        }

        return $enviados;
    }

    private function corpo(string $nome, float $valor): string
    {
        return sprintf(
            "Olá %s,\n\nFoi registrada uma multa de R$ %.2f por desistência após o prazo. "
            . "O valor entra como pendência no seu cadastro até a quitação.\n\nAbraço.",
            $nome,
            $valor,
        );
    }
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Multa/NotificadorMultaTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Email/EnviadorEmail.php tests/Email/EnviadorEmailFake.php src/Multa/NotificadorMulta.php tests/Multa/NotificadorMultaTest.php
git commit -m "feat(multa): notificacao de multa por e-mail (porta EnviadorEmail + NotificadorMulta idempotente)"
```

---

### Task 6: Quitação de multa (marca `paga` + reduz `saldo_pendente`, idempotente)

**Files:**
- Create: `src/Multa/RepositorioMultaPdo.php`
- Test: `tests/Multa/RepositorioMultaPdoTest.php`

**Interfaces:**
- Consumes: tabelas `multas` e `jogadores`; `SchemaSqlite`.
- Produces: `final class RepositorioMultaPdo`: `__construct(PDO $pdo)`; `quitar(int $multaId, DateTimeImmutable $agora): void` — em transação: reivindica a multa com `UPDATE ... SET status='paga', quitado_em=? WHERE id=? AND status='pendente'` (idempotente); se venceu, reduz `jogadores.saldo_pendente` no valor da multa **sem deixar negativo** (clamp em PHP com `max(0.0, ...)`). Multa já paga ou inexistente → no-op.

- [ ] **Step 1: Escrever o teste que falha `tests/Multa/RepositorioMultaPdoTest.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Multa;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Multa\RepositorioMultaPdo;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class RepositorioMultaPdoTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug) VALUES (1, 'Quinta', 'quinta')");
    }

    private function jogadorComSaldo(int $id, float $saldo): void
    {
        $this->pdo->prepare("INSERT INTO jogadores (id, pelada_id, nome, tipo, saldo_pendente) VALUES (?, 1, 'J', 'linha', ?)")
            ->execute([$id, $saldo]);
    }

    private function multaPendente(int $id, int $jogadorId, float $valor): void
    {
        $this->pdo->prepare(
            "INSERT INTO multas (id, pelada_id, jogador_id, rodada_id, valor, status, criado_em)
             VALUES (?, 1, ?, 5, ?, 'pendente', '2026-01-08 16:00:00')"
        )->execute([$id, $jogadorId, $valor]);
    }

    private function repo(): RepositorioMultaPdo
    {
        return new RepositorioMultaPdo($this->pdo);
    }

    public function test_quitar_marca_paga_e_reduz_saldo(): void
    {
        $this->jogadorComSaldo(10, 20.00);
        $this->multaPendente(100, 10, 20.00);

        $this->repo()->quitar(100, new DateTimeImmutable('2026-01-10 09:00:00'));

        $m = $this->pdo->query('SELECT status, quitado_em FROM multas WHERE id = 100')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('paga', $m['status']);
        $this->assertSame('2026-01-10 09:00:00', $m['quitado_em']);
        $this->assertSame(0.0, (float) $this->pdo->query('SELECT saldo_pendente FROM jogadores WHERE id = 10')->fetchColumn());
    }

    public function test_quitar_e_idempotente(): void
    {
        $this->jogadorComSaldo(10, 20.00);
        $this->multaPendente(100, 10, 20.00);
        $repo = $this->repo();

        $repo->quitar(100, new DateTimeImmutable('2026-01-10 09:00:00'));
        $repo->quitar(100, new DateTimeImmutable('2026-01-10 09:05:00')); // segunda vez: no-op

        // saldo não é reduzido duas vezes
        $this->assertSame(0.0, (float) $this->pdo->query('SELECT saldo_pendente FROM jogadores WHERE id = 10')->fetchColumn());
    }

    public function test_saldo_nao_fica_negativo(): void
    {
        $this->jogadorComSaldo(10, 5.00);   // saldo menor que a multa
        $this->multaPendente(100, 10, 20.00);

        $this->repo()->quitar(100, new DateTimeImmutable('2026-01-10 09:00:00'));

        $this->assertSame(0.0, (float) $this->pdo->query('SELECT saldo_pendente FROM jogadores WHERE id = 10')->fetchColumn());
    }

    public function test_quitar_inexistente_e_noop(): void
    {
        $this->repo()->quitar(999, new DateTimeImmutable('2026-01-10 09:00:00'));
        $this->assertTrue(true); // não lança
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Multa/RepositorioMultaPdoTest.php`
Expected: FAIL — classe `RepositorioMultaPdo` não existe.

- [ ] **Step 3: Criar `src/Multa/RepositorioMultaPdo.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Multa;

use DateTimeImmutable;
use PDO;

final class RepositorioMultaPdo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function quitar(int $multaId, DateTimeImmutable $agora): void
    {
        $this->pdo->beginTransaction();
        try {
            // Reivindica a multa de forma atômica; sem linhas afetadas (já paga ou
            // inexistente) => no-op idempotente.
            $marca = $this->pdo->prepare(
                "UPDATE multas SET status = 'paga', quitado_em = ? WHERE id = ? AND status = 'pendente'"
            );
            $marca->execute([$agora->format('Y-m-d H:i:s'), $multaId]);
            if ($marca->rowCount() === 0) {
                $this->pdo->commit();

                return;
            }

            $busca = $this->pdo->prepare('SELECT jogador_id, valor FROM multas WHERE id = ?');
            $busca->execute([$multaId]);
            $m = $busca->fetch(PDO::FETCH_ASSOC);

            // Reduz o saldo pendente sem deixar negativo (clamp em PHP: GREATEST não
            // existe no SQLite e MAX(2 args) é agregação no MySQL).
            $saldoStmt = $this->pdo->prepare('SELECT saldo_pendente FROM jogadores WHERE id = ?');
            $saldoStmt->execute([(int) $m['jogador_id']]);
            $saldoAtual = (float) $saldoStmt->fetchColumn();
            $novo = max(0.0, $saldoAtual - (float) $m['valor']);

            $this->pdo->prepare('UPDATE jogadores SET saldo_pendente = ? WHERE id = ?')
                ->execute([$novo, (int) $m['jogador_id']]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Multa/RepositorioMultaPdoTest.php`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte inteira**

Run: `composer test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Multa/RepositorioMultaPdo.php tests/Multa/RepositorioMultaPdoTest.php
git commit -m "feat(multa): quitar multa (marca paga + reduz saldo_pendente, idempotente)"
```

---

### Task 7: Orquestrador `CicloRodada` + script `cron/processar.php` + `EnviadorEmailMail`

**Files:**
- Create: `src/Rodada/CicloRodada.php`
- Test: `tests/Rodada/CicloRodadaTest.php`
- Create: `src/Email/EnviadorEmailMail.php` (glue não testado — `mail()` do PHP)
- Create: `cron/processar.php` (glue não testado — wiring do cron)

**Interfaces:**
- Consumes: `RepositorioCicloRodadaPdo` (Tasks 3–4), `ProcessadorRodada`+`RepositorioRodadaPdo`+`ServicoRegrasRodada` (Planos 1–2), `NotificadorMulta` (Task 5), `AgendaPelada`/`CalendarioRodada` (Task 2), `EnviadorEmail` (Task 5), `Database` (Plano 2).
- Produces:
  - `final class CicloRodada`: `__construct(RepositorioCicloRodadaPdo $ciclo, ProcessadorRodada $processador, NotificadorMulta $notificador)`; `executar(int $peladaId, AgendaPelada $agenda, DateTimeImmutable $agora): void` — cria a rodada se a abertura chegou; se há rodada, processa promoções/multas; notifica multas pendentes; fecha rodadas vencidas.
  - `final class EnviadorEmailMail implements EnviadorEmail` — usa `mail()`.
  - `cron/processar.php` — carrega config, monta as dependências com PDO real e `EnviadorEmailMail`, e roda `CicloRodada::executar` para cada pelada ativa com `new DateTimeImmutable('now')`.

- [ ] **Step 1: Escrever o teste de integração que falha `tests/Rodada/CicloRodadaTest.php`**

O teste monta tudo com um PDO SQLite e um `EnviadorEmailFake`, cobrindo o ciclo ponta-a-ponta.

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Rodada;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Multa\NotificadorMulta;
use RcInfoti\Pelada\Rodada\AgendaPelada;
use RcInfoti\Pelada\Rodada\CalendarioRodada;
use RcInfoti\Pelada\Rodada\CicloRodada;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\RepositorioCicloRodadaPdo;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Tests\Email\EnviadorEmailFake;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class CicloRodadaTest extends TestCase
{
    private PDO $pdo;
    private EnviadorEmailFake $email;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->email = new EnviadorEmailFake();

        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha, limite_goleiro)
            VALUES (1, 'Quinta', 'quinta', 1, 1)");
    }

    private function agenda(): AgendaPelada
    {
        return new AgendaPelada(4, '20:00:00', 7, '08:00:00', 3, '12:00:00', 4, '16:00:00');
    }

    private function ciclo(): CicloRodada
    {
        $calendario = new CalendarioRodada();
        $cicloRepo = new RepositorioCicloRodadaPdo($this->pdo, $calendario);
        $processador = new ProcessadorRodada(new RepositorioRodadaPdo($this->pdo), new ServicoRegrasRodada());
        $notificador = new NotificadorMulta($this->pdo, $this->email);

        return new CicloRodada($cicloRepo, $processador, $notificador);
    }

    public function test_cria_a_rodada_quando_a_abertura_chegou(): void
    {
        // segunda 2026-01-05 09:00: abertura (domingo) já passou
        $this->ciclo()->executar(1, $this->agenda(), new DateTimeImmutable('2026-01-05 09:00:00'));

        $row = $this->pdo->query("SELECT * FROM rodadas WHERE pelada_id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('2026-01-08', $row['data_jogo']);
        $this->assertSame('aberta', $row['status']);
    }

    public function test_processa_promocao_e_multa_e_notifica_na_rodada_existente(): void
    {
        // Rodada de hoje já existe, com prazo de multa às 16:00 e o jogo às 20:00.
        // Ana confirmada desiste após o prazo (multa); Bia está na espera e sobe... porém
        // após o prazo não há promoção (spec §3.3). Então usamos $agora ENTRE a virada e o prazo
        // para promover Bia, e uma desistência anterior separada geraria multa só após o prazo.
        // Aqui focamos: desistência após prazo => multa aplicada + e-mail; e a vaga não é promovida.
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, email, tipo) VALUES
            (10, 1, 'Ana', 'ana@example.com', 'linha'),
            (11, 1, 'Bia', 'bia@example.com', 'linha')");
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, abre_em, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-04 08:00:00', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        // Ana confirmada e já desistiu às 16:30 (após o prazo 16:00) => multa
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem, desistiu_em)
            VALUES (5, 10, 'linha', 'desistiu', 1, '2026-01-08 16:30:00')");
        // Bia na espera
        $this->pdo->exec("INSERT INTO inscricoes (rodada_id, jogador_id, tipo, status, ordem)
            VALUES (5, 11, 'linha', 'espera', 2)");

        // $agora após o prazo: aplica multa da Ana; sem promoção (jogo iminente)
        $this->ciclo()->executar(1, $this->agenda(), new DateTimeImmutable('2026-01-08 17:00:00'));

        // multa aplicada e persistida
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM multas WHERE jogador_id = 10')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT multa_aplicada FROM inscricoes WHERE rodada_id = 5 AND jogador_id = 10')->fetchColumn());
        // e-mail de multa enviado para Ana
        $this->assertCount(1, $this->email->enviados);
        $this->assertSame('ana@example.com', $this->email->enviados[0]['para']);
        // Bia continua na espera (sem promoção após o prazo)
        $this->assertSame('espera', $this->pdo->query('SELECT status FROM inscricoes WHERE rodada_id = 5 AND jogador_id = 11')->fetchColumn());
    }

    public function test_fecha_rodada_antiga_no_ciclo(): void
    {
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (9, 1, '2026-01-01', 'aberta', '2025-12-31 12:00:00', '2026-01-01 16:00:00')");

        $this->ciclo()->executar(1, $this->agenda(), new DateTimeImmutable('2026-01-05 09:00:00'));

        $this->assertSame('fechada', $this->pdo->query('SELECT status FROM rodadas WHERE id = 9')->fetchColumn());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `./vendor/bin/phpunit tests/Rodada/CicloRodadaTest.php`
Expected: FAIL — classe `CicloRodada` não existe.

- [ ] **Step 3: Criar `src/Rodada/CicloRodada.php`**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;
use RcInfoti\Pelada\Multa\NotificadorMulta;

final class CicloRodada
{
    public function __construct(
        private readonly RepositorioCicloRodadaPdo $ciclo,
        private readonly ProcessadorRodada $processador,
        private readonly NotificadorMulta $notificador,
    ) {
    }

    public function executar(int $peladaId, AgendaPelada $agenda, DateTimeImmutable $agora): void
    {
        $rodadaId = $this->ciclo->criarRodadaSeAberta($peladaId, $agenda, $agora);

        if ($rodadaId !== null) {
            $this->processador->processar($rodadaId, $agora);
        }

        $this->notificador->notificarPendentes($peladaId, $agora);
        $this->ciclo->fecharRodadasVencidas($peladaId, $agora);
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `./vendor/bin/phpunit tests/Rodada/CicloRodadaTest.php`
Expected: PASS.

- [ ] **Step 5: Criar `src/Email/EnviadorEmailMail.php` (glue — não testado)**

```php
<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Email;

final class EnviadorEmailMail implements EnviadorEmail
{
    public function __construct(private readonly string $de)
    {
    }

    public function enviar(string $para, string $assunto, string $corpo): void
    {
        $cabecalhos = 'From: ' . $this->de . "\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n";

        mail($para, $assunto, $corpo, $cabecalhos);
    }
}
```

- [ ] **Step 6: Criar `cron/processar.php` (glue — não testado)**

```php
<?php

declare(strict_types=1);

// Cron Job do cPanel: chamar a cada 5–10 min.
//   php /home1/rcinfoti/public_html/futebol/cron/processar.php

require __DIR__ . '/../vendor/autoload.php';

use RcInfoti\Pelada\Email\EnviadorEmailMail;
use RcInfoti\Pelada\Infra\Database;
use RcInfoti\Pelada\Multa\NotificadorMulta;
use RcInfoti\Pelada\Rodada\AgendaPelada;
use RcInfoti\Pelada\Rodada\CalendarioRodada;
use RcInfoti\Pelada\Rodada\CicloRodada;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\RepositorioCicloRodadaPdo;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;

/** @var array{db: array{host:string,porta:int,banco:string,usuario:string,senha:string}, email_de: string} $config */
$config = require __DIR__ . '/../config/config.local.php';

$pdo = Database::fromConfig($config['db'])->pdo();
$agora = new DateTimeImmutable('now');

$ciclo = new CicloRodada(
    new RepositorioCicloRodadaPdo($pdo, new CalendarioRodada()),
    new ProcessadorRodada(new RepositorioRodadaPdo($pdo), new ServicoRegrasRodada()),
    new NotificadorMulta($pdo, new EnviadorEmailMail($config['email_de'])),
);

$peladas = $pdo->query('SELECT * FROM peladas WHERE ativa = 1')->fetchAll(PDO::FETCH_ASSOC);
foreach ($peladas as $pelada) {
    $ciclo->executar((int) $pelada['id'], AgendaPelada::deArray($pelada), $agora);
}
```

- [ ] **Step 7: Rodar a suíte inteira (garante que o glue não quebrou o autoload)**

Run: `composer test`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Rodada/CicloRodada.php tests/Rodada/CicloRodadaTest.php src/Email/EnviadorEmailMail.php cron/processar.php
git commit -m "feat(ciclo): orquestrador CicloRodada + cron/processar.php + EnviadorEmailMail"
```

---

## Notas de encerramento

- Ao final, `composer test` deve passar somando os testes novos aos 57 existentes.
- **Fronteira não testada (glue):** `cron/processar.php` e `EnviadorEmailMail` (usa `mail()`), além de `config.local.php` (fora do versionamento). São wiring de I/O real, como o `config` do Plano 2; a lógica que eles acionam está toda coberta por `CicloRodada`/`NotificadorMulta`.
- **Convenção de dia da semana** (ISO 1=segunda…7=domingo) precisa bater com o que a UI/admin gravar nas colunas `*_dia` de `peladas` no Plano 5. Deixar isso explícito na tela de configuração da pelada.
- **Deploy:** aplicar `migrations/003_ciclo_e_multas.sql` no MySQL do cPanel; configurar o Cron Job apontando para `cron/processar.php`; preencher `config.local.php` com `db` e `email_de`.
- **Fora de escopo (fases seguintes):** movimento de caixa para multa quitada (a spec §7 escopa entradas a pagamentos futebol/festa; reavaliar depois); status `encerrada` (fechamento definitivo/arquivamento); toda a aplicação web/PWA, login por PIN e as telas — que são o **Plano 5**.
