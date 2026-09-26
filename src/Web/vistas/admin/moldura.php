<?php
/** @var string $miolo */
/** @var string $base */
/** @var int|null $peladaId */
/** @var array{id:int,nome:string,email:string,papel:string}|null $usuario */
/** @var array{tipo:string,msg:string,pin:?string}|null $flash */
/** @var string $csrf */
?>
<?php if ($usuario !== null): ?>
  <header class="topo">
    <a class="marca" href="<?= htmlspecialchars($base) ?>/admin">Pelada · <?= $usuario['papel'] === 'super_admin' ? 'Admin' : 'Organizador' ?></a>
    <div class="topo-links">
      <?php if ($usuario['papel'] === 'super_admin'): ?>
        <a class="link" href="<?= htmlspecialchars($base) ?>/admin">Peladas</a>
        <a class="link" href="<?= htmlspecialchars($base) ?>/admin/organizadores">Organizadores</a>
      <?php endif; ?>
      <a class="link" href="<?= htmlspecialchars($base) ?>/admin/senha">Minha senha</a>
      <form method="post" action="<?= htmlspecialchars($base) ?>/admin/sair" class="inline">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="link">Sair</button>
      </form>
    </div>
  </header>
  <?php if ($peladaId !== null): ?>
    <nav class="abas">
      <a href="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>">Painel</a>
      <a href="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>/jogadores">Jogadores</a>
      <a href="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>/caixa">Caixa</a>
      <a href="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>/relatorio">Relatório</a>
      <a href="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>/config">Config</a>
    </nav>
  <?php endif; ?>
<?php endif; ?>

<?php if ($flash !== null): ?>
  <div class="cartao flash flash-<?= htmlspecialchars($flash['tipo']) ?>" role="status">
    <?= htmlspecialchars($flash['msg']) ?>
    <?php if ($flash['pin'] !== null && $flash['tipo'] === 'senha'): ?>
      <div class="pin senha" data-senha="<?= htmlspecialchars($flash['pin']) ?>"><?= htmlspecialchars($flash['pin']) ?></div>
    <?php elseif ($flash['pin'] !== null): ?>
      <div class="pin" data-pin="<?= htmlspecialchars($flash['pin']) ?>"><?= htmlspecialchars($flash['pin']) ?></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?= $miolo ?>
