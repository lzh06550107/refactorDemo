import { describe, expect, it } from 'vitest'
import { initNavigation } from '../components/navigation.js'

describe('initNavigation', () => {
  it('toggles the navigation panel while keeping aria-expanded in sync', () => {
    document.body.innerHTML = `
      <button type="button" data-nav-toggle aria-controls="site-navigation">Menu</button>
      <nav id="site-navigation" data-nav-panel>Navigation</nav>
    `

    initNavigation(document)

    const toggle = document.querySelector('[data-nav-toggle]')
    const panel = document.querySelector('[data-nav-panel]')

    expect(toggle?.getAttribute('aria-expanded')).toBe('false')
    expect(panel?.hasAttribute('hidden')).toBe(true)

    toggle?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(toggle?.getAttribute('aria-expanded')).toBe('true')
    expect(panel?.hasAttribute('hidden')).toBe(false)

    toggle?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    expect(toggle?.getAttribute('aria-expanded')).toBe('false')
    expect(panel?.hasAttribute('hidden')).toBe(true)
  })
})
