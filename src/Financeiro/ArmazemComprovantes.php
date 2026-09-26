<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Financeiro;

use RcInfoti\Pelada\Jogadores\DadosInvalidos;

/**
 * Comprovantes de pagamento (spec §5, §11): guardados FORA da raiz web, com nome
 * aleatório e tipo validado pelo CONTEÚDO (finfo), nunca pela extensão enviada.
 * Só JPG/PNG/WEBP/PDF — SVG fica de fora porque pode carregar script.
 */
final class ArmazemComprovantes
{
    public const TAMANHO_MAXIMO = 5 * 1024 * 1024;
    private const TIPOS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];
    private const NOME = '/^[a-f0-9]{32}\.(jpg|png|webp|pdf)$/';

    /** @var callable(string,string):bool */
    private $mover;

    /** @param (callable(string,string):bool)|null $mover padrão: move_uploaded_file (injetável pra teste) */
    public function __construct(private readonly string $diretorio, ?callable $mover = null)
    {
        $this->mover = $mover ?? static fn (string $de, string $para): bool => move_uploaded_file($de, $para);
    }

    /**
     * @param array{name?:string,tmp_name?:string,size?:int,error?:int}|null $arquivo item de $_FILES
     * @return string|null nome gravado; null se nenhum arquivo foi enviado
     */
    public function salvar(?array $arquivo): ?string
    {
        $erro = (int) ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($arquivo === null || $erro === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($erro === UPLOAD_ERR_INI_SIZE || $erro === UPLOAD_ERR_FORM_SIZE) {
            self::recusar('Arquivo grande demais (máx. 5 MB). Tire um print em vez de enviar a foto original.');
        }
        if ($erro !== UPLOAD_ERR_OK) {
            self::recusar('Não deu pra receber o arquivo. Tente de novo.');
        }

        $tmp = (string) ($arquivo['tmp_name'] ?? '');
        $tamanho = is_file($tmp) ? (int) filesize($tmp) : 0;
        if ($tamanho === 0 || $tamanho > self::TAMANHO_MAXIMO) {
            self::recusar('Arquivo vazio ou grande demais (máx. 5 MB).');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $ext = self::TIPOS[$mime] ?? null;
        if ($ext === null) {
            self::recusar('Envie foto (JPG, PNG, WEBP) ou PDF do comprovante.');
        }

        if (!is_dir($this->diretorio) && !mkdir($this->diretorio, 0750, true) && !is_dir($this->diretorio)) {
            throw new \RuntimeException('Não foi possível criar o diretório de comprovantes.');
        }
        $nome = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!($this->mover)($tmp, $this->diretorio . '/' . $nome)) {
            self::recusar('Não deu pra guardar o arquivo. Tente de novo.');
        }
        @chmod($this->diretorio . '/' . $nome, 0640);

        return $nome;
    }

    /** Caminho absoluto de um comprovante existente; null se o nome não bate o formato (anti path traversal). */
    public function caminho(string $nome): ?string
    {
        if (preg_match(self::NOME, $nome) !== 1) {
            return null;
        }
        $caminho = $this->diretorio . '/' . $nome;

        return is_file($caminho) ? $caminho : null;
    }

    public static function mime(string $nome): string
    {
        $ext = pathinfo($nome, PATHINFO_EXTENSION);

        return array_flip(self::TIPOS)[$ext] ?? 'application/octet-stream';
    }

    private static function recusar(string $msg): never
    {
        throw new DadosInvalidos(['comprovante' => $msg]);
    }
}
