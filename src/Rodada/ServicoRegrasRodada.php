<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Rodada;

use DateTimeImmutable;

final class ServicoRegrasRodada
{
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

        $antesDaVirada = $agora < $estado->viraRegraEm;
        $candidatos = $antesDaVirada
            ? $espera
            : array_values(array_filter($espera, static fn (Inscricao $i): bool => $i->pagou));

        $acoes = [];
        foreach (array_slice($candidatos, 0, $vagas) as $inscricao) {
            $acoes[] = new AcaoPromover($inscricao->jogadorId);
        }

        return $acoes;
    }
}
