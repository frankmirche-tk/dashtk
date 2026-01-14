import { createApp } from 'vue'
import App from '@/App.vue'
import router from '@/router'
import '@/app.css'

console.log('Frank: vuejs ist korrekt gefunden')
createApp(App).use(router).mount('#app')
