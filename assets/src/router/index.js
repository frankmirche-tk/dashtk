import { createRouter, createWebHistory } from 'vue-router'
import ChatView from '@/views/ChatView.vue'

const routes = [
    { path: '/', name: 'chat', component: ChatView },
]

const router = createRouter({
    history: createWebHistory('/'),
    routes,
})

// SessionId automatisch verwalten (wie in deinem alten Projekt)
router.beforeEach((to, from, next) => {
    let sessionId = sessionStorage.getItem('sessionId')
    if (!sessionId) {
        sessionId = crypto.randomUUID()
        sessionStorage.setItem('sessionId', sessionId)
    }

    if (!to.query.sessionId) {
        next({ ...to, query: { ...to.query, sessionId }, replace: true })
    } else {
        next()
    }
})

export default router
