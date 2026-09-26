<?php
/** @var array{id:int,nome:string,email:string,papel:string,ativo:bool}|null $alvo */
/** @var array<string,mixed> $valores */
/** @var list<int> $vinculos */
/** @var array<string,string> $erros */
/** @var list<array{id:int,nome:string,slug:string,ativa:bool}> $peladas */
/** @var bool $euMesmo */
/** @var string $base */
/** @var string $csrf */
$v = static fn (string $k): string => htmlspecialchars((string) ($valores[$k] ?? ''));
$url = $base . '/admin/organizadores' . ($alvo !== null ? '/' . $alvo['id'] : '');
$checks = static function () use ($peladas, $vinculos): void {
    foreach ($peladas as $pl) {
        $marcado = in_array($pl['id'], $vinculos, true) ? 'checked' : '';
        echo '<label class="check"><input type="checkbox" name="peladas[]" value="' . (int) $pl['id'] . '" ' . $marcado . '> '
            . htmlspecialchars($pl['nome']) . ($pl['ativa'] ? '' : ' <span class="etiqueta">inativa</span>') . '</label>';
    }
};
?>
<p class="marca"><a href="<?= htmlspecialchars($base) ?>/admin/organizadores">‹ Organizadores</a></p>

<?php if ($alvo === null): ?>
  <h1>Novo organizador</h1>
  <form method="post" action="<?= htmlspecialchars($url) ?>" class="cartao">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div class="campo"><label for="nome">Nome</label><input id="nome" name="nome" type="text" value="<?= $v('nome') ?>" required></div>
    <div class="campo"><label for="email">E-mail (login)</label><input id="email" name="email" type="email" value="<?= $v('email') ?>" required></div>
    <div class="campo">
      <label for="papel">Papel</label>
      <select id="papel" name="papel">
        <option value="organizador" <?= ($valores['papel'] ?? '') !== 'super_admin' ? 'selected' : '' ?>>Organizador (só as peladas marcadas)</option>
        <option value="super_admin" <?= ($valores['papel'] ?? '') === 'super_admin' ? 'selected' : '' ?>>Super admin (tudo)</option>
      </select>
    </div>
    <div class="campo"><label>Peladas que organiza</label><?php $checks(); ?></div>
    <?php if (isset($erros['geral'])): ?><p class="aviso"><?= htmlspecialchars($erros['geral']) ?></p><?php endif; ?>
    <button class="btn btn-confirmar" type="submit">Criar e gerar senha</button>
  </form>
<?php else: ?>
  <h1><?= htmlspecialchars($alvo['nome']) ?></h1>
  <div class="cartao">
    <div><?= htmlspecialchars($alvo['email']) ?></div>
    <div class="muted"><?= $alvo['papel'] === 'super_admin' ? 'Super admin — vê todas as peladas' : 'Organizador' ?><?= $alvo['ativo'] ? '' : ' · inativo' ?></div>
  </div>

  <?php if ($alvo['papel'] !== 'super_admin'): ?>
    <h2>Peladas que organiza</h2>
    <form method="post" action="<?= htmlspecialchars($url) ?>" class="cartao">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <?php $checks(); ?>
      <button class="btn btn-pequeno" type="submit" style="margin-top:.6rem">Salvar</button>
    </form>
  <?php endif; ?>

  <div class="cartao cab-lista">
    <span>Esqueceu a senha?</span>
    <form method="post" action="<?= htmlspecialchars($url) ?>/senha" onsubmit="return confirm('Gerar senha nova? A atual para de funcionar.')">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <button class="btn btn-pequeno" type="submit">Gerar senha nova</button>
    </form>
  </div>

  <?php if (!$euMesmo): ?>
    <form method="post" action="<?= htmlspecialchars($url) ?>/ativo" class="rodape">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="ativo" value="<?= $alvo['ativo'] ? '0' : '1' ?>">
      <button type="submit"><?= $alvo['ativo'] ? 'Desativar usuário' : 'Reativar usuário' ?></button>
    </form>
  <?php endif; ?>
<?php endif; ?>
