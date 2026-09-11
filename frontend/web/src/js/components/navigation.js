export function initNavigation(root = document) {
  const toggle = root.querySelector('[data-nav-toggle]')
  const panel = root.querySelector('[data-nav-panel]')

  if (!(toggle instanceof HTMLElement) || !(panel instanceof HTMLElement)) {
    return
  }

  const setExpanded = (expanded) => {
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false')
    panel.toggleAttribute('hidden', !expanded)
  }

  setExpanded(false)

  toggle.addEventListener('click', () => {
    const expanded = toggle.getAttribute('aria-expanded') === 'true'
    setExpanded(!expanded)
  })
}
