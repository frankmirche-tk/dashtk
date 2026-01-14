<template>
    <div class="wrap">
        <header class="header">
            <h1>Dashboard Support Chat</h1>

            <div class="actions">
                <router-link class="btn" to="/kb/new">Neues Thema</router-link>
                <router-link class="btn" to="/kb">Wissen bearbeiten</router-link>
                <button class="btn" @click="newChat">Neuer Chat</button>
            </div>
        </header>

        <ChatMessages
            :messages="messages"
            :roleLabel="roleLabel"
            @db-only="useDbStepsOnly"
        >
            <!-- Avatar wieder aktivieren? Dann hier rein als Slot -->
            <!--
            <template #avatar>
              <AvatarGuide v-if="avatarEnabled && pendingGuide" ... />
              ...
            </template>
            -->
        </ChatMessages>

        <ChatComposer
            v-model="input"
            :disabled="sending"
            @submit="send"
        />
    </div>
</template>

<script setup>
import axios from 'axios'
import { ref } from 'vue'
import { uuid } from '../utils/uuid'

import ChatMessages from '@/components/chat/ChatMessages.vue'
import ChatComposer from '@/components/chat/ChatComposer.vue'

const input = ref('')
const sending = ref(false)

const sessionId = ref(sessionStorage.getItem('sessionId') || uuid())
sessionStorage.setItem('sessionId', sessionId.value)

const messages = ref([{ role: 'system', content: 'Willkommen. Beschreibe dein Problem.' }])

const ROLE_LABELS = { assistant: 'KI Antwort', system: 'System', user: 'Du' }
function roleLabel(role) {
    return ROLE_LABELS[role] ?? role
}

function mapSteps(raw) {
    if (!Array.isArray(raw)) return []
    return raw.map(s => ({
        id: s.id ?? null,
        no: s.no ?? s.stepNo ?? 0,
        text: s.text ?? s.instruction ?? '',
        mediaUrl: s.mediaUrl
            ? s.mediaUrl
            : s.mediaPath
                ? '/' + String(s.mediaPath).replace(/^\/+/, '')
                : null,
        mediaMimeType: s.mediaMimeType ?? null,
    }))
}

async function send(textFromComposer) {
    const text = String(textFromComposer ?? input.value).trim()
    if (!text) return

    messages.value.push({ role: 'user', content: text })
    input.value = ''
    sending.value = true

    try {
        const { data } = await axios.post('/api/chat', { sessionId: sessionId.value, message: text })
        messages.value.push({
            role: 'assistant',
            content: data.answer ?? '[leer]',
            matches: data.matches ?? [],
            steps: mapSteps(data.steps),
        })
    } catch (e) {
        console.error(e)
        messages.value.push({ role: 'assistant', content: 'Fehler beim Senden. Siehe Console/Logs.' })
    } finally {
        sending.value = false
    }
}

async function useDbStepsOnly(solutionId) {
    sending.value = true
    try {
        const { data } = await axios.post('/api/chat', {
            sessionId: sessionId.value,
            message: '',
            dbOnlySolutionId: solutionId,
        })
        messages.value.push({
            role: 'assistant',
            content: data.answer ?? '[leer]',
            matches: data.matches ?? [],
            steps: mapSteps(data.steps),
        })
    } catch (e) {
        console.error(e)
        messages.value.push({ role: 'assistant', content: 'Fehler beim Laden der Steps (DB-only).' })
    } finally {
        sending.value = false
    }
}

function newChat() {
    sessionId.value = uuid()
    sessionStorage.setItem('sessionId', sessionId.value)
    messages.value = [{ role: 'system', content: 'Neuer Chat gestartet. Beschreibe dein Problem.' }]
    input.value = ''
}
</script>

<style scoped>
.wrap { max-width: 900px; margin: 24px auto; padding: 0 16px; font-family: system-ui, sans-serif; }
.header { display:flex; justify-content:space-between; align-items:center; gap:12px; }
.actions { display:flex; gap:8px; }
</style>
