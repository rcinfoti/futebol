<?php

use RcInfoti\Pelada\Painel\PainelJogador;

/** @var PainelJogador $p */
/** @var string $csrf */

$diasSemana = [1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado', 7 => 'domingo'];
$quandoJogo = null;
if ($p->dataJogo !== null) {
    $d = new DateTimeImmutable($p->dataJogo);
    $quandoJogo = $diasSemana[(int) $d->format('N')] . ', ' . $d->format('d/m');
}

$mapa = [
    'confirmado' => ['classe' => 'is-verde', 'texto' => 'Você está confirmado', 'sub' => 'Te vejo em campo.'],
    'espera' => ['classe' => 'is-amarelo', 'texto' => 'Você está na espera', 'sub' => 'Sobe pra vaga assim que abrir.'],
    'desistiu' => ['classe' => 'is-vermelho', 'texto' => 'Você marcou que não vai', 'sub' => 'Mudou de ideia? É só confirmar.'],
    'fora' => ['classe' => 'is-vermelho', 'texto' => 'Você ainda não confirmou', 'sub' => 'Bora? Confirme sua presença.'],
];
$st = $mapa[$p->situacao] ?? null;

$moeda = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
?>
<p class="marca">Pelada · <?= htmlspecialchars($p->nome) ?></p>

<?php if ($p->situacao === 'sem_rodada'): ?>
  <h1>Sem jogo aberto agora</h1>
  <div class="cartao muted">Quando a próxima rodada abrir, você confirma sua presença por aqui.</div>
<?php else: ?>
  <h1>Você vai jogar <?= htmlspecialchars($quandoJogo ?? '') ?>?</h1>

  <div class="cartao">
    <div class="status <?= $st['classe'] ?>">
      <span class="ponto"></span>
      <span><?= htmlspecialchars($st['texto']) ?><small><?= htmlspecialchars($st['sub']) ?></small></span>
    </div>
  </div>

  <form method="post" action="/confirmar">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <button class="btn btn-confirmar" type="submit">Confirmar presença</button>
  </form>
  <form method="post" action="/nao-vou">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <button class="btn btn-nao" type="submit">Não vou</button>
  </form>

  <div class="cartao" style="margin-top:1rem">
    <div class="muted">Essa semana você paga</div>
    <div class="valor"><?= htmlspecialchars($moeda($p->devidoSemana)) ?></div>
    <form method="post" action="/avisar-pagamento" style="margin-top:.8rem">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="categoria" value="futebol">
      <div class="campo">
        <label for="forma">Como você pagou?</label>
        <select id="forma" name="forma">
          <option value="pix">Pix</option>
          <option value="dinheiro">Dinheiro</option>
        </select>
      </div>
      <div class="campo">
        <label for="valor">Valor (R$)</label>
        <input id="valor" name="valor" type="number" inputmode="decimal" step="0.01" min="0"
               value="<?= htmlspecialchars(number_format($p->devidoSemana, 2, '.', '')) ?>">
      </div>
      <button class="btn btn-nao" type="submit" style="color:var(--chalk);border-color:var(--line)">Avisar pagamento</button>
    </form>
  </div>
<?php endif; ?>

<?php if ($p->saldoPendente > 0): ?>
  <div class="cartao">
    <div class="status is-vermelho" style="font-size:1.15rem">
      <span class="ponto"></span>
      <span>Você tem <?= htmlspecialchars($moeda($p->saldoPendente)) ?> em aberto</span>
    </div>
  </div>
<?php endif; ?>

<div class="rodape">
  <form method="post" action="/sair">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <button type="submit">Sair</button>
  </form>
</div>
