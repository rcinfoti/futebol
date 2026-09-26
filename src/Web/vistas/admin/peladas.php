<?php
/** @var list<array{id:int,nome:string,slug:string,ativa:bool}> $peladas */
/** @var string $base */
?>
<div class="cab-lista">
  <h1>Suas peladas</h1>
  <?php if (($usuario['papel'] ?? '') === 'super_admin'): ?>
    <a class="btn btn-pequeno" href="<?= htmlspecialchars($base) ?>/admin/peladas/nova">+ Nova</a>
  <?php endif; ?>
</div>
<?php if ($peladas === []): ?>
  <div class="cartao muted">Você ainda não foi vinculado a nenhuma pelada. Fale com o administrador.</div>
<?php endif; ?>
<?php foreach ($peladas as $pl): ?>
  <a class="cartao linha-link" href="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $pl['id'] ?>">
    <strong><?= htmlspecialchars($pl['nome']) ?></strong>
    <?php if (!$pl['ativa']): ?><span class="etiqueta">inativa</span><?php endif; ?>
    <span class="seta">›</span>
  </a>
<?php endforeach; ?>
