<?php

use RcInfoti\Pelada\Admin\PainelPelada;

/** @var PainelPelada $p */
/** @var string $base */
/** @var string $csrf */
/** @var list<array{id:int,nome:string,tipo:string}> $fora */

$moeda = static fn (float $v): string => 'R$ ' . number_format($v, 2, ',', '.');
$link = $base . '/p/' . rawurlencode($p->slug) . '/entrar';
$acao = static function (string $rota, int $jogadorId, string $rotulo, string $classe = '') use ($base, $p, $csrf): void {
    ?>
    <form method="post" class="inline" action="<?= htmlspecialchars($base) ?>/admin/p/<?= $p->peladaId ?>/rodada/<?= $jogadorId ?>/<?= $rota ?>">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="rodada_id" value="<?= (int) $p->rodadaId ?>">
      <button type="submit" class="link <?= $classe ?>"><?= htmlspecialchars($rotulo) ?></button>
    </form>
    <?php
};
$lista = static function (string $titulo, array $itens, ?int $limite, string $classe, array $acoes = []) use ($base, $p, $acao): void {
    ?>
    <div class="cartao">
      <div class="cab-lista">
        <strong><?= htmlspecialchars($titulo) ?></strong>
        <span class="<?= $classe ?>"><?= count($itens) ?><?= $limite !== null ? ' / ' . $limite : '' ?></span>
      </div>
      <?php if ($itens === []): ?>
        <p class="muted">Ninguém.</p>
      <?php else: ?>
        <ol class="lista">
          <?php foreach ($itens as $i): ?>
            <li>
              <a href="<?= htmlspecialchars($base) ?>/admin/p/<?= $p->peladaId ?>/jogadores/<?= (int) $i['id'] ?>"><?= htmlspecialchars($i['nome']) ?></a>
              <?php if ($i['pago']): ?><span class="etiqueta is-verde">pago</span><?php endif; ?>
              <?php foreach ($acoes as [$rota, $rotulo, $cl]) { $acao($rota, (int) $i['id'], $rotulo, $cl); } ?>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </div>
    <?php
};
?>
<p class="marca"><?= htmlspecialchars($p->nome) ?></p>

<div class="cartao">
  <div class="muted">Link dos jogadores</div>
  <div class="link-pelada">
    <code id="link-pelada" data-caminho="<?= htmlspecialchars($link) ?>"><?= htmlspecialchars($link) ?></code>
    <button type="button" class="link" id="copiar-link">Copiar</button>
    <a class="link" id="whats-link" href="#" target="_blank" rel="noopener">WhatsApp</a>
  </div>
</div>

<div class="grade-2">
  <div class="cartao">
    <div class="muted">Caixa</div>
    <div class="valor <?= $p->saldoCaixa < 0 ? 'is-vermelho' : '' ?>"><?= htmlspecialchars($moeda($p->saldoCaixa)) ?></div>
  </div>
  <div class="cartao">
    <div class="muted">A receber (multas/pendências)</div>
    <div class="valor"><?= htmlspecialchars($moeda(array_sum(array_column($p->pendencias, 'saldo')))) ?></div>
  </div>
</div>

