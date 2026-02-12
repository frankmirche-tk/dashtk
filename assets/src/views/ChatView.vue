<template>
    <div class="wrap">
        <header class="header">
            <h1>Dashboard Support Chat</h1>

            <div class="actions">
                <select v-model="provider" class="select" @change="onProviderChange">
                    <option value="openai">OpenAI(Standard)</option>
                    <option value="gemini">Gemini </option>
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
            v-model:category="importCategory"
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
const provider = ref('openai')
const model = ref(null)

const sessionId = ref(sessionStorage.getItem('sessionId') || uuid())
sessionStorage.setItem('sessionId', sessionId.value)

/**
 * File Upload / Newsletter state
 */
const newsletterFile = ref(null)
const newsletterFileName = ref('')
const driveUrl = ref('')
const importCategory = ref('GENERAL') // GENERAL | NEWSLETTER

const pendingNewsletterDraftId = ref(null)
const waitingForNewsletterPatch = ref(false)

function createBaseUrl() {
    return importCategory.value === 'NEWSLETTER'
        ? '/api/chat/newsletter'
        : '/api/chat/form'
}

function extractDriveFileId(url) {
    const u = String(url || '').trim()
    if (!u) return ''

    let m = u.match(/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/)
    if (m?.[1]) return m[1]

    m = u.match(/drive\.google\.com\/open\?id=([a-zA-Z0-9_-]+)/)
    if (m?.[1]) return m[1]

    m = u.match(/drive\.google\.com\/uc\?id=([a-zA-Z0-9_-]+)/)
    if (m?.[1]) return m[1]

    return ''
}



function onNewsletterFile(f) {
    newsletterFile.value = f
    newsletterFileName.value = f?.name || ''
    dlog('newsletter file selected:', newsletterFileName.value)

    // ✅ Auto-select category based on filename
    const name = (newsletterFileName.value || '').toLowerCase()
    if (name.includes('newsletter')) {
        importCategory.value = 'NEWSLETTER'
    }
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

    try {
        const { data } = await axios.post(`${createBaseUrl()}/patch`, {
            sessionId: sessionId.value,
            draftId: pendingNewsletterDraftId.value,
            message: text,
            provider: provider.value,
            model: model.value,
        })

        lastTraceId.value = data.trace_id ?? null

        if (data.type === 'error') {
            messages.value.push({
                role: 'assistant',
                content: (data.answer ?? 'Fehler') + (data.code ? ` [${data.code}]` : ''),
            })
            waitingForNewsletterPatch.value = false
            return
        }

        messages.value.push({
            role: 'assistant',
            content: data.answer ?? 'Aktualisiert. Bitte erneut prüfen.',
            newsletterConfirmCard: data.confirmCard ?? null,
        })

        waitingForNewsletterPatch.value = false
        if (data.draftId) pendingNewsletterDraftId.value = data.draftId
    } catch (err) {
        dlog('newsletter patch error:', err)
        pushApiErrorMessage(err)
        waitingForNewsletterPatch.value = false
    }
}


/**
 * Newsletter: ANALYZE (multipart)
 */
async function analyzeNewsletter(text) {
    const cleanDriveUrl = String(driveUrl.value || '').trim()
    const driveFileId = extractDriveFileId(cleanDriveUrl)

    dlog('newsletter analyze ->', {
        text,
        driveUrl: cleanDriveUrl,
        driveFileId: driveFileId || null,
        fileName: newsletterFile.value?.name ?? null,
        baseUrl: createBaseUrl(),
    })

    const form = new FormData()
    form.append('sessionId', sessionId.value)
    form.append('message', text)
    form.append('provider', provider.value)
    if (model.value) form.append('model', model.value)

    // ✅ Drive always preferred if present
    if (cleanDriveUrl) form.append('drive_url', cleanDriveUrl)
    if (driveFileId) form.append('drive_file_id', driveFileId)

    // ✅ Upload optional
    if (newsletterFile.value) form.append('file', newsletterFile.value)

    try {
        const { data } = await axios.post(`${createBaseUrl()}/analyze`, form, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })

        lastTraceId.value = data.trace_id ?? null

        if (data.type === 'error') {
            messages.value.push({
                role: 'assistant',
                content: (data.answer ?? 'Fehler') + (data.code ? ` [${data.code}]` : ''),
            })
            return
        }

        if (data.type === 'confirm' || data.code === 'needs_confirmation') {
            pendingNewsletterDraftId.value = data.draftId ?? null
            messages.value.push({
                role: 'assistant',
                content: data.answer ?? 'Bitte prüfen und bestätigen.',
                newsletterConfirmCard: data.confirmCard ?? null,
            })
            return
        }

        messages.value.push({ role: 'assistant', content: data.answer ?? '[leer]' })
    } catch (err) {
        dlog('newsletter analyze error:', err)
        pushApiErrorMessage(err)
    }
}

/**
 * Newsletter: CONFIRM insert
 */
