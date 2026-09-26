<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

use DateTimeImmutable;
use RcInfoti\Pelada\Acesso\Autorizacao;
use RcInfoti\Pelada\Acesso\ServicoAcessoOrganizador;
use RcInfoti\Pelada\Admin\RelatorioCsv;
use RcInfoti\Pelada\Financeiro\ArmazemComprovantes;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Financeiro\RelatorioFinanceiroPdo;
use RcInfoti\Pelada\Financeiro\RepositorioCaixaPdo;
use RcInfoti\Pelada\Financeiro\TipoMovimento;

/** Caixa & gastos e relatórios (spec §7, §8 telas 6–7). */
final class AdminFinanceiroController extends AdminBaseController
{
    /** Categorias que o organizador pode lançar à mão (pagamento/multa têm origem própria). */
    public const CATEGORIAS_SAIDA = [
        'campo' => 'Aluguel do campo',
        'bola' => 'Bola',
        'material' => 'Material (colete, rede...)',
        'arbitragem' => 'Arbitragem',
        'festa' => 'Festa / resenha',
        'outros' => 'Outros',
    ];
    public const CATEGORIAS_ENTRADA = [
        'ajuste' => 'Saldo inicial / ajuste',
        'doacao' => 'Doação / patrocínio',
    ];
    private const VALOR_MAXIMO = 100000.0;

    public function __construct(
        Sessao $sessao,
        Csrf $csrf,
        ServicoAcessoOrganizador $acesso,
        Autorizacao $autorizacao,
        string $base,
        private readonly RepositorioCaixaPdo $caixa,
        private readonly RelatorioFinanceiroPdo $relatorio,
        private readonly RepositorioPagamentoPdo $pagamentos,
        private readonly ArmazemComprovantes $comprovantes,
    ) {
        parent::__construct($sessao, $csrf, $acesso, $autorizacao, $base);
    }

    public function caixa(Request $req, int $peladaId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId): Response {
            $mes = self::mes($req);
            $extrato = $this->caixa->extrato($peladaId, $mes, $mes->modify('last day of this month')->setTime(23, 59, 59));
            $entradas = $saidas = 0.0;
            foreach ($extrato as $m) {
                $m->tipo === TipoMovimento::Entrada ? $entradas += $m->valor : $saidas += $m->valor;
            }

            return $this->pagina('Caixa', 'admin/caixa', [
                'saldo' => $this->caixa->saldo($peladaId),
                'mes' => $mes,
                'extrato' => $extrato,
                'entradasMes' => $entradas,
                'saidasMes' => $saidas,
                'categoriasSaida' => self::CATEGORIAS_SAIDA,
                'categoriasEntrada' => self::CATEGORIAS_ENTRADA,
                'hoje' => (new DateTimeImmutable('now'))->format('Y-m-d'),
            ], peladaId: $peladaId);
        });
    }

    public function lancar(Request $req, int $peladaId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId): Response {
            $saida = $req->entrada('tipo') !== 'entrada';
            $categorias = $saida ? self::CATEGORIAS_SAIDA : self::CATEGORIAS_ENTRADA;
            $categoria = (string) $req->entrada('categoria');
            $valor = self::valor($req->entrada('valor'));
            $descricao = mb_substr(trim((string) $req->entrada('descricao')), 0, 255);
            $data = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $req->entrada('data'));
            $agora = new DateTimeImmutable('now');

            $erros = [];
            if (!isset($categorias[$categoria])) {
                $erros[] = 'Escolha a categoria.';
            }
            if ($valor === null || $valor <= 0 || $valor > self::VALOR_MAXIMO) {
                $erros[] = 'Valor inválido.';
            }
            if ($data === false || $data > $agora->setTime(23, 59, 59)) {
                $erros[] = 'Data inválida (não pode ser no futuro).';
            }
            if ($erros !== []) {
                $this->flash('erro', implode(' ', $erros));

                return $this->ir("/admin/p/{$peladaId}/caixa");
            }

            $descricao = $descricao !== '' ? $descricao : $categorias[$categoria];
            $saida
                ? $this->caixa->lancarSaida($peladaId, $valor, $categoria, $descricao, $data->setTime(12, 0), $agora)
                : $this->caixa->lancarEntrada($peladaId, $valor, $categoria, $descricao, $data->setTime(12, 0), $agora);
            $this->flash('ok', $saida ? 'Gasto lançado.' : 'Entrada lançada.');

            return $this->ir("/admin/p/{$peladaId}/caixa?mes=" . $data->format('Y-m'));
        }, post: true);
    }

    public function excluir(Request $req, int $peladaId, int $movimentoId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId, $movimentoId): Response {
            $feito = $this->caixa->excluirManual($peladaId, $movimentoId);
            $this->flash($feito ? 'ok' : 'erro', $feito
                ? 'Lançamento excluído.'
                : 'Esse lançamento não pode ser excluído (vem de pagamento ou multa).');
            $mes = self::mes($req)->format('Y-m');

            return $this->ir("/admin/p/{$peladaId}/caixa?mes={$mes}");
        }, post: true);
    }

    public function relatorio(Request $req, int $peladaId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId): Response {
            $mes = self::mes($req);

            return $this->pagina('Relatório', 'admin/relatorio', [
                'mes' => $mes,
                'linhas' => $this->relatorio->resumoMensalJogador($peladaId, (int) $mes->format('Y'), (int) $mes->format('n')),
            ], peladaId: $peladaId);
        });
    }

    public function relatorioCsv(Request $req, int $peladaId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId): Response {
            $mes = self::mes($req);
            $csv = RelatorioCsv::mensal(
                $this->relatorio->resumoMensalJogador($peladaId, (int) $mes->format('Y'), (int) $mes->format('n'))
            );

            return new Response(200, $csv, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="pelada-' . $peladaId . '-' . $mes->format('Y-m') . '.csv"',
                'Cache-Control' => 'no-store',
            ]);
        });
    }

    /** Serve o comprovante só pro organizador da pelada do pagamento. */
    public function comprovante(Request $req, int $peladaId, int $pagamentoId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($peladaId, $pagamentoId): Response {
            $nome = $this->pagamentos->comprovanteDe($peladaId, $pagamentoId);
            $caminho = $nome === null ? null : $this->comprovantes->caminho($nome); // regex barra "../"
            if ($caminho === null) {
                return $this->naoEncontrado();
            }

            return new Response(200, (string) file_get_contents($caminho), [
                'Content-Type' => ArmazemComprovantes::mime($nome),
                'Content-Disposition' => 'inline; filename="comprovante-' . $pagamentoId . '.' . pathinfo($nome, PATHINFO_EXTENSION) . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
                'Cache-Control' => 'private, no-store',
            ]);
        });
    }

    /** ?mes=AAAA-MM (qualquer outra coisa → mês atual), sempre no dia 1 às 00:00. */
    private static function mes(Request $req): DateTimeImmutable
    {
        $m = (string) $req->entrada('mes');
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $m) === 1) {
            return new DateTimeImmutable($m . '-01 00:00:00');
        }

        return (new DateTimeImmutable('first day of this month'))->setTime(0, 0);
    }
}
