<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

use RcInfoti\Pelada\Acesso\Autorizacao;
use RcInfoti\Pelada\Acesso\Papel;
use RcInfoti\Pelada\Acesso\ServicoAcessoOrganizador;
use RcInfoti\Pelada\Jogadores\DadosInvalidos;
use RcInfoti\Pelada\Peladas\DadosPelada;
use RcInfoti\Pelada\Peladas\RepositorioPeladaPdo;

/**
 * Configuração da pelada (organizador dela, tela 8), peladas e organizadores (super admin, tela 9)
 * e "minha senha" (qualquer usuário logado).
 */
final class AdminPeladasController extends AdminBaseController
{
    private const CAMPOS = [
        'nome', 'slug', 'dia_jogo', 'hora_jogo', 'abre_dia', 'abre_hora', 'vira_regra_dia', 'vira_regra_hora',
        'prazo_multa_dia', 'prazo_multa_hora', 'limite_linha', 'limite_goleiro',
        'valor_futebol', 'valor_festa_semana', 'valor_festa_ano', 'festa_inicio', 'festa_fim',
    ];

    public function __construct(
        Sessao $sessao,
        Csrf $csrf,
        ServicoAcessoOrganizador $acesso,
        Autorizacao $autorizacao,
        string $base,
        private readonly RepositorioPeladaPdo $peladas,
    ) {
        parent::__construct($sessao, $csrf, $acesso, $autorizacao, $base);
    }

    // --- configuração da pelada ------------------------------------------

