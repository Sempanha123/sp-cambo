# SP Cambo Playground Mobile + Website Logo R1

This package fixes the phone-size Playground scrolling problem and updates the
website brand mark/favicon to the supplied SP Cambo gold logo.

## What changes

### Playground mobile
- Removes the 32rem minimum height that can make the internal chat taller than a phone viewport.
- Makes the chat section a real `min-height: 0` flex column.
- Makes the model header non-shrinking.
- Makes the chat area a dedicated touch-scroll surface.
- Removes `position: sticky` from the composer so it no longer paints over the latest response.
- Keeps the composer as a normal `shrink-0` flex item.
- Limits textarea auto-growth on phones so a long draft cannot consume the whole chat.
- Scrolls to the latest message when the composer gains focus.
- Keeps safe-area padding for iPhone-style bottom insets.

### Logo
- Uses the exact supplied SP Cambo gold logo, optimized to 512x512.
- Replaces the old terminal-glyph `SpBrandMark` everywhere that component is used.
- Updates the website favicon/apple-touch icon.
- Adds `viewport-fit=cover` for better mobile safe-area handling.

## Apply

From the SP Cambo repository root:

```powershell
# First copy the frontend/ folder from this ZIP into your project root.
# Allow it to overwrite the matching app.vue and SpBrandMark.vue files.

git apply --check .\playground-mobile-fix.patch
git apply .\playground-mobile-fix.patch

cd frontend
npm run typecheck
npm run build
```

If `git apply --check` reports that the Playground file has changed locally since
the ZIP was made, do not force it. Keep your local file and manually apply only
the five small class/template changes shown in the patch.

## Production

After pushing/pulling the frontend changes:

```bash
cd /var/www/sp-cambo/frontend
npm ci
npm run typecheck
npm run build
sudo systemctl restart sp-cambo-frontend
```
