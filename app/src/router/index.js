import Vue from 'vue'
import VueRouter from 'vue-router'

Vue.use(VueRouter)

const routes = [
  { path: '/', name: 'home' },
  { path: '/dashboard', name: 'dashboard', meta: { mainView: 'dashboard' } },
  { path: '/courses', name: 'courses', meta: { mainView: 'courses' } },
  { path: '/courses/:id/:tab?', name: 'course-tab', meta: { mainView: 'courses' } },
  { path: '/pools', name: 'pools', meta: { mainView: 'pools' } },
  { path: '/tools', name: 'tools', meta: { mainView: 'werkzeuge' } },
  { path: '/skill-map', name: 'skill-map', meta: { mainView: 'skillmap' } },
  { path: '/settings', name: 'settings', meta: { mainView: 'settings' } },
  { path: '/virtuprof', name: 'virtuprof', meta: { mainView: 'virtuprof-fullscreen' } },
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
