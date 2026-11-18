# BuildLead CRM — Frontend

Vue 3 single-page app (Composition API, `<script setup>`) built with Vite, Tailwind CSS 3, Pinia, Vue Router, Axios, @vueuse/core, lucide-vue-next, Inter, ApexCharts and vuedraggable. The design rules are in [`../PROJECT_SPEC.md`](../PROJECT_SPEC.md) §6.

## Run

```bash
cd frontend
npm install
cp .env.example .env

npm run dev        # http://localhost:5190 — /api is proxied to Laravel on :8020
npm run dev:demo   # demo mode: realistic mock data, no backend needed
npm run build      # production build (build:demo for a static demo)
```

With the real API, also run `php artisan serve --port=8020` and `php artisan queue:work --queue=scoring,notifications,default` in `backend/`. The worker is what scores new leads.

Log in with `admin@buildlead.test`, `manager@buildlead.test` or `anna.weber@buildlead.test`; the password is `password` for all three. The login page has one-click demo account buttons.

## Demo mode (`VITE_DEMO_MODE=true`)

- `src/api/http.js` swaps the axios adapter for `src/mocks`. Pages call exactly the same API functions in both modes, so there is no demo-specific UI code.
- `src/mocks/data/demo.json` is a snapshot of real API responses from the seeded backend. To regenerate it after changing the seeder or resources:
  ```bash
  cd backend && php artisan migrate:fresh --seed && php artisan demo:export-mocks
  ```
- When the app loads, all dates are shifted by the time since the export, so the demo always looks current. Responses have small random delays, so skeleton loaders show.
- Changes (new leads, pipeline moves, scoring settings) are kept in memory until reload. New leads are scored with a JavaScript port of the rule scorer plus a simulated AI opinion.

## Structure

```
src/
  api/          http.js (axios, token interceptor, 401 → login, demo adapter) · index.js (endpoints)
  mocks/        demo adapter + routes, scoring.js (rule scorer port), data/demo.json
  stores/       auth, ui (theme, sidebar, palette, drawer), toasts, meta (select options)
  router/       routes with auth + role guards, page titles
  components/
    ui/         BaseButton, BaseCard, BaseBadge, TemperatureBadge, ScoreRing, DataTable, SlideOver, Modal,
                ToastHost, Skeleton, EmptyState, StatCard, Avatar, Dropdown(+Item), Tabs, FilterMenu, FormField, PageHeader, Logo
    layout/     AppShell, AppSidebar (collapsible), AppTopbar (breadcrumbs, search, bell, theme, user menu), MobileNav
    leads/      NewLeadModal, LeadDrawer (quick view), StatusMenu, ActivityTimeline, LocationCard
    quotes/     QuotePreview (live A4 preview mirroring the dompdf template)
    settings/   TeamSettings
    CommandPalette.vue
  pages/        Login, Dashboard, Leads, LeadDetail, Pipeline, Quotes, QuoteBuilder, Projects, ProjectDetail, Invoices, Settings, NotFound
  lib/          format (€ 125.000, 13 Nov 2026), fuzzy, navigation, status tones, charts (tree-shaken ApexCharts)
```

## Screens (Phase 6)

- **Dashboard:**
  - KPI cards: new leads, open hot leads, pipeline value and win rate, each with a trend arrow and sparkline.
  - Charts: stacked area chart of leads per week, donut of lead sources, funnel.
  - Lists: "Hot leads to call now" (next action and Call button), team leaderboard with win-rate bars, recent activity.
- **Leads:**
  - Filtering and sorting: temperature chips, Status/Source/Owner filter menus, search, sortable columns.
  - Row hover actions: call, email, full page.
  - Bulk selection: assign, change status (Lost asks for a reason) and CSV export (`;` separator with BOM, so it opens correctly in German Excel).
  - Clicking a row opens the quick-view drawer.
- **Lead detail (`/leads/:id`):**
  - Header: status menu, owner menu, and Call / Email / New quote buttons.
  - Details: contact, project and budget, plus a map-style location card drawn in SVG (no map tiles, so it works offline).
  - AI score card: score ring, summary, reasons, next action, factor bars and a Re-score button.
  - Activity composer (note, call, meeting, email, site visit, with optional follow-up scheduling), activity timeline, and a Quotes tab with PDF download.

## Screens (Phase 7)

