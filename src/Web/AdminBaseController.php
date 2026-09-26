<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Web;

use RcInfoti\Pelada\Acesso\Autorizacao;
use RcInfoti\Pelada\Acesso\ServicoAcessoOrganizador;

/**
 * Infra comum da área do organizador. Toda rota /admin/p/{id}/… passa por
 * {@see self::guarda()}: sessão de organizador → CSRF (POST) → podeGerir(pelada).
 * Recursos filhos (jogador, pagamento, multa, movimento) são sempre buscados
 * escopados pela pelada da URL: trocar id na URL dá 404, nunca acesso cruzado.
 */
abstract class AdminBaseController
{
    protected const SESSAO = 'usuario_id';
    private const FLASH = '_flash_admin';

    public function __construct(
        protected readonly Sessao $sessao,
        protected readonly Csrf $csrf,
        protected readonly ServicoAcessoOrganizador $acesso,
        protected readonly Autorizacao $autorizacao,
        protected readonly string $base = '',
    ) {
    }

    /** @param callable():Response $acao */
    protected function guarda(Request $req, int $peladaId, callable $acao, bool $post = false): Response
    {
        $uid = $this->usuarioLogado();
        if ($uid === null) {
            return $this->ir('/admin/entrar');
        }
        if ($post && !$this->csrf->valido($req->entrada('_csrf'))) {
            return Response::html('CSRF inválido', 400);
        }
        if (!$this->autorizacao->podeGerir($uid, $peladaId)) {
            return $this->pagina('Sem acesso', 'admin/sem-acesso', [], status: 403);
        }

        return $acao();
    }

    /** Telas só do super admin (spec §8 tela 9): criar peladas, gerir organizadores. */
    protected function guardaSuperAdmin(Request $req, callable $acao, bool $post = false): Response
    {
        $uid = $this->usuarioLogado();
        if ($uid === null) {
            return $this->ir('/admin/entrar');
        }
        if ($post && !$this->csrf->valido($req->entrada('_csrf'))) {
            return Response::html('CSRF inválido', 400);
        }
        if (!$this->autorizacao->ehSuperAdmin($uid)) {
            return $this->pagina('Sem acesso', 'admin/sem-acesso', [], status: 403);
        }

        return $acao($uid);
    }

    protected function usuarioLogado(): ?int
    {
        $id = $this->sessao->get(self::SESSAO);
        if (!is_int($id)) {
            return null;
        }
        if ($this->acesso->buscar($id) === null) { // desativado depois do login
            $this->sessao->remove(self::SESSAO);

            return null;
        }

        return $id;
    }

    protected function flash(string $tipo, string $msg, ?string $pin = null): void
    {
        $this->sessao->set(self::FLASH, ['tipo' => $tipo, 'msg' => $msg, 'pin' => $pin]);
    }

    /** @return array{tipo:string,msg:string,pin:?string}|null */
    private function consumirFlash(): ?array
    {
        $f = $this->sessao->get(self::FLASH);
        $this->sessao->remove(self::FLASH);

        return is_array($f) ? $f : null;
    }

    protected function ir(string $caminho): Response
    {
        return Response::redirecionar($this->base . $caminho);
    }

    protected function naoEncontrado(): Response
    {
        return $this->pagina('Não encontrado', 'admin/nao-encontrado', [], status: 404);
    }

    /** Valor monetário digitado ("20", "20,50", "1.234,56"); null se vazio/ inválido. */
    protected static function valor(?string $bruto): ?float
    {
        $s = trim((string) $bruto);
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }

        return is_numeric($s) ? round((float) $s, 2) : null;
    }

    /** @param array<string,mixed> $dados */
    protected function pagina(
        string $titulo,
        string $vista,
        array $dados,
        ?int $peladaId = null,
        int $status = 200,
        bool $largo = true,
    ): Response {
        $uid = $this->usuarioLogado();
        $dados += [
            'base' => $this->base,
            'peladaId' => $peladaId,
            'usuario' => $uid === null ? null : $this->acesso->buscar($uid),
            'flash' => $this->consumirFlash(),
            'csrf' => $this->csrf->token(),
        ];
        $v = new Vista(__DIR__ . '/vistas');
        $conteudo = $v->render('admin/moldura', $dados + ['miolo' => $v->render($vista, $dados)]);

        return Response::html($v->render('layout', [
            'titulo' => $titulo, 'conteudo' => $conteudo, 'base' => $this->base, 'largo' => $largo,
        ]), $status);
    }
}
