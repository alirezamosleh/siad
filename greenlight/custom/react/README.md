# Local React sources (LIVE)

Edit these files, then apply:

```bash
cd production-direct
./apply-react.sh
```

Then hard-refresh the browser (`Ctrl+Shift+R`).

## How it works

1. `./apply-react.sh` copies `custom/react/*` into `../greenlight` and runs `esbuild`.
2. The bundle is written to `custom/styles/hoda-main.js` (Docker-mounted).
3. `rtl-fix.js` blocks the stock `/assets/main-*.js` and loads our bundle instead.

## Files

| Path | Area |
|------|------|
| `home/HomePage.jsx` | Homepage |
| `shared_components/Header.jsx` | Navbar |
| `shared_components/Footer.jsx` | Footer |
| `rooms/*` | Rooms |
| `users/authentication/*` | Sign in / up |
| `App.jsx` | App shell |

CSS (no build needed): edit `custom/styles/*.css` or `hoda-theme.css`.
