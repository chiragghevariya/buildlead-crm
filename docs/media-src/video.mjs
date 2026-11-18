// Scripted walkthrough of the demo-mode SPA, captured via CDP screencast at 1920×1080 (1440×810 CSS px @ 4/3).
import { chromium } from 'playwright'
import fs from 'node:fs'
const FRAMES = process.argv[2]; const BASE = process.env.BASE_URL || 'http://localhost:5192'
fs.rmSync(FRAMES, { recursive: true, force: true }); fs.mkdirSync(FRAMES, { recursive: true })
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const ctx = await browser.newContext({ viewport: { width: 1440, height: 810 }, deviceScaleFactor: 4 / 3 })
await ctx.addInitScript(() => {
  localStorage.setItem('buildlead-theme', 'light')
  const boot = () => {
    const css = document.createElement('style')
    css.textContent = `[data-demo-banner]{display:none!important}
      #__cur{position:fixed;left:0;top:0;z-index:2147483647;pointer-events:none;transform:translate(-100px,-100px);transition:none}
      #__cur .halo{position:absolute;left:-20px;top:-20px;width:40px;height:40px;border-radius:50%;background:rgba(245,158,11,.28);border:2px solid rgba(245,158,11,.9);box-shadow:0 0 0 6px rgba(245,158,11,.12);transition:transform .15s ease}
      #__cur.down .halo{transform:scale(.72);background:rgba(245,158,11,.45)}
      #__cur svg{position:absolute;left:-3px;top:-2px;filter:drop-shadow(0 2px 3px rgba(0,0,0,.35))}
      .__ripple{position:fixed;z-index:2147483646;pointer-events:none;width:16px;height:16px;margin:-8px 0 0 -8px;border-radius:50%;border:3px solid #F59E0B;animation:__rip .55s ease-out forwards}
      @keyframes __rip{to{transform:scale(4.2);opacity:0}}
      #__keys{position:fixed;left:50%;bottom:44px;transform:translateX(-50%);z-index:2147483647;pointer-events:none;display:flex;gap:6px;opacity:0;transition:opacity .2s}
      #__keys.on{opacity:1}
      #__keys b{font:600 20px/1 Inter,system-ui;color:#fff;background:rgba(11,18,32,.88);border:1px solid rgba(255,255,255,.18);border-radius:10px;padding:10px 14px;box-shadow:0 10px 30px rgba(0,0,0,.3)}`
    document.head.appendChild(css)
    const c = document.createElement('div'); c.id = '__cur'
    c.innerHTML = '<div class="halo"></div><svg width="22" height="26" viewBox="0 0 22 26"><path d="M2 2v19l5-4.5 3.5 7.5 3.4-1.6-3.4-7.3H17z" fill="#0B1220" stroke="#fff" stroke-width="1.8" stroke-linejoin="round"/></svg>'
    document.body.appendChild(c)
    const k = document.createElement('div'); k.id = '__keys'; document.body.appendChild(k)
    addEventListener('mousemove', (e) => { c.style.transform = `translate(${e.clientX}px,${e.clientY}px)` }, true)
    addEventListener('mousedown', (e) => { c.classList.add('down'); const r = document.createElement('div'); r.className = '__ripple'; r.style.left = e.clientX + 'px'; r.style.top = e.clientY + 'px'; document.body.appendChild(r); setTimeout(() => r.remove(), 600) }, true)
    addEventListener('mouseup', () => c.classList.remove('down'), true)
    let t; window.__keys = (keys) => { k.innerHTML = keys.map((x) => `<b>${x}</b>`).join(''); k.classList.add('on'); clearTimeout(t); t = setTimeout(() => k.classList.remove('on'), 1300) }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot()
})
const page = await ctx.newPage()
const errors = []; page.on('pageerror', (e) => errors.push(e.message)); page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })

// --- screencast capture --------------------------------------------------------------------------
const cdp = await ctx.newCDPSession(page)
const frames = []
cdp.on('Page.screencastFrame', ({ data, metadata, sessionId }) => {
  const file = `${FRAMES}/f${String(frames.length).padStart(6, '0')}.jpg`
  fs.writeFileSync(file, Buffer.from(data, 'base64')); frames.push({ file, ts: metadata.timestamp })
  cdp.send('Page.screencastFrameAck', { sessionId }).catch(() => {})
})
const markers = {}
const mark = (name) => { markers[name] = Date.now() / 1000; console.log('•', name) }

