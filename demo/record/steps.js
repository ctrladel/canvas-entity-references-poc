// The walkthrough, one function per step. Each takes the Demo helper (d) and
// the Playwright page. Roles: Site builder, Editor, Visitor.
const BASE = process.env.BASE || 'https://canvas-demo.ddev.site';

const layer = (page, name) => page.locator('[data-testid="canvas-primary-panel"]').getByText(name, { exact: true });
const menuItem = (page, text) => page.locator('[role=menuitem], [role=option], [role=menuitemradio]').filter({ hasText: text }).first();
const bindTrigger = (page) => page.locator('[data-testid="entity-binding-trigger"]');
const fieldLinker = (page, label) => page.locator(`button[aria-label^="Link ${label}"]`).first();
async function selectLayer(d, locator) {
  const p = d.page;
  const sel = '[data-testid^="canvas-contextual-panel-"]:not([data-testid*="--"])';
  const read = () => p.evaluate((q) => document.querySelector(q)?.getAttribute('data-testid') ?? null, sel);
  const before = await read();
  await d.click(locator);
  await p.waitForFunction(([q, b]) => { const e = document.querySelector(q); return e && e.getAttribute('data-testid') !== b; }, [sel, before], { timeout: 1500 }).catch(() => {});
  await p.locator('[data-testid="canvas-contextual-panel--settings"]').waitFor();
  await d.settle(350);
}
const center = (page) => page.locator('[data-testid="scale-to-fit"]').click();

async function openLayers(d) {
  const p = d.page;
  await p.getByRole('button', { name: 'Layers' }).click();
  await d.settle();
}

// Waits until the preview has the text, so the next move starts from the result.
async function previewShows(d, text) {
  const end = Date.now() + 10000;
  while (Date.now() < end) {
    for (const f of d.page.frames()) if ((await f.getByText(text).count().catch(() => 0)) > 0) return;
    await d.wait(100);
  }
  throw new Error('preview never showed ' + text);
}

// Opens the reference popup of the selected component and picks content.
async function pickContent(d, bundle, name) {
  const p = d.page;
  await d.click(bindTrigger(p));
  await d.hover(menuItem(p, /^Content$/));
  await d.wait(250);
  await d.click(menuItem(p, new RegExp(`^${bundle}$`)));
  await d.wait(250);
  await d.click(menuItem(p, new RegExp(`^${name}$`)));
  await d.settle(350);
}

// Links one prop of the selected component to a field, by the menu path.
async function linkProp(d, label, ...path) {
  const p = d.page;
  await d.click(fieldLinker(p, label));
  for (let i = 0; i < path.length; i++) {
    const it = menuItem(p, new RegExp(`^${path[i]}`));
    if (i < path.length - 1) { await d.hover(it); await d.wait(250); } else await d.click(it);
  }
  await d.settle(350);
}

async function closeMenus(d) {
  const p = d.page;
  for (let i = 0; i < 3 && (await p.locator('[role=menu]').count()) > 0; i++) {
    await p.keyboard.press('Escape');
    await d.wait(400);
  }
}


const panel = (p) => p.locator('[data-testid="canvas-primary-panel"]');
const ctx = (p) => p.locator('[data-testid^="canvas-contextual-panel"]');
const frame = (p) => p.locator('[data-testid="canvas-editor-frame"]');
const formInputs = (p) => p.locator('[data-testid^="canvas-component-form-"] input[type=text], [data-testid^="canvas-component-form-"] textarea');

async function ensurePanel(d, name) {
  const p = d.page;
  if ((await panel(p).getByText(name, { exact: true }).count()) === 0) {
    await d.click(p.getByRole('button', { name }));
    await d.settle();
  }
}

// Keeps the preview centred and settled before the next action.
async function fit(d) {
  await d.wait(300);
  await d.settle(350);
}

// Drags a component from the library into the preview and checks it landed.
async function dropComponent(d, name, target, slotId) {
  const p = d.page;
  await ensurePanel(d, 'Library');
  await fit(d);
  const formId = () => p.evaluate(() => document.querySelector('[data-testid^="canvas-component-form-"]')?.getAttribute('data-testid') ?? null);
  const slots = () => p.locator(`[data-testid^="canvas-empty-slot-drop-zone-"][data-testid$=":${slotId}"]`).count();
  const before = slotId ? await slots() : await formId();
  for (let k = 0; k < 3; k++) {
    await d.drag(panel(p).getByText(name, { exact: true }).first(), target());
    await fit(d);
    if (slotId) { if ((await slots()) > before) return; } else {
      const after = await formId();
      if (after && after !== before) return;
    }
  }
  throw new Error('could not place ' + name);
}

