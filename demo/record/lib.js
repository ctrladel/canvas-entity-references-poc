// Helpers for a one-take recording: an overlay cursor that moves along eased
// paths, a caption bar that survives navigation, and human-paced typing.
const OVERLAY = `
(() => {
  if (window.__demoOverlay) return;
  const build = () => {
  if (window.__demoOverlay || !document.documentElement) return;
  window.__demoOverlay = true;
  const K = Math.max(1, window.innerWidth / 1280);
  const css = document.createElement('style');
  css.textContent = \`
    #demo-cursor{position:fixed;left:0;top:0;width:\${26*K}px;height:\${26*K}px;z-index:2147483647;pointer-events:none;
      transform:translate(-100px,-100px);filter:drop-shadow(0 1px 2px rgba(0,0,0,.45));transition:none}
    #demo-cursor svg{display:block;width:100%;height:100%;transition:transform .09s}
    #demo-cursor.down svg{transform:scale(.82)}
    #demo-ring{position:fixed;left:0;top:0;width:\${34*K}px;height:\${34*K}px;margin:-\${17*K}px 0 0 -\${17*K}px;border-radius:50%;
      border:\${3*K}px solid #009cde;z-index:2147483646;pointer-events:none;opacity:0}
    #demo-ring.go{animation:demo-ring .45s ease-out}
    @keyframes demo-ring{0%{opacity:.9;transform:scale(.4)}100%{opacity:0;transform:scale(1.35)}}
    #demo-caption{position:fixed;left:50%;bottom:\${18*K}px;transform:translateX(-50%);z-index:2147483645;pointer-events:none;
      display:flex;align-items:stretch;border-radius:\${8*K}px;overflow:hidden;box-shadow:0 4px 18px rgba(6,71,113,.35);
      font:600 \${19*K}px/1.25 "Noto Sans","Helvetica Neue",Arial,sans-serif;opacity:0;transition:opacity .25s;white-space:nowrap}
    #demo-caption.on{opacity:1}
    #demo-caption .role{padding:\${9*K}px \${14*K}px;color:#fff;letter-spacing:.02em}
    #demo-caption .text{padding:\${9*K}px \${16*K}px;background:#064771;color:#fff}
    #demo-caption .role.builder{background:#0678be}
    #demo-caption .role.editor{background:#53b0eb;color:#064771}
    #demo-caption .role.visitor,#demo-caption .role.reviewer{background:#666}
    #demo-caption .role.none{display:none}
    #demo-dim{position:fixed;inset:0;background:rgba(6,71,113,.72);z-index:2147483640;pointer-events:none;opacity:0;transition:opacity .35s}
    #demo-dim.on{opacity:1}
    #demo-spot{position:fixed;z-index:2147483639;pointer-events:none;opacity:0;border-radius:\${8*K}px;transition:opacity .3s;
      box-shadow:0 0 0 \${3*K}px rgba(0,156,222,.9),0 0 0 \${9999}px rgba(6,71,113,.16)}
    #demo-spot.on{opacity:1}
    #demo-spot.pulse{animation:demo-pulse 1.1s ease-in-out 1}
    @keyframes demo-pulse{0%,100%{box-shadow:0 0 0 \${3*K}px rgba(0,156,222,.9),0 0 0 9999px rgba(6,71,113,.16)}50%{box-shadow:0 0 0 \${8*K}px rgba(0,156,222,.45),0 0 0 9999px rgba(6,71,113,.16)}}
    #demo-card{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);z-index:2147483641;pointer-events:none;opacity:0;transition:opacity .35s;
      background:#fff;color:#2d2926;border-radius:\${14*K}px;border-top:\${8*K}px solid #009cde;padding:\${30*K}px \${44*K}px;max-width:\${760*K}px;text-align:center;
      font-family:"Noto Sans","Helvetica Neue",Arial,sans-serif;box-shadow:0 12px 40px rgba(0,0,0,.35)}
    #demo-card.on{opacity:1}
    #demo-card .n{font-size:\${17*K}px;font-weight:700;color:#0678be;letter-spacing:.08em;text-transform:uppercase}
    #demo-card .t{font-size:\${34*K}px;font-weight:700;color:#064771;margin:\${8*K}px 0 \${12*K}px;line-height:1.15}
    #demo-card .g{font-size:\${20*K}px;line-height:1.35;color:#2d2926}
  \`;
  document.documentElement.appendChild(css);
  const mk = (tag, id, html) => { const e = document.createElement(tag); e.id = id; if (html) e.innerHTML = html; document.documentElement.appendChild(e); return e; };
  mk('div', 'demo-dim');
  mk('div', 'demo-spot');
  mk('div', 'demo-card', '<div class="n"></div><div class="t"></div><div class="g"></div>');
  mk('div', 'demo-ring');
  mk('div', 'demo-cursor', '<svg viewBox="0 0 26 26"><path d="M4 2 L4 21 L9 16.5 L12.5 24 L16 22.4 L12.6 15 L19.5 15 Z" fill="#fff" stroke="#2d2926" stroke-width="1.6" stroke-linejoin="round"/></svg>');
  mk('div', 'demo-caption', '<span class="role"></span><span class="text"></span>');
  window.__demoMove = (x, y) => { document.getElementById('demo-cursor').style.transform = 'translate(' + (x - 4 * K) + 'px,' + (y - 2 * K) + 'px)'; };
  window.__demoDown = (on, x, y) => {
    document.getElementById('demo-cursor').classList.toggle('down', on);
    if (on) { const r = document.getElementById('demo-ring'); r.style.left = x + 'px'; r.style.top = y + 'px'; r.classList.remove('go'); void r.offsetWidth; r.classList.add('go'); }
  };
  window.__demoCaption = (role, text) => {
    const c = document.getElementById('demo-caption');
    if (!text) { c.classList.remove('on'); return; }
    const r = c.querySelector('.role'); r.textContent = role || ''; r.className = 'role ' + (!role ? 'none' : /builder/i.test(role) ? 'builder' : /editor/i.test(role) ? 'editor' : 'visitor');
    c.querySelector('.text').textContent = text; c.classList.add('on');
  };
  window.__demoSpot = (r) => {
    const e = document.getElementById('demo-spot');
    if (!r) { e.classList.remove('on', 'pulse'); return; }
    const pad = 6 * K;
    e.style.left = (r.x - pad) + 'px'; e.style.top = (r.y - pad) + 'px';
    e.style.width = (r.width + 2 * pad) + 'px'; e.style.height = (r.height + 2 * pad) + 'px';
    e.classList.add('on');
  };
  window.__demoCard = (n, t, g) => {
    const c = document.getElementById('demo-card'), d = document.getElementById('demo-dim');
    if (!t) { c.classList.remove('on'); d.classList.remove('on'); return; }
    c.querySelector('.n').textContent = n || ''; c.querySelector('.t').textContent = t; c.querySelector('.g').textContent = g || '';
    c.classList.add('on'); d.classList.add('on');
  };
  };
  if (document.documentElement) build(); else document.addEventListener('readystatechange', build);
})();
`;

