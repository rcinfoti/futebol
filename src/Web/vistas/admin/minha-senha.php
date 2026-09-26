<?php
/** @var string|null $erro */
/** @var string $base */
/** @var string $csrf */
?>
<h1>Minha senha</h1>
<form method="post" action="<?= htmlspecialchars($base) ?>/admin/senha" class="cartao">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
  <div class="campo"><label for="atual">Senha atual</label><input id="atual" name="atual" type="password" autocomplete="current-password" required></div>
  <div class="campo"><label for="nova">Senha nova (mín. 8)</label><input id="nova" name="nova" type="password" minlength="8" autocomplete="new-password" required></div>
  <div class="campo"><label for="repete">Repita a nova</label><input id="repete" name="repete" type="password" minlength="8" autocomplete="new-password" required></div>
  <?php if ($erro !== null): ?><p class="aviso"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
  <button class="btn btn-confirmar" type="submit">Trocar senha</button>
</form>
