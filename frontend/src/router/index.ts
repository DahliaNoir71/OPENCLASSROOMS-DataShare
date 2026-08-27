import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('../views/RegisterView.vue'),
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
    },
    {
      path: '/mon-espace',
      name: 'my-files',
      component: () => import('../views/MyFilesView.vue'),
    },
    {
      path: '/l/:token',
      name: 'download',
      component: () => import('../views/DownloadView.vue'),
    },
  ],
})

export default router