const slot = (p, id) => () => p.locator(`[data-testid^="canvas-empty-slot-drop-zone-"][data-testid$=":${id}"]`).first();

// Canvas's own page navigator: the title in the top bar, then the page.
async function gotoPage(d, title) {
  const p = d.page;
  await d.autosaved();
  await d.click(p.locator('[data-testid="canvas-navigation-button"]'));
  await d.pause(150);
  await d.click(p.locator('[data-testid="canvas-navigation-results"] a[role=listitem]').filter({ hasText: title }).first());
  await d.settle(350);
  await d.pause(150);
}

// The Pattern editor through the library: Patterns tab, the row menu, Edit.
async function openPattern(d, name) {
  const p = d.page;
  await ensurePanel(d, 'Library');
  const tab = panel(p).locator('[role=tab]').filter({ hasText: 'Patterns' });
  await d.wait(700);
  await d.click(tab);
  await d.wait(300);
  await d.hover(panel(p).getByText(name));
  await d.click(panel(p).getByLabel('Open contextual menu').first());
  await d.click(menuItem(p, /^Edit pattern/));
  await d.settle(350);
  await d.pause(150);
}

// Drupal's content list, then Edit on the row (the list is reached by URL).
async function editFromContentList(d, title) {
  const p = d.page;
  await d.goto(`${BASE}/admin/content`);
  await d.settle(350);
  const row = p.locator('tr').filter({ hasText: title }).first();
  await d.click(row.getByRole('link', { name: 'Edit' }));
  await d.settle(350);
}

// Step 1: the problem, built from a blank page: an SDC with hand-typed values.
async function step1(d) {
  const p = d.page;
  await d.section(null, 'Show Drupal content in components', 'Goal: a card that is set up once and shows a person or a product on any page.');
  await d.section(1, 'The problem', 'Goal: the starting point, a component with no link to content.');
  await dropComponent(d, 'Testimonial', () => frame(p));
  await d.caption('', 'Every value is entered by hand');
  await d.fill(formInputs(p).first(), 'Setup took two minutes.');
  await dropComponent(d, 'Attribution', slot(p, 'attribution'));
  await d.fill(formInputs(p).nth(0), 'Maya Okafor');
  await d.fill(formInputs(p).nth(1), 'Head of Design');
  await d.pause(1500);
}

// Step 2: raw linking on the testimonial.
async function step2(d) {
  const p = d.page;
  await d.section(2, 'Link any component to content', "Goal: a testimonial that shows a person's details.");
  await ensurePanel(d, 'Layers');
  await fit(d);
  await selectLayer(d, layer(p, 'Testimonial').first());
  await d.caption('Site builder', 'Pick a person for the component');
  await d.pause(150);
  await pickContent(d, 'Person', 'Daniel Reyes');
  await d.pause(150);
  await d.caption('', 'The quote stays as entered');
  await d.hover(formInputs(p).first());
  await d.pause(1800);
}

// Step 3: inheritance in the slot, then links and an override.
async function step3(d) {
  const p = d.page;
  await d.caption('', 'The attribution inherits the person');
  await selectLayer(d, layer(p, 'Attribution').last());
  await d.pause(150);
  await d.click(bindTrigger(p));
  await d.spotlight(menuItem(p, /^Inherit/), 3400);
  await p.keyboard.press('Escape');
  await d.wait(500);
  await d.caption('', 'Each prop links to a field of the person');
  await linkProp(d, 'name', 'Title');
  await linkProp(d, 'role', 'Role');
  await linkProp(d, 'photo', 'Photo');
  await d.pause(1800);
  await d.caption('', 'The attribution can pick a different person');
  await pickContent(d, 'Person', 'Priya Raman');
  await previewShows(d, 'Priya Raman');
  await d.pause(2000);
  await d.caption('', '');
  await d.click(bindTrigger(p));
  await d.click(menuItem(p, /^Inherit/));
  await previewShows(d, 'Daniel Reyes');
  await closeMenus(d);
  await d.pause(1800);
}

const publishAll = async (d) => {
  const p = d.page;
  await d.autosaved();
  await p.locator('[data-testid="canvas-publish-review"]').filter({ hasText: /Review/ }).waitFor();
  await d.click(p.locator('[data-testid="canvas-publish-review"]'));
  await d.click(p.getByText('Select All', { exact: true }));
  await d.click(p.getByRole('button', { name: /^Publish/ }).last());
  await d.settle(500, 20000);
  await d.pause(700);
};

