<?php
/** @var list<array{id:int,nome:string,slug:string}> $peladas */
/** @var string $base */
?>
<h1>Qual é a sua pelada?</h1>

<?php if ($peladas === []): ?>
  <div class="cartao muted">Nenhuma pelada aberta no momento.</div>
<?php else: ?>
  <?php foreach ($peladas as $pl): ?>
    <a class="btn btn-confirmar" style="display:block;text-align:center;text-decoration:none;margin-bottom:.8rem"
       href="<?= htmlspecialchars($base . '/p/' . rawurlencode($pl['slug']) . '/entrar') ?>"><?= htmlspecialchars($pl['nome']) ?></a>
  <?php endforeach; ?>
<?php endif; ?>
