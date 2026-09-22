/* Shared stylesheet for the TicketHub error pages (400, 404, production).
   Standalone on purpose: these pages must render even when the CDN, fonts or the
   database are unavailable. Same token names and values as partials/head.php. */
:root{color-scheme:light;--surface:#FFFFFF;--canvas:#F1F3F7;--ink:#10141C;--muted:#5B6577;--faint:#8B93A3;--line:#E3E6EC;--brand:#0E7C6B;--brand-600:#0B6558;--shadow:0 1px 2px rgba(16,20,28,.05),0 1px 3px rgba(16,20,28,.05)}
:root[data-theme="dark"]{color-scheme:dark;--surface:#171C26;--canvas:#0F131A;--ink:#E6EAF2;--muted:#A0A8B6;--faint:#7C8698;--line:#2A3241;--brand:#3AA58F;--brand-600:#2E8F7C;--shadow:0 1px 2px rgba(0,0,0,.35),0 1px 3px rgba(0,0,0,.3)}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){color-scheme:dark;--surface:#171C26;--canvas:#0F131A;--ink:#E6EAF2;--muted:#A0A8B6;--faint:#7C8698;--line:#2A3241;--brand:#3AA58F;--brand-600:#2E8F7C;--shadow:0 1px 2px rgba(0,0,0,.35),0 1px 3px rgba(0,0,0,.3)}}
*{box-sizing:border-box}
html,body{height:100%;margin:0}
body{background:var(--canvas);color:var(--ink);font-family:"IBM Plex Sans",system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased;display:grid;place-items:center;padding:24px}
.wrap{width:100%;max-width:440px;background:var(--surface);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow);padding:32px;text-align:center}
.brand{display:inline-flex;align-items:center;gap:10px;font-weight:600;font-size:15px;margin-bottom:28px}
.mark{display:inline-grid;place-items:center;width:32px;height:32px;border-radius:8px;background:var(--brand);color:#fff;font-weight:700;font-size:13px}
h1{font-family:Archivo,system-ui,sans-serif;font-size:44px;line-height:1;letter-spacing:-.02em;margin:0 0 10px;font-weight:600}
.lead{font-size:16px;font-weight:600;margin:0 0 6px}
.msg{font-size:13.5px;color:var(--muted);line-height:1.6;margin:0 0 22px;overflow-wrap:anywhere}
.btn{display:inline-flex;align-items:center;height:40px;padding:0 18px;border-radius:10px;background:var(--brand);color:#fff;font-weight:600;font-size:14px;text-decoration:none;transition:background .15s}
.btn:hover{background:var(--brand-600)}
a{color:var(--brand)}
code{font-family:"IBM Plex Mono",ui-monospace,monospace;font-size:.9em;background:var(--canvas);border:1px solid var(--line);padding:1px 5px;border-radius:4px}
