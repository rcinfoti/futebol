<?php
/** @var string $titulo */
/** @var string $conteudo */
/** @var string $base */
$base ??= '';
$largo ??= false;
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0c241f">
<link rel="icon" href="<?= htmlspecialchars($base) ?>/icone-192.png">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($base) ?>/icone-192.png">
<link rel="manifest" href="<?= htmlspecialchars($base) ?>/manifest.webmanifest">
<title><?= htmlspecialchars($titulo) ?> · Pelada</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --pitch:#0c241f;        /* fundo: gramado à noite */
    --pitch-2:#123a31;      /* cartão sobre o gramado */
    --chalk:#f2f5ee;        /* texto: linha de cal */
    --muted:#9fb7ad;        /* texto secundário */
    --line:#1e4d41;         /* divisórias */
    --verde:#37d67a;        /* confirmado */
    --amarelo:#f4c04e;      /* espera */
    --vermelho:#f0674f;     /* fora / não vou */
  }
  *{box-sizing:border-box}
  html,body{margin:0}
  body{
    background:
      radial-gradient(120% 60% at 50% -10%, #16493d 0%, var(--pitch) 60%) fixed,
      var(--pitch);
    color:var(--chalk);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    line-height:1.5;
    -webkit-text-size-adjust:100%;
  }
  main{
    max-width:26rem;
    margin:0 auto;
    padding:1.75rem 1rem calc(2rem + env(safe-area-inset-bottom));
    min-height:100dvh;
  }
  .marca{
    font-family:"Barlow Condensed",sans-serif;
    font-weight:600;
    letter-spacing:.02em;
    text-transform:uppercase;
    color:var(--muted);
    font-size:.95rem;
  }
  h1{
    font-family:"Barlow Condensed",sans-serif;
    font-weight:700;
    font-size:2.4rem;
    line-height:1.02;
    margin:.4rem 0 1.25rem;
  }
  .cartao{
    background:var(--pitch-2);
    border:1px solid var(--line);
    border-radius:18px;
    padding:1.1rem 1.15rem;
    margin:0 0 1rem;
  }
  .status{
    display:flex;align-items:center;gap:.6rem;
    font-family:"Barlow Condensed",sans-serif;
    font-weight:700;font-size:1.5rem;line-height:1.05;
  }
  .status .ponto{width:.85rem;height:.85rem;border-radius:50%;flex:none}
  .is-verde{color:var(--verde)} .is-verde .ponto{background:var(--verde)}
  .is-amarelo{color:var(--amarelo)} .is-amarelo .ponto{background:var(--amarelo)}
  .is-vermelho{color:var(--vermelho)} .is-vermelho .ponto{background:var(--vermelho)}
  .status small{display:block;font-family:inherit;font-weight:500;font-size:.95rem;color:var(--muted)}
  form{margin:0}
  .btn{
    display:block;width:100%;border:0;border-radius:14px;
    font-family:"Barlow Condensed",sans-serif;font-weight:700;
    text-transform:uppercase;letter-spacing:.03em;font-size:1.45rem;
    padding:.85rem 1rem;cursor:pointer;text-align:center;
  }
  .btn-confirmar{background:var(--verde);color:#06231a;margin-bottom:.6rem}
  .btn-nao{background:transparent;color:var(--vermelho);border:1.5px solid var(--vermelho);font-size:1.15rem;padding:.7rem}
  .btn:active{transform:translateY(1px)}
  .valor{font-family:"Barlow Condensed",sans-serif;font-weight:700;font-size:2rem}
  .muted{color:var(--muted)}
  label{display:block;font-size:.9rem;color:var(--muted);margin:.2rem 0 .35rem}
  select,input[type=tel],input[type=number],input[type=time]{
    width:100%;padding:.75rem .85rem;border-radius:12px;
    border:1px solid var(--line);background:#0a1f1a;color:var(--chalk);font-size:1.05rem;
  }
  .campo{margin-bottom:.9rem}
  .anexo{position:relative;display:flex;align-items:center;gap:.6rem;padding:.75rem .85rem;border:1.5px dashed var(--line);border-radius:12px;cursor:pointer;color:var(--chalk)}
  .anexo:focus-within{border-color:var(--verde)}
  .anexo input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%}
  .anexo .nome-arquivo{color:var(--muted);font-size:.9rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .anexo.com-arquivo{border-style:solid;border-color:var(--verde)}
  .aviso{color:var(--vermelho);font-size:.95rem;margin:.2rem 0 0}
  .rodape{margin-top:1.5rem;text-align:center}
  .rodape button{background:none;border:0;color:var(--muted);text-decoration:underline;cursor:pointer;font-size:.95rem}
  a{color:var(--chalk)}
  /* --- área do organizador --- */
  main.largo{max-width:44rem}
  h2{font-family:"Barlow Condensed",sans-serif;font-weight:700;font-size:1.5rem;margin:1.6rem 0 .7rem}
  input[type=text],input[type=email],input[type=password]{
    width:100%;padding:.75rem .85rem;border-radius:12px;
    border:1px solid var(--line);background:#0a1f1a;color:var(--chalk);font-size:1.05rem;
  }
  .topo{display:flex;justify-content:space-between;align-items:center;gap:.4rem 1rem;margin-bottom:.6rem;flex-wrap:wrap}
  .topo .marca{white-space:nowrap}
  .topo .marca{text-decoration:none}
  .link{background:none;border:0;color:var(--muted);text-decoration:underline;cursor:pointer;font-size:.95rem;padding:0}
  .abas{display:flex;gap:.1rem;margin:0 0 1.2rem;border-bottom:1px solid var(--line);overflow-x:auto;white-space:nowrap;scrollbar-width:none}
  .abas::-webkit-scrollbar{display:none}
  .abas a{padding:.5rem .65rem;text-decoration:none;color:var(--muted);font-weight:600;flex:none}
  .abas a:hover{color:var(--chalk)}
  .flash{border-color:var(--verde)}
  .flash-pin{border-color:var(--amarelo)}
  .pin{font-family:"Barlow Condensed",sans-serif;font-weight:700;font-size:3rem;letter-spacing:.3em;color:var(--amarelo);text-align:center}
  .linha-link{display:flex;justify-content:space-between;align-items:center;gap:.6rem;text-decoration:none;color:var(--chalk)}
  .linha-link:hover{border-color:var(--muted)}
  .linha-link.inativo{opacity:.55}
  .seta{color:var(--muted);font-size:1.4rem}
  .etiqueta{display:inline-block;font-size:.75rem;padding:.05rem .45rem;border-radius:999px;border:1px solid currentColor;margin-left:.3rem;color:var(--muted)}
  .etiqueta.is-verde{color:var(--verde)} .etiqueta.is-amarelo{color:var(--amarelo)} .etiqueta.is-vermelho{color:var(--vermelho)}
  .cab-lista{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:.4rem}
  .cab-lista h1{margin:.4rem 0}
  .lista{margin:.3rem 0 0;padding-left:1.4rem}
  .lista li{padding:.2rem 0}
  .lista li span{margin-left:.4rem}
  .btn-pequeno{display:inline-block;width:auto;font-size:1rem;padding:.5rem .9rem;background:var(--verde);color:#06231a;text-decoration:none;margin:0}
  .grade-2{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
  .grade-2 .cartao{margin:0 0 1rem}
  .grade-2 .valor{font-size:clamp(1.3rem,6vw,2rem);white-space:nowrap}
  .pagamento{display:flex;justify-content:space-between;align-items:center;gap:1rem}
  .link-pelada{display:flex;flex-wrap:wrap;gap:.8rem;align-items:center;margin-top:.3rem}
  .link-pelada code{word-break:break-all;font-size:.9rem}
  form.inline{display:inline;margin-left:.5rem}
  .pequeno{font-size:.85rem}
  .topo-links{display:flex;gap:.9rem;align-items:center;flex-wrap:wrap;justify-content:flex-end}
  .topo-links form.inline{margin:0}
  .pin.senha{font-size:1.8rem;letter-spacing:.08em;user-select:all;word-break:break-all}
  .check{display:flex;gap:.5rem;align-items:center;padding:.3rem 0;color:var(--chalk);font-size:1rem}
  input[type=time]{padding:.7rem .85rem;border-radius:12px;border:1px solid var(--line);background:#0a1f1a;color:var(--chalk);font-size:1rem;color-scheme:dark}
  .sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
  .linha-form{display:flex;gap:.6rem;align-items:center}
  .linha-form select{flex:1}
  .acoes{display:flex;flex-direction:column;align-items:flex-end;gap:.3rem}
  details summary{cursor:pointer;font-weight:600}
  input[type=date]{width:100%;padding:.7rem .85rem;border-radius:12px;border:1px solid var(--line);background:#0a1f1a;color:var(--chalk);font-size:1rem;color-scheme:dark}
  .navega-mes a{font-size:1.8rem;text-decoration:none;padding:0 .6rem;color:var(--muted)}
  .navega-mes h1,.navega-mes h2{margin:.4rem 0;text-align:center}
  .extrato{list-style:none;margin:0;padding:0}
  .extrato li{display:flex;gap:.7rem;align-items:center;padding:.45rem 0;border-bottom:1px solid var(--line)}
  .extrato li:last-child{border-bottom:0}
  .extrato .desc{flex:1}
  .rolagem{overflow-x:auto}
  .tabela{width:100%;border-collapse:collapse;font-size:.95rem}
  .tabela th,.tabela td{padding:.45rem .5rem;text-align:right;border-bottom:1px solid var(--line);white-space:nowrap}
  .tabela th:first-child,.tabela td:first-child{text-align:left;white-space:normal}
  .tabela tfoot th{border-bottom:0}
  @media (prefers-reduced-motion:reduce){.btn:active{transform:none}}
</style>
</head>
<body>
<main<?= $largo ? ' class="largo"' : '' ?>>
<?= $conteudo ?>
</main>
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(<?= json_encode($base . '/sw.js') ?>).catch(function () {});
    });
  }
</script>
</body>
</html>
