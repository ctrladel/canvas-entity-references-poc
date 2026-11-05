// node encode.js in.webm out.mp4 out/sections.json ready-seconds
// H.264 yuv420p, faststart, no metadata. A 40 px strip is added under the
// recording: the five sections, finished ones ticked, the current one
// highlighted, built from the recorded section start times.
const { execFileSync } = require('child_process');
const fs = require('fs');
const [, , input, output, sectionsFile, ready] = process.argv;
const { sections, end } = JSON.parse(fs.readFileSync(sectionsFile, 'utf8'));
const FONT = process.env.FONT || '/usr/share/fonts/google-noto/NotoSans-Bold.ttf';
const TICK = process.env.TICK || '/usr/share/fonts/gdouros-symbola/Symbola.ttf';
const labels = ['1  The problem', '2  Link any component to content', '3  A card bound to content', '4  Live placements', '5  Overrides and edge cases'];
const W = 1920, SW = W / 5, Y = 1040, H = 40;
const start = sections.map((s) => s.t);
const stop = (i) => (i < 4 ? start[i + 1] : end + 5);
const f = [`pad=${W}:${Y + H}:0:0:color=0xF6F6F2`];
labels.forEach((label, i) => {
  const x = i * SW;
  const t0 = start[i], t1 = stop(i);
  f.push(`drawbox=x=${x}:y=${Y}:w=${SW}:h=${H}:color=0x064771:t=fill:enable='gte(t,${t1})'`);
  f.push(`drawbox=x=${x}:y=${Y}:w=${SW}:h=${H}:color=0x0678BE:t=fill:enable='between(t,${t0},${t1})'`);
  f.push(`drawbox=x=${x}:y=${Y}:w=${SW}:h=${H}:color=0xF6F6F2:t=fill:enable='lt(t,${t0})'`);
  f.push(`drawbox=x=${x + SW - 2}:y=${Y}:w=2:h=${H}:color=0xFFFFFF:t=fill`);
  const txt = (color, text, enable) => `drawtext=fontfile=${FONT}:text='${text}':fontsize=20:fontcolor=${color}:x=${x + 18}:y=${Y + 9}:enable='${enable}'`;
  f.push(txt('0x666666', label, `lt(t,${t0})`));
  f.push(txt('white', label, `between(t,${t0},${t1})`));
  f.push(txt('0x9fd3f2', label, `gte(t,${t1})`));
  f.push(`drawtext=fontfile=${TICK}:text='\u2713':fontsize=24:fontcolor=0x9fd3f2:x=${x + SW - 30}:y=${Y + 7}:enable='gte(t,${t1})'`);
});
execFileSync('ffmpeg', ['-y', '-v', 'error', '-ss', ready, '-i', input, '-an', '-vf', f.join(','),
  '-c:v', 'libx264', '-preset', 'slow', '-crf', process.env.CRF || '24', '-pix_fmt', 'yuv420p', '-r', '25',
  '-x264-params', 'no-info=1', '-map_metadata', '-1', '-fflags', '+bitexact', '-flags:v', '+bitexact', '-movflags', '+faststart', output], { stdio: 'inherit' });