- **Pipeline:**
  - Six columns (New → Won), each with a count and total value.
  - Drag and drop with a toast. While dragging, a floating "Drop here to mark as lost" target appears, and dropping there asks for a reason.
  - Cards show a small score ring, name, company, budget, owner and days in stage, coloured by stage (New: red after 2 days; other stages: amber after 10, red after 21).
  - Filters for temperature and owner.
- **Quotes:**
  - List with status tabs.
  - Builder at `/quotes/new?lead=…` and `/quotes/:id`: lead search, line items pre-filled per project type, live subtotal / 19 % VAT / total, notes and valid-until date.
  - A live A4 preview that matches the PDF.
  - Save draft, Save & send, Mark accepted (creates the project), Declined and PDF download.
- **Projects:**
  - Cards with progress and budget-vs-spent bars.
  - Detail page `/projects/:id`: status, progress and spent can be edited; invoiced and outstanding figures; milestone timeline; invoice table (issue, mark paid, PDF); new-invoice form that enforces the 110 % cap in the UI.
- **Invoices:** status pills (Paid green, Sent blue, Overdue red), summary cards (outstanding, overdue, paid this month), and Issue / Mark paid / PDF actions.
- **Settings:**
  - AI scoring: OpenAI status and model, AI-weight slider, hot/warm threshold sliders, factor-weight sliders with a live total that must be 100, and "Save & re-score open leads".
  - Team: regions, project types, capacity bar and win rate per rep. Admins can edit; `/team` redirects here.
- **⌘K palette:** also searches quotes (Q-…) and projects (P-…). Actions include New quote, Team & capacity, AI scoring settings and the pipeline. Groups are ordered by how well they match.

## Real API, roles and states (Phase 8)

- **API client** (`src/api/http.js`): Sanctum bearer token in `localStorage`. Every failure gets a human message: offline or proxy down, timeout, 403, 404, 429, 5xx. Server exception text never reaches the UI. Identical error toasts are not stacked.
- **401:** the token is cleared and the user is sent to `/login?redirect=…` with a "session expired" toast. They come back to the same page after signing in.
- **Error states:** every screen that loads data has a "Could not load…" card (`ErrorState.vue`) with **Try again**, in addition to the toast. `DataTable` takes `:error` and emits `retry`.
- **Roles** (spec §3): Projects, Invoices and Settings are **admin/manager only**. Sales sees Dashboard, Leads, Pipeline and Quotes. Menus, palette and mobile nav are filtered from `src/lib/navigation.js`. The router guard blocks direct URLs with a toast. Within Settings, AI scoring is admin-only and Team is admin/manager.
- **Responsive:** checked at 1440, 1024, 768 and 390px for each role, in light and dark, with an automated overflow check. Tablets (768–1023px) get the icon sidebar rail. Phones get a bottom nav with a role-aware **More** sheet and snap-scrolling pipeline columns.
- **Accessibility:** skip link and visible focus everywhere (amber outline or ring). Dialogs and drawers trap focus and restore it on close (`useFocusTrap`). Menus support ↑/↓/Home/End/Esc. The palette is an ARIA combobox/listbox. Icon buttons have labels, and the faint text colour has stronger contrast.

## Design system

- **Colors:** `tailwind.config.js` maps semantic names (`canvas`, `surface`, `subtle`, `line`, `ink`, `muted`, `faint`) to CSS variables in `src/styles/main.css`. Light and dark themes switch by redefining those variables. Brand amber is `#F59E0B` (hover `#D97706`); the sidebar navy is `#0B1220`.
- **Shape and type:** cards use a 14px radius and soft shadows (`shadow-card`, `-lift`, `-pop`). Base text is 14px Inter, and money uses `.num` (tabular numbers).
- **Dark mode:** applied as the `dark` class, remembered in `localStorage` (`buildlead-theme`), and set before first paint so there's no flash.

## Keyboard

| Keys | Action |
|---|---|
| `⌘/Ctrl` + `K` | Command palette (fuzzy search over leads, pages, actions) |
| `/` | Search (opens the palette) |
| `N` | New lead |
| `G` then `D` / `L` / `P` | Dashboard / Leads / Pipeline |
| `?` | Keyboard shortcuts dialog (also in the account menu) |
| `↑` / `↓` | Move through menus and palette results |
| `Esc` | Close dialog, panel or menu (focus returns to where you were) |
