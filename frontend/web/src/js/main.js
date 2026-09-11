import '../css/main.css'
import { initNavigation } from './components/navigation.js'

const boot = () => initNavigation(document)

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true })
} else {
  boot()
}