    public function config(Request $req, int $peladaId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($peladaId): Response {
            $row = $this->peladas->buscar($peladaId);

            return $row === null ? $this->naoEncontrado() : $this->formPelada($peladaId, $row, DadosPelada::paraFormulario($row), []);
        });
    }

    public function salvarConfig(Request $req, int $peladaId): Response
    {
        return $this->guarda($req, $peladaId, function () use ($req, $peladaId): Response {
            $row = $this->peladas->buscar($peladaId);
            if ($row === null) {
                return $this->naoEncontrado();
            }
            $campos = $this->campos($req);
            try {
                $this->peladas->atualizar($peladaId, DadosPelada::deFormulario($campos));
            } catch (DadosInvalidos $e) {
                return $this->formPelada($peladaId, $row, $campos, $e->erros, 422);
            }
            $this->flash('ok', 'Configuração salva. Horários novos valem a partir da próxima rodada.');

            return $this->ir("/admin/p/{$peladaId}/config");
        }, post: true);
    }

    // --- peladas (super admin) -------------------------------------------

    public function novaPelada(Request $req): Response
    {
        return $this->guardaSuperAdmin($req, fn (): Response => $this->formPelada(null, null, self::padroes(), []));
    }

    public function criarPelada(Request $req): Response
    {
        return $this->guardaSuperAdmin($req, function () use ($req): Response {
            $campos = $this->campos($req);
            try {
                $id = $this->peladas->criar(DadosPelada::deFormulario($campos));
            } catch (DadosInvalidos $e) {
                return $this->formPelada(null, null, $campos, $e->erros, 422);
            }
            $this->flash('ok', 'Pelada criada. Agora cadastre os jogadores e vincule os organizadores.');

            return $this->ir("/admin/p/{$id}");
        }, post: true);
    }

    public function alternarPelada(Request $req, int $peladaId): Response
    {
        return $this->guardaSuperAdmin($req, function () use ($req, $peladaId): Response {
            if ($this->peladas->buscar($peladaId) === null) {
                return $this->naoEncontrado();
            }
            $ativa = $req->entrada('ativa') === '1';
            $this->peladas->definirAtiva($peladaId, $ativa);
            $this->flash('ok', $ativa ? 'Pelada reativada.' : 'Pelada desativada: o cron para de abrir rodadas e o link some.');

            return $this->ir("/admin/p/{$peladaId}/config");
        }, post: true);
    }

    // --- organizadores (super admin) -------------------------------------

    public function organizadores(Request $req): Response
    {
        return $this->guardaSuperAdmin($req, fn (): Response => $this->pagina('Organizadores', 'admin/organizadores', [
            'usuarios' => $this->acesso->listar(),
        ]));
    }

    public function novoOrganizador(Request $req): Response
    {
        return $this->guardaSuperAdmin($req, fn (int $uid): Response => $this->formOrganizador(null, ['papel' => 'organizador'], [], [], $uid));
    }

    public function criarOrganizador(Request $req): Response
    {
        return $this->guardaSuperAdmin($req, function (int $uid) use ($req): Response {
            $valores = ['nome' => (string) $req->entrada('nome'), 'email' => (string) $req->entrada('email'), 'papel' => (string) $req->entrada('papel')];
            $papel = Papel::tryFrom($valores['papel']) ?? Papel::Organizador;
            $vinculos = array_map('intval', $req->lista('peladas'));
            try {
                [$id, $senha] = $this->acesso->criarComSenhaTemporaria($valores['nome'], $valores['email'], $papel);
            } catch (\InvalidArgumentException $e) {
                return $this->formOrganizador(null, $valores, $vinculos, ['geral' => $e->getMessage()], $uid, 422);
            }
            $this->autorizacao->definirVinculos($id, $vinculos);
            $this->flash('senha', 'Usuário criado. Passe esta senha pra ele — ela não aparece de novo. Ele pode trocar em "Minha senha".', $senha);

            return $this->ir("/admin/organizadores/{$id}");
        }, post: true);
    }

    public function editarOrganizador(Request $req, int $usuarioId): Response
    {
        return $this->guardaSuperAdmin($req, function (int $uid) use ($usuarioId): Response {
            $u = $this->acesso->buscarQualquer($usuarioId);

            return $u === null
                ? $this->naoEncontrado()
                : $this->formOrganizador($u, $u, $this->autorizacao->idsPeladasDoUsuario($usuarioId), [], $uid);
        });
    }

    public function salvarOrganizador(Request $req, int $usuarioId): Response
    {
        return $this->guardaSuperAdmin($req, function () use ($req, $usuarioId): Response {
            if ($this->acesso->buscarQualquer($usuarioId) === null) {
                return $this->naoEncontrado();
            }
            $this->autorizacao->definirVinculos($usuarioId, array_map('intval', $req->lista('peladas')));
            $this->flash('ok', 'Peladas do organizador atualizadas.');

            return $this->ir("/admin/organizadores/{$usuarioId}");
        }, post: true);
    }

    public function redefinirSenha(Request $req, int $usuarioId): Response
    {
        return $this->guardaSuperAdmin($req, function () use ($usuarioId): Response {
            if ($this->acesso->buscarQualquer($usuarioId) === null) {
                return $this->naoEncontrado();
            }
            $this->flash('senha', 'Senha nova gerada — a antiga parou de funcionar. Ela não aparece de novo.', $this->acesso->redefinirSenhaTemporaria($usuarioId));

            return $this->ir("/admin/organizadores/{$usuarioId}");
        }, post: true);
    }

    public function alternarOrganizador(Request $req, int $usuarioId): Response
    {
        return $this->guardaSuperAdmin($req, function (int $uid) use ($req, $usuarioId): Response {
            if ($this->acesso->buscarQualquer($usuarioId) === null) {
                return $this->naoEncontrado();
            }
            $ativo = $req->entrada('ativo') === '1';
            if (!$ativo && $usuarioId === $uid) {
                $this->flash('erro', 'Você não pode desativar a si mesmo.');
            } else {
                $this->acesso->definirAtivo($usuarioId, $ativo);
                $this->flash('ok', $ativo ? 'Usuário reativado.' : 'Usuário desativado: perde o acesso na hora.');
            }

            return $this->ir("/admin/organizadores/{$usuarioId}");
        }, post: true);
    }

    // --- minha senha -----------------------------------------------------

    public function minhaSenha(Request $req): Response
    {
        if ($this->usuarioLogado() === null) {
            return $this->ir('/admin/entrar');
        }

        return $this->pagina('Minha senha', 'admin/minha-senha', ['erro' => null]);
    }

    public function salvarMinhaSenha(Request $req): Response
    {
        $uid = $this->usuarioLogado();
        if ($uid === null) {
            return $this->ir('/admin/entrar');
        }
        if (!$this->csrf->valido($req->entrada('_csrf'))) {
            return Response::html('CSRF inválido', 400);
        }

        $nova = (string) $req->entrada('nova');
        $erro = null;
        if ($nova !== (string) $req->entrada('repete')) {
            $erro = 'A senha nova e a repetição não conferem.';
        } else {
            try {
                if (!$this->acesso->trocarPropriaSenha($uid, (string) $req->entrada('atual'), $nova)) {
                    $erro = 'Senha atual incorreta.';
                }
            } catch (\InvalidArgumentException $e) {
                $erro = $e->getMessage();
            }
        }
        if ($erro !== null) {
            return $this->pagina('Minha senha', 'admin/minha-senha', ['erro' => $erro], status: 422);
        }
        $this->flash('ok', 'Senha trocada.');

        return $this->ir('/admin');
    }

    // --- apoio -----------------------------------------------------------

    /** @return array<string,string> */
    private function campos(Request $req): array
    {
        $c = [];
        foreach (self::CAMPOS as $k) {
            $c[$k] = (string) ($req->entrada($k) ?? '');
        }

        return $c;
    }

    /** @return array<string,string> padrões da spec (§3) para uma pelada nova */
    private static function padroes(): array
    {
        return [
            'nome' => '', 'slug' => '', 'dia_jogo' => '4', 'hora_jogo' => '20:00',
            'abre_dia' => '7', 'abre_hora' => '08:00', 'vira_regra_dia' => '3', 'vira_regra_hora' => '12:00',
            'prazo_multa_dia' => '4', 'prazo_multa_hora' => '16:00', 'limite_linha' => '20', 'limite_goleiro' => '4',
            'valor_futebol' => '15,00', 'valor_festa_semana' => '5,00', 'valor_festa_ano' => '220,00',
            'festa_inicio' => '01/01', 'festa_fim' => '30/11',
        ];
    }

    /**
     * @param array<string,mixed>|null $pelada
     * @param array<string,string> $valores
     * @param array<string,string> $erros
     */
    private function formPelada(?int $peladaId, ?array $pelada, array $valores, array $erros, int $status = 200): Response
    {
        $uid = (int) $this->usuarioLogado();

        return $this->pagina($peladaId === null ? 'Nova pelada' : 'Configuração', 'admin/pelada-form', [
            'pelada' => $pelada,
            'valores' => $valores,
            'erros' => $erros,
            'dias' => DadosPelada::DIAS,
            'superAdmin' => $this->autorizacao->ehSuperAdmin($uid),
        ], peladaId: $peladaId, status: $status);
    }

    /**
     * @param array<string,mixed>|null $usuario
     * @param array<string,mixed> $valores
     * @param list<int> $vinculos
     * @param array<string,string> $erros
     */
    private function formOrganizador(?array $usuario, array $valores, array $vinculos, array $erros, int $uid, int $status = 200): Response
    {
        return $this->pagina($usuario === null ? 'Novo organizador' : 'Organizador', 'admin/organizador', [
            'alvo' => $usuario,
            'valores' => $valores,
            'vinculos' => $vinculos,
            'erros' => $erros,
            'peladas' => $this->autorizacao->peladasVisiveis($uid),
            'euMesmo' => $usuario !== null && $usuario['id'] === $uid,
        ], status: $status);
    }
}
