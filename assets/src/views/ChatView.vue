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
                <div v-if="avatarOfferEnabled && pendingGuide && !avatarEnabled" class="avatar-offer">
                    <div class="avatar-offer-title">Möchtest du dazu eine geführte Avatar-Demo?</div>
                    <div class="avatar-offer-actions">
                        <button class="btn ghost" @click="declineAvatar">Nein, Steps reichen</button>
                        <button class="btn" @click="acceptAvatar">Ja, Avatar starten</button>
                    </div>
                </div>

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
            :show-attention="inputAttentionEnabled"
            :attention-key="attentionKey"
            @submit="send"
            @attention-consumed="inputAttentionEnabled = false"
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

const avatarOfferEnabled = true
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

// Input Attention (Hint + Pulse)
const inputAttentionEnabled = ref(true)
const attentionKey = ref(0)

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

    // Beim normalen Chatten: Avatar/Offer reset
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

        // ✅ NUR jetzt: Opt-In vorbereiten (einmal!)
        pendingGuide.value = {
            tts: data.tts ?? 'Soll ich dir das als Avatar-Demo zeigen?',
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

    // ✅ Avatar/Offer komplett reset
    avatarEnabled.value = false
    pendingGuide.value = null

    // ✅ Hint + Pulse wieder anwerfen
    inputAttentionEnabled.value = true
    attentionKey.value += 1
}

function onNextStep() {
    messages.value.push({
        role: 'assistant',
        content: 'Alles klar. Schritt 2 folgt (Demo).',
        matches: []
    })
}
</script>


<style scoped>
.wrap { max-width: 900px; margin: 24px auto; padding: 0 16px; font-family: system-ui, sans-serif; }
.header { display:flex; justify-content:space-between; align-items:center; gap:12px; }
.actions { display:flex; gap:8px; }
.btn{
    appearance: none;
    border: 1px solid #111;
    background: #111;
    color: #fff;
    border-radius: 999px;
    padding: 10px 16px;
    font-weight: 650;
    cursor: pointer;
    transition: transform .05s ease, background .15s ease, border-color .15s ease, opacity .15s ease;
    line-height: 1;
}
.btn:hover{ background:#000; }
.btn:active{ transform: translateY(1px); }
.btn:disabled{ opacity:.55; cursor:not-allowed; }

.btn.ghost{
    background: transparent;
    color: #111;
    border-color: #bbb;
}
.btn.ghost:hover{
    border-color:#111;
    background: rgba(0,0,0,.03);
}

/* Avatar Offer Box etwas "cardiger" */
.avatar-offer{
    margin: 14px 0;
    border: 1px solid #ddd;
    border-radius: 14px;
    padding: 12px 14px;
    background: #fff;
    display:flex;
    justify-content: space-between;
    align-items:center;
    gap:12px;
    box-shadow: 0 1px 0 rgba(0,0,0,.03);
}
.avatar-offer-title{
    font-weight: 750;
}
.avatar-offer-actions{
    display:flex;
    gap:10px;
}

</style>
