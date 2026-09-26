<?php
/** @var list<array{id:int,nome:string,email:string,papel:string,ativo:bool}> $usuarios */
/** @var string $base */
?>
<div class="cab-lista">
  <h1>Organizadores</h1>
  <a class="btn btn-pequeno" href="<?= htmlspecialchars($base) ?>/admin/organizadores/novo">+ Novo</a>
</div>
<?php foreach ($usuarios as $u): ?>
  <a class="cartao linha-link <?= $u['ativo'] ? '' : 'inativo' ?>" href="<?= htmlspecialchars($base) ?>/admin/organizadores/<?= $u['id'] ?>">
    <span>
      <strong><?= htmlspecialchars($u['nome']) ?></strong>
      <span class="muted"><?= htmlspecialchars($u['email']) ?></span>
      <?php if ($u['papel'] === 'super_admin'): ?><span class="etiqueta is-amarelo">admin</span><?php endif; ?>
      <?php if (!$u['ativo']): ?><span class="etiqueta">inativo</span><?php endif; ?>
    </span>
    <span class="seta">›</span>
  </a>
<?php endforeach; ?>
