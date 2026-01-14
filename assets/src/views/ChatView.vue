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
            <template #avatar>
                <!-- Angebot erscheint nur nach DB-only Auswahl -->
                <div v-if="pendingGuide && !avatarEnabled" class="avatar-offer">
                    <div class="avatar-offer-title">
                        Möchtest du dazu eine geführte Avatar-Demo?
                    </div>
                    <div class="avatar-offer-actions">
                        <button class="btn ghost" @click="declineAvatar">Nein, Steps reichen</button>
                        <button class="btn" @click="acceptAvatar">Ja, Avatar starten</button>
                    </div>
                </div>

                <!-- Avatar wird NUR gezeigt, wenn User Opt-In gegeben hat -->
                <AvatarGuide
                    v-if="avatarEnabled && pendingGuide"
                    :tts-text="avatarTtsText"
                    :media-url="avatarMediaUrl"
                    @next="onNextStep"
                />
            </template>
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
import { ref, computed } from 'vue'
import { uuid } from '../utils/uuid'

import AvatarGuide from '@/views/AvatarGuide.vue'
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

const avatarEnabled = ref(false)
const pendingGuide = ref(null)

const avatarTtsText = computed(() => pendingGuide.value?.tts ?? '')
const avatarMediaUrl = computed(() => pendingGuide.value?.mediaUrl ?? '/guides/print/step1.gif')

function acceptAvatar() {
    avatarEnabled.value = true
}
function declineAvatar() {
    avatarEnabled.value = false
    pendingGuide.value = null
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
    pendingGuide.value = null
    avatarEnabled.value = false

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

        // ✅ NUR jetzt: Opt-In vorbereiten
        pendingGuide.value = {
            tts: data.tts ?? 'Hallo, ich zeige dir jetzt wie du die Aufträge löschst.',
            mediaUrl: data.mediaUrl ?? '/guides/print/step1.gif',
        }
        avatarEnabled.value = false
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
