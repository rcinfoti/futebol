<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

use DateTimeImmutable;
use RcInfoti\Pelada\Acesso\Autorizacao;
use RcInfoti\Pelada\Acesso\ServicoAcessoOrganizador;
use RcInfoti\Pelada\Acesso\ServicoAcessoPin;
use RcInfoti\Pelada\Admin\ConsultaPainelPelada;
use RcInfoti\Pelada\Admin\GestaoRodada;
use RcInfoti\Pelada\Admin\RecebimentoPagamento;
use RcInfoti\Pelada\Financeiro\RepositorioPagamentoPdo;
use RcInfoti\Pelada\Jogadores\DadosInvalidos;
use RcInfoti\Pelada\Jogadores\DadosJogador;
use RcInfoti\Pelada\Jogadores\RepositorioJogadorPdo;
use RcInfoti\Pelada\Multa\RepositorioMultaPdo;

/** Área do organizador (spec §8, telas 1–5): sessão, painel/rodada, jogadores, multas, pagamentos. */
final class AdminController extends AdminBaseController
{
    public function __construct(
        Sessao $sessao,
        Csrf $csrf,
        ServicoAcessoOrganizador $acesso,
        Autorizacao $autorizacao,
        string $base,
        private readonly RepositorioJogadorPdo $jogadores,
        private readonly ServicoAcessoPin $pins,
        private readonly ConsultaPainelPelada $consulta,
        private readonly RepositorioPagamentoPdo $pagamentos,
        private readonly GestaoRodada $gestao,
        private readonly RepositorioMultaPdo $multas,
        private readonly RecebimentoPagamento $recebimento,
    ) {
        parent::__construct($sessao, $csrf, $acesso, $autorizacao, $base);
    }

    // --- sessão ------------------------------------------------------------

    public function entrar(Request $req): Response
    {
        if ($this->usuarioLogado() !== null) {
            return $this->ir('/admin');
        }

        return $this->pagina('Entrar · Organizador', 'admin/entrar', [
            'csrf' => $this->csrf->token(),
            'erro' => $req->entrada('erro') !== null,
            'bloqueado' => $req->entrada('bloqueado') !== null,
        ], largo: false);
    }

    public function autenticar(Request $req): Response
    {
        if (!$this->csrf->valido($req->entrada('_csrf'))) {
            return Response::html('CSRF inválido', 400);
        }

        $email = $req->entrada('email') ?? '';
        $agora = new DateTimeImmutable('now');
        if ($this->acesso->bloqueado($email, $agora)) {
            return $this->ir('/admin/entrar?bloqueado=1');
        }

        $id = $this->acesso->autenticar($email, $req->entrada('senha') ?? '', $agora);
        if ($id === null) {
            return $this->ir('/admin/entrar?' . ($this->acesso->bloqueado($email, $agora) ? 'bloqueado=1' : 'erro=1'));
        }

        $this->sessao->regenerar();
        $this->sessao->set(self::SESSAO, $id);

        return $this->ir('/admin');
    }

    public function sair(Request $req): Response
    {
        if ($this->usuarioLogado() !== null && !$this->csrf->valido($req->entrada('_csrf'))) {
            return Response::html('CSRF inválido', 400);
        }
        $this->sessao->remove(self::SESSAO);
        $this->sessao->regenerar();

        return $this->ir('/admin/entrar');
    }

    // --- telas -------------------------------------------------------------

    public function inicio(Request $req): Response
    {
        $uid = $this->usuarioLogado();
        if ($uid === null) {
            return $this->ir('/admin/entrar');
        }

        $peladas = $this->autorizacao->peladasVisiveis($uid);
        if (count($peladas) === 1) {
            return $this->ir('/admin/p/' . $peladas[0]['id']);
        }

        return $this->pagina('Suas peladas', 'admin/peladas', ['peladas' => $peladas]);
    }

