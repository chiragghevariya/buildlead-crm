/**
 * Tiny fuzzy matcher (subsequence with bonuses for word starts and runs).
 * Returns a score (higher = better) or -1 when not all query chars appear in order.
 */
export function fuzzyScore(query, text) {
  const q = query.toLowerCase().trim()
  const t = (text || '').toLowerCase()
  if (!q) return 0
  if (t.includes(q)) return 1000 - t.indexOf(q) + (t.startsWith(q) ? 500 : 0)

  let score = 0
  let ti = 0
  let run = 0
  for (const ch of q) {
    if (ch === ' ') continue
    const found = t.indexOf(ch, ti)
    if (found === -1) return -1
    const wordStart = found === 0 || /[\s\-_./·]/.test(t[found - 1])
    run = found === ti ? run + 1 : 0
    score += 10 + (wordStart ? 25 : 0) + run * 8 - Math.min(found - ti, 10)
    ti = found + 1
  }
  return score
}

/** Rank items by the best score across the given keys. */
export function fuzzyFilter(query, items, keys, limit = 50) {
  if (!query.trim()) return items.slice(0, limit)
  return items
    .map((item) => ({ item, score: Math.max(...keys.map((k) => fuzzyScore(query, typeof k === 'function' ? k(item) : item[k]))) }))
    .filter((r) => r.score >= 0)
    .sort((a, b) => b.score - a.score)
    .slice(0, limit)
    .map((r) => r.item)
}
