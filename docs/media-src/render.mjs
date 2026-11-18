import path from 'node:path'
import { chromium } from 'playwright'
const [, , html, out, w, h, dsf = '1'] = process.argv
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || undefined, args: ['--allow-file-access-from-files'] })
const page = await (await browser.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: +dsf })).newPage()
await page.goto('file://' + path.resolve(html)); await page.evaluate(() => document.fonts.ready); await page.waitForTimeout(600)
await page.screenshot({ path: out }); console.log('✓', out)
await browser.close()
