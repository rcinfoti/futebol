<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Acesso;

use DateTimeImmutable;
use PDO;

/** Login de organizador/super admin por e-mail + senha (spec §2, §5). */
final class ServicoAcessoOrganizador
{
    public const MAX_FALHAS = 5;
    public const MINUTOS_BLOQUEIO = 15;
    public const SENHA_MINIMA = 8;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function criar(string $nome, string $email, string $senha, Papel $papel): int
    {
        $nome = trim($nome);
        $email = self::normalizar($email);
        if ($nome === '') {
            throw new \InvalidArgumentException('Informe o nome.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('E-mail inválido.');
        }
        self::validarSenha($senha);
        if ($this->idPorEmail($email) !== null) {
            throw new \InvalidArgumentException('Já existe usuário com esse e-mail.');
        }

        $this->pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, papel) VALUES (?, ?, ?, ?)')
            ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $papel->value]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{0:int,1:string} [id, senha temporária em claro — mostrada uma única vez] */
    public function criarComSenhaTemporaria(string $nome, string $email, Papel $papel): array
    {
        $senha = self::senhaAleatoria();

        return [$this->criar($nome, $email, $senha, $papel), $senha];
    }

    public function redefinirSenhaTemporaria(int $usuarioId): string
    {
        $senha = self::senhaAleatoria();
        $this->alterarSenha($usuarioId, $senha);

        return $senha;
    }

    /** Troca feita pelo próprio usuário: exige a senha atual. */
    public function trocarPropriaSenha(int $usuarioId, string $atual, string $nova): bool
    {
        $stmt = $this->pdo->prepare('SELECT senha_hash FROM usuarios WHERE id = ? AND ativo = 1');
        $stmt->execute([$usuarioId]);
        $hash = $stmt->fetchColumn();
        if (!is_string($hash) || !password_verify($atual, $hash)) {
            return false;
        }
        $this->alterarSenha($usuarioId, $nova); // valida o tamanho mínimo

        return true;
    }

    /** @return list<array{id:int,nome:string,email:string,papel:string,ativo:bool}> */
    public function listar(): array
    {
        return array_map(static fn (array $u): array => [
            'id' => (int) $u['id'], 'nome' => (string) $u['nome'], 'email' => (string) $u['email'],
            'papel' => (string) $u['papel'], 'ativo' => (int) $u['ativo'] === 1,
        ], $this->pdo->query('SELECT id, nome, email, papel, ativo FROM usuarios ORDER BY ativo DESC, nome')->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{id:int,nome:string,email:string,papel:string,ativo:bool}|null inclusive inativo */
    public function buscarQualquer(int $usuarioId): ?array
    {
        foreach ($this->listar() as $u) {
            if ($u['id'] === $usuarioId) {
                return $u;
            }
        }

        return null;
    }

    public function definirAtivo(int $usuarioId, bool $ativo): void
    {
        $this->pdo->prepare('UPDATE usuarios SET ativo = ? WHERE id = ?')->execute([$ativo ? 1 : 0, $usuarioId]);
    }

    /** 12 caracteres sem ambíguos (0/O, 1/l/I) — fácil de ditar por telefone. */
    private static function senhaAleatoria(): string
    {
        $alfabeto = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 12; $i++) {
            $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $s;
    }

    public function alterarSenha(int $usuarioId, string $senha): void
    {
        self::validarSenha($senha);
        $this->pdo->prepare('UPDATE usuarios SET senha_hash = ?, falhas_login = 0, bloqueado_ate = NULL WHERE id = ?')
            ->execute([password_hash($senha, PASSWORD_DEFAULT), $usuarioId]);
    }

    /** @return int|null id do usuário autenticado */
    public function autenticar(string $email, string $senha, DateTimeImmutable $agora): ?int
    {
        $email = self::normalizar($email);
        $stmt = $this->pdo->prepare('SELECT id, senha_hash, ativo, falhas_login, bloqueado_ate FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($u === false) {
            // gasta o mesmo tempo de um e-mail existente: não dá pra descobrir quem é cadastrado
            password_verify($senha, self::hashFicticio());

            return null;
        }
        if (self::estaBloqueado($u['bloqueado_ate'], $agora)) {
            return null;
        }

        if ((int) $u['ativo'] === 1 && password_verify($senha, (string) $u['senha_hash'])) {
            $this->pdo->prepare('UPDATE usuarios SET falhas_login = 0, bloqueado_ate = NULL WHERE id = ?')
                ->execute([(int) $u['id']]);

            return (int) $u['id'];
        }

        // bloqueio anterior expirado: recomeça a contagem
        $falhas = $u['bloqueado_ate'] !== null ? 1 : (int) $u['falhas_login'] + 1;
        $ate = $falhas >= self::MAX_FALHAS
            ? $agora->modify('+' . self::MINUTOS_BLOQUEIO . ' minutes')->format('Y-m-d H:i:s')
            : null;
        $this->pdo->prepare('UPDATE usuarios SET falhas_login = ?, bloqueado_ate = ? WHERE id = ?')
            ->execute([$falhas, $ate, (int) $u['id']]);

        return null;
    }

    public function bloqueado(string $email, DateTimeImmutable $agora): bool
    {
        $stmt = $this->pdo->prepare('SELECT bloqueado_ate FROM usuarios WHERE email = ?');
        $stmt->execute([self::normalizar($email)]);
        $ate = $stmt->fetchColumn();

        return $ate !== false && self::estaBloqueado($ate, $agora);
    }

    /** @return array{id:int,nome:string,email:string,papel:string}|null */
    public function buscar(int $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nome, email, papel FROM usuarios WHERE id = ? AND ativo = 1');
        $stmt->execute([$usuarioId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        return $u === false ? null : [
            'id' => (int) $u['id'], 'nome' => (string) $u['nome'],
            'email' => (string) $u['email'], 'papel' => (string) $u['papel'],
        ];
    }

    private static ?string $hashFicticio = null;

    private static function hashFicticio(): string
    {
        return self::$hashFicticio ??= password_hash('nao-existe', PASSWORD_DEFAULT);
    }

    private function idPorEmail(string $email): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private static function estaBloqueado(mixed $ate, DateTimeImmutable $agora): bool
    {
        return is_string($ate) && $ate !== '' && new DateTimeImmutable($ate) > $agora;
    }

    private static function normalizar(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function validarSenha(string $senha): void
    {
        if (mb_strlen($senha) < self::SENHA_MINIMA) {
            throw new \InvalidArgumentException('A senha precisa de pelo menos ' . self::SENHA_MINIMA . ' caracteres.');
        }
    }
}
