# Screenshots

The README embeds these images. Capture each at 1440×900 (desktop) in **light**
mode, and the same four in **dark** mode with a `-dark` suffix, signed in as
the seeded administrator (`maya.ortiz@tickethub.co`) unless noted. Crop
nothing; the browser chrome is left out.

| File                   | Page                                   | Notes                                                      |
|------------------------|----------------------------------------|------------------------------------------------------------|
| `dashboard.png`        | `/app/dashboard`                       | KPIs, breach horizon, volume chart, queue, announcements    |
| `ticket.png`           | `/app/tickets/<code>` (an open ticket) | Conversation on the left, SLA burn bar and properties panel |
| `portal-home.png`      | `/portal` as `jordan.whitfield@tickethub.co` | Hero search, quick actions, notices, popular answers  |
| `admin.png`            | `/app/admin`                           | General tab                                                |
| `*-dark.png`           | same four pages                        | Set the theme to Dark from the account menu first          |

Regenerate all of them after any palette or layout change so the README does
not drift from the product.

## Status

The screenshots were not written to disk by the tooling used to build this
release: the browser used for verification can render and inspect pages but
cannot save an image file into the repository. Capture them manually with the
table above (any browser's "capture full size screenshot" DevTools command
works) and drop the PNGs into this directory.
