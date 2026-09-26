<?php
/** @var list<array{id:int,nome:string,tipo:string,saldo_pendente:float,ativo:bool,tem_pin:bool}> $jogadores */
/** @var string $base */
/** @var int $peladaId */
$moeda = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
?>
<div class="cab-lista">
  <h1>Jogadores</h1>
  <a class="btn btn-pequeno" href="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>/jogadores/novo">+ Novo</a>
</div>

<?php if ($jogadores === []): ?>
  <div class="cartao muted">Nenhum jogador ainda. Cadastre o primeiro.</div>
<?php endif; ?>

<?php foreach ($jogadores as $j): ?>
  <a class="cartao linha-link <?= $j['ativo'] ? '' : 'inativo' ?>"
     href="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>/jogadores/<?= (int) $j['id'] ?>">
    <span>
      <strong><?= htmlspecialchars($j['nome']) ?></strong>
      <span class="muted"><?= $j['tipo'] === 'goleiro' ? 'goleiro' : 'linha' ?></span>
      <?php if (!$j['ativo']): ?><span class="etiqueta">inativo</span><?php endif; ?>
      <?php if (!$j['tem_pin']): ?><span class="etiqueta is-amarelo">sem PIN</span><?php endif; ?>
      <?php if ($j['saldo_pendente'] > 0): ?><span class="etiqueta is-vermelho"><?= htmlspecialchars($moeda($j['saldo_pendente'])) ?></span><?php endif; ?>
    </span>
    <span class="seta">›</span>
  </a>
<?php endforeach; ?>