    public function painel(Request $req, int $peladaId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($peladaId): Response {
            $p = $this->consulta->montar($peladaId);
            if ($p->rodadaId !== null) {
                $this->gestao->reprocessar($peladaId, $p->rodadaId, new DateTimeImmutable('now'));
                $p = $this->consulta->montar($peladaId); // estado fresco depois das promoções
            }

            return $this->pagina('Painel', 'admin/painel', [
                'p' => $p,
                'fora' => $p->rodadaId === null ? [] : $this->gestao->jogadoresForaDaRodada($peladaId, $p->rodadaId),
            ], peladaId: $peladaId);
        });
    }

    public function jogadores(Request $req, int $peladaId): Response
    {
        return $this->guarda($req, $peladaId, fn (): Response => $this->pagina('Jogadores', 'admin/jogadores', [
            'jogadores' => $this->jogadores->listar($peladaId, incluirInativos: true),
        ], peladaId: $peladaId));
    }

    public function novoJogador(Request $req, int $peladaId): Response
    {
        return $this->guarda($req, $peladaId, fn (): Response => $this->formJogador($peladaId, null, [], []));
    }

    public function criarJogador(Request $req, int $peladaId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId): Response {
            $campos = self::camposJogador($req);
            try {
                $id = $this->jogadores->criar($peladaId, DadosJogador::deFormulario($campos));
            } catch (DadosInvalidos $e) {
                return $this->formJogador($peladaId, null, $campos, $e->erros, 422);
            }
            $this->flash('ok', 'Jogador cadastrado. Agora gere o PIN dele.');

            return $this->ir("/admin/p/{$peladaId}/jogadores/{$id}");
        }, post: true);
    }

    public function editarJogador(Request $req, int $peladaId, int $jogadorId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($peladaId, $jogadorId): Response {
            $j = $this->jogadores->buscar($peladaId, $jogadorId);

            return $j === null ? $this->naoEncontrado() : $this->formJogador($peladaId, $j, $j, []);
        });
    }

    public function salvarJogador(Request $req, int $peladaId, int $jogadorId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId, $jogadorId): Response {
            $j = $this->jogadores->buscar($peladaId, $jogadorId);
            if ($j === null) {
                return $this->naoEncontrado();
            }
            $campos = self::camposJogador($req);
            try {
                $this->jogadores->atualizar($peladaId, $jogadorId, DadosJogador::deFormulario($campos));
            } catch (DadosInvalidos $e) {
                return $this->formJogador($peladaId, $j, $campos, $e->erros, 422);
            }
            $this->flash('ok', 'Dados salvos.');

            return $this->ir("/admin/p/{$peladaId}/jogadores/{$jogadorId}");
        }, post: true);
    }

    public function gerarPin(Request $req, int $peladaId, int $jogadorId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($peladaId, $jogadorId): Response {
            if ($this->jogadores->buscar($peladaId, $jogadorId) === null) {
                return $this->naoEncontrado();
            }
            $pin = $this->pins->gerarPin($jogadorId);
            // o PIN em claro vive só nesta flash: some no próximo carregamento
            $this->flash('pin', 'Novo PIN gerado. Passe pro jogador agora — ele não aparece de novo.', $pin);

            return $this->ir("/admin/p/{$peladaId}/jogadores/{$jogadorId}");
        }, post: true);
    }

    public function alternarAtivo(Request $req, int $peladaId, int $jogadorId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId, $jogadorId): Response {
            $ativo = $req->entrada('ativo') === '1';
            if (!$this->jogadores->definirAtivo($peladaId, $jogadorId, $ativo)) {
                return $this->naoEncontrado();
            }
            $this->flash('ok', $ativo ? 'Jogador reativado.' : 'Jogador desativado (sai do login e das listas).');

            return $this->ir("/admin/p/{$peladaId}/jogadores/{$jogadorId}");
        }, post: true);
    }

    public function confirmarPagamento(Request $req, int $peladaId, int $pagamentoId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($peladaId, $pagamentoId): Response {
            // RepositorioPagamentoPdo::confirmar não conhece a pelada: a checagem é aqui
            if (!$this->consulta->pagamentoDaPelada($pagamentoId, $peladaId)) {
                return $this->naoEncontrado();
            }
            $this->pagamentos->confirmar($pagamentoId, new DateTimeImmutable('now'));
            $this->flash('ok', 'Pagamento confirmado e lançado no caixa.');

            return $this->ir("/admin/p/{$peladaId}");
        }, post: true);
    }

    // --- rodada (ações manuais) -------------------------------------------

    public function rodadaAdicionar(Request $req, int $peladaId): Response
    {
        return $this->acaoRodada($req, $peladaId, (int) ($req->entrada('jogador_id') ?? '0'), 'adicionar',
            'Jogador colocado na rodada.', 'Não deu pra colocar esse jogador (rodada fechada ou jogador inativo).');
    }

    public function rodadaPromover(Request $req, int $peladaId, int $jogadorId): Response
    {
        return $this->acaoRodada($req, $peladaId, $jogadorId, 'promover',
            'Jogador subiu da espera.', 'Esse jogador não está na espera.');
    }

    public function rodadaDesistir(Request $req, int $peladaId, int $jogadorId): Response
    {
        return $this->acaoRodada($req, $peladaId, $jogadorId, 'desistir',
            'Marcado como "não vai". A vaga foi repassada conforme a regra.', 'Esse jogador não está na rodada.');
    }

    private function acaoRodada(Request $req, int $peladaId, int $jogadorId, string $metodo, string $ok, string $falha): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId, $jogadorId, $metodo, $ok, $falha): Response {
            $rodadaId = (int) ($req->entrada('rodada_id') ?? '0');
            $feito = $this->gestao->{$metodo}($peladaId, $rodadaId, $jogadorId, new DateTimeImmutable('now'));
            $this->flash($feito ? 'ok' : 'erro', $feito ? $ok : $falha);

            return $this->ir("/admin/p/{$peladaId}");
        }, post: true);
    }

    // --- multas e pagamentos na ficha ------------------------------------

    public function multaReceber(Request $req, int $peladaId, int $multaId): Response
    {
        return $this->acaoMulta($req, $peladaId, $multaId, 'receber', 'Multa recebida e lançada no caixa.');
    }

    public function multaCancelar(Request $req, int $peladaId, int $multaId): Response
    {
        return $this->acaoMulta($req, $peladaId, $multaId, 'cancelar', 'Multa cancelada — saiu da pendência do jogador.');
    }

    private function acaoMulta(Request $req, int $peladaId, int $multaId, string $metodo, string $ok): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId, $multaId, $metodo, $ok): Response {
            $feito = $this->multas->{$metodo}($peladaId, $multaId, new DateTimeImmutable('now'));
            $this->flash($feito ? 'ok' : 'erro', $feito ? $ok : 'Essa multa não está mais pendente.');
            $jogadorId = (int) ($req->entrada('jogador_id') ?? '0');

            return $this->ir($jogadorId > 0 ? "/admin/p/{$peladaId}/jogadores/{$jogadorId}" : "/admin/p/{$peladaId}");
        }, post: true);
    }

    public function receberPagamento(Request $req, int $peladaId, int $jogadorId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId, $jogadorId): Response {
            $j = $this->jogadores->buscar($peladaId, $jogadorId);
            if ($j === null) {
                return $this->naoEncontrado();
            }
            $bruto = $req->entrada('valor');
            $valor = self::valor($bruto);
            try {
                if ($bruto !== null && trim($bruto) !== '' && $valor === null) {
                    throw new DadosInvalidos(['valor' => 'Valor inválido.']);
                }
                $this->recebimento->receber(
                    $peladaId, $jogadorId,
                    (string) $req->entrada('tipo'), (string) $req->entrada('forma'),
                    $valor, new DateTimeImmutable('now'),
                );
            } catch (DadosInvalidos $e) {
                $this->flash('erro', implode(' ', $e->erros));

                return $this->ir("/admin/p/{$peladaId}/jogadores/{$jogadorId}");
            }
            $this->flash('ok', 'Pagamento registrado e lançado no caixa.');

            return $this->ir("/admin/p/{$peladaId}/jogadores/{$jogadorId}");
        }, post: true);
    }

    // --- infraestrutura ----------------------------------------------------

    /**
     * @param array<string,mixed>|null $jogador registro atual (null = novo)
     * @param array<string,mixed> $valores
     * @param array<string,string> $erros
     */
    private function formJogador(int $peladaId, ?array $jogador, array $valores, array $erros, int $status = 200): Response
    {
        return $this->pagina($jogador === null ? 'Novo jogador' : 'Jogador', 'admin/jogador', [
            'jogador' => $jogador,
            'valores' => $valores,
            'erros' => $erros,
            'multas' => $jogador === null ? [] : $this->multas->listarDoJogador($peladaId, (int) $jogador['id']),
        ], peladaId: $peladaId, status: $status);
    }

    /** @return array<string,string> */
    private static function camposJogador(Request $req): array
    {
        return [
            'nome' => $req->entrada('nome') ?? '',
            'tipo' => $req->entrada('tipo') ?? '',
            'email' => $req->entrada('email') ?? '',
            'telefone' => $req->entrada('telefone') ?? '',
        ];
    }

}