async function confirmNewsletterInsert(draftId) {
    dlog('newsletter confirm ->', { draftId })

    sending.value = true
    try {
        const { data } = await axios.post(`${createBaseUrl()}/confirm`, {
            sessionId: sessionId.value,
            draftId,
            provider: provider.value,
            model: model.value,
        })

        lastTraceId.value = data.trace_id ?? null

        if (data.type === 'error') {
            messages.value.push({
                role: 'assistant',
                content: (data.answer ?? 'Fehler') + (data.code ? ` [${data.code}]` : ''),
            })
            return
        }

        messages.value.push({
            role: 'assistant',
            content: data.answer ?? 'OK, eingefügt.',
        })

        pendingNewsletterDraftId.value = null
        waitingForNewsletterPatch.value = false
        clearNewsletterFile()
        driveUrl.value = ''
    } catch (err) {
        dlog('newsletter confirm error:', err)
        pushApiErrorMessage(err)
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

        // ✅ 0) "Einfügen" Shortcut
        if (
            (lower === 'einfügen' ||
                lower === 'einfuegen' ||
                lower === 'ok einfügen' ||
                lower === 'ok einfuegen') &&
            pendingNewsletterDraftId.value &&
            !waitingForNewsletterPatch.value
        ) {
            await confirmNewsletterInsert(pendingNewsletterDraftId.value)
            return
        }

        // 1) Patch-Modus hat Priorität
        if (pendingNewsletterDraftId.value && waitingForNewsletterPatch.value) {
            await patchNewsletterDraft(text)
            return
        }

        // ✅ 2) Import Analyze (Drive-only ODER Upload) – sobald Link oder Datei vorhanden, nicht auf /api/chat fallen
        const hasDrive = String(driveUrl.value || '').trim() !== ''
        const hasFile = !!newsletterFile.value

        if (!pendingNewsletterDraftId.value && (hasFile || hasDrive)) {
            await analyzeNewsletter(text)
            return
        }

        // 3) Contact resolve
        const uiSpanContact = 'ui.ChatView.send.contactResolve'
        const uiAtContact = Date.now()

        let c
        try {
            const resp = await axios.post(
                '/api/contact/resolve',
                { query: text },
                {
                    headers: {
                        'X-UI-Span': uiSpanContact,
                        'X-UI-At': String(uiAtContact),
                    },
                }
            )
            c = resp.data
        } catch (err) {
            dlog('contact resolve error:', err)
            c = null
        }

        if (c?.trace_id) lastTraceId.value = c.trace_id

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

        // Provider/Model aus Backend übernehmen (falls Backend umschaltet)
        const p = data.provider ?? provider.value
        const m = data.model ?? model.value
        provider.value = p
        model.value = m ?? null

        // System-Text aktualisieren
        if (messages.value.length > 0 && messages.value[0].role === 'system') {
            messages.value[0].content = systemText()
        }

        if (data.type === 'error') {
            messages.value.push({
                role: 'assistant',
                content: (data.answer ?? 'Fehler') + (data.code ? ` [${data.code}]` : ''),
            })
            return
        }

        // ✅ WICHTIG: matches/choices/cards/steps mitschicken, sonst rendert ChatMessages.vue keine SOP/Form/Newsletter-Boxen
        messages.value.push({
            role: 'assistant',
            // optional: Provider-Label wie früher (wenn du es nicht willst -> entferne den Prefix)
            content: `(${providerModelLabel(p, m)}) ${data.answer ?? '[leer]'}`,

            // Diese Felder steuern die DIV-Ausgabe in ChatMessages.vue:
            matches: data.matches ?? [],
            choices: data.choices ?? [],
            formCard: data.formCard ?? null,
            newsletterConfirmCard: data.confirmCard ?? data.newsletterConfirmCard ?? null,

            // optional steps (falls Backend welche liefert)
            steps: mapSteps(data.steps),

            provider: p,
            model: m ?? null,
        })
    } catch (err) {
        dlog('send error:', err)
        pushApiErrorMessage(err)
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

        // Wenn Backend mal error liefert, anzeigen statt "kaputt"
        if (data.type === 'error') {
            messages.value.push({
                role: 'assistant',
                content: (data.answer ?? 'Fehler') + (data.code ? ` [${data.code}]` : ''),
            })
            return
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
    } catch (err) {
        dlog('dbOnly error:', err)
        pushApiErrorMessage(err)
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

/**
 * Newsletter Erstellung: Sicherheitspin für
 */
const newsletterToolsUnlocked = ref(false)
const showPinPrompt = ref(false)
const pinInput = ref('')
const pinError = ref('')

function openNewsletterTools() {
    showPinPrompt.value = true
    pinInput.value = ''
    pinError.value = ''
}

function cancelPin() {
    showPinPrompt.value = false
    pinInput.value = ''
    pinError.value = ''
}

function confirmPin() {
    // UI-only (keine echte Security)
    const EXPECTED = '2014'
    if (pinInput.value.trim() === EXPECTED) {
        newsletterToolsUnlocked.value = true
        showPinPrompt.value = false
    } else {
        pinError.value = 'PIN ist falsch.'
    }
}

function lockNewsletterTools() {
    newsletterToolsUnlocked.value = false
}

function pickApiData(err) {
    // Axios error mit Backend-JSON (z.B. 422) -> err.response.data
    const data = err?.response?.data
    if (data && typeof data === 'object') return data

    // selten: Backend liefert JSON als string
    if (typeof data === 'string') {
        try { return JSON.parse(data) } catch (_) {}
    }

    return null
}

function pushApiErrorMessage(err, fallbackText = 'Fehler beim Senden. Bitte dev.log prüfen.') {
    const data = pickApiData(err)

    // Trace merken, wenn vorhanden
    if (data?.trace_id) lastTraceId.value = data.trace_id

    if (data && typeof data === 'object') {
        // Backend-Contract: type/code/answer
        const answer = String(data.answer ?? fallbackText)
        const code = data.code ? ` [${data.code}]` : ''
        const type = data.type ? String(data.type) : 'error'

        messages.value.push({
            role: 'assistant',
            content: answer + code,
            // optional: falls du das später im UI brauchst
            apiError: { type, code: data.code ?? null },
        })
        return true
    }

    // kein Backend-JSON vorhanden -> echter Netzwerk/JS Fehler
    messages.value.push({
        role: 'assistant',
        content: fallbackText,
    })
    return false
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