// Step 4: a reference sourced from a field, on a Content Template built from empty.
async function step4(d) {
  const p = d.page;
  await d.caption('', '');
  await d.click(p.getByRole('button', { name: 'Templates' }));
  await d.click(panel(p).getByText('Product', { exact: true }));
  await d.click(panel(p).getByText('Full content template'));
  await d.settle(350);
  await ensurePanel(d, 'Layers');
  await selectLayer(d, layer(p, 'Testimonial').first());
  await d.click(bindTrigger(p));
  await d.caption('', "The person comes from the product's Expert field");
  await d.hover(menuItem(p, /^From a field/));
  await d.spotlight(menuItem(p, /^Expert/), 2600);
  await d.click(menuItem(p, /^Expert/));
  await d.settle(500);
  // The preview shows a product with an expert, so the person arrives.
  await d.click(p.getByRole('button', { name: /Travel kettle/ }));
  await d.click(menuItem(p, /Pour-over set/));
  await d.settle(500);
  await d.spotlight(p.locator('[data-testid="canvas-editor-frame"]'), 2800);
  await d.caption('', '');
  await publishAll(d);
}

// Step 5: a card becomes a Pattern bound to Person and Product.
async function step5(d) {
  const p = d.page;
  await d.section(3, 'A card bound to content', 'Goal: one card that shows a person or a product.');
  await gotoPage(d, 'Catalog');
  await ensurePanel(d, 'Library');
  await fit(d);
  await dropComponent(d, 'Three-column grid', () => frame(p), 'first-column');
  await dropComponent(d, 'Card', slot(p, 'first-column'));
  await d.fill(formInputs(p).nth(0), 'Pour-over set');
  await d.fill(formInputs(p).nth(1), 'A slow, clean cup.');
  await ensurePanel(d, 'Layers');
  await fit(d);
  await selectLayer(d, layer(p, 'Card').first());
  await d.click(panel(p).getByLabel('Open contextual menu').nth(1));
  await d.click(menuItem(p, /^Create pattern/));
  await p.getByLabel('Pattern name').waitFor();
  await d.wait(400);
  await d.pause(150);
  await p.keyboard.press('Control+A');
  await d.type('Catalog card');
  await d.pause(150);
  await d.click(p.getByRole('button', { name: 'Add to library' }));
  await d.settle(350);
  await d.pause(150);
  await openPattern(d, 'Catalog card');
  await d.caption('', 'Bind the Pattern to Person and Product');
  await d.select(ctx(p).locator('select').first(), /^Content$/);
  await d.pause(150);
  await d.click(ctx(p).getByLabel('Person'));
  await d.click(ctx(p).getByLabel('Product'));
  await d.settle(350);
  await d.pause(150);
  await ensurePanel(d, 'Layers');
  await fit(d);
  await selectLayer(d, layer(p, 'Card').first());
  await d.caption('', 'Links are set for each content type');
  await linkProp(d, 'heading', 'Title');
  await linkProp(d, 'body', 'Role');
  await linkProp(d, 'image', 'Photo');
  await linkProp(d, 'url', 'Relative URL');
  await d.pause(1200);
  await d.caption('', 'A product fills the same card from its own fields');
  await d.select(ctx(p).locator('select').first(), /^Product/);
  await d.settle(350);
  await linkProp(d, 'heading', 'Title');
  await linkProp(d, 'body', 'Summary');
  await linkProp(d, 'image', 'Image');
  await linkProp(d, 'url', 'Relative URL');
  await d.pause(1500);
  await d.caption('', '');
  await publishAll(d);
}

// Step 6: the editor places the Pattern three times and picks content.
async function step6(d) {
  const p = d.page;
  await d.section(4, 'Live placements', 'Goal: a three-wide page that stays up to date.');
  await gotoPage(d, 'Featured');
  await ensurePanel(d, 'Library');
  await d.click(panel(p).locator('[role=tab]').filter({ hasText: 'Components' }));
  await fit(d);
  await dropComponent(d, 'Three-column grid', () => frame(p), 'first-column');
  await d.pause(150);
  const names = ['first-column', 'second-column', 'third-column'];
  const picks = [['Maya', 'Maya Okafor'], ['Desk', 'Desk lamp'], ['Daniel', 'Daniel Reyes']];
  for (let i = 0; i < 3; i++) {
    if (i === 0) await d.caption('Editor', 'This placement stays in sync with the Pattern');
    const sel = `[data-testid^="canvas-empty-slot-drop-zone-"][data-testid$=":${names[i]}"]`;
    for (let k = 0; k < 3; k++) {
      const before = await p.locator(sel).count();
      await d.drag(panel(p).getByText('Catalog card', { exact: true }), p.locator(sel).first());
      await fit(d);
      if ((await p.locator(sel).count()) < before) break;
    }
    if (i === 0) await d.caption('', 'Content picks what this placement shows');
    await d.click(p.getByPlaceholder(/Find content/));
    await d.type(picks[i][0]);
    await d.wait(700);
    await d.click(p.locator('[role=option]').filter({ hasText: picks[i][1] }).first());
    await d.settle(350);
    await d.pause(1200);
  }
  await d.caption('', '');
  await publishAll(d);
}

