<?php
/** @var array $addrState @var ?array $addrCert @var array $addrIssues @var array $addrLinks @var bool $addrRootCa */
use App\Libraries\SiteAddress;

$cfg      = $addrState['config'];
$req      = $addrState['request'];
$plat     = $cfg['platform'];
$base     = SiteAddress::parse($cfg['baseURL']);
$https    = $base['scheme'] === 'https';
$docker   = $plat['docker'];
$modes    = [
    'auto'     => ['Automatic — Let\'s Encrypt', 'Public certificate, obtained and renewed automatically. Needs ports 80 and 443 reachable from the internet.'],
    'dns'      => ['Automatic — Let\'s Encrypt via DNS', 'Public certificate proven through your DNS provider (' . ($plat['provider'] ?: 'not set') . '), renewed automatically. Works for internal-only sites.'],
    'files'    => ['Your own certificate', 'Certificate and key files from docker/certs/. Renew them yourself before they expire.'],
    'internal' => ['TicketHub\'s internal authority', 'Self-issued and renewed automatically. Browsers trust it once the root certificate is installed on each computer.'],
    'upstream' => ['Handled in front of TicketHub', 'A load balancer, tunnel or proxy you run terminates HTTPS and forwards plain http to TicketHub.'],
];
$modeLabel = $docker
    ? ($plat['domain'] === '' ? ['Local demo (no domain set)', 'Plain http on this machine. Set TICKETHUB_DOMAIN to give it a real address with HTTPS.'] : ($modes[$plat['tls'] ?: 'auto'] ?? ['Unknown mode "' . $plat['tls'] . '"', 'Check TICKETHUB_TLS in .env.docker.']))
    : ['Manual install', 'HTTPS is provided by your web server or a proxy in front of it.'];
$chip = static fn (string $text, string $tone) => '<span class="inline-flex items-center h-[20px] px-1.5 rounded-sm text-[10.5px] font-bold uppercase tracking-wide '
    . ['ok' => 'bg-brand-50 text-brand', 'warn' => 'bg-signal-50 text-signal', 'bad' => 'bg-alert-50 text-alert', 'neutral' => 'bg-canvas text-muted border border-line'][$tone]
    . '">' . $text . '</span>';
$row = static fn (string $label, string $value) => '<div class="flex flex-wrap items-start gap-x-4 gap-y-1 px-4 py-2.5 border-b border-line last:border-0">'
    . '<span class="w-[190px] shrink-0 text-[12.5px] text-muted">' . $label . '</span><span class="flex-1 min-w-0 text-[13px] text-ink break-words">' . $value . '</span></div>';
$code = static fn (string $s) => '<pre class="mt-2 px-3 py-2 rounded-lg bg-canvas border border-line font-mono text-[12px] text-ink whitespace-pre-wrap break-all">' . esc($s) . '</pre>';
$worst = array_reduce($addrIssues, static fn ($w, $i) => max($w, ['info' => 0, 'warning' => 1, 'error' => 2][$i['level']]), -1);
?>

<?= th_card(
    th_card_head('Site address', $worst === 2 ? $chip('Needs attention', 'bad') : ($worst === 1 ? $chip('Check below', 'warn') : $chip('Looks right', 'ok')))
    . '<div class="px-4 py-4 flex flex-wrap items-center gap-3 border-b border-line">'
    . th_icon($https ? 'lock' : 'warn', 'w-5 h-5 ' . ($https ? 'text-brand' : 'text-signal'))
    . '<span class="font-mono text-[15px] font-semibold text-ink break-all">' . esc($cfg['baseURL']) . '</span>'
    . '<button type="button" data-copy="' . esc($cfg['baseURL'], 'attr') . '" class="text-[12px] text-brand font-medium hover:underline">Copy</button>'
    . '</div>'
    . $row('HTTPS', $https ? ($cfg['forceHttps'] ? 'On — plain http is redirected, HSTS sent, cookies marked Secure' : 'Address is https://, but the redirect is turned off (app.forceGlobalSecureRequests = false)') : 'Off — plain http')
    . $row('Certificates', '<b class="font-semibold">' . esc($modeLabel[0]) . '</b><div class="text-[12px] text-muted mt-0.5">' . esc($modeLabel[1]) . '</div>')
    . $row('Environment', esc($cfg['environment']) . ($docker ? ' · Docker' : ''))
    . $row('This page reached you as', esc(($req['secure'] ? 'https' : 'http') . '://' . $req['host']) . ' <span class="text-muted">from ' . esc($req['remote'] ?: 'unknown') . '</span>'
        . ($req['xfp'] !== '' || $req['xff'] !== ''
            ? '<div class="text-[12px] text-muted mt-0.5">via a proxy (X-Forwarded-Proto: ' . esc($req['xfp'] ?: '—') . ') — ' . ($req['trusted'] ? '<span class="text-brand font-medium">trusted</span>' : '<span class="text-alert font-medium">not trusted</span>') . '</div>'
            : ''))
) ?>

