<?php
/** @var array{id:int,nome:string,tipo:string,ativo:bool,tem_pin:bool,saldo_pendente:float}|null $jogador */
/** @var array<string,mixed> $valores */
/** @var array<string,string> $erros */
/** @var string $base */
/** @var string $csrf */
/** @var int $peladaId */
/** @var list<array{id:int,valor:float,status:string,motivo:?string,criado_em:string,data_jogo:?string}> $multas */
$moeda = static fn (float $x): string => 'R$ ' . number_format($x, 2, ',', '.');
$v = static fn (string $k): string => htmlspecialchars((string) ($valores[$k] ?? ''));
$acao = $base . '/admin/p/' . $peladaId . '/jogadores' . ($jogador !== null ? '/' . $jogador['id'] : '');
$erro = static function (string $k) use ($erros): void {
    if (isset($erros[$k])) {
        echo '<p class="aviso">' . htmlspecialchars($erros[$k]) . '</p>';
    }
};
?>
<p class="marca"><a href="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>/jogadores">‹ Jogadores</a></p>
<h1><?= $jogador === null ? 'Novo jogador' : htmlspecialchars($jogador['nome']) ?></h1>

<div class="cartao">
  <form method="post" action="<?= htmlspecialchars($acao) ?>">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div class="campo">
      <label for="nome">Nome (como aparece no login)</label>
      <input id="nome" name="nome" type="text" value="<?= $v('nome') ?>" maxlength="120" required>
      <?php $erro('nome'); ?>
    </div>
    <div class="campo">
      <label for="tipo">Posição</label>
      <select id="tipo" name="tipo">
        <option value="linha" <?= ($valores['tipo'] ?? 'linha') === 'linha' ? 'selected' : '' ?>>Linha</option>
        <option value="goleiro" <?= ($valores['tipo'] ?? '') === 'goleiro' ? 'selected' : '' ?>>Goleiro</option>
      </select>
      <?php $erro('tipo'); ?>
    </div>
    <div class="campo">
      <label for="email">E-mail (recebe aviso de multa)</label>
      <input id="email" name="email" type="email" value="<?= $v('email') ?>">
      <?php $erro('email'); ?>
    </div>
    <div class="campo">
      <label for="telefone">Telefone</label>
      <input id="telefone" name="telefone" type="tel" value="<?= $v('telefone') ?>">
      <?php $erro('telefone'); ?>
    </div>
    <button class="btn btn-confirmar" type="submit"><?= $jogador === null ? 'Cadastrar' : 'Salvar' ?></button>
  </form>
</div>

<?php if ($jogador !== null): ?>
  <div class="cartao">
    <div class="cab-lista">
      <span>PIN: <?= $jogador['tem_pin'] ? '<span class="is-verde">definido</span>' : '<span class="is-amarelo">não definido</span>' ?></span>
      <form method="post" action="<?= htmlspecialchars($acao) ?>/pin"
            onsubmit="return <?= $jogador['tem_pin'] ? "confirm('Gerar um PIN novo? O antigo para de funcionar.')" : 'true' ?>">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <button class="btn btn-pequeno" type="submit"><?= $jogador['tem_pin'] ? 'Gerar novo PIN' : 'Gerar PIN' ?></button>
      </form>
    </div>
  </div>

  <?php if ($jogador['saldo_pendente'] > 0): ?>
    <div class="cartao status is-vermelho" style="font-size:1.15rem">
      <span class="ponto"></span><span>Pendência de <?= htmlspecialchars($moeda($jogador['saldo_pendente'])) ?></span>
    </div>
  <?php endif; ?>

  <h2>Recebi um pagamento</h2>
  <div class="cartao">
    <form method="post" action="<?= htmlspecialchars($acao) ?>/pagamento">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <div class="grade-2">
        <div class="campo">
          <label for="p-tipo">O quê</label>
          <select id="p-tipo" name="tipo">
            <option value="futebol">Futebol da semana</option>
            <option value="festa_semana">Festa da semana</option>
            <option value="festa_ano">Festa do ano inteiro</option>
          </select>
        </div>
        <div class="campo">
          <label for="p-forma">Como</label>
          <select id="p-forma" name="forma">
            <option value="dinheiro">Dinheiro</option>
            <option value="pix">Pix</option>
          </select>
        </div>
      </div>
      <div class="campo">
        <label for="p-valor">Valor (vazio = valor padrão da pelada)</label>
        <input id="p-valor" name="valor" type="text" inputmode="decimal" placeholder="padrão">
      </div>
      <button class="btn btn-pequeno" type="submit">Registrar e lançar no caixa</button>
    </form>
  </div>

  <?php if ($multas !== []): ?>
    <h2>Multas</h2>
    <?php foreach ($multas as $m): ?>
      <div class="cartao pagamento">
        <div>
          <strong><?= htmlspecialchars($moeda($m['valor'])) ?></strong>
          <?php $rot = ['pendente' => ['pendente', 'is-vermelho'], 'paga' => ['paga', 'is-verde'], 'cancelada' => ['cancelada', '']][$m['status']] ?? [$m['status'], '']; ?>
          <span class="etiqueta <?= $rot[1] ?>"><?= htmlspecialchars($rot[0]) ?></span>
          <div class="muted pequeno">
            <?= $m['data_jogo'] !== null ? 'Jogo de ' . htmlspecialchars((new DateTimeImmutable($m['data_jogo']))->format('d/m')) . ' · ' : '' ?>
            <?= htmlspecialchars((string) $m['motivo']) ?>
          </div>
        </div>
        <?php if ($m['status'] === 'pendente'): ?>
          <div class="acoes">
            <form method="post" action="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>/multas/<?= (int) $m['id'] ?>/receber">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="jogador_id" value="<?= (int) $jogador['id'] ?>">
              <button class="btn btn-pequeno" type="submit">Recebi</button>
            </form>
            <form method="post" action="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>/multas/<?= (int) $m['id'] ?>/cancelar"
                  onsubmit="return confirm('Cancelar essa multa? Ela sai da pendência do jogador.')">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="jogador_id" value="<?= (int) $jogador['id'] ?>">
              <button class="link is-vermelho" type="submit">cancelar</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <form method="post" action="<?= htmlspecialchars($acao) ?>/ativo" class="rodape">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="ativo" value="<?= $jogador['ativo'] ? '0' : '1' ?>">
    <button type="submit"><?= $jogador['ativo'] ? 'Desativar jogador' : 'Reativar jogador' ?></button>
  </form>
<?php endif; ?>
