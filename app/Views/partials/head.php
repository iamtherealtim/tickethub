<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-name" content="<?= csrf_token() ?>">
<meta name="csrf-value" content="<?= csrf_hash() ?>">
<title><?= esc($title ?? 'TicketHub') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: {
    extend: {
      colors: {
        ink:    { DEFAULT:'#10141C', 700:'#1A2130', 600:'#232B3C', 500:'#333C50' },
        muted:  '#5B6577',
        faint:  '#8B93A3',
        line:   '#E3E6EC',
        canvas: '#F1F3F7',
        brand:  { DEFAULT:'#0E7C6B', 600:'#0B6558', 100:'#CFE7E1', 50:'#E7F3F0' },
        signal: { DEFAULT:'#B8760B', 400:'#E9A23B', 100:'#F7E4C4', 50:'#FCF4E6' },
        alert:  { DEFAULT:'#BC332D', 400:'#DC5B54', 100:'#F5D4D2', 50:'#FBEBEA' },
        violet: { DEFAULT:'#5A4FB0', 50:'#EEECF8' }
      },
      fontFamily: {
        display:['Archivo','system-ui','sans-serif'],
        sans:['"IBM Plex Sans"','system-ui','sans-serif'],
        mono:['"IBM Plex Mono"','ui-monospace','monospace']
      },
      boxShadow: {
        card:'0 1px 2px rgba(16,20,28,.05), 0 1px 3px rgba(16,20,28,.05)',
        pop:'0 16px 40px -12px rgba(16,20,28,.28), 0 2px 8px rgba(16,20,28,.08)'
      },
      borderRadius:{ xl:'10px', '2xl':'14px' }
    }
  }
}
</script>
<style>
  html,body{height:100%}
  body{background:#F1F3F7;color:#10141C;font-family:"IBM Plex Sans",system-ui,sans-serif;-webkit-font-smoothing:antialiased}
  h1,h2,h3,.font-display{font-family:Archivo,system-ui,sans-serif;letter-spacing:-.015em}
  ::-webkit-scrollbar{width:10px;height:10px}
  ::-webkit-scrollbar-thumb{background:#C9CED8;border:3px solid transparent;background-clip:content-box;border-radius:8px}
  ::-webkit-scrollbar-thumb:hover{background:#A9B0BE;background-clip:content-box}
  ::-webkit-scrollbar-track{background:transparent}
  :focus-visible{outline:2px solid #0E7C6B;outline-offset:2px;border-radius:4px}
  /* Form controls get one clean focus treatment (border + soft halo) instead of the
     keyboard outline stacked on top of their own focus border. */
  input:focus-visible,select:focus-visible,textarea:focus-visible,[contenteditable]:focus-visible{outline:none;outline-offset:0;border-color:#0E7C6B;box-shadow:0 0 0 3px rgba(14,124,107,.14)}
  input[type=checkbox]:focus-visible,input[type=radio]:focus-visible{box-shadow:0 0 0 3px rgba(14,124,107,.25)}
  .sidebar ::-webkit-scrollbar-thumb{background:#333C50;background-clip:content-box}
  .burn{height:2px;background:#E9ECF1;overflow:hidden}
  .burn > i{display:block;height:100%;transition:width .4s ease}
  .led{display:inline-block;width:8px;height:8px;border-radius:2px;flex:none}
  .row-hover:hover{background:#F7F8FB}
  .tick{font-variant-numeric:tabular-nums}
  .fade-in{animation:fade .22s ease both}
  @keyframes fade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
  .pop-in{animation:pop .16s cubic-bezier(.2,.8,.3,1) both}
  @keyframes pop{from{opacity:0;transform:translateY(8px) scale(.985)}to{opacity:1;transform:none}}
  .stripe{background-image:repeating-linear-gradient(135deg,rgba(188,51,45,.10) 0 6px,transparent 6px 12px)}
  input[type=checkbox]{accent-color:#0E7C6B}
  /* !important: the Tailwind CDN injects its preflight after this sheet and zeroes select padding */
  select{background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%235B6577' stroke-width='2'><path d='m6 9 6 6 6-6'/></svg>");background-repeat:no-repeat;background-position:right 8px center;appearance:none;padding-right:28px!important}
  .prose-kb h2{font-size:1.05rem;font-weight:600;margin:1.4rem 0 .5rem}
  .prose-kb p{margin:.6rem 0;line-height:1.7;color:#333C50}
  .prose-kb ul{margin:.6rem 0 .6rem 1.1rem;list-style:disc;line-height:1.8;color:#333C50}
  .prose-kb code{font-family:"IBM Plex Mono",monospace;font-size:.85em;background:#EEF0F4;padding:1px 5px;border-radius:4px}
  @media (prefers-reduced-motion: reduce){*{animation:none!important;transition:none!important}}
</style>
