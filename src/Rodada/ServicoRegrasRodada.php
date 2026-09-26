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