const FAST = !!process.env.FAST;
const PACE = Number(process.env.PACE || 1);
const sleep = (ms) => new Promise((r) => setTimeout(r, FAST ? Math.min(ms, 15) : ms * PACE));
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

class Demo {
  constructor(page) {
    this.page = page;
    this.x = 640;
    this.y = 360;
    this.cap = null;
    this.sections = [];
    this.t0 = Date.now();
    this.ready = 0;
    const orig = page.evaluate.bind(page);
    page.evaluate = async (...a) => {
      for (let i = 0; ; i++) {
        try { return await orig(...a); } catch (e) {
          if (i > 5 || !/context was destroyed|navigation|Target closed/i.test(e.message)) throw e;
          await page.waitForLoadState('domcontentloaded').catch(() => {});
          await wait(200);
        }
      }
    };
    this.pendingSave = false;
    page.on('response', (r) => {
      if (r.request().method() !== 'GET' && /\/canvas\/api\/v0\/(layout|form\/component-instance)\//.test(r.url())) this.pendingSave = false;
    });
    this.inflight = 0;
    this.lastActivity = Date.now();
    const bump = () => { this.lastActivity = Date.now(); };
    page.on('request', (r) => { if (!/\.(png|jpe?g|svg|woff2?|ico)(\?|$)/.test(r.url())) { this.inflight++; bump(); } });
    page.on('requestfinished', (r) => { if (!/\.(png|jpe?g|svg|woff2?|ico)(\?|$)/.test(r.url())) { this.inflight = Math.max(0, this.inflight - 1); bump(); } });
    page.on('requestfailed', (r) => { if (!/\.(png|jpe?g|svg|woff2?|ico)(\?|$)/.test(r.url())) { this.inflight = Math.max(0, this.inflight - 1); bump(); } });
  }

  // Wait until the page has been quiet (no requests in flight) for a moment,
  // so the next move starts from a settled screen.
  async settle(quiet = 350, max = 2500) {
    const start = Date.now();
    while (Date.now() - start < max) {
      if (this.inflight === 0 && Date.now() - this.lastActivity >= quiet) break;
      await wait(100);
    }
    // After a full page load the overlay is rebuilt by the init script; put the
    // cursor and caption back where they were.
    const has = await this.page.evaluate(() => !!window.__demoMove).catch(() => true);
    if (!has) await this.overlay();
    else if (this.cap && !(await this.page.evaluate(() => document.querySelector('#demo-caption .text')?.textContent || '').catch(() => 'x'))) await this.caption(...this.cap);
  }

  async init(context) {
    await context.addInitScript(OVERLAY);
  }

  async overlay() {
    await this.page.evaluate(OVERLAY);
    await this.page.evaluate(([x, y]) => window.__demoMove(x, y), [this.x, this.y]);
    if (this.cap) await this.caption(...this.cap);
  }

  async caption(role, text) {
    // A caption set just before a navigation belongs to the new page: keep
    // showing the previous one until the page has loaded (see goto).
    this.prevCap = this.cap;
    this.capFresh = Date.now();
    this.cap = [role, text];
    await this.page.evaluate(([r, t]) => window.__demoCaption && window.__demoCaption(r, t), [role, text]);
  }

  async goto(url, until = 'load') {
    // Canvas saves edits a moment after they are made; let it finish first.
    const fresh = Date.now() - (this.capFresh || 0) < 800;
    if (fresh && this.prevCap) await this.page.evaluate(([r, t]) => window.__demoCaption && window.__demoCaption(r, t), this.prevCap);
    await this.autosaved();
    await this.page.goto(url, { waitUntil: until });
    await this.overlay();
  }

  // The visitor is the same browser with the session cookie set aside, as in a
  // private window; the admin session comes back when the script asks for it.
  async asVisitor(context = this.context) {
    await context.clearCookies();
  }

  async asAdmin(context = this.context) {
    await context.clearCookies();
    if (this.adminCookies) await context.addCookies(this.adminCookies);
  }

  async asReviewer(context = this.context) {
    await context.clearCookies();
    await context.addCookies(this.reviewerCookies);
  }

  async moveTo(x, y, { speed = 1 } = {}) {
    const dx = x - this.x, dy = y - this.y;
    const dist = Math.hypot(dx, dy);
    if (FAST) { await this.page.mouse.move(x, y); this.x = x; this.y = y; return; }
    const dur = (Math.min(700, 170 + dist * 0.45) * PACE) / speed;
    const steps = Math.max(8, Math.round(dur / 16));
    // A slight arc so the path is not a straight rail.
    const nx = -dy / (dist || 1), ny = dx / (dist || 1);
    const bend = Math.min(40, dist * 0.06) * (dx > 0 ? 1 : -1);
    const sx = this.x, sy = this.y;
    for (let i = 1; i <= steps; i++) {
      const t = ease(i / steps);
      const arc = Math.sin(Math.PI * t) * bend;
      const px = sx + dx * t + nx * arc, py = sy + dy * t + ny * arc;
      await this.page.mouse.move(px, py);
      await this.page.evaluate(([a, b]) => window.__demoMove && window.__demoMove(a, b), [px, py]);
      await sleep(12);
    }
    this.x = x; this.y = y;
  }

  async box(target) {
    const loc = typeof target === 'string' ? this.page.locator(target).first() : target.first();
    await loc.waitFor({ state: 'visible' });
    let b = await loc.boundingBox();
    const vp = this.page.viewportSize();
    if (b && (b.y < 0 || b.y + b.height > vp.height || b.x < 0 || b.x + b.width > vp.width)) {
      await loc.scrollIntoViewIfNeeded();
      b = await loc.boundingBox();
    }
    if (!b) throw new Error('no box for ' + target);
    return b;
  }

  async hover(target, { dx = 0.5, dy = 0.5 } = {}) {
    const b = await this.box(target);
    await this.moveTo(b.x + b.width * dx, b.y + b.height * dy);
    return b;
  }

  async click(target, opts = {}) {
    const b = await this.hover(target, opts);
    await sleep(opts.pause ?? 200);
    const x = this.x, y = this.y;
    await this.page.evaluate(([a, c]) => window.__demoDown && window.__demoDown(true, a, c), [x, y]);
    await this.page.mouse.down();
    await sleep(70);
    await this.page.mouse.up();
    await this.page.evaluate(() => window.__demoDown && window.__demoDown(false)).catch(() => {});
    await sleep(opts.after ?? 180);
    await this.settle(opts.quiet ?? 300);
    return b;
  }

  // A soft ring and a slight dim around an element that was just opened to
  // show a value. It follows the element, then fades out before the cursor moves.
  async spotlight(target, hold = 2600) {
    const loc = typeof target === 'string' ? this.page.locator(target).first() : target.first();
    await loc.waitFor({ state: 'visible' });
    const end = Date.now() + (FAST ? 100 : hold);
    let first = true;
    while (Date.now() < end) {
      const b = await loc.boundingBox().catch(() => null);
      if (b) await this.page.evaluate((r) => window.__demoSpot && window.__demoSpot(r), b);
      if (first) { first = false; await this.page.evaluate(() => document.getElementById('demo-spot')?.classList.add('pulse')); }
      await wait(60);
    }
    await this.page.evaluate(() => window.__demoSpot && window.__demoSpot(null));
    await wait(FAST ? 20 : 350);
  }

  // A native select: the cursor goes there and clicks, the option is set directly
  // (the browser's own popup is not part of the page, so it can't be recorded).
  async select(target, pattern) {
    const loc = typeof target === 'string' ? this.page.locator(target).first() : target.first();
    const b = await this.hover(loc);
    await sleep(380);
    // A row menu left open would take the keys: close it first.
    if ((await this.page.locator('[role=menu]').count()) > 0) { await this.page.keyboard.press('Escape'); await wait(300); }
    const { idx, cur } = await loc.evaluate((el, src) => {
      const re = new RegExp(src);
      return { idx: [...el.options].findIndex((x) => re.test(x.textContent.trim())), cur: el.selectedIndex };
    }, pattern.source);
    if (idx < 0) throw new Error('no option ' + pattern);
    // A real click opens the browser's popup (it shows in the recording); the
    // keyboard then walks to the option, so the choice is visible.
    await this.page.evaluate(([a, c]) => window.__demoDown && window.__demoDown(true, a, c), [this.x, this.y]);
    await this.page.mouse.down();
    await sleep(70);
    await this.page.mouse.up();
    await this.page.evaluate(() => window.__demoDown && window.__demoDown(false));
    await wait(FAST ? 400 : 500);
    for (let i = 0; i < Math.abs(idx - cur); i++) {
      await this.page.keyboard.press(idx > cur ? 'ArrowDown' : 'ArrowUp');
      await wait(FAST ? 200 : 300);
    }
    await wait(FAST ? 250 : 350);
    await this.page.keyboard.press('Enter');
    await sleep(350);
    await this.settle(350);
    return b;
  }

  async drag(src, dst, { dx = 0.5, dy = 0.5 } = {}) {
    await this.hover(src, { dx: 0.3 });
    await wait(300);
    await this.page.mouse.down();
    await this.page.evaluate(() => window.__demoDown && window.__demoDown(true, 0, 0));
    await wait(200);
    // Small first move so the drag starts, then the eased path to the target.
    await this.moveTo(this.x + 14, this.y + 14, { speed: 2 });
    await wait(150);
    const db = await this.box(dst);
    await this.moveTo(db.x + db.width * dx, db.y + db.height * dy);
    await wait(500);
    await this.page.mouse.up();
    await this.page.evaluate(() => window.__demoDown && window.__demoDown(false));
    await wait(400);
    await this.settle(350);
  }

  // Scrolls whatever is under the cursor, in small eased steps.
  async scroll(target, dy) {
    await this.hover(target);
    const n = Math.max(6, Math.round(Math.abs(dy) / 30));
    let done = 0;
    for (let i = 1; i <= n; i++) {
      const to = Math.round(dy * ease(i / n));
      await this.page.mouse.wheel(0, to - done);
      done = to;
      await sleep(30);
    }
    await sleep(300);
  }

  async type(text, delay = 42) {
    for (const ch of text) {
      await this.page.keyboard.type(ch);
      // Typing speed is not scaled by PACE: it stays human.
      await wait(FAST ? 5 : delay + Math.random() * 22);
    }
  }

  async fill(target, text, opts = {}) {
    if (opts.canvas !== false) this.pendingSave = true;
    await this.click(target, { dx: 0.9, ...opts });
    await this.page.keyboard.press('Control+A');
    await sleep(150);
    await this.page.keyboard.press('Backspace');
    await sleep(150);
    await this.type(text);
    await sleep(300);
  }

  pause(ms) { return sleep(ms); }

  // A section card over the dimmed page, shown between actions. Records the
  // start time so the section strip can be burned in at encode time.
  async section(n, title, goal) {
    const t = (Date.now() - this.t0) / 1000 - this.ready;
    if (n) this.sections.push({ n, title, t: Math.max(0, t) });
    await this.page.evaluate(([a, b, c]) => window.__demoCard && window.__demoCard(a, b, c), [n ? `${n} of 5` : '', title, goal]);
    await wait(FAST ? 50 : 5000);
    await this.page.evaluate(() => window.__demoCard && window.__demoCard(null));
    await wait(FAST ? 20 : 450);
  }

  // Waits for Canvas to save what was just typed (it saves after a short
  // debounce), and goes on as soon as the save has happened.
  async autosaved() {
    const end = Date.now() + 3500;
    while (this.pendingSave && Date.now() < end) await wait(80);
  }

  // A real wait that is not shortened in FAST mode (for page state).
  wait(ms) { return wait(ms); }
}

module.exports = { Demo, sleep, OVERLAY };
