<?php
/** @var list<array{id:int,nome:string}> $jogadores */
/** @var string $csrf */
/** @var bool $erro */
/** @var bool $bloqueado */
/** @var array{id:int,nome:string,slug:string} $pelada */
/** @var string $base */
?>
<p class="marca"><?= htmlspecialchars($pelada['nome']) ?></p>
<h1>Bora pra pelada</h1>

<div class="cartao">
  <form method="post" action="<?= htmlspecialchars($base . '/p/' . rawurlencode($pelada['slug']) . '/entrar') ?>">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <div class="campo">
      <label for="jogador_id">Quem é você?</label>
      <select id="jogador_id" name="jogador_id" required>
        <option value="" disabled selected>Escolha seu nome</option>
        <?php foreach ($jogadores as $j): ?>
          <option value="<?= (int) $j['id'] ?>"><?= htmlspecialchars($j['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="campo">
      <label for="pin">Seu PIN</label>
      <input id="pin" name="pin" type="tel" inputmode="numeric" autocomplete="off"
             pattern="[0-9]*" maxlength="8" placeholder="••••" required>
    </div>
    <?php if ($bloqueado): ?>
      <p class="aviso">Muitas tentativas erradas. Espere uns 15 minutos ou peça pro organizador trocar seu PIN.</p>
    <?php elseif ($erro): ?>
      <p class="aviso">PIN ou nome não conferem. Tente de novo.</p>
    <?php endif; ?>
    <button class="btn btn-confirmar" type="submit">Entrar</button>
  </form>
</div>

<p class="muted" style="text-align:center;font-size:.9rem">Não sabe seu PIN? Fale com o organizador da pelada.</p>
