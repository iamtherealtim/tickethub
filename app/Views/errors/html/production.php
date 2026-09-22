<!DOCTYPE html>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex">
    <title><?= lang('Errors.whoops') ?> · <?= lang('Errors.appName') ?></title>
    <script>(function(){try{var t=localStorage.getItem('th-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();</script>
    <style>
        <?= view('errors/html/_th_error_css') ?>
    </style>
</head>
<body>
    <main class="wrap">
        <div class="brand"><span class="mark">TH</span><span><?= lang('Errors.appName') ?></span></div>
        <h1><?= lang('Errors.whoops') ?></h1>
        <p class="lead"><?= lang('Errors.weHitASnag') ?></p>
        <p><a class="btn" href="<?= site_url('/') ?>"><?= lang('Errors.backHome') ?></a></p>
    </main>
</body>
</html>
