import Vue from 'vue'
import VueRouter from 'vue-router'

Vue.use(VueRouter)

const RouteShellMarker = {
	name: 'RouteShellMarker',
	render(h) {
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
  { path: '*', redirect: '/' },
]

const router = new VueRouter({
  mode: 'history',
  base: '/apps/learning/',
  routes,
  scrollBehavior() {
    return { x: 0, y: 0 }
  },
})

export default router
