# WordPress.org directory assets

These files are deployed to the plugin's **SVN `/assets/`** directory (the
WordPress.org listing), **not** bundled in the installable plugin zip — the
whole `.wordpress-org/` folder is `export-ignore`d.

| File | Purpose | Status |
|---|---|---|
| `icon-256x256.png` | Directory icon (retina) | ✅ |
| `icon-128x128.png` | Directory icon | ✅ |
| `banner-1544x500.png` | Listing banner (retina) | ⬜ TODO |
| `banner-772x250.png` | Listing banner | ⬜ TODO |
| `screenshot-1.png` … | Listing screenshots (match `readme.txt`) | ⬜ TODO |

Source brand marks live in `/assets` (`logo.png`, `icon.png`). Icons here are
derived from `assets/icon.png` (1000×1000).

Deployment is wired up after WordPress.org approval (see `docs/RELEASING.md` and
Linear R2O-19/R2O-21) via `10up/action-wordpress-plugin-asset-update`.
