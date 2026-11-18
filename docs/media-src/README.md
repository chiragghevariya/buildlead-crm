# Portfolio media sources

Scripts that produce everything in `docs/` (screenshots, cover, architecture diagram, demo video and GIF) from the SPA in **demo mode**.

```bash
# 1. Start the SPA with mock data on port 5192
cd frontend && npx vite --port 5192 --mode demo

# 2. In another terminal
cd docs/media-src
npm install && npm run setup     # Playwright + Chromium
npm run all                      # or: shots | cover | diagram | video
```

| Script | Output |
|---|---|
| `shots.mjs` | `docs/screenshots/*.png`: Chromium at 1440×900 (mobile 390×844), deviceScaleFactor 2 |
| `cover.html` → `render.mjs` | `docs/cover.png` (1600×1200) |
| `arch.html` → `render.mjs` | `docs/diagrams/architecture.png` (1600×1000 @2x) |
| `video.mjs` → `assemble.py` | `docs/video/buildlead-demo.mp4` (H.264 1080p30), a 15 s README GIF and a poster frame |

- `video.mjs` drives a scripted walkthrough with a drawn cursor highlight, click ripples and a keystroke overlay. Frames are captured through the Chrome DevTools screencast at 1920×1080 with their real timestamps.
- `assemble.py` turns those frames into a constant-frame-rate MP4 with ffmpeg, so pauses stay true to the script.
- The GIF is cut around the "new lead → scored Hot → AI breakdown" moment.

Needs ffmpeg and Python 3. Set `CHROMIUM_PATH` to use a specific Chromium build and `BASE_URL` for a different demo server.
