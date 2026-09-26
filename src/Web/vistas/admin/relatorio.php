<?php

use RcInfoti\Pelada\Financeiro\ResumoMensalJogador;

/** @var DateTimeImmutable $mes */
/** @var list<ResumoMensalJogador> $linhas */
/** @var string $base */
/** @var int $peladaId */

$moeda = static fn (float $v): string => number_format($v, 2, ',', '.');
$url = $base . '/admin/p/' . $peladaId . '/relatorio';
$meses = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$tf = array_sum(array_map(static fn ($l) => $l->pagoFutebol, $linhas));
$tfe = array_sum(array_map(static fn ($l) => $l->pagoFesta, $linhas));
$tp = array_sum(array_map(static fn ($l) => $l->pendente, $linhas));
?>
<div class="cab-lista navega-mes">
  <a href="<?= htmlspecialchars($url) ?>?mes=<?= $mes->modify('-1 month')->format('Y-m') ?>">‹</a>
  <h1><?= htmlspecialchars(ucfirst($meses[(int) $mes->format('n')]) . ' ' . $mes->format('Y')) ?></h1>
  <a href="<?= htmlspecialchars($url) ?>?mes=<?= $mes->modify('+1 month')->format('Y-m') ?>">›</a>
</div>
<p class="muted">Pagamentos confirmados no mês, por jogador. Pendente = saldo em aberto hoje.</p>

<div class="cartao rolagem">
  <table class="tabela">
    <thead><tr><th>Jogador</th><th>Futebol</th><th>Festa</th><th>Pendente</th></tr></thead>
    <tbody>
      <?php foreach ($linhas as $l): ?>
        <tr>
          <td><a href="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>/jogadores/<?= $l->jogadorId ?>"><?= htmlspecialchars($l->nome) ?></a></td>
          <td><?= $moeda($l->pagoFutebol) ?></td>
          <td><?= $moeda($l->pagoFesta) ?></td>
          <td class="<?= $l->pendente > 0 ? 'is-vermelho' : 'muted' ?>"><?= $moeda($l->pendente) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>Total</th><th><?= $moeda($tf) ?></th><th><?= $moeda($tfe) ?></th><th><?= $moeda($tp) ?></th></tr></tfoot>
  </table>
</div>

<a class="btn btn-pequeno" href="<?= htmlspecialchars($url) ?>.csv?mes=<?= $mes->format('Y-m') ?>">Baixar planilha (CSV)</a>
