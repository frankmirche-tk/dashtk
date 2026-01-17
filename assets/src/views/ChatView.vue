<template>
    <div class="wrap">
        <header class="header">
            <h1>Dashboard Support Chat</h1>

            <div class="actions">
                <select v-model="provider" class="select" @change="onProviderChange">
                    <option value="gemini">Gemini (Standard)</option>
                    <option value="openai">OpenAI</option>
                </select>

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

/**
 * UI State
 */
const input = ref('')
const sending = ref(false)
const provider = ref('gemini') // 'gemini' | 'openai'

// falls du später Model-Auswahl ergänzt:
const model = ref(null) // optional string (z.B. 'gpt-4o-mini'), bleibt sonst null

const sessionId = ref(sessionStorage.getItem('sessionId') || uuid())
sessionStorage.setItem('sessionId', sessionId.value)

/**
 * System Message helper
 */
function providerLabel(p) {
    if (p === 'openai') return 'OpenAI'
    if (p === 'gemini') return 'Gemini'
    return String(p || '')
}
function providerModelLabel(p, m) {
    return m ? `${providerLabel(p)} (${m})` : providerLabel(p)
}
function systemText() {
    return `Willkommen. Aktiver KI-Provider: ${providerModelLabel(provider.value, model.value)}`
}

const messages = ref([
    { role: 'system', content: systemText() }
])

/**
 * Role labels (für ChatMessages)
 */
const ROLE_LABELS = { assistant: 'KI Antwort', system: 'System', user: 'Du' }
function roleLabel(role) {
    return ROLE_LABELS[role] ?? role
}

/**
 * Avatar / Attention
 */
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

/**
 * Provider change: System-Message aktualisieren
 */
function onProviderChange() {
    // optional: model zurücksetzen, wenn Provider wechselt
    model.value = null

    // System Message in-place updaten (erste Nachricht)
    if (messages.value.length > 0 && messages.value[0].role === 'system') {
        messages.value[0].content = systemText()
    } else {
        messages.value.unshift({ role: 'system', content: systemText() })
    }
}

/**
 * Send
 */
async function send(textFromComposer) {
    const text = String(textFromComposer ?? input.value).trim()
    if (!text) return

    // Avatar reset
    pendingGuide.value = null
    avatarEnabled.value = false

    messages.value.push({ role: 'user', content: text })
    input.value = ''
    sending.value = true

    try {
        const { data } = await axios.post('/api/chat', {
            sessionId: sessionId.value,
            message: text,
            provider: provider.value,
            model: model.value, // null ok
        })

        // Backend kann provider/model zurückgeben (du setzt es im ChatController)
        const p = data.provider ?? provider.value
        const m = data.model ?? model.value

        // UI State aktualisieren (falls Backend was zurückgibt)
        provider.value = p
        model.value = m ?? null

        // System-Message aktualisieren (zeigt den aktiven Provider)
        if (messages.value.length > 0 && messages.value[0].role === 'system') {
            messages.value[0].content = systemText()
        }

        // Assistant Message (mit Provider-Sichtbarkeit)
        const prefix = `(${providerModelLabel(p, m)}) `
        messages.value.push({
            role: 'assistant',
            content: prefix + (data.answer ?? '[leer]'),
            matches: data.matches ?? [],
            steps: mapSteps(data.steps),
            provider: p,
            model: m ?? null,
        })
    } catch (e) {
        console.error('chat error:', e)
        console.error('status:', e?.response?.status)
        console.error('server data:', e?.response?.data)

        const serverMsg =
            (typeof e?.response?.data === 'string' && e.response.data) ||
            e?.response?.data?.detail ||
            e?.response?.data?.message ||
            e?.response?.data?.error ||
            null

        messages.value.push({
            role: 'assistant',
            content: serverMsg ? `Serverfehler: ${serverMsg}` : 'Fehler beim Senden. Bitte dev.log prüfen.',
        })
    } finally {
        sending.value = false
    }
}

/**
 * DB-only Steps (bleibt provider-unabhängig)
 */
async function useDbStepsOnly(solutionId) {
    sending.value = true
    try {
        const { data } = await axios.post('/api/chat', {
            sessionId: sessionId.value,
            message: '',
            dbOnlySolutionId: solutionId,
            provider: provider.value,
            model: model.value,
        })

        const p = data.provider ?? provider.value
        const m = data.model ?? model.value

        provider.value = p
        model.value = m ?? null

        if (messages.value.length > 0 && messages.value[0].role === 'system') {
            messages.value[0].content = systemText()
        }

        const prefix = `(${providerModelLabel(p, m)}) `
        messages.value.push({
            role: 'assistant',
            content: prefix + (data.answer ?? '[leer]'),
            matches: data.matches ?? [],
            steps: mapSteps(data.steps),
            provider: p,
            model: m ?? null,
        })

        // Optional Avatar offer setup (falls du es nutzt)
        if (data.tts || data.mediaUrl) {
            pendingGuide.value = {
                tts: data.tts ?? 'Soll ich dir das als Avatar-Demo zeigen?',
                mediaUrl: data.mediaUrl ?? '/guides/print/step1.gif',
            }
            avatarEnabled.value = false
        }
    } finally {
        sending.value = false
    }
}

/**
 * New chat
 */
function newChat() {
    sessionId.value = uuid()
    sessionStorage.setItem('sessionId', sessionId.value)

    messages.value = [{ role: 'system', content: systemText() }]
    input.value = ''

    avatarEnabled.value = false
    pendingGuide.value = null

    inputAttentionEnabled.value = true
    attentionKey.value += 1
}

function onNextStep() {
    messages.value.push({
        role: 'assistant',
        content: 'Alles klar. Schritt 2 folgt (Demo).',
        matches: [],
    })
}
</script>

<style scoped>
.wrap { max-width: 900px; margin: 24px auto; padding: 0 16px; font-family: system-ui, sans-serif; }
.header { display:flex; justify-content:space-between; align-items:center; gap:12px; }
.actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

.select{
    border: 1px solid #bbb;
    border-radius: 999px;
    padding: 10px 12px;
    background: #fff;
}

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
.avatar-offer-title{ font-weight: 750; }
.avatar-offer-actions{ display:flex; gap:10px; }
</style>
