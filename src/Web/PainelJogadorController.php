<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

use DateTimeImmutable;
use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Financeiro\ArmazemComprovantes;
use RcInfoti\Pelada\Financeiro\CategoriaPagamento;
use RcInfoti\Pelada\Jogadores\DadosInvalidos;
use RcInfoti\Pelada\Financeiro\EscopoPagamento;
use RcInfoti\Pelada\Financeiro\FormaPagamento;
use RcInfoti\Pelada\Financeiro\Pagamento;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Painel\ConsultaPainelJogador;
use RcInfoti\Pelada\Presenca\ServicoPresenca;
use RcInfoti\Pelada\Rodada\ProcessadorRodada;

final class PainelJogadorController
{
    /** Teto do valor que o jogador pode avisar (evita digitação absurda; o organizador confirma depois). */
    private const VALOR_MAXIMO_AVISO = 1000.0;

    /**
     * @param ProcessadorRodada|null $processador reavalia as regras da rodada logo após confirmar/desistir
     *                                          (spec §6: rede de segurança além do cron)
     * @param string $base subdiretório público do app (ex.: "/futebol"), prefixado em redirects e links
     */
    public function __construct(
        private readonly Sessao $sessao,
        private readonly Csrf $csrf,
        private readonly ServicoAcessoPin $acesso,
        private readonly ServicoPresenca $presenca,
        private readonly ConsultaPainelJogador $consulta,
        private readonly RepositorioPagamentoPdo $pagamentos,
        private readonly ?ProcessadorRodada $processador = null,
        private readonly string $base = '',
        private readonly ?ArmazemComprovantes $comprovantes = null,
    ) {
    }

    public function entrar(Request $req, ?string $slug = null): Response
    {
        if ($this->jogadorLogado() !== null) {
            return $this->ir('/');
        }

        if ($slug === null) {
            $peladas = $this->consulta->peladasAtivas();
            if (count($peladas) === 1) {
                return $this->ir('/p/' . rawurlencode($peladas[0]['slug']) . '/entrar');
            }

            return $this->pagina('Escolha a pelada', 'escolher-pelada', ['peladas' => $peladas]);
        }

        $pelada = $this->consulta->peladaPorSlug($slug);
        if ($pelada === null) {
            return $this->naoEncontrada();
        }

        return $this->pagina('Entrar', 'entrar', [
            'pelada' => $pelada,
            'jogadores' => $this->consulta->jogadoresParaLogin($pelada['id']),
            'csrf' => $this->csrf->token(),
            'erro' => $req->entrada('erro') !== null,
            'bloqueado' => $req->entrada('bloqueado') !== null,
        ]);
    }

    public function autenticar(Request $req, string $slug): Response
    {
        if (!$this->csrf->valido($req->entrada('_csrf'))) {
            return Response::html('CSRF inválido', 400);
        }

        $pelada = $this->consulta->peladaPorSlug($slug);
        if ($pelada === null) {
            return $this->naoEncontrada();
        }

        $login = '/p/' . rawurlencode($pelada['slug']) . '/entrar';
        $jogadorId = (int) ($req->entrada('jogador_id') ?? '0');
        $pin = $req->entrada('pin') ?? '';
        $agora = new DateTimeImmutable('now');

        if (!$this->consulta->pertenceAPelada($jogadorId, $pelada['id'])) {
            return $this->ir($login . '?erro=1');
        }
        if ($this->acesso->bloqueado($jogadorId, $agora)) {
            return $this->ir($login . '?bloqueado=1');
        }
        if (!$this->acesso->autenticar($jogadorId, $pin, $agora)) {
            // a falha que completou o limite já bloqueia: avisa na hora
            return $this->ir($login . ($this->acesso->bloqueado($jogadorId, $agora) ? '?bloqueado=1' : '?erro=1'));
        }

        $this->sessao->regenerar();
        $this->sessao->set('jogador_id', $jogadorId);

        return $this->ir('/');
    }

    public function painel(Request $req): Response
    {
        $jogadorId = $this->jogadorLogado();
        if ($jogadorId === null) {
            return $this->ir('/entrar');
        }

        $agora = new DateTimeImmutable('now');
        $painel = $this->consulta->montar($jogadorId, $agora);
        if ($painel->rodadaId !== null && $this->processador !== null) {
            // abrir a página também reavalia a rodada (idempotente) e remonta com o estado fresco
            $this->processador->processar($painel->rodadaId, $agora);
            $painel = $this->consulta->montar($jogadorId, $agora);
        }

        return $this->pagina('Sua pelada', 'painel', ['p' => $painel, 'csrf' => $this->csrf->token(), 'aviso' => $req->entrada('pagamento')]);
    }

