<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Admin;

use DateTimeImmutable;
use PDO;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;

/**
 * Ações manuais do organizador na rodada (spec §8.4). Toda ação confere que a rodada
 * está ABERTA e é da pelada, e que o jogador é da pelada; depois reprocessa as regras
 * (promoção da espera / multa) na hora, como o cron faria.
 */
final class GestaoRodada
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ServicoPresenca $presenca,
        private readonly ProcessadorRodada $processador,
    ) {
    }

    /** Confirma em nome do jogador (ex.: avisou pelo WhatsApp). Entra na espera se lotado. */
    public function adicionar(int $peladaId, int $rodadaId, int $jogadorId, DateTimeImmutable $agora): bool
    {
        if (!$this->rodadaAberta($peladaId, $rodadaId) || !$this->jogadorAtivo($peladaId, $jogadorId)) {
            return false;
        }
        $this->presenca->confirmar($rodadaId, $jogadorId, $agora);
        $this->processador->processar($rodadaId, $agora);

        return true;
    }

    /** Tira da espera direto pra confirmado, mesmo acima do limite (decisão do organizador). */
    public function promover(int $peladaId, int $rodadaId, int $jogadorId, DateTimeImmutable $agora): bool
    {
        if (!$this->rodadaAberta($peladaId, $rodadaId)) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE inscricoes SET status = 'confirmado', confirmado_em = ?, promovido_em = ?, promocao_notificada = 0
             WHERE rodada_id = ? AND jogador_id = ? AND status = 'espera'"
        );
        $quando = $agora->format('Y-m-d H:i:s');
        $stmt->execute([$quando, $quando, $rodadaId, $jogadorId]);

        return $stmt->rowCount() > 0;
    }

    /** Marca que não vai. Após o prazo, o reprocessamento aplica a multa (organizador pode cancelar depois). */
    public function desistir(int $peladaId, int $rodadaId, int $jogadorId, DateTimeImmutable $agora): bool
    {
        if (!$this->rodadaAberta($peladaId, $rodadaId) || !$this->inscrito($rodadaId, $jogadorId)) {
            return false;
        }
        $this->presenca->desistir($rodadaId, $jogadorId, $agora);
        $this->processador->processar($rodadaId, $agora);

        return true;
    }

    /** Reavalia as regras (idempotente) — chamado ao abrir o painel (spec §6, rede de segurança do cron). */
    public function reprocessar(int $peladaId, int $rodadaId, DateTimeImmutable $agora): void
    {
        if ($this->rodadaAberta($peladaId, $rodadaId)) {
            $this->processador->processar($rodadaId, $agora);
        }
    }

    /** @return list<array{id:int,nome:string,tipo:string}> ativos da pelada que ainda não estão na rodada */
    public function jogadoresForaDaRodada(int $peladaId, int $rodadaId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT j.id, j.nome, j.tipo FROM jogadores j
             WHERE j.pelada_id = ? AND j.ativo = 1
               AND NOT EXISTS (SELECT 1 FROM inscricoes i WHERE i.rodada_id = ? AND i.jogador_id = j.id AND i.status <> 'desistiu')
             ORDER BY j.nome"
        );
        $stmt->execute([$peladaId, $rodadaId]);

        return array_map(
            static fn (array $r): array => ['id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'tipo' => (string) $r['tipo']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function rodadaAberta(int $peladaId, int $rodadaId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM rodadas WHERE id = ? AND pelada_id = ? AND status = 'aberta'");
        $stmt->execute([$rodadaId, $peladaId]);

        return $stmt->fetchColumn() !== false;
    }

    private function jogadorAtivo(int $peladaId, int $jogadorId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM jogadores WHERE id = ? AND pelada_id = ? AND ativo = 1');
        $stmt->execute([$jogadorId, $peladaId]);

        return $stmt->fetchColumn() !== false;
    }

    private function inscrito(int $rodadaId, int $jogadorId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM inscricoes WHERE rodada_id = ? AND jogador_id = ? AND status IN ('confirmado','espera')");
        $stmt->execute([$rodadaId, $jogadorId]);

        return $stmt->fetchColumn() !== false;
    }
}
