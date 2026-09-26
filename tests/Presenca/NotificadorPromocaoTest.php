<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Presenca;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Admin\GestaoRodada;
use RcInfoti\Pelada\Presenca\NotificadorPromocao;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;
use RcInfoti\Pelada\Rodada\RepositorioRodadaPdo;
use RcInfoti\Pelada\Rodada\ServicoRegrasRodada;
use RcInfoti\Pelada\Tests\Email\EnviadorEmailFake;
use RcInfoti\Pelada\Tests\Infra\SchemaSqlite;

final class NotificadorPromocaoTest extends TestCase
{
    private PDO $pdo;
    private DateTimeImmutable $t;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaSqlite::criar($this->pdo);
        $this->pdo->exec("INSERT INTO peladas (id, nome, slug, limite_linha) VALUES (1, 'Quinta', 'quinta', 1)");
        $this->pdo->exec("INSERT INTO jogadores (id, pelada_id, nome, email, tipo) VALUES
            (10, 1, 'Ana', 'ana@x.com', 'linha'), (11, 1, 'Bia', 'bia@x.com', 'linha'), (12, 1, 'Caio', NULL, 'linha')");
        $this->pdo->exec("INSERT INTO rodadas (id, pelada_id, data_jogo, status, vira_regra_em, prazo_multa_em)
            VALUES (5, 1, '2026-01-08', 'aberta', '2026-01-07 12:00:00', '2026-01-08 16:00:00')");
        $this->t = new DateTimeImmutable('2026-01-05 10:00:00');
    }

    private function presenca(): ServicoPresenca
    {
        return new ServicoPresenca($this->pdo);
    }

    private function processar(): void
    {
        (new ProcessadorRodada(new RepositorioRodadaPdo($this->pdo), new ServicoRegrasRodada()))->processar(5, $this->t);
    }

    public function test_quem_sobe_da_espera_recebe_email_uma_vez(): void
    {
        $this->presenca()->confirmar(5, 10, $this->t);
        $this->presenca()->confirmar(5, 11, $this->t); // espera
        $this->presenca()->desistir(5, 10, $this->t);
        $this->processar(); // Bia sobe

        $fake = new EnviadorEmailFake();
        $n = new NotificadorPromocao($this->pdo, $fake);

        $this->assertSame(1, $n->notificarPendentes(1));
        $this->assertSame('bia@x.com', $fake->enviados[0]['para']);
        $this->assertStringContainsString('08/01', $fake->enviados[0]['corpo']);
        $this->assertSame(0, $n->notificarPendentes(1)); // não reenvia
    }

    public function test_quem_confirmou_direto_nao_recebe(): void
    {
        $this->presenca()->confirmar(5, 10, $this->t);

        $fake = new EnviadorEmailFake();
        $this->assertSame(0, (new NotificadorPromocao($this->pdo, $fake))->notificarPendentes(1));
    }

    public function test_promocao_manual_do_organizador_tambem_avisa(): void
    {
        $this->presenca()->confirmar(5, 10, $this->t);
        $this->presenca()->confirmar(5, 11, $this->t); // espera
        (new GestaoRodada($this->pdo, $this->presenca(), new ProcessadorRodada(new RepositorioRodadaPdo($this->pdo), new ServicoRegrasRodada())))
            ->promover(1, 5, 11, $this->t);

        $fake = new EnviadorEmailFake();
        $this->assertSame(1, (new NotificadorPromocao($this->pdo, $fake))->notificarPendentes(1));
    }

    public function test_quem_subiu_e_desistiu_antes_do_email_nao_recebe(): void
    {
        $this->presenca()->confirmar(5, 10, $this->t);
        $this->presenca()->confirmar(5, 11, $this->t);
        $this->presenca()->desistir(5, 10, $this->t);
        $this->processar();
        $this->presenca()->desistir(5, 11, $this->t); // mudou de ideia antes do cron

        $fake = new EnviadorEmailFake();
        $this->assertSame(0, (new NotificadorPromocao($this->pdo, $fake))->notificarPendentes(1));
    }

    public function test_sem_email_cadastrado_fica_pendente_ate_ter(): void
    {
        $this->presenca()->confirmar(5, 10, $this->t);
        $this->presenca()->confirmar(5, 12, $this->t); // Caio sem e-mail, espera
        $this->presenca()->desistir(5, 10, $this->t);
        $this->processar();

        $fake = new EnviadorEmailFake();
        $n = new NotificadorPromocao($this->pdo, $fake);
        $this->assertSame(0, $n->notificarPendentes(1));

        $this->pdo->exec("UPDATE jogadores SET email = 'caio@x.com' WHERE id = 12");
        $this->assertSame(1, $n->notificarPendentes(1));
    }
}