    public function confirmar(Request $req): Response
    {
        return $this->comAcaoDeRodada($req, function (int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void {
            $this->presenca->confirmar($rodadaId, $jogadorId, $agora);
        });
    }

    public function naoVou(Request $req): Response
    {
        return $this->comAcaoDeRodada($req, function (int $rodadaId, int $jogadorId, DateTimeImmutable $agora): void {
            $this->presenca->desistir($rodadaId, $jogadorId, $agora);
        });
    }

    public function avisarPagamento(Request $req): Response
    {
        $guard = $this->exigirSessaoECsrf($req);
        if ($guard !== null) {
            return $guard;
        }

        $valorBruto = str_replace(',', '.', trim($req->entrada('valor') ?? ''));
        $valor = is_numeric($valorBruto) ? round((float) $valorBruto, 2) : 0.0;
        if ($valor <= 0 || $valor > self::VALOR_MAXIMO_AVISO) {
            return $this->ir('/?pagamento=invalido');
        }

        // comprovante é opcional; se veio, precisa ser válido — antes de gravar qualquer coisa
        $comprovante = null;
        if ($this->comprovantes !== null) {
            try {
                $comprovante = $this->comprovantes->salvar($req->arquivo('comprovante'));
            } catch (DadosInvalidos) {
                return $this->ir('/?pagamento=comprovante');
            }
        }

        $jogadorId = (int) $this->jogadorLogado();
        $agora = new DateTimeImmutable('now');
        $painel = $this->consulta->montar($jogadorId, $agora);

        $categoria = $req->entrada('categoria') === 'festa' ? CategoriaPagamento::Festa : CategoriaPagamento::Futebol;
        $forma = $req->entrada('forma') === 'dinheiro' ? FormaPagamento::Dinheiro : FormaPagamento::Pix;

        $this->pagamentos->registrar(
            new Pagamento(
                $this->consulta->peladaId($jogadorId),
                $jogadorId,
                $categoria,
                EscopoPagamento::Semana,
                $valor,
                $forma,
                rodadaId: $painel->rodadaId,
                comprovanteArquivo: $comprovante,
            ),
            $agora,
        );

        return $this->ir('/?pagamento=avisado');
    }

    public function sair(Request $req): Response
    {
        $guard = $this->exigirSessaoECsrf($req);
        if ($guard !== null) {
            return $guard;
        }

        $slug = $this->consulta->slugDoJogador((int) $this->jogadorLogado());
        $this->sessao->remove('jogador_id');
        $this->sessao->regenerar();

        return $this->ir($slug === null ? '/entrar' : '/p/' . rawurlencode($slug) . '/entrar');
    }

    /** @param callable(int,int,DateTimeImmutable):void $acao */
    private function comAcaoDeRodada(Request $req, callable $acao): Response
    {
        $guard = $this->exigirSessaoECsrf($req);
        if ($guard !== null) {
            return $guard;
        }

        $jogadorId = (int) $this->jogadorLogado();
        $agora = new DateTimeImmutable('now');
        $painel = $this->consulta->montar($jogadorId, $agora);
        if ($painel->rodadaId !== null) {
            $acao($painel->rodadaId, $jogadorId, $agora);
            // promove da espera / aplica multa na hora, sem esperar o próximo cron
            $this->processador?->processar($painel->rodadaId, $agora);
        }

        return $this->ir('/');
    }

    private function exigirSessaoECsrf(Request $req): ?Response
    {
        if ($this->jogadorLogado() === null) {
            return $this->ir('/entrar');
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

    private function ir(string $caminho): Response
    {
        return Response::redirecionar($this->base . $caminho);
    }

    private function naoEncontrada(): Response
    {
        $conteudo = $this->vista()->render('nao-encontrada', ['base' => $this->base]);

        return Response::html($this->vista()->render('layout', [
            'titulo' => 'Não encontrada', 'conteudo' => $conteudo, 'base' => $this->base,
        ]), 404);
    }

    /** @param array<string,mixed> $dados */
    private function pagina(string $titulo, string $vista, array $dados): Response
    {
        $dados['base'] = $this->base;
        $conteudo = $this->vista()->render($vista, $dados);

        return Response::html($this->vista()->render('layout', [
            'titulo' => $titulo, 'conteudo' => $conteudo, 'base' => $this->base,
        ]));
    }

    private function vista(): Vista
    {
        return new Vista(__DIR__ . '/vistas');
    }
}
