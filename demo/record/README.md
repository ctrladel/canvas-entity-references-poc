# Recording the demo video

> AI-Generated: Yes. This document and the script it describes were written entirely by Claude Code. I directed the work and reviewed the overall result, not this document in detail.

`run.js` drives a headless Chromium with Playwright through the work, one step per
feature, and records it. It needs the sandbox site of this repository on the
`pattern-overrides` branch, installed with `ddev canvas-setup fresh`.

To record again:

```
demo/record/setup.sh                     # puts the site in the state the script starts from
cd demo/record
npm i playwright
PACE=0.5 node run.js --steps=1,2,3,4,5,6,7,8,9 --video
CRF=22 ./encode.sh out/*.webm out.mp4 $(cat out/ready.txt)
```

`run.js` writes `out/sections.json` too; `node encode.js in.webm out.mp4
out/sections.json <ready>` burns the strip of sections into the video. The
recording and everything in `out/` stay out of git.

Notes:

- Editing a static value and leaving the page within about a second loses it
  (Canvas debounces its auto-save), so the script waits for the save.
- The logged-out view and the reviewer are the same browser with another session
  cookie.
- The window is 1920x1040, and the encoder adds a 40 px strip of sections.
