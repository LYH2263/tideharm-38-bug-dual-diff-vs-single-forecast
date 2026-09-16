import { createRouter, createWebHistory } from 'vue-router'
import Forecast from './pages/Forecast.vue'
import Stations from './pages/Stations.vue'
import StationDetail from './pages/StationDetail.vue'
import Residuals from './pages/Residuals.vue'
import Settings from './pages/Settings.vue'
import Compare from './pages/Compare.vue'

export default createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', component: Forecast },
    { path: '/compare', component: Compare },
    { path: '/stations', component: Stations },
    { path: '/stations/:slug', component: StationDetail },
    { path: '/stations/:slug/residuals', component: Residuals },
    { path: '/settings', component: Settings },
  ],
})
