<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="csrf-name" content="<?= csrf_token() ?>">
<meta name="csrf-value" content="<?= csrf_hash() ?>">
<title><?= esc($title ?? 'TicketHub') ?></title>
<script>
/* Pre-paint: apply the saved theme before Tailwind or fonts load so there is no flash. */
(function(){try{var t=localStorage.getItem('th-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  /* ---------- design tokens ----------
     Every Tailwind colour below is an RGB triplet so `bg-white/60`, `text-ink`, `border-line`
     etc. keep working with opacity modifiers. Light values are the original palette; the
     dark block flips them. Views never need a `dark:` variant — the tokens do the work. */
  :root{
    color-scheme:light;
    --th-surface:255 255 255;
    --th-canvas:241 243 247;
    --th-ink:16 20 28; --th-ink-700:26 33 48; --th-ink-600:35 43 60; --th-ink-500:51 60 80;
    --th-muted:91 101 119; --th-faint:139 147 163; --th-line:227 230 236;
    --th-brand:14 124 107; --th-brand-600:11 101 88; --th-brand-100:207 231 225; --th-brand-50:231 243 240;
    --th-signal:184 118 11; --th-signal-400:233 162 59; --th-signal-100:247 228 196; --th-signal-50:252 244 230;
    --th-alert:188 51 45; --th-alert-400:220 91 84; --th-alert-100:245 212 210; --th-alert-50:251 235 234;
    --th-violet:90 79 176; --th-violet-50:238 236 248;
    --th-shadow-card:0 1px 2px rgba(16,20,28,.05), 0 1px 3px rgba(16,20,28,.05);
    --th-shadow-pop:0 16px 40px -12px rgba(16,20,28,.28), 0 2px 8px rgba(16,20,28,.08);
    --th-scroll:#C9CED8; --th-scroll-hover:#A9B0BE;
    --th-row-hover:#F7F8FB; --th-burn:#E9ECF1; --th-code:#EEF0F4; --th-prose:#333C50;
    --th-hex-hover-border:#CBD1DC; --th-hex-chip:#EFF1F5; --th-hex-violet-border:#DCD8F0;
    --th-hex-medium-text:#3B5BDB; --th-hex-medium-bg:#EDF2FF; --th-hex-ink-avatar:#E8EAEF;
    --th-hex-ink-avatar-border:#D8DCE4; --th-hex-toggle-off:#D3D8E0; --th-scrim:rgba(16,20,28,.4);
  }
  :root[data-theme="dark"]{
    color-scheme:dark;
    --th-surface:23 28 38;
    --th-canvas:15 19 26;
    --th-ink:230 234 242; --th-ink-700:213 218 228; --th-ink-600:195 201 214; --th-ink-500:180 187 201;
    --th-muted:160 168 182; --th-faint:124 134 152; --th-line:42 50 65;
    --th-brand:58 165 143; --th-brand-600:46 143 124; --th-brand-100:31 74 67; --th-brand-50:20 46 42;
    --th-signal:217 146 42; --th-signal-400:233 162 59; --th-signal-100:79 58 20; --th-signal-50:44 35 20;
    --th-alert:224 96 90; --th-alert-400:220 91 84; --th-alert-100:90 37 35; --th-alert-50:51 25 26;
    --th-violet:155 147 222; --th-violet-50:38 35 70;
    --th-shadow-card:0 1px 2px rgba(0,0,0,.35), 0 1px 3px rgba(0,0,0,.3);
    --th-shadow-pop:0 16px 40px -12px rgba(0,0,0,.7), 0 2px 8px rgba(0,0,0,.4);
    --th-scroll:#3A4356; --th-scroll-hover:#4B5569;
    --th-row-hover:#1D2330; --th-burn:#2A3241; --th-code:#232B3C; --th-prose:#C3C9D6;
    --th-hex-hover-border:#3A4356; --th-hex-chip:#232B3C; --th-hex-violet-border:#3B3766;
    --th-hex-medium-text:#7E9BFF; --th-hex-medium-bg:#1E2A4A; --th-hex-ink-avatar:#2A3241;
    --th-hex-ink-avatar-border:#3A4356; --th-hex-toggle-off:#3A4356; --th-scrim:rgba(0,0,0,.6);
  }
  @media (prefers-color-scheme: dark){
    :root:not([data-theme="light"]){
      color-scheme:dark;
      --th-surface:23 28 38;
      --th-canvas:15 19 26;
      --th-ink:230 234 242; --th-ink-700:213 218 228; --th-ink-600:195 201 214; --th-ink-500:180 187 201;
      --th-muted:160 168 182; --th-faint:124 134 152; --th-line:42 50 65;
      --th-brand:58 165 143; --th-brand-600:46 143 124; --th-brand-100:31 74 67; --th-brand-50:20 46 42;
      --th-signal:217 146 42; --th-signal-400:233 162 59; --th-signal-100:79 58 20; --th-signal-50:44 35 20;
      --th-alert:224 96 90; --th-alert-400:220 91 84; --th-alert-100:90 37 35; --th-alert-50:51 25 26;
      --th-violet:155 147 222; --th-violet-50:38 35 70;
      --th-shadow-card:0 1px 2px rgba(0,0,0,.35), 0 1px 3px rgba(0,0,0,.3);
      --th-shadow-pop:0 16px 40px -12px rgba(0,0,0,.7), 0 2px 8px rgba(0,0,0,.4);
      --th-scroll:#3A4356; --th-scroll-hover:#4B5569;
      --th-row-hover:#1D2330; --th-burn:#2A3241; --th-code:#232B3C; --th-prose:#C3C9D6;
      --th-hex-hover-border:#3A4356; --th-hex-chip:#232B3C; --th-hex-violet-border:#3B3766;
      --th-hex-medium-text:#7E9BFF; --th-hex-medium-bg:#1E2A4A; --th-hex-ink-avatar:#2A3241;
      --th-hex-ink-avatar-border:#3A4356; --th-hex-toggle-off:#3A4356; --th-scrim:rgba(0,0,0,.6);
    }
  }
  /* Surfaces that are dark-on-purpose in both themes (sidebar, portal hero, login panel) re-declare
     the tokens locally so `bg-ink text-white` inside them stays dark-with-light-text. */
  .th-inverse, .sidebar{
    --th-surface:255 255 255; --th-ink:16 20 28; --th-ink-700:26 33 48; --th-ink-600:35 43 60; --th-ink-500:51 60 80;
    --th-muted:91 101 119; --th-faint:139 147 163; --th-line:227 230 236; --th-brand-100:207 231 225;
  }
  :root[data-theme="dark"] .th-inverse, :root[data-theme="dark"] .sidebar{ --th-ink:11 14 20; --th-ink-700:23 28 38; --th-line:42 50 65; }
  @media (prefers-color-scheme: dark){
    :root:not([data-theme="light"]) .th-inverse, :root:not([data-theme="light"]) .sidebar{ --th-ink:11 14 20; --th-ink-700:23 28 38; --th-line:42 50 65; }
  }
</style>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: {
        white:  'rgb(var(--th-surface) / <alpha-value>)',
        ink:    { DEFAULT:'rgb(var(--th-ink) / <alpha-value>)', 700:'rgb(var(--th-ink-700) / <alpha-value>)', 600:'rgb(var(--th-ink-600) / <alpha-value>)', 500:'rgb(var(--th-ink-500) / <alpha-value>)' },
        muted:  'rgb(var(--th-muted) / <alpha-value>)',
        faint:  'rgb(var(--th-faint) / <alpha-value>)',
        line:   'rgb(var(--th-line) / <alpha-value>)',
        canvas: 'rgb(var(--th-canvas) / <alpha-value>)',
        brand:  { DEFAULT:'rgb(var(--th-brand) / <alpha-value>)', 600:'rgb(var(--th-brand-600) / <alpha-value>)', 100:'rgb(var(--th-brand-100) / <alpha-value>)', 50:'rgb(var(--th-brand-50) / <alpha-value>)' },
        signal: { DEFAULT:'rgb(var(--th-signal) / <alpha-value>)', 400:'rgb(var(--th-signal-400) / <alpha-value>)', 100:'rgb(var(--th-signal-100) / <alpha-value>)', 50:'rgb(var(--th-signal-50) / <alpha-value>)' },
        alert:  { DEFAULT:'rgb(var(--th-alert) / <alpha-value>)', 400:'rgb(var(--th-alert-400) / <alpha-value>)', 100:'rgb(var(--th-alert-100) / <alpha-value>)', 50:'rgb(var(--th-alert-50) / <alpha-value>)' },
        violet: { DEFAULT:'rgb(var(--th-violet) / <alpha-value>)', 50:'rgb(var(--th-violet-50) / <alpha-value>)' }
      },
      fontFamily: {
        display:['Archivo','system-ui','sans-serif'],
        sans:['"IBM Plex Sans"','system-ui','sans-serif'],
        mono:['"IBM Plex Mono"','ui-monospace','monospace']
      },
      boxShadow: {
        card:'var(--th-shadow-card)',
        pop:'var(--th-shadow-pop)'
      },
      borderRadius:{ xl:'10px', '2xl':'14px' }
    }
  }
}
</script>
<style>
  html,body{height:100%}
  body{background:rgb(var(--th-canvas));color:rgb(var(--th-ink));font-family:"IBM Plex Sans",system-ui,sans-serif;-webkit-font-smoothing:antialiased}
  h1,h2,h3,.font-display{font-family:Archivo,system-ui,sans-serif;letter-spacing:-.015em}
  ::-webkit-scrollbar{width:10px;height:10px}
  ::-webkit-scrollbar-thumb{background:var(--th-scroll);border:3px solid transparent;background-clip:content-box;border-radius:8px}
  ::-webkit-scrollbar-thumb:hover{background:var(--th-scroll-hover);background-clip:content-box}
  ::-webkit-scrollbar-track{background:transparent}
  :focus-visible{outline:2px solid rgb(var(--th-brand));outline-offset:2px;border-radius:4px}
  /* Form controls get one clean focus treatment (border + soft halo) instead of the
     keyboard outline stacked on top of their own focus border. */
  input:focus-visible,select:focus-visible,textarea:focus-visible,[contenteditable]:focus-visible{outline:none;outline-offset:0;border-color:rgb(var(--th-brand));box-shadow:0 0 0 3px rgb(var(--th-brand) / .14)}
  input[type=checkbox]:focus-visible,input[type=radio]:focus-visible{box-shadow:0 0 0 3px rgb(var(--th-brand) / .25)}
  .sidebar ::-webkit-scrollbar-thumb{background:#333C50;background-clip:content-box}
  .burn{height:2px;background:var(--th-burn);overflow:hidden}
  .burn > i{display:block;height:100%;transition:width .4s ease}
  .led{display:inline-block;width:8px;height:8px;border-radius:2px;flex:none}
  .row-hover:hover{background:var(--th-row-hover)}
  .tick{font-variant-numeric:tabular-nums}
  .fade-in{animation:fade .22s ease both}
  @keyframes fade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
  .pop-in{animation:pop .16s cubic-bezier(.2,.8,.3,1) both}
  @keyframes pop{from{opacity:0;transform:translateY(8px) scale(.985)}to{opacity:1;transform:none}}
  .shake-no{animation:shakeNo .32s ease}
  @keyframes shakeNo{0%,100%{transform:translateX(0)}20%{transform:translateX(-6px)}40%{transform:translateX(5px)}60%{transform:translateX(-3px)}80%{transform:translateX(2px)}}
  .stripe{background-image:repeating-linear-gradient(135deg,rgb(var(--th-alert) / .10) 0 6px,transparent 6px 12px)}
  input[type=checkbox]{accent-color:rgb(var(--th-brand))}
  input,select,textarea{color:rgb(var(--th-ink))}
  input::placeholder,textarea::placeholder{color:rgb(var(--th-faint))}
  select option{background:rgb(var(--th-surface));color:rgb(var(--th-ink))}
  /* !important: the Tailwind CDN injects its preflight after this sheet and zeroes select padding */
  select{background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%235B6577' stroke-width='2'><path d='m6 9 6 6 6-6'/></svg>");background-repeat:no-repeat;background-position:right 8px center;appearance:none;padding-right:28px!important}
  .prose-kb h2{font-size:1.05rem;font-weight:600;margin:1.4rem 0 .5rem}
  .prose-kb p{margin:.6rem 0;line-height:1.7;color:var(--th-prose)}
  .prose-kb ul{margin:.6rem 0 .6rem 1.1rem;list-style:disc;line-height:1.8;color:var(--th-prose)}
  .prose-kb code{font-family:"IBM Plex Mono",monospace;font-size:.85em;background:var(--th-code);padding:1px 5px;border-radius:4px}
  /* Arbitrary hex utilities used across views/helpers. Tokenised here so dark mode can restyle
     them without touching every call site. Same selector specificity as Tailwind's own. */
  html .hover\:border-\[\#CBD1DC\]:hover{border-color:var(--th-hex-hover-border)}
  html .bg-\[\#EFF1F5\]{background-color:var(--th-hex-chip)}
  html .border-\[\#DCD8F0\]{border-color:var(--th-hex-violet-border)}
  html .text-\[\#3B5BDB\]{color:var(--th-hex-medium-text)}
  html .bg-\[\#EDF2FF\]{background-color:var(--th-hex-medium-bg)}
  html .bg-\[\#E8EAEF\]{background-color:var(--th-hex-ink-avatar)}
  html .border-\[\#D8DCE4\]{border-color:var(--th-hex-ink-avatar-border)}
  html .bg-\[\#D3D8E0\]{background-color:var(--th-hex-toggle-off)}
  html .bg-ink\/40{background-color:var(--th-scrim)}
  .th-dark-only{display:none}
  :root[data-theme="dark"] select{background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23A0A8B6' stroke-width='2'><path d='m6 9 6 6 6-6'/></svg>")}
  :root[data-theme="dark"] .th-dark-only{display:inline}
  :root[data-theme="dark"] .th-light-only{display:none}
  :root[data-theme="dark"] .sidebar{border-right:1px solid rgb(var(--th-line))}
  :root[data-theme="dark"] .sidebar ::-webkit-scrollbar-thumb{background:#2A3241;background-clip:content-box}
  :root[data-theme="dark"] input[type=date]::-webkit-calendar-picker-indicator{filter:invert(.8)}
  @media (prefers-color-scheme: dark){
    :root:not([data-theme="light"]) select{background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23A0A8B6' stroke-width='2'><path d='m6 9 6 6 6-6'/></svg>")}
    :root:not([data-theme="light"]) .th-dark-only{display:inline}
    :root:not([data-theme="light"]) .th-light-only{display:none}
    :root:not([data-theme="light"]) .sidebar{border-right:1px solid rgb(var(--th-line))}
    :root:not([data-theme="light"]) .sidebar ::-webkit-scrollbar-thumb{background:#2A3241;background-clip:content-box}
    :root:not([data-theme="light"]) input[type=date]::-webkit-calendar-picker-indicator{filter:invert(.8)}
  }
  /* Theme switcher: the icon that matches the current mode is the only one shown. */
  [data-theme-toggle] [data-theme-icon]{display:none}
  [data-theme-toggle][data-theme-state="system"] [data-theme-icon="system"],
  [data-theme-toggle][data-theme-state="light"]  [data-theme-icon="light"],
  [data-theme-toggle][data-theme-state="dark"]   [data-theme-icon="dark"]{display:inline-flex}
  [data-theme-set][aria-pressed="true"]{background:rgb(var(--th-brand-50));border-color:rgb(var(--th-brand));color:rgb(var(--th-brand))}
  @media (prefers-reduced-motion: reduce){*{animation:none!important;transition:none!important}}
</style>
