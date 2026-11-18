// Portfolio screenshots from the SPA in demo mode (http://localhost:5192, VITE_DEMO_MODE=true).
import { chromium } from 'playwright'
import fs from 'node:fs'
const OUT = process.argv[2]
const BASE = process.env.BASE_URL || 'http://localhost:5192'
fs.mkdirSync(OUT, { recursive: true })
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined })
const errors = []

async function session({ theme = 'light', width = 1440, height = 900, login = true } = {}) {
  const ctx = await browser.newContext({ viewport: { width, height }, deviceScaleFactor: 2, colorScheme: theme })
  await ctx.addInitScript((th) => { localStorage.setItem('buildlead-theme', th) }, theme)
  const page = await ctx.newPage()
  page.on('pageerror', (e) => errors.push(e.message))
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()) })
  await page.goto(BASE + '/login')
  if (login) {
    await page.getByRole('button', { name: /Admin/ }).click()
    await page.getByRole('button', { name: 'Sign in' }).click()
    await page.waitForURL(BASE + '/')
  }
  return page
}
async function settle(page, ms = 1600) {
  await page.addStyleTag({ content: '[data-demo-banner]{display:none!important} *{caret-color:transparent!important}' })
  await page.evaluate(() => document.fonts.ready)
  await page.waitForLoadState('networkidle')
  await page.waitForTimeout(ms)
}
async function shot(page, name, opts = {}) {
  await page.mouse.move(0, 0)
  await page.screenshot({ path: `${OUT}/${name}.png`, ...opts })
  console.log('✓', name)
}

// 1. Login (signed out)
let page = await session({ login: false }); await settle(page, 800); await shot(page, '01-login'); await page.context().close()

// Light session
page = await session()
await settle(page, 2200); await shot(page, '02-dashboard-light')
await page.goto(BASE + '/leads'); await settle(page); await shot(page, '04-leads')
await page.goto(BASE + '/leads/28'); await settle(page, 2000)
await shot(page, '05b-lead-detail-full', { fullPage: true })
// Scroll so the AI score card (ring, reasons, factor bars) fills the viewport
await page.evaluate(() => { const h = [...document.querySelectorAll('h2,h3,p,span')].find((e) => e.textContent.trim() === 'AI score'); window.scrollBy(0, h.getBoundingClientRect().top - 110) })
await page.waitForTimeout(500); await shot(page, '05-lead-detail-ai-score')
await page.goto(BASE + '/pipeline'); await settle(page); await shot(page, '06-pipeline')
await page.goto(BASE + '/quotes/new?lead=54'); await settle(page, 2000); await shot(page, '07-quote-builder')
await page.goto(BASE + '/projects'); await settle(page); await shot(page, '08-projects')
await page.goto(BASE + '/invoices'); await settle(page); await shot(page, '09-invoices')
await page.goto(BASE + '/settings?tab=scoring'); await settle(page); await shot(page, '10-ai-scoring-settings')
await page.goto(BASE + '/'); await settle(page, 2200)
await page.keyboard.press('Meta+k'); await page.waitForTimeout(1200)
await shot(page, '11-command-palette')
await page.context().close()

// Dark dashboard
page = await session({ theme: 'dark' }); await settle(page, 2200); await shot(page, '03-dashboard-dark'); await page.context().close()

// Mobile
page = await session({ width: 390, height: 844 }); await settle(page, 2200); await shot(page, '12-mobile-dashboard'); await page.context().close()

console.log(errors.length ? 'ERRORS: ' + errors.join('\n') : 'no console errors')
await browser.close()
