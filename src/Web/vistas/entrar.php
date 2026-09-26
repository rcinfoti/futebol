<?php
/** @var list<array{id:int,nome:string}> $jogadores */
/** @var string $csrf */
/** @var bool $erro */
?>
<p class="marca">Pelada de Quinta</p>
<h1>Bora pra pelada</h1>

<div class="cartao">
  <form method="post" action="/entrar">
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
    <?php if ($erro): ?>
      <p class="aviso">PIN ou nome não conferem. Tente de novo.</p>
    <?php endif; ?>
    <button class="btn btn-confirmar" type="submit">Entrar</button>
  </form>
</div>

<p class="muted" style="text-align:center;font-size:.9rem">Não sabe seu PIN? Fale com o organizador da pelada.</p>
