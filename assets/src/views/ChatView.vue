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

                <button
                    v-if="isDev && lastTraceId"
                    class="btn"
                    @click="openTrace"
                    title="Letzten Request-Flow anzeigen"
                >
                    Flow anzeigen
                </button>

                <button
                    v-if="isDev && lastTraceId"
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
            @choose="onChoose"
            @newsletter-confirm="confirmNewsletterInsert"
            @newsletter-edit="startNewsletterPatch"
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
            v-model:driveUrl="driveUrl"
            :fileName="newsletterFileName"
            :disabled="sending"
            :show-attention="inputAttentionEnabled"
            :attention-key="attentionKey"
            @file-selected="onNewsletterFile"
            @file-cleared="clearNewsletterFile"
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
const isDev = import.meta.env.DEV

function openTrace() {
    if (!isDev || !lastTraceId.value) return
    router.push({ name: 'trace_view', params: { traceId: lastTraceId.value } })
}

/**
 * -------- DEV LOGGING HELPERS --------
 */
const debugEnabled = isDev
const reqSeq = ref(0)

function dlog(...args) {
    if (!debugEnabled) return
    // eslint-disable-next-line no-console
    console.log('[ChatView]', ...args)
}

function dgroup(title, fn) {
    if (!debugEnabled) return fn()
    // eslint-disable-next-line no-console
    console.groupCollapsed(title)
    try {
        return fn()
    } finally {
        // eslint-disable-next-line no-console
        console.groupEnd()
    }
}

/**
 * Optional: Axios Interceptor (nur DEV)
 */
if (debugEnabled && !axios.__chatViewInterceptorInstalled) {
    axios.__chatViewInterceptorInstalled = true

    axios.interceptors.request.use((config) => {
        const id = ++reqSeq.value
        config.headers = config.headers || {}
        config.headers['X-UI-Req-Id'] = String(id)

        dgroup(`HTTP -> ${config.method?.toUpperCase()} ${config.url} (#${id})`, () => {
            dlog('headers:', config.headers)
            // Body bei FormData nicht komplett loggen (zu groß), nur Keys
            if (config.data instanceof FormData) {
                const keys = []
                for (const k of config.data.keys()) keys.push(k)
                dlog('body(FormData) keys:', keys)
            } else {
                dlog('body:', config.data)
            }
        })

        return config
    })

    axios.interceptors.response.use(
        (res) => {
            const id = res?.config?.headers?.['X-UI-Req-Id']
            dgroup(`HTTP <- ${res.status} ${res.config?.url} (#${id ?? '?'})`, () => {
                dlog('data:', res.data)
            })
            return res
        },
        (err) => {
            const id = err?.config?.headers?.['X-UI-Req-Id']
            dgroup(`HTTP !! ERROR ${err?.config?.url ?? ''} (#${id ?? '?'})`, () => {
                dlog('message:', err?.message)
                dlog('response:', err?.response?.data)
            })
            throw err
        }
    )
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
 * File Upload / Newsletter state
 */
const newsletterFile = ref(null)
const newsletterFileName = ref('')
const driveUrl = ref('')

const pendingNewsletterDraftId = ref(null)
const waitingForNewsletterPatch = ref(false)

function onNewsletterFile(f) {
    newsletterFile.value = f
    newsletterFileName.value = f?.name || ''
    dlog('newsletter file selected:', newsletterFileName.value)
}
function clearNewsletterFile() {
    newsletterFile.value = null
    newsletterFileName.value = ''
    dlog('newsletter file cleared')
}

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
 * Newsletter: PATCH draft
 */
async function patchNewsletterDraft(text) {
    dlog('newsletter patch ->', { draftId: pendingNewsletterDraftId.value, text })

    const { data } = await axios.post('/api/chat/newsletter/patch', {
        sessionId: sessionId.value,
        draftId: pendingNewsletterDraftId.value,
        message: text,
        provider: provider.value,
        model: model.value,
    })

    lastTraceId.value = data.trace_id ?? null

    messages.value.push({
        role: 'assistant',
        content: data.answer ?? 'Aktualisiert. Bitte erneut prüfen.',
        newsletterConfirmCard: data.confirmCard ?? null,
    })

    waitingForNewsletterPatch.value = false
    if (data.draftId) pendingNewsletterDraftId.value = data.draftId
}

/**
 * Newsletter: ANALYZE (multipart)
 */
async function analyzeNewsletter(text) {
    dlog('newsletter analyze ->', {
        text,
        driveUrl: driveUrl.value,
        fileName: newsletterFile.value?.name ?? null,
    })

    const form = new FormData()
    form.append('sessionId', sessionId.value)
    form.append('message', text)
    form.append('provider', provider.value)
    if (model.value) form.append('model', model.value)
    if (driveUrl.value) form.append('drive_url', driveUrl.value)
    if (newsletterFile.value) form.append('file', newsletterFile.value)

    const { data } = await axios.post('/api/chat/newsletter/analyze', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
    })

    lastTraceId.value = data.trace_id ?? null

    if (data.type === 'need_drive') {
        messages.value.push({ role: 'assistant', content: data.answer })
        return
    }

    if (data.type === 'needs_confirmation') {
        pendingNewsletterDraftId.value = data.draftId ?? null
        messages.value.push({
            role: 'assistant',
            content: data.answer ?? 'Bitte prüfen und bestätigen.',
            newsletterConfirmCard: data.confirmCard ?? null,
        })
        return
    }

    messages.value.push({ role: 'assistant', content: data.answer ?? '[leer]' })
}