// --- helpers ----------------------------------------------------------------------------------------
const SPEED = 0.78 // scales every scripted pause
const wait = (ms) => page.waitForTimeout(ms * SPEED)
let cur = { x: 720, y: 460 }
const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2)
async function moveTo(x, y, ms = 650) {
  ms *= 0.8
  const from = { ...cur }; const t0 = Date.now()
  for (;;) { const k = Math.min(1, (Date.now() - t0) / ms); const e = ease(k); await page.mouse.move(from.x + (x - from.x) * e, from.y + (y - from.y) * e); if (k >= 1) break; await new Promise((r) => setTimeout(r, 12)) }
  cur = { x, y }
}
async function center(loc) { await loc.scrollIntoViewIfNeeded(); const b = await loc.boundingBox(); return { x: b.x + b.width / 2, y: b.y + b.height / 2 } }
async function hover(loc, ms) { const c = await center(loc); await moveTo(c.x, c.y, ms) }
async function click(loc, ms) { await hover(loc, ms); await wait(140); await page.mouse.down(); await wait(90); await page.mouse.up(); await wait(200) }
async function type(loc, text, delay = 32) { await click(loc, 330); await page.keyboard.type(text, { delay }) }
async function keys(label, press) { await page.evaluate((l) => window.__keys?.(l), label); await wait(250); await page.keyboard.press(press) }
async function smoothScroll(dy, ms = 900) { const n = Math.round(ms / 16); for (let i = 0; i < n; i++) { await page.mouse.wheel(0, dy / n); await wait(14) } }

