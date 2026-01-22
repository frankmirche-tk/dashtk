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

                <!-- ✅ TRACE BUTTON -->
                <button
                    v-if="lastTraceId"
                    class="btn"
                    @click="openTrace"
                    title="Letzten Request-Flow anzeigen"
                >
                    Flow anzeigen
                </button>
                <button
                    v-if="lastTraceId"
                    class="btn"
                    @click="exportTrace"
                    title="Trace als JSON exportieren"
                >
                    Trace exportieren
                </button>

            </div>
        </header>

        <ChatMessages
            :messages="messages"
            :roleLabel="roleLabel"
            @db-only="useDbStepsOnly"
            @contact-selected="onContactSelected"
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
import { useRouter } from 'vue-router'
import { uuid } from '../utils/uuid'

import AvatarGuide from '@/views/AvatarGuide.vue'
import ChatMessages from '@/components/chat/ChatMessages.vue'
import ChatComposer from '@/components/chat/ChatComposer.vue'

/**
 * Router + Trace
 */
const router = useRouter()
const lastTraceId = ref(null)

function openTrace() {
    if (!lastTraceId.value) return
    router.push({ name: 'trace_view', params: { traceId: lastTraceId.value } })
}

/**
 * UI State
 */
const input = ref('')
const sending = ref(false)
const provider = ref('gemini')
const model = ref(null)

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

const messages = ref([{ role: 'system', content: systemText() }])

/**
 * Role labels
 */
const ROLE_LABELS = { assistant: 'KI Antwort', system: 'System', user: 'Du', info: 'Info' }
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

/**
 * Provider change
 */
function onProviderChange() {
    model.value = null
    if (messages.value.length > 0 && messages.value[0].role === 'system') {
        messages.value[0].content = systemText()
    }
}

/**
 * Steps mapping
 */
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
 * CONTACT selection
 */
function onContactSelected(payload) {
    const type = payload?.type ?? null
    const match = payload?.match ?? null
    if (!match) return
    pushContactCardMessage(type, match)
}

function pushContactCardMessage(type, match) {
    const data = match?.data ?? {}
    messages.value.push({
        role: 'info',
        content: '',
        contactCard: { type, data },
    })
}

/**
 * SEND
 */
async function send(textFromComposer) {
    const text = String(textFromComposer ?? input.value).trim()
    if (!text) return

    pendingGuide.value = null
    avatarEnabled.value = false

    messages.value.push({ role: 'user', content: text })
    input.value = ''
    sending.value = true

    try {
        // 1) Contact resolve
        const { data: c } = await axios.post('/api/contact/resolve', { query: text })

        if (c?.type && c.type !== 'none' && Array.isArray(c.matches) && c.matches.length > 0) {
            lastTraceId.value = null
            for (const hit of c.matches) {
                messages.value.push({
                    role: 'info',
                    content: '',
                    contactCard: {
                        type: c.type,
                        data: hit.data,
                    }
                })
            }
            return
        }

        // 2) Normaler KI-Chat
        const uiSpan = 'ui.ChatView.send.normal'
        const uiAt = Date.now()

        // 2) HTTP-Span "axios"
        const httpSpan = 'http.api.chat'
        const httpAt = Date.now()

        const { data } = await axios.post(
            '/api/chat',
            {
                sessionId: sessionId.value,
                message: text,
                provider: provider.value,
                model: model.value,
            },
            {
                headers: {
                    'X-UI-Span': uiSpan,
                    'X-UI-At': String(uiAt),

                    // NEU: eigener HTTP-Span (für Tree)
                    'X-UI-Http-Span': httpSpan,
                    'X-UI-Http-At': String(httpAt),
                },
            }
        )

        lastTraceId.value = data.trace_id ?? null

        const p = data.provider ?? provider.value
        const m = data.model ?? model.value
        provider.value = p
        model.value = m ?? null

        if (messages.value.length > 0 && messages.value[0].role === 'system') {
            messages.value[0].content = systemText()
        }

        messages.value.push({
            role: 'assistant',
            content: `(${providerModelLabel(p, m)}) ${data.answer ?? '[leer]'}`,
            matches: data.matches ?? [],
            steps: mapSteps(data.steps),
            provider: p,
            model: m ?? null,
        })
    } catch (e) {
        messages.value.push({
            role: 'assistant',
            content: 'Fehler beim Senden. Bitte dev.log prüfen.',
        })
    } finally {
        sending.value = false
    }
}

/**
 * DB-only steps
 */
async function useDbStepsOnly(solutionId) {
    sending.value = true
    try {
        const uiSpan = 'ui.ChatView.send.dbOnly'
        const uiAt = Date.now()

        const { data } = await axios.post(
            '/api/chat',
            {
                sessionId: sessionId.value,
                message: '',
                dbOnlySolutionId: solutionId,
                provider: provider.value,
                model: model.value,
            },
            {
                headers: {
                    'X-UI-Span': uiSpan,
                    'X-UI-At': String(uiAt),
                },
            }
        )

        lastTraceId.value = data.trace_id ?? null

        const p = data.provider ?? provider.value
        const m = data.model ?? model.value
        provider.value = p
        model.value = m ?? null

        if (messages.value.length > 0 && messages.value[0].role === 'system') {
            messages.value[0].content = systemText()
        }

        messages.value.push({
            role: 'assistant',
            content: `(${providerModelLabel(p, m)}) ${data.answer ?? '[leer]'}`,
            matches: data.matches ?? [],
            steps: mapSteps(data.steps),
            provider: p,
            model: m ?? null,
        })
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
    lastTraceId.value = null
}

function onNextStep() {
    messages.value.push({
        role: 'assistant',
        content: 'Alles klar. Schritt 2 folgt (Demo).',
        matches: [],
    })
}

/**
 * Trace Export
 * Wichtig: Backend erwartet i.d.R. "traceId" (nicht trace_id).
 * Wenn dein Controller aktuell trace_id liest, kannst du es unten wieder zurückdrehen.
 */
async function exportTrace() {
    if (!lastTraceId.value) return

    await axios.post('/api/trace/export', {
        traceId: lastTraceId.value,
        view: 'ChatView.vue',
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
