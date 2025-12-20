import { createRouter, createWebHistory } from 'vue-router'
import ChatView from '@/views/ChatView.vue'
import SupportSolutionCreateView from '@/views/SupportSolutionCreateView.vue'
import SupportSolutionListView from '@/views/SupportSolutionListView.vue'
import SupportSolutionEditView from '@/views/SupportSolutionEditView.vue'


const routes = [
    { path: '/', name: 'chat', component: ChatView },
    { path: '/kb', name: 'kb_list', component: SupportSolutionListView, meta: { inMenu: true } },
    { path: '/kb/new', name: 'kb_new', component: SupportSolutionCreateView, meta: { inMenu: true } },
    { path: '/kb/:id', name: 'kb_edit', component: SupportSolutionEditView, props: true },
]

const router = createRouter({
    history: createWebHistory('/'),
    routes,
})

export default router
