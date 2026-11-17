import { h } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import { generateUrl } from '@nextcloud/router'

const RouteShellMarker = {
	name: 'RouteShellMarker',
	render() {
		return h('div')
	},
}

const routes = [
  { path: '/', name: 'home', component: RouteShellMarker },
  { path: '/dashboard', name: 'dashboard', component: RouteShellMarker, meta: { mainView: 'dashboard' } },
  { path: '/courses', name: 'courses', component: RouteShellMarker, meta: { mainView: 'courses' } },
  { path: '/courses/:id/:tab?', name: 'course-tab', component: RouteShellMarker, meta: { mainView: 'courses' } },
  { path: '/pools', name: 'pools', component: RouteShellMarker, meta: { mainView: 'pools' } },
  { path: '/tools', name: 'tools', component: RouteShellMarker, meta: { mainView: 'werkzeuge' } },
  { path: '/skill-map', name: 'skill-map', component: RouteShellMarker, meta: { mainView: 'skillmap' } },
  { path: '/settings', name: 'settings', component: RouteShellMarker, meta: { mainView: 'settings' } },
  { path: '/virtuprof', name: 'virtuprof', component: RouteShellMarker, meta: { mainView: 'virtuprof-fullscreen' } },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

/**
 * The history base: generateUrl() adds the webroot and, without pretty URLs, index.php; a fixed
 * '/apps/learning/' base breaks subdirectory and index.php-routed installs.
 * GitHub #82: with pretty URLs on, Nextcloud still serves the app under /index.php/apps/learning/
 * too (older links, bookmarks, links built by other tools). Opened that way, the address did not
 * start with the base, so every deep link fell through to the catch-all and landed on the start
 * view without a word. The base follows the form the address bar actually has.
 *
 * @param {string} pathname the current location's path
 * @return {string}
 */
export function historyBase(pathname = (typeof window !== 'undefined' ? window.location.pathname : '')) {
  const base = generateUrl('/apps/learning/')
  if (!base.includes('/index.php/')) {
    const withIndex = base.replace(/\/apps\/learning\/$/, '/index.php/apps/learning/')
    if (withIndex !== base && (pathname.startsWith(withIndex) || pathname === withIndex.slice(0, -1))) {
      return withIndex
    }
  }
  return base
}

const router = createRouter({
  history: createWebHistory(historyBase()),
  routes,
  scrollBehavior() {
    return { left: 0, top: 0 }
  },
})

export default router
