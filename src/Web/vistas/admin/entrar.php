<?php
/** @var string $base */
/** @var string $csrf */
/** @var bool $erro */
/** @var bool $bloqueado */
?>
<p class="marca">Área do organizador</p>
<h1>Entrar</h1>
<div class="cartao">
  <form method="post" action="<?= htmlspecialchars($base) ?>/admin/entrar">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div class="campo">
      <label for="email">E-mail</label>
      <input id="email" name="email" type="email" autocomplete="username" required>
    </div>
    <div class="campo">
      <label for="senha">Senha</label>
      <input id="senha" name="senha" type="password" autocomplete="current-password" required>
    </div>
    <?php if ($bloqueado): ?>
      <p class="aviso">Muitas tentativas erradas. Tente de novo em 15 minutos.</p>
    <?php elseif ($erro): ?>
      <p class="aviso">E-mail ou senha não conferem.</p>
    <?php endif; ?>
    <button class="btn btn-confirmar" type="submit">Entrar</button>
  </form>
</div>
