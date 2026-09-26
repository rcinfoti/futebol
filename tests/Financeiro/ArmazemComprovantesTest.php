<?php

declare(strict_types=1);

namespace RcInfoti\Pelada\Tests\Financeiro;

use PHPUnit\Framework\TestCase;
use RcInfoti\Pelada\Financeiro\ArmazemComprovantes;
use RcInfoti\Pelada\Jogadores\DadosInvalidos;

final class ArmazemComprovantesTest extends TestCase
{
    private string $dir;
    private string $tmp;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/comprovantes_' . uniqid();
        $this->tmp = sys_get_temp_dir() . '/upload_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        @unlink($this->tmp);
    }

    /** em teste não há upload HTTP real: o "mover" é um rename */
    private function armazem(): ArmazemComprovantes
    {
        return new ArmazemComprovantes($this->dir, static fn (string $de, string $para): bool => rename($de, $para));
    }

    /** @return array{name:string,tmp_name:string,size:int,error:int} */
    private function upload(string $conteudo, string $nomeOriginal, int $erro = UPLOAD_ERR_OK): array
    {
        file_put_contents($this->tmp, $conteudo);

        return ['name' => $nomeOriginal, 'tmp_name' => $this->tmp, 'size' => strlen($conteudo), 'error' => $erro];
    }

    private function png(): string
    {
        // PNG 1x1 válido (sem depender da extensão GD)
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    public function test_sem_arquivo_devolve_null(): void
    {
        $this->assertNull($this->armazem()->salvar(['name' => '', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_NO_FILE]));
        $this->assertNull($this->armazem()->salvar(null));
    }

    public function test_salva_png_com_nome_aleatorio_e_extensao_pelo_conteudo(): void
    {
        $nome = $this->armazem()->salvar($this->upload($this->png(), 'foto.pdf')); // extensão mentirosa

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $nome);
        $this->assertFileExists($this->dir . '/' . $nome);
        $this->assertSame('image/png', ArmazemComprovantes::mime($nome));
    }

    public function test_salva_pdf(): void
    {
        $nome = $this->armazem()->salvar($this->upload("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'recibo.pdf'));
        $this->assertStringEndsWith('.pdf', $nome);
    }

    public function test_recusa_php_disfarcado_de_imagem(): void
    {
        $this->expectException(DadosInvalidos::class);
        $this->armazem()->salvar($this->upload('<?php system($_GET["c"]); ?>', 'comprovante.jpg'));
    }

    public function test_recusa_svg(): void
    {
        // SVG pode carregar script: fora da lista, mesmo sendo "imagem"
        $this->expectException(DadosInvalidos::class);
        $this->armazem()->salvar($this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.svg'));
    }

    public function test_recusa_arquivo_grande(): void
    {
        $this->expectException(DadosInvalidos::class);
        $this->armazem()->salvar($this->upload($this->png() . str_repeat('x', ArmazemComprovantes::TAMANHO_MAXIMO), 'grande.png'));
    }

    public function test_erro_de_upload_do_php_vira_mensagem(): void
    {
        try {
            $this->armazem()->salvar($this->upload('', 'x.png', UPLOAD_ERR_INI_SIZE));
            $this->fail();
        } catch (DadosInvalidos $e) {
            $this->assertStringContainsString('grande', $e->erros['comprovante']);
        }
    }

    public function test_caminho_so_para_nome_valido_e_existente(): void
    {
        $nome = $this->armazem()->salvar($this->upload($this->png(), 'a.png'));

        $this->assertSame($this->dir . '/' . $nome, $this->armazem()->caminho($nome));
        $this->assertNull($this->armazem()->caminho('../../config/config.local.php'));
        $this->assertNull($this->armazem()->caminho(str_repeat('a', 32) . '.png')); // formato ok, não existe
    }
}