<?php if ($p->pagamentosAConfirmar !== []): ?>
  <h2>Pagamentos a confirmar</h2>
  <?php foreach ($p->pagamentosAConfirmar as $pg): ?>
    <div class="cartao pagamento">
      <div>
        <strong><?= htmlspecialchars($pg['jogador']) ?></strong> · <?= htmlspecialchars($moeda($pg['valor'])) ?>
        <div class="muted"><?= htmlspecialchars($pg['categoria'] === 'festa' ? ($pg['escopo'] === 'ano' ? 'Festa (ano)' : 'Festa') : 'Futebol') ?>
          · <?= htmlspecialchars($pg['forma'] === 'pix' ? 'Pix' : 'Dinheiro') ?>
          · <?= htmlspecialchars((new DateTimeImmutable($pg['criado_em']))->format('d/m H:i')) ?>
          <?php if ($pg['comprovante']): ?>
            · <a href="<?= htmlspecialchars($base) ?>/admin/p/<?= $p->peladaId ?>/pagamentos/<?= (int) $pg['id'] ?>/comprovante" target="_blank" rel="noopener">ver comprovante</a>
          <?php endif; ?></div>
      </div>
      <form method="post" action="<?= htmlspecialchars($base) ?>/admin/p/<?= $p->peladaId ?>/pagamentos/<?= (int) $pg['id'] ?>/confirmar">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <button class="btn btn-pequeno" type="submit">Confirmar</button>
      </form>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<h2>Rodada<?= $p->dataJogo !== null ? ' de ' . htmlspecialchars((new DateTimeImmutable($p->dataJogo))->format('d/m')) : '' ?></h2>
<?php if ($p->rodadaId === null): ?>
  <div class="cartao muted">Nenhuma rodada aberta. Ela abre sozinha no dia e hora configurados.</div>
<?php else: ?>
  <?php $naoVai = ['desistir', 'não vai', 'is-vermelho']; $sobe = ['promover', 'subir', 'is-verde']; ?>
  <?php $lista('Linha — confirmados', $p->confirmadosLinha, $p->limiteLinha, 'is-verde', [$naoVai]); ?>
  <?php $lista('Linha — espera', $p->esperaLinha, null, 'is-amarelo', [$sobe, $naoVai]); ?>
  <?php $lista('Goleiros — confirmados', $p->confirmadosGoleiro, $p->limiteGoleiro, 'is-verde', [$naoVai]); ?>
  <?php $lista('Goleiros — espera', $p->esperaGoleiro, null, 'is-amarelo', [$sobe, $naoVai]); ?>

  <?php if ($fora !== []): ?>
    <div class="cartao">
      <form method="post" class="linha-form" action="<?= htmlspecialchars($base) ?>/admin/p/<?= $p->peladaId ?>/rodada/adicionar">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="rodada_id" value="<?= (int) $p->rodadaId ?>">
        <label for="jogador_id" class="sr">Colocar na rodada</label>
        <select id="jogador_id" name="jogador_id" required>
          <option value="" disabled selected>Colocar jogador na rodada…</option>
          <?php foreach ($fora as $f): ?>
            <option value="<?= (int) $f['id'] ?>"><?= htmlspecialchars($f['nome']) ?><?= $f['tipo'] === 'goleiro' ? ' (goleiro)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-pequeno" type="submit">Colocar</button>
      </form>
      <p class="muted pequeno">Se estiver lotado, entra na espera. "Subir" passa por cima do limite.</p>
    </div>
  <?php endif; ?>
  <?php if ($p->desistiram !== []) { $lista('Desistiram', $p->desistiram, null, 'is-vermelho'); } ?>
<?php endif; ?>

<?php if ($p->pendencias !== []): ?>
  <h2>Pendências</h2>
  <div class="cartao">
    <ul class="lista">
      <?php foreach ($p->pendencias as $pd): ?>
        <li><a href="<?= htmlspecialchars($base) ?>/admin/p/<?= $p->peladaId ?>/jogadores/<?= (int) $pd['id'] ?>"><?= htmlspecialchars($pd['nome']) ?></a>
          <span class="is-vermelho"><?= htmlspecialchars($moeda($pd['saldo'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<script>
  (function () {
    var el = document.getElementById('link-pelada');
    var url = location.origin + el.dataset.caminho;
    el.textContent = url;
    document.getElementById('whats-link').href =
      'https://wa.me/?text=' + encodeURIComponent(<?= json_encode('Confirme sua presença na ' . $p->nome . ': ', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?> + url);
    document.getElementById('copiar-link').addEventListener('click', function () {
      navigator.clipboard && navigator.clipboard.writeText(url).then(function () {
        document.getElementById('copiar-link').textContent = 'Copiado!';
      });
    });
  })();
</script>