// The logged-out page, scrolled to the cards.
async function visitor(d, label) {
  const p = d.page;
  await d.caption(label ? 'Visitor' : '', label);
  await d.asVisitor();
  await d.goto(`${BASE}/featured`);
  await d.settle(350);
  await d.scroll(p.locator('body'), 260);
  await d.pause(2800);
}

async function editNode(d, title, field, value) {
  const p = d.page;
  await d.caption('', '');
  await d.asAdmin();
  await editFromContentList(d, title);
  await d.fill(p.locator(`#edit-${field}-0-value`), value, { canvas: false });
  await d.scroll(p.locator('main, body').first(), 700);
  await d.click(p.locator('#edit-submit'));
  await d.settle(350);
  await d.pause(150);
}

// Step 7: edits reach the live page, seen logged out.
async function step7(d) {
  const p = d.page;
  await visitor(d, '');
  await editNode(d, 'Maya Okafor', 'field-role', 'Design Director');
  await visitor(d, 'The new role shows on the page');
  await editNode(d, 'Desk lamp', 'field-summary', 'Warm, dimmable light.');
  await visitor(d, '');
  await d.caption('', '');
  await d.asAdmin();
  await d.goto(`${BASE}/canvas`);
  await d.settle(350);
  await gotoPage(d, 'Featured');
  await openPattern(d, 'Catalog card');
  await ensurePanel(d, 'Layers');
  await fit(d);
  await selectLayer(d, layer(p, 'Card').first());
  await d.select(ctx(p).locator('select').filter({ has: p.locator('option', { hasText: 'Accent' }) }).first(), /^Accent/);
  await d.pause(1800);
  await publishAll(d);
  await visitor(d, 'The Pattern change shows on every card');
}

// Step 8: expose the tone, then override it on one placement.
async function step8(d) {
  const p = d.page;
  await d.section(5, 'Overrides and edge cases', 'Goal: give one card its own tone.');
  await d.caption('Site builder', 'The lock lets placements change Tone');
  await d.asAdmin();
  await d.goto(`${BASE}/canvas`);
  await d.settle(350);
  await gotoPage(d, 'Featured');
  await openPattern(d, 'Catalog card');
  await ensurePanel(d, 'Layers');
  await fit(d);
  await selectLayer(d, layer(p, 'Card').first());
  await d.click(p.locator('button[aria-label="Expose tone to placements"]'));
  await d.wait(500);
  await d.pause(150);
  await d.click(p.getByRole('button', { name: 'Expose', exact: true }));
  await d.settle(350);
  await d.pause(1800);
  await d.caption('', '');
  await publishAll(d);
  await gotoPage(d, 'Featured');
  await ensurePanel(d, 'Layers');
  await fit(d);
  await selectLayer(d, layer(p, 'Catalog card').nth(1));
  await d.pause(1400);
  await d.caption('Editor', 'An open lock means this card has its own value');
  await d.click(p.getByRole('button', { name: 'Override this value' }));
  await d.pause(150);
  await d.select(ctx(p).locator('select').filter({ has: p.locator('option', { hasText: 'Featured' }) }).first(), /^Featured/);
  await d.pause(2600);
  await d.caption('', '');
  await publishAll(d);
}

// Step 9: the example value stands in for an empty required prop, and the
// permission to link props.
async function step9(d) {
  const p = d.page;
  await d.caption('', '');
  await d.caption('Reviewer', 'Without the link permission, links can be seen but not changed');
  await d.asReviewer();
  await d.goto(`${BASE}/canvas`);
  await d.settle(350);
  await gotoPage(d, 'Customer stories');
  await ensurePanel(d, 'Layers');
  await fit(d);
  await selectLayer(d, layer(p, 'Testimonial').first());
  await d.pause(1800);
  await d.click(bindTrigger(p));
  await d.spotlight(p.getByText(/^Linked to/).first(), 3000);
  await p.keyboard.press('Escape');
  await d.wait(500);
  await selectLayer(d, layer(p, 'Attribution').last());
  await d.pause(2800);
}

module.exports = { BASE, layer, menuItem, bindTrigger, fieldLinker, center, openLayers, pickContent, linkProp, closeMenus, selectLayer, publishAll, panel, ctx, ensurePanel, fit, step1, step2, step3, step4, step5, step6, step7, step8, step9 };
