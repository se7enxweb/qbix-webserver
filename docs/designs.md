## 🎨 Designs

The server's own pages — the dashboard, the control panel, the documentation
viewer, directory listings and error pages — are design files on disk, not markup
inside the PHP. Restyling them, or replacing one of them outright, needs no change
to the engine: put the files you want to change in the configuration directory and
the server uses them from the next request.

- [Views](#views)
- [Where files are looked for](#where-files-are-looked-for)
- [Overriding one file](#overriding-one-file)
- [A design of your own](#a-design-of-your-own)
- [How a page is rendered](#how-a-page-is-rendered)

---

### Views

A design is a directory of views. Each view is a page template and the files it
includes:

```
designs/<design>/<view>/page.html     the page, with {{placeholders}}
designs/<design>/<view>/style.css     included by {{@style.css}}
designs/<design>/<view>/script.js     included by {{@script.js}}
designs/<design>/common/chrome.css    included by {{@common/chrome.css}}
```

`common/chrome.css` is the chrome the dashboard, control panel, metrics and PHP
info views share: the palette (`--bg`, `--txt`, `--dim` ...), the header, the
brand, the status badge and the view navigation. Each of those pages includes it
ahead of its own `style.css`, which holds only what that view does differently.
To recolour every view at once, override that one file:

```sh
mkdir -p /etc/qbix/designs/default/common
cp designs/default/common/chrome.css /etc/qbix/designs/default/common/
```

It is inlined into each page rather than linked, so a view costs one request
and paints without waiting for a stylesheet; the pages are compressed on the
way out (brotli when the extension is loaded, else gzip), so the repetition
across views costs little.

| View | Serves | Placeholders |
|---|---|---|
| `dashboard` | `/Q/dashboard` | `brand`, `brandHead`, `brandHeader`, `brandName`, `verLabel`, `maintainedBy`, `stats`, `recent`, `tokenParam`, `topPathsHtml`, `sysramHtml`, `sysramDetailHtml` |
| `panel` | `/Q/panel` | `brand`, `brandHead` |
| `docs` | `/Q/docs` | `brand`, `brandHead` |
| `listing` | directory listings | `safePath`, `listHtml`, `imagesJson` |
| `error` | error pages | `brand`, `code`, `title`, `msg` |

The engine's own design is `designs/default/` in its source tree. The dashboard,
panel and docs views share a toolbar near the top of the page that links the
server's views — dashboard, control panel, documentation, PHP information, health
and metrics — and marks the current one.

---

### Where files are looked for

Every file is looked up on its own, in this order:

1. the active design in the configuration trees' `designs/` — the top overlay
   first, then `/etc/qbix/designs/`;
2. the active design in the engine's `designs/`;
3. then the same places again for `default`.

The active design is `Q.webserver.design` (default `"default"`). A design name or a
file name that is not a plain name is refused, so no setting can reach outside the
designs directories; an invalid design name falls back to `default`.

Because every file is looked up on its own, a design contains only what it changes.

---

### Overriding one file

To restyle the dashboard and keep everything else as shipped:

```sh
mkdir -p /etc/qbix/designs/default/dashboard
cp designs/default/dashboard/style.css /etc/qbix/designs/default/dashboard/
$EDITOR /etc/qbix/designs/default/dashboard/style.css
```

The page still comes from the engine; only `style.css` comes from `/etc/qbix`.
Files are read again whenever they change on disk, so an edit shows on the next
request, without a reload.

The designs directory is used when the server runs with a configuration directory
(see [layout.md](layout.md)).

---

### A design of your own

```json
{ "Q": { "webserver": { "design": "midnight" } } }
```

```
/etc/qbix/designs/midnight/
  dashboard/style.css
  docs/style.css
  error/page.html
```

Every view and file the design does not have comes from `default`.

---

### How a page is rendered

Rendering is plain substitution, never evaluation:

1. each `{{@file}}` is replaced by that file of the same view, and each
   `{{@view/file}}` by that file of another view (`{{@common/chrome.css}}`),
   one level deep;
2. then each `{{name}}` is replaced by its value, in a single pass.

A value that itself contains braces is never read as a placeholder, and a
placeholder with no value is left as it is. Values arrive already escaped for where
they go, so a template needs no escaping of its own. A page template can therefore
hold any markup, styles and scripts, and cannot run code in the server.

`tests/unit-design-render.php` renders each shipped view with sample values and
compares it byte for byte with a golden copy in `tests/fixtures/`.

---
[← Back to README](../README.md)