<div class="mt-3">
<?php
if ($addrIssues === []) {
    echo th_card('<div class="flex items-center gap-3 px-4 py-4 text-[13px] text-ink">' . th_icon('check', 'w-4 h-4 text-brand') . 'Everything checks out: the address matches how you reached this page' . ($https ? ', HTTPS is on and the certificate is valid.' : '.') . '</div>');
} else {
    $html = '';
    foreach ($addrIssues as $i) {
        $tone = ['error' => ['warn', 'text-alert', 'border-l-alert'], 'warning' => ['warn', 'text-signal', 'border-l-signal'], 'info' => ['note', 'text-muted', 'border-l-line']][$i['level']];
        $html .= '<div class="flex items-start gap-3 px-4 py-3.5 border-b border-line last:border-0 border-l-[3px] ' . $tone[2] . '">'
            . th_icon($tone[0], 'w-4 h-4 mt-0.5 shrink-0 ' . $tone[1])
            . '<div class="flex-1 min-w-0"><div class="text-[13px] font-semibold text-ink">' . esc($i['title']) . '</div>'
            . '<p class="text-[12.5px] text-ink-500 mt-1 leading-relaxed">' . esc($i['detail']) . '</p>'
            . (isset($i['fix']) ? '<div class="mt-2 text-[11.5px] text-muted">Fix — in ' . esc($i['fixWhere'] ?? '') . ':</div>' . $code($i['fix']) : '')
            . '</div></div>';
    }
    echo th_card(th_card_head('What to fix', '<span class="text-muted">' . count($addrIssues) . ' item' . (count($addrIssues) === 1 ? '' : 's') . '</span>') . $html);
}
?>
</div>

<?php if ($https): ?>
<div class="mt-3">
<?php
$c = $addrCert;
$recheck = '<a href="' . site_url('app/admin/address?recheck=1') . '" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg border border-line bg-white text-[12.5px] font-medium text-ink-500 hover:bg-canvas">' . th_icon('refresh', 'w-3.5 h-3.5') . 'Check again</a>';
if ($c === null || ! empty($c['error'])) {
    $body = $row('Status', '<span class="text-signal">Could not read it</span>' . ($c ? ' — ' . esc($c['error']) . ' <span class="text-muted">(' . esc($c['target']) . ')</span>' : ''));
} else {
    $days = (int) floor(($c['validTo'] - time()) / 86400);
    $body = $row('Issued for', esc(implode(', ', $c['names'])))
        . $row('Issued by', esc($c['issuer'] ?: $c['issuerCN']) . ($c['issuerCN'] && $c['issuerCN'] !== $c['issuer'] ? ' <span class="text-muted">(' . esc($c['issuerCN']) . ')</span>' : ''))
        . $row('Valid until', esc(gmdate('j M Y', $c['validTo'])) . ' ' . ($days < 0 ? $chip('expired', 'bad') : ($days < 30 ? $chip($days . ' days left', 'warn') : $chip($days . ' days left', 'ok'))))
        . $row('Trusted by this server', $c['trusted'] ? 'Yes' : 'No <span class="text-muted">— ' . esc($c['trustError'] ?: 'issuer not in the server\'s trust store') . '</span>');
}
echo th_card(th_card_head('Certificate', '<span class="text-muted">' . ($c ? 'checked ' . esc(th_rel(date('Y-m-d H:i:s', $c['checkedAt']))) : '') . '</span>' . $recheck) . $body);
?>
</div>
<?php endif ?>

