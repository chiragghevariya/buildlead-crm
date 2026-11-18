/** v-range: keeps a styled <input type="range"> track filled up to its value (CSS var --fill, see main.css). */
function paint(el) {
  const min = Number(el.min || 0); const max = Number(el.max || 100)
  el.style.setProperty('--fill', `${((Number(el.value) - min) / (max - min || 1)) * 100}%`)
}
export default {
  mounted(el) { paint(el); el.addEventListener('input', () => paint(el)) },
  updated: paint,
}