/**
 * Newsletter: CONFIRM insert
 */
async function confirmNewsletterInsert(draftId) {
    dlog('newsletter confirm ->', { draftId })

    sending.value = true
    try {
        const { data } = await axios.post('/api/chat/newsletter/confirm', {
            sessionId: sessionId.value,
            draftId,
            provider: provider.value,
            model: model.value,
        })

        lastTraceId.value = data.trace_id ?? null

        messages.value.push({
            role: 'assistant',
            content: data.answer ?? 'OK, eingefügt.',
        })

        pendingNewsletterDraftId.value = null
        waitingForNewsletterPatch.value = false
        clearNewsletterFile()
        driveUrl.value = ''
    } finally {
        sending.value = false
    }
}

/**
 * SEND
 */
async function send(textFromComposer) {
    const text = String(textFromComposer ?? input.value).trim()
    if (!text) return

    pendingGuide.value = null
    avatarEnabled.value = false

    // push user message once
    messages.value.push({ role: 'user', content: text })
    input.value = ''

    sending.value = true
    try {
        const lower = text.toLowerCase()

        // ✅ 0) "Einfügen" als Shortcut: wenn Draft existiert -> CONFIRM
        // (sonst würdest du wegen newsletterFile.value wieder in analyze landen)
        if ((lower === 'einfügen' || lower === 'einfuegen' || lower === 'ok einfügen' || lower === 'ok einfuegen')
            && pendingNewsletterDraftId.value
            && !waitingForNewsletterPatch.value
        ) {
            await confirmNewsletterInsert(pendingNewsletterDraftId.value)
            return
        }

        // 1) Patch-Modus hat Priorität
        if (pendingNewsletterDraftId.value && waitingForNewsletterPatch.value) {
            await patchNewsletterDraft(text)
            return
        }

        // 2) Newsletter Analyze (Keyword oder File)
        const looksLikeNewsletter = /newsletter\s+einarbeiten/i.test(text)

        // ✅ Nur dann analyze, wenn wirklich Newsletter-Intent ODER noch kein Draft existiert
        // Wenn Draft existiert und User etwas schreibt, soll er eher patchen oder bestätigen – nicht neu analysieren.
        if ((looksLikeNewsletter || newsletterFile.value) && !pendingNewsletterDraftId.value) {
            await analyzeNewsletter(text)
            return
        }

        // 3) Contact resolve
        const uiSpanContact = 'ui.ChatView.send.contactResolve'
        const uiAtContact = Date.now()

        const { data: c } = await axios.post(
            '/api/contact/resolve',
            { query: text },
            {
                headers: {
                    'X-UI-Span': uiSpanContact,
                    'X-UI-At': String(uiAtContact),
                },
            }
        )

        lastTraceId.value = c?.trace_id ?? null

        if (c?.type && c.type !== 'none' && Array.isArray(c.matches) && c.matches.length > 0) {
            for (const hit of c.matches) {
                messages.value.push({
                    role: 'info',
                    content: '',
                    contactCard: { type: c.type, data: hit.data },
                })
            }
            return
        }

        // 4) Normaler KI-Chat
        const headers = {}

        if (isDev) {
            headers['X-UI-Span'] = 'ui.ChatView.send.normal'
            headers['X-UI-At'] = String(Date.now())
            headers['X-UI-Http-Span'] = 'http.api.chat'
            headers['X-UI-Http-At'] = String(Date.now())
        }

        const { data } = await axios.post(
            '/api/chat',
            {
                sessionId: sessionId.value,
                message: text,
                provider: provider.value,
                model: model.value,
            },
            { headers }
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
            choices: data.choices ?? [],
            formCard: data.formCard ?? null,
            steps: mapSteps(data.steps),
            provider: p,
            model: m ?? null,
        })
    } catch (e) {
        dlog('send error:', e)
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
            choices: data.choices ?? [],
            formCard: data.formCard ?? null,
            steps: mapSteps(data.steps),
            provider: p,
            model: m ?? null,
        })
    } finally {
        sending.value = false
    }
}

/**
 * CHOICE click
 */
function onChoose(idx) {
    const n = Number(idx)
    if (!Number.isFinite(n) || n <= 0) return
    send(String(n))
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

    pendingNewsletterDraftId.value = null
    waitingForNewsletterPatch.value = false
    clearNewsletterFile()
    driveUrl.value = ''
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
 */
async function exportTrace() {
    if (!isDev || !lastTraceId.value) return
    await axios.post('/api/trace/export', { traceId: lastTraceId.value, view: 'ChatView.vue' })
}

/**
 * Newsletter: UI Handlers
 */
function startNewsletterPatch(draftId) {
    pendingNewsletterDraftId.value = draftId
    waitingForNewsletterPatch.value = true
    messages.value.push({
        role: 'assistant',
        content:
            'Alles klar – sende mir deine Änderungen als Text (z.B. „published_at auf 2024-12-30“ oder „Drive-Link ist …“). Danach zeige ich dir die Werte erneut zur Bestätigung.',
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
