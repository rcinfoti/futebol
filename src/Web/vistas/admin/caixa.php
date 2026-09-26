<?php

use RcInfoti\Pelada\Financeiro\MovimentoCaixa;
use RcInfoti\Pelada\Financeiro\TipoMovimento;

/** @var float $saldo */
/** @var DateTimeImmutable $mes */
/** @var list<MovimentoCaixa> $extrato */
/** @var float $entradasMes */
/** @var float $saidasMes */
/** @var array<string,string> $categoriasSaida */
/** @var array<string,string> $categoriasEntrada */
/** @var string $hoje */
/** @var string $base */
/** @var string $csrf */
/** @var int $peladaId */

$moeda = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$url = $base . '/admin/p/' . $peladaId . '/caixa';
$meses = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$rotuloMes = $meses[(int) $mes->format('n')] . ' de ' . $mes->format('Y');
$form = static function (string $tipo, string $titulo, array $categorias) use ($url, $csrf, $hoje): void {
    ?>
    <details class="cartao">
      <summary><?= htmlspecialchars($titulo) ?></summary>
      <form method="post" action="<?= htmlspecialchars($url) ?>/lancar" style="margin-top:.8rem">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="tipo" value="<?= $tipo ?>">
        <div class="grade-2">
          <div class="campo">
            <label>Categoria</label>
            <select name="categoria" required>
              <?php foreach ($categorias as $k => $rot): ?>
                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($rot) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="campo">
            <label>Valor (R$)</label>
            <input name="valor" type="text" inputmode="decimal" required placeholder="0,00">
          </div>
        </div>
        <div class="grade-2">
          <div class="campo">
            <label>Data</label>
            <input name="data" type="date" value="<?= htmlspecialchars($hoje) ?>" max="<?= htmlspecialchars($hoje) ?>" required>
          </div>
          <div class="campo">
            <label>Descrição (opcional)</label>
            <input name="descricao" type="text" maxlength="255">
          </div>
        </div>
        <button class="btn btn-pequeno" type="submit">Lançar</button>
      </form>
    </details>
    <?php
};
?>
<h1>Caixa</h1>

<div class="cartao">
  <div class="muted">Saldo atual</div>
  <div class="valor <?= $saldo < 0 ? 'is-vermelho' : '' ?>"><?= htmlspecialchars($moeda($saldo)) ?></div>
</div>

<?php $form('saida', '− Lançar gasto', $categoriasSaida); ?>
<?php $form('entrada', '+ Lançar entrada avulsa (saldo inicial, doação)', $categoriasEntrada); ?>

<div class="cab-lista navega-mes">
  <a href="<?= htmlspecialchars($url) ?>?mes=<?= $mes->modify('-1 month')->format('Y-m') ?>">‹</a>
  <h2><?= htmlspecialchars(ucfirst($rotuloMes)) ?></h2>
  <a href="<?= htmlspecialchars($url) ?>?mes=<?= $mes->modify('+1 month')->format('Y-m') ?>">›</a>
</div>

<div class="grade-2">
  <div class="cartao"><div class="muted">Entradas no mês</div><div class="valor is-verde"><?= htmlspecialchars($moeda($entradasMes)) ?></div></div>
  <div class="cartao"><div class="muted">Saídas no mês</div><div class="valor is-vermelho"><?= htmlspecialchars($moeda($saidasMes)) ?></div></div>
</div>

<?php if ($extrato === []): ?>
  <div class="cartao muted">Nenhum lançamento nesse mês.</div>
<?php else: ?>
  <div class="cartao">
    <ul class="extrato">
      <?php foreach ($extrato as $m): ?>
        <?php $entrada = $m->tipo === TipoMovimento::Entrada; $manual = $m->pagamentoId === null && !in_array($m->categoria, ['pagamento', 'multa'], true); ?>
        <li>
          <span class="muted"><?= htmlspecialchars($m->ocorridoEm->format('d/m')) ?></span>
          <span class="desc"><?= htmlspecialchars($m->descricao ?? ($m->categoria === 'pagamento' ? 'Pagamento' : $m->categoria)) ?></span>
          <span class="<?= $entrada ? 'is-verde' : 'is-vermelho' ?>"><?= $entrada ? '+' : '−' ?> <?= htmlspecialchars($moeda($m->valor)) ?></span>
          <?php if ($manual): ?>
            <form method="post" class="inline" action="<?= htmlspecialchars($url) ?>/<?= $m->id ?>/excluir"
                  onsubmit="return confirm('Excluir esse lançamento?')">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="mes" value="<?= $mes->format('Y-m') ?>">
              <button type="submit" class="link" title="Excluir">×</button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
