<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="csrf-name" content="<?= csrf_token() ?>">
<meta name="csrf-value" content="<?= csrf_hash() ?>">
<title><?= esc($title ?? 'TicketHub') ?></title>
<script>
/* Pre-paint: apply the saved theme before the stylesheet or fonts load so there is no flash. */
(function(){try{var t=localStorage.getItem('th-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php /* Built from resources/css/app.css with `npm run build:css`; the mtime busts caches after a rebuild. */ $thCss = FCPATH . 'assets/css/tailwind.css'; ?>
<link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>?v=<?= is_file($thCss) ? filemtime($thCss) : 0 ?>">
