<?php
/** @var array<string,mixed>|null $pelada */
/** @var array<string,string> $valores */
/** @var array<string,string> $erros */
/** @var array<int,string> $dias */
/** @var bool $superAdmin */
/** @var string $base */
/** @var string $csrf */
/** @var int|null $peladaId */
$v = static fn (string $k): string => htmlspecialchars($valores[$k] ?? '');
$erro = static function (string $k) use ($erros): void {
    if (isset($erros[$k])) {
        echo '<p class="aviso">' . htmlspecialchars($erros[$k]) . '</p>';
    }
};
$diaHora = static function (string $rotulo, string $d, string $h, string $ajuda = '') use ($dias, $valores, $v, $erro): void {
    ?>
    <div class="campo">
      <label><?= htmlspecialchars($rotulo) ?></label>
      <div class="linha-form">
        <select name="<?= $d ?>" aria-label="<?= htmlspecialchars($rotulo) ?> — dia">
          <?php foreach ($dias as $n => $nome): ?>
            <option value="<?= $n ?>" <?= ($valores[$d] ?? '') === (string) $n ? 'selected' : '' ?>><?= $nome ?></option>
          <?php endforeach; ?>
        </select>
        <input name="<?= $h ?>" type="time" value="<?= $v($h) ?>" required aria-label="<?= htmlspecialchars($rotulo) ?> — hora" style="max-width:9rem">
      </div>
      <?php if ($ajuda !== ''): ?><p class="muted pequeno"><?= htmlspecialchars($ajuda) ?></p><?php endif; ?>
      <?php $erro($d); $erro($h); ?>
    </div>
    <?php
};
$acao = $peladaId === null ? $base . '/admin/peladas' : $base . '/admin/p/' . $peladaId . '/config';
?>
<h1><?= $peladaId === null ? 'Nova pelada' : 'Configuração' ?></h1>

<form method="post" action="<?= htmlspecialchars($acao) ?>">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

  <div class="cartao">
    <div class="campo">
      <label for="nome">Nome</label>
      <input id="nome" name="nome" type="text" value="<?= $v('nome') ?>" maxlength="120" required>
      <?php $erro('nome'); ?>
    </div>
    <div class="campo">
      <label for="slug">Link dos jogadores: …/p/<strong>link</strong>/entrar</label>
      <input id="slug" name="slug" type="text" value="<?= $v('slug') ?>" maxlength="60" placeholder="gerado a partir do nome" pattern="[a-z0-9-]*">
      <?php if ($peladaId !== null): ?><p class="muted pequeno">Mudar o link quebra o que já foi mandado no grupo.</p><?php endif; ?>
      <?php $erro('slug'); ?>
    </div>
  </div>

  <h2>Semana da pelada</h2>
  <div class="cartao">
    <?php $diaHora('Dia e hora do jogo', 'dia_jogo', 'hora_jogo'); ?>
    <?php $diaHora('Abre a confirmação', 'abre_dia', 'abre_hora', 'Lista por ordem de chegada; quem desiste libera a vaga pro 1º da espera.'); ?>
    <?php $diaHora('Vira pra "pago primeiro"', 'vira_regra_dia', 'vira_regra_hora', 'A partir daqui, vaga que abrir vai pra quem já pagou.'); ?>
    <?php $diaHora('Prazo da multa', 'prazo_multa_dia', 'prazo_multa_hora', 'Confirmado que desistir depois disso leva multa.'); ?>
    <?php if ($peladaId !== null): ?><p class="muted pequeno">Mudanças de horário valem a partir da próxima rodada.</p><?php endif; ?>
  </div>

  <h2>Vagas</h2>
  <div class="cartao grade-2">
    <div class="campo"><label for="ll">Linha</label><input id="ll" name="limite_linha" type="number" min="1" max="100" value="<?= $v('limite_linha') ?>"><?php $erro('limite_linha'); ?></div>
    <div class="campo"><label for="lg">Goleiros</label><input id="lg" name="limite_goleiro" type="number" min="0" max="20" value="<?= $v('limite_goleiro') ?>"><?php $erro('limite_goleiro'); ?></div>
  </div>

  <h2>Valores (R$)</h2>
  <div class="cartao">
    <div class="grade-2">
      <div class="campo"><label for="vf">Futebol / semana</label><input id="vf" name="valor_futebol" type="text" inputmode="decimal" value="<?= $v('valor_futebol') ?>"><?php $erro('valor_futebol'); ?></div>
      <div class="campo"><label for="vs">Festa / semana</label><input id="vs" name="valor_festa_semana" type="text" inputmode="decimal" value="<?= $v('valor_festa_semana') ?>"><?php $erro('valor_festa_semana'); ?></div>
    </div>
    <div class="campo"><label for="va">Festa — temporada inteira de uma vez</label><input id="va" name="valor_festa_ano" type="text" inputmode="decimal" value="<?= $v('valor_festa_ano') ?>"><?php $erro('valor_festa_ano'); ?></div>
    <p class="muted pequeno">Goleiro não paga o futebol, só a festa.</p>
  </div>

  <h2>Temporada da festa</h2>
  <div class="cartao">
    <div class="grade-2">
      <div class="campo"><label for="fi">De (DD/MM)</label><input id="fi" name="festa_inicio" type="text" inputmode="numeric" placeholder="01/01" value="<?= $v('festa_inicio') ?>"><?php $erro('festa_inicio'); ?></div>
      <div class="campo"><label for="ff">Até (DD/MM)</label><input id="ff" name="festa_fim" type="text" inputmode="numeric" placeholder="30/11" value="<?= $v('festa_fim') ?>"><?php $erro('festa_fim'); ?></div>
    </div>
    <p class="muted pequeno">Fora desse período a festa semanal não é cobrada.</p>
  </div>

  <button class="btn btn-confirmar" type="submit"><?= $peladaId === null ? 'Criar pelada' : 'Salvar' ?></button>
</form>

<?php if ($peladaId !== null && $superAdmin && $pelada !== null): ?>
  <form method="post" action="<?= htmlspecialchars($base) ?>/admin/p/<?= (int) $peladaId ?>/ativa" class="rodape"
        onsubmit="return confirm('<?= (int) $pelada['ativa'] === 1 ? 'Desativar a pelada? O cron para de abrir rodadas.' : 'Reativar a pelada?' ?>')">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="ativa" value="<?= (int) $pelada['ativa'] === 1 ? '0' : '1' ?>">
    <button type="submit"><?= (int) $pelada['ativa'] === 1 ? 'Desativar pelada' : 'Reativar pelada' ?></button>
  </form>
<?php endif; ?>
