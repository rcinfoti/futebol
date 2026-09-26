<?php
/** @var string $titulo */
/** @var string $conteudo */
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0c241f">
<link rel="manifest" href="/manifest.webmanifest">
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
  select,input[type=tel],input[type=number]{
    width:100%;padding:.75rem .85rem;border-radius:12px;
    border:1px solid var(--line);background:#0a1f1a;color:var(--chalk);font-size:1.05rem;
  }
  .campo{margin-bottom:.9rem}
  .aviso{color:var(--vermelho);font-size:.95rem;margin:.2rem 0 0}
  .rodape{margin-top:1.5rem;text-align:center}
  .rodape button{background:none;border:0;color:var(--muted);text-decoration:underline;cursor:pointer;font-size:.95rem}
  a{color:var(--chalk)}
  @media (prefers-reduced-motion:reduce){.btn:active{transform:none}}
</style>
</head>
<body>
<main>
<?= $conteudo ?>
</main>
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js').catch(function () {});
    });
  }
</script>
</body>
</html>
