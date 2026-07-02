# WordPress.org plugin assets

These are the **directory assets** for the plugin's page on WordPress.org — the
icon and banner shown in the directory grid, the search results, the "Add New"
screen, and the installed **Plugins** page. They are **not** part of the plugin
that runs on a user's site: they live in the plugin's SVN `assets/` folder, which
WordPress.org reads separately, and are never included in the installable ZIP
(`.wordpress-org/` is `export-ignore`d).

## Files

| File | Size | Where it appears |
| ---- | ---- | ---------------- |
| `icon-256x256.gif` | 256×256, animated | Directory grid + search (animates); the installed Plugins page shows a static frame |
| `icon-256x256.png` | 256×256 | Static fallback icon (retina) |
| `icon-128x128.png` | 128×128 | Static icon (standard DPI) |
| `banner-772x250.png` | 772×250 | Header banner on the plugin's directory page |
| `banner-1544x500.png` | 1544×500 | Header banner (retina) |
| `screenshot-1.png` | 1160×1008 @2x | "Screenshots" tab — the branded gateway settings screen |
| `screenshot-2.png` | 610×904 @2x | "Screenshots" tab — the buyer pay page (amount, address, QR, confirm form) |
| `screenshot-3.png` | 610×266 @2x | "Screenshots" tab — the live "Confirming your payment…" panel |

The `screenshot-N.png` files map, in order, to the numbered list under
`== Screenshots ==` in `readme.txt` — `screenshot-1.png` is caption 1, and so on.
They are captured from a live WooCommerce store running this plugin (a throwaway
`wp-env` site), not mocked. WordPress.org shows them on the plugin page's
**Screenshots** tab.

The animated icon is a Shadow Software hexagon-and-hood mark beside a coin that
flips between the Ethereum and Bitcoin faces. WordPress.org supports an animated
GIF icon and will show it in the directory grid; the installed Plugins page and
some surfaces render a single static frame, which is why the matching
`icon-256x256.png` / `icon-128x128.png` fallbacks are provided alongside it.

## How these reach WordPress.org

WordPress.org serves plugin assets from SVN, not from this Git repo. On release,
the CI deploy workflow copies this `.wordpress-org/` folder into the SVN `assets/`
directory (see `.github/workflows/deploy.yml`). Nothing here is bundled into the
plugin download.

## Regenerating

The editable sources live in [`.github/assets/`](../.github/assets/):

- `icon-frames.py` — generates the per-frame icon SVGs (the coin flip).
- `banner.svg` — the banner artwork.
- `logo.svg` — the animated README logo.

Rebuild the icon GIF and PNGs:

```bash
python3 .github/assets/icon-frames.py frames 36
for f in frames/frame_*.svg; do rsvg-convert -w 256 -h 256 "$f" -o "png/$(basename "$f" .svg).png"; done
magick -delay 7 -loop 0 $(ls -v png/frame_*.png) -coalesce -background '#060606' -alpha remove \
  -colors 128 +dither -layers Optimizeframe .wordpress-org/icon-256x256.gif
rsvg-convert -w 256 -h 256 frames/frame_000.svg -o .wordpress-org/icon-256x256.png
rsvg-convert -w 128 -h 128 frames/frame_000.svg -o .wordpress-org/icon-128x128.png
```

Rebuild the banners:

```bash
rsvg-convert -w 1544 -h 500 .github/assets/banner.svg -o .wordpress-org/banner-1544x500.png
rsvg-convert -w  772 -h 250 .github/assets/banner.svg -o .wordpress-org/banner-772x250.png
```