// --- walkthrough ------------------------------------------------------------------------------------
await page.goto(BASE + '/login'); await page.evaluate(() => document.fonts.ready)
await cdp.send('Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: 1920, maxHeight: 1080, everyNthFrame: 1 })
mark('start')
await page.mouse.move(cur.x, cur.y); await wait(1200)

// 1 · Login
await click(page.getByRole('button', { name: /Admin/ }), 900); await wait(500)
await click(page.getByRole('button', { name: 'Sign in' }), 650)
await page.waitForURL(BASE + '/'); mark('dashboard'); await wait(2200)

// 2 · Dashboard
await moveTo(900, 640, 900); await wait(900); await moveTo(1180, 600, 700); await wait(700)
await smoothScroll(620, 1100); await wait(1400); await smoothScroll(-620, 900); await wait(600)

// 3 · Command palette → New lead
mark('palette')
await keys(['⌘', 'K'], 'Meta+k'); await wait(900)
await page.keyboard.type('kessler', { delay: 80 }); await wait(1300)
await page.keyboard.press('Meta+a'); await page.keyboard.type('new lead', { delay: 65 }); await wait(900)
await keys(['↵'], 'Enter'); await wait(900)

// 4 · Create a lead
mark('new-lead')
const m = page.getByRole('dialog')
await type(m.locator('#nl-name'), 'Clara Neumann')
await type(m.locator('#nl-company'), 'Nordwind Architekten GmbH', 20)
await type(m.locator('#nl-email'), 'clara.neumann@nordwind.example', 14)
await type(m.locator('#nl-phone'), '+49 30 4445556', 18)
await hover(m.locator('#nl-source'), 330); await m.locator('#nl-source').selectOption('referral'); await wait(250)
await hover(m.locator('#nl-type'), 300); await m.locator('#nl-type').selectOption('commercial_fit_out'); await wait(250)
await hover(m.locator('#nl-timeline'), 300); await m.locator('#nl-timeline').selectOption('asap'); await wait(250)
await type(m.locator('#nl-bmin'), '350000', 26); await type(m.locator('#nl-bmax'), '480000', 26); await type(m.locator('#nl-area'), '650', 40)
await type(m.locator('#nl-desc'), 'Ausbau unserer neuen Bürofläche (650 m²) in Mitte, Mietvertrag unterschrieben, Einzug im März.', 7)
await type(m.locator('#nl-street'), 'Friedrichstraße 100', 14); await type(m.locator('#nl-pc'), '10117', 40)
await hover(m.locator('input[type=checkbox]'), 450); await wait(600) // consent is pre-ticked
mark('submit')
await click(m.getByRole('button', { name: 'Create & score lead' }), 600)
const toast = page.locator('text=/Lead scored [0-9]+\\/100/')
await toast.waitFor({ timeout: 15000 }); const toastText = await toast.innerText(); console.log('toast:', toastText)
mark('scored'); await wait(2200)

// 5 · Lead detail with AI breakdown
await click(page.getByRole('button', { name: /Open lead/ }), 700)
await page.waitForURL(/\/leads\/[0-9]+/); await wait(2000)
await hover(page.locator('text=AI score').first(), 700); await wait(700)
await smoothScroll(430, 1000); await wait(900)
await hover(page.locator('text=SCORE FACTORS').or(page.locator('text=Score factors')).first(), 600); await moveTo(cur.x + 200, cur.y + 110, 700); await wait(1800)
mark('lead-detail-done')

// 6 · Pipeline drag
await click(page.locator('aside').getByRole('link', { name: 'Pipeline' }), 800); await page.waitForURL(BASE + '/pipeline'); await wait(1800)
const cols = page.locator('main section:has(header)')
const card = cols.nth(0).locator('article', { hasText: 'Clara Neumann' }).first()
const target = cols.nth(1).locator('header')
const a = await center(card); const t = await target.boundingBox()
await moveTo(a.x, a.y - 10, 800); await wait(400)
await page.mouse.down(); await wait(260)
await moveTo(a.x + 20, a.y + 10, 200)
await moveTo(t.x + t.width / 2, t.y + 90, 1300); await wait(350)
await page.mouse.up(); mark('dropped')
await page.locator('text=/→ Contacted/').waitFor({ timeout: 8000 }).catch(() => console.log('no drag toast'))
await wait(1800)

// 7 · Build and send a quote
await click(page.locator('aside').getByRole('link', { name: 'Quotes' }), 800); await wait(1300)
await click(page.getByRole('link', { name: 'New quote' }).first(), 700); await wait(1000)
await type(page.getByPlaceholder(/Search an open lead/), 'Nordwind', 70); await wait(1100)
await click(page.locator('main ul li button').first(), 600); await wait(1500)
const firstPrice = page.locator('main table tbody tr').first().locator('input').nth(2)
await click(firstPrice, 600); await page.keyboard.press('Meta+a'); await page.keyboard.type('195', { delay: 120 }); await wait(800)
await moveTo(1200, 520, 900); await wait(1300)
await click(page.getByRole('button', { name: 'Save & send' }), 900)
await page.locator('text=/Quote Q-[0-9-]+ sent/').waitFor({ timeout: 10000 }).catch(() => console.log('no send toast'))
mark('quote-sent'); await wait(2200)

// 8 · Projects
await click(page.locator('aside').getByRole('link', { name: 'Projects' }), 800); await wait(1600)
await hover(page.locator('main article').nth(1), 800); await wait(1600)

// 9 · Settings → AI scoring
await click(page.locator('aside').getByRole('link', { name: 'Settings' }), 800); await wait(900)
await click(page.getByRole('tab', { name: /AI scoring/ }).or(page.locator('button:has-text("AI scoring")')).first(), 600); await wait(1500)
const slider = page.locator('main input[aria-label="AI weight"]')
const sb = await slider.boundingBox()
await moveTo(sb.x + sb.width * 0.6, sb.y + sb.height / 2, 800); await page.mouse.down(); await moveTo(sb.x + sb.width * 0.75, sb.y + sb.height / 2, 900); await page.mouse.up(); await wait(1200)
await hover(page.getByRole('button', { name: /Save & re-score/ }), 900); await wait(2600)
mark('end')

await cdp.send('Page.stopScreencast')
fs.writeFileSync(`${FRAMES}/frames.json`, JSON.stringify({ frames, markers }))
console.log('frames:', frames.length, 'duration:', (frames.at(-1).ts - frames[0].ts).toFixed(1) + 's', '| errors:', errors.length ? errors : 'none')
await browser.close()
