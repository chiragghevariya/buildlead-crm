const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform)

/** Shown in the shortcuts dialog (?) and Settings → Shortcuts. Keep in sync with useShortcuts. */
export const SHORTCUTS = [
  { keys: [isMac ? '⌘' : 'Ctrl', 'K'], label: 'Open command palette' },
  { keys: ['/'], label: 'Search' },
  { keys: ['N'], label: 'New lead' },
  { keys: ['G', 'D'], label: 'Go to Dashboard', then: true },
  { keys: ['G', 'L'], label: 'Go to Leads', then: true },
  { keys: ['G', 'P'], label: 'Go to Pipeline', then: true },
  { keys: ['?'], label: 'Show keyboard shortcuts' },
  { keys: ['↑', '↓'], label: 'Move through menus and results' },
  { keys: ['Esc'], label: 'Close dialog / panel / menu' },
]
