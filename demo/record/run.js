// node run.js [--steps 1,2,3] [--video]
// FAST=1 skips the pacing, for building state or debugging a step.
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { Demo } = require('./lib');
const S = require('./steps');

const args = process.argv.slice(2);
const stepsArg = (args.find((a) => a.startsWith('--steps=')) || '--steps=1,2,3').split('=')[1].split(',').map((v) => (/^\d+$/.test(v) ? Number(v) : v));
const video = args.includes('--video');
const out = path.join(__dirname, 'out');
fs.mkdirSync(out, { recursive: true });
const exe = process.env.CHROME || path.join(process.env.HOME, '.cache/ms-playwright/chromium-1234/chrome-linux64/chrome');
const W = Number(process.env.W || 1920), H = Number(process.env.H || 1040);

async function login(browser, user, pass) {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
  const p = await ctx.newPage();
  await p.goto(`${S.BASE}/user/login`);
  await p.fill('#edit-name', user);
  await p.fill('#edit-pass', pass);
  await p.click('#edit-submit');
  await p.waitForLoadState('networkidle');
  const state = await ctx.storageState();
  await ctx.close();
  return state;
}

(async () => {
  const browser = await chromium.launch({ executablePath: exe, args: ['--force-device-scale-factor=1'] });
  const admin = await login(browser, 'admin', 'admin');
  const reviewer = await login(browser, 'reviewer', 'reviewer');
  const ctx = await browser.newContext({
    viewport: { width: W, height: H },
    ignoreHTTPSErrors: true,
    storageState: admin,
    ...(video ? { recordVideo: { dir: out, size: { width: W, height: H } } } : {}),
  });
  const page = await ctx.newPage();
  page.setDefaultTimeout(15000);
  const d = new Demo(page);
  d.context = ctx;
  d.adminCookies = admin.cookies;
  d.reviewerCookies = reviewer.cookies;
  await d.init(ctx);
  const t0 = Date.now();
  try {
    await d.goto(process.env.START_URL || `${S.BASE}/canvas/editor/canvas_page/${process.env.START_PAGE || 1}`);
    await d.settle(1500);
    // Pre-roll: the Tablet-width preview (a three-column desktop layout) at 110%, kept throughout.
    await page.getByRole('button', { name: /Tablet|Mobile|Desktop/ }).first().click();
    await page.getByText('Tablet (1024px)').click();
    await d.settle(800);
    await page.locator('[data-testid="canvas-editor-frame-controls"]').getByText(/^\d+%$/).click();
    await page.locator('[role=menuitem], [role=option]').filter({ hasText: /^110%/ }).first().click();
    await S.openLayers(d);
    await S.center(page);
    await d.settle(1200);
    const ready = (Date.now() - t0) / 1000;
    d.t0 = t0; d.ready = ready;
    fs.writeFileSync(path.join(out, 'ready.txt'), String(ready));
    for (const n of stepsArg) {
      console.log('step', n, ((Date.now() - t0) / 1000).toFixed(1));
      await (typeof n === 'number' ? S['step' + n](d) : require('./' + n + '.js')(d, S));
    }
    fs.writeFileSync(path.join(out, 'sections.json'), JSON.stringify({ sections: d.sections, end: (Date.now() - t0) / 1000 - d.ready }));
    await page.screenshot({ path: path.join(out, 'last.png') });
    console.log('done', ((Date.now() - t0) / 1000).toFixed(1));
  } catch (e) {
    console.log('FAILED', e.message.split('\n').slice(0, 5).join('\n'));
    await page.screenshot({ path: path.join(out, 'fail.png') });
  }
  await ctx.close();
  await browser.close();
})();
