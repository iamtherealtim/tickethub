# Design prototype

`design-prototype.html` is the original single-file design reference for
TicketHub: a static, client-side mock of the agent workspace and the employee
portal that the PHP views were ported from. It is kept for reference only.

- It runs from the file system (open it in a browser); it needs no server and
  talks to no backend. All data is fabricated inside the file.
- It predates the CodeIgniter application and is **not** kept in sync with it.
  Where the two disagree, `app/Views/` is the truth.
- The Tailwind palette, typography (Archivo / IBM Plex) and the layout
  primitives (`th_card`, `th_kpi`, status chips, the SLA burn bar) in
  `app/Helpers/tickethub_helper.php` and `app/Views/partials/head.php` are
  direct ports of what is in this file.

It was written under a placeholder product name; the name has been replaced
with TicketHub, nothing else was changed.
