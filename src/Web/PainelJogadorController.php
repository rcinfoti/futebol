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

    public function entrar(Request $req): Response
    {
        if ($this->jogadorLogado() !== null) {
            return Response::redirecionar('/');
        }

        $conteudo = $this->vista()->render('entrar', [
            'jogadores' => $this->consulta->jogadoresParaLogin(),
            'csrf' => $this->csrf->token(),
            'erro' => $req->entrada('erro') !== null,
        ]);

        return Response::html($this->vista()->render('layout', ['titulo' => 'Entrar', 'conteudo' => $conteudo]));
    }

    public function painel(Request $req): Response
    {
        $jogadorId = $this->jogadorLogado();
        if ($jogadorId === null) {
            return Response::redirecionar('/entrar');
        }

        $painel = $this->consulta->montar($jogadorId, new DateTimeImmutable('now'));
        $conteudo = $this->vista()->render('painel', ['p' => $painel, 'csrf' => $this->csrf->token()]);

        return Response::html($this->vista()->render('layout', ['titulo' => 'Sua pelada', 'conteudo' => $conteudo]));
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

    private function vista(): Vista
    {
        return new Vista(__DIR__ . '/vistas');
    }
}