<?php if ($docker && $plat['tls'] === 'internal'): ?>
<div class="mt-3">
<?= th_card(
    th_card_head('Trusting the internal certificate', $addrRootCa ? '<a href="' . site_url('app/admin/address/root-ca') . '" class="inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg bg-brand text-white text-[12.5px] font-semibold hover:bg-brand-600">' . th_icon('down', 'w-3.5 h-3.5') . 'Download root certificate</a>' : '')
    . '<div class="p-4 text-[12.5px] text-ink-500 leading-relaxed space-y-2">'
    . '<p>Install the root certificate (tickethub-root-ca.crt) as a <b>trusted root certification authority</b> on every computer that uses TicketHub. The root is long-lived; the site certificate it signs is short-lived and renews itself, so this is a one-time step per computer.</p>'
    . '<p><b>Windows, many PCs</b> — Group Policy: Computer Configuration → Policies → Windows Settings → Security Settings → Public Key Policies → Trusted Root Certification Authorities → Import. With Intune: Devices → Configuration → Create → Templates → Trusted certificate.</p>'
    . '<p><b>One Windows PC</b> — double-click the file → Install Certificate → Local Machine → Trusted Root Certification Authorities.</p>'
    . '<p><b>macOS</b> — open it in Keychain Access (System keychain) and set "When using this certificate" to Always Trust. <b>Firefox</b> can keep its own list: if it still warns, import the file under Settings → Privacy &amp; Security → Certificates, or set security.enterprise_roots.enabled.</p>'
    . ($addrRootCa ? '' : '<p class="text-signal">The download appears here within a minute of Caddy starting. Or copy it from the server: <code class="font-mono">docker compose cp caddy:/data/tickethub-root-ca.crt .</code></p>')
    . '</div>'
) ?>
</div>
<?php endif ?>

<div class="mt-3">
<?php
if ($docker) {
    $howto = '<p>Everything lives in <code class="font-mono">.env.docker</code>. Change it, then run <code class="font-mono">docker compose up -d</code> — the new address, certificate mode and proxy settings apply on the next start.</p>'
        . $code("# Public name people type. Empty = local demo on http://localhost\nTICKETHUB_DOMAIN=helpdesk.example.com\n\n# auto     = Let's Encrypt (public site, ports 80+443 open to the internet)\n# dns      = Let's Encrypt via your DNS provider (internal sites; see docs/https.md)\n# files    = your own certificate in docker/certs/tls.crt + tls.key\n# internal = TicketHub's own authority (install its root on client PCs)\n# upstream = something in front already does HTTPS\nTICKETHUB_TLS=auto");
} else {
    $howto = '<p>From the TicketHub folder on the server:</p>'
        . $code('php spark tickethub:url https://helpdesk.example.com/')
        . '<p class="mt-2">It checks the address, updates <code class="font-mono">.env</code> and lists what else to update (the sign-in callbacks below). HTTPS itself comes from your web server or a proxy — <a class="text-brand font-medium hover:underline" href="https://github.com/iamtherealtim/tickethub/blob/main/docs/https.md" target="_blank" rel="noopener">copy-paste setups for Caddy, nginx and Apache</a>. Behind a proxy, add <code class="font-mono">--trust-proxy=&lt;its IP&gt;</code>.</p>';
}
echo th_card(th_card_head('Changing the address') . '<div class="p-4 text-[12.5px] text-ink-500 leading-relaxed">' . $howto
    . '<p class="mt-2 text-muted">It is not editable here on purpose: a typo would send you — and every link — to an address that does not work, with no page left to fix it from.</p></div>');
?>
</div>

<div class="mt-3">
<?php
$rows = '';
foreach ($addrLinks as [$label, $url]) {
    $rows .= '<div class="flex flex-wrap md:flex-nowrap items-center gap-3 px-4 py-2.5 border-b border-line last:border-0">'
        . '<span class="md:w-[300px] shrink-0 text-[12.5px] text-muted">' . esc($label) . '</span>'
        . '<code class="flex-1 min-w-0 font-mono text-[12px] text-ink break-all">' . esc($url) . '</code>'
        . '<button type="button" data-copy="' . esc($url, 'attr') . '" class="text-[12px] text-brand font-medium hover:underline shrink-0">Copy</button></div>';
}
echo th_card(th_card_head('Addresses to give other systems', '<span class="text-muted">Update them whenever the site address changes</span>') . $rows);
?>
</div>
