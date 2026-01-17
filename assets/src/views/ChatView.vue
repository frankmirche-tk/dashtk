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
const model = ref(null)        // optional model string

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

/**
 * Provider change: System-Message aktualisieren
 */
function onProviderChange() {
    model.value = null

    if (messages.value.length > 0 && messages.value[0].role === 'system') {
        messages.value[0].content = systemText()
    } else {
        messages.value.unshift({ role: 'system', content: systemText() })
    }
}

/**
 * Steps mapping (dein bestehendes Schema)
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
 * CONTACT: Auswahl aus Mehrtreffern (kommt aus ChatMessages)
 * -> Wir zeigen danach sofort eine strukturierte Contact-Card im Chat (ohne KI).
 */
function onContactSelected(payload) {
    const type = payload?.type ?? null
    const match = payload?.match ?? null
    if (!match) return
    pushContactCardMessage(type, match)
}

/**
 * Send: Kontakt/Filiale hat Vorrang vor KI
 * 1) User-Message immer anzeigen
 * 2) Resolve versuchen
 *    - 1 Treffer + hohe Confidence => Contact-Card, KEIN /api/chat
 *    - >1 Treffer => Auswahl-Message, KEIN /api/chat
 *    - 0 Treffer => normal /api/chat
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
        // 1) IMMER zuerst Kontakt auflösen
        const { data: c } = await axios.post('/api/contact/resolve', { query: text })

        if (c?.type && c.type !== 'none' && Array.isArray(c.matches) && c.matches.length > 0) {
            // Optional: kleine Info-Zeile statt KI
            // messages.value.push({ role: 'info', content: 'Kontakt gefunden:' })

            // 2) Alle Treffer als Cards ausgeben
            for (const hit of c.matches) {
                messages.value.push({
                    role: 'info',
                    content: '',
                    contactCard: {
                        type: c.type,     // 'branch' | 'person'
                        data: hit.data,   // dein JSON payload
                    }
                })
            }

            // WICHTIG: hier abbrechen, KEIN /api/chat!
            return
        }

        // 3) Kein Kontakt -> normaler Chat (KI / SOP)
        const { data } = await axios.post('/api/chat', {
            sessionId: sessionId.value,
            message: text,
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
    } catch (e) {
        console.error('chat error:', e)
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
 * Resolver-Gate: erkennt Filialcode/Person und rendert strukturierte Chat-Einträge
 * @returns {Promise<boolean>} handled? (true => kein /api/chat mehr)
 */
async function tryResolveContactAndRender(text) {
    // optional: nur bei kurzen Eingaben, damit nicht jeder Satz resolve triggert
    const tokenCount = String(text).trim().split(/\s+/).filter(Boolean).length
    if (tokenCount > 2) return false

    try {
        const { data } = await axios.post('/api/contact/resolve', {
            query: text,
            limit: 5,
        })

        const matches = Array.isArray(data?.matches) ? data.matches : []
        const type = data?.type ?? null

        if (matches.length === 0) return false

        // 1 Treffer -> wenn sehr sicher, sofort Contact-Card (ohne KI)
        if (matches.length === 1) {
            const m = matches[0]
            const conf = Number(m?.confidence ?? 0)

            // Filialcodes sollten praktisch immer sehr sicher sein; default: 0.95
            if (conf >= 0.95) {
                pushContactCardMessage(type, m)
                return true
            }

            // bei niedriger confidence lieber nicht “hart” routen
            return false
        }

        // Mehrtreffer -> alle Treffer als Kontaktkarten anzeigen (ohne Auswahl)
        messages.value.push({
            role: 'info',
            content: `Mehrere Treffer für "${text}":`,
        })

        for (const m of matches) {
            pushContactCardMessage(type, m)
        }

        return true
    } catch (e) {
        console.error('resolve error:', e)
        return false
    }
}

function pushContactCardMessage(type, match) {
    const data = match?.data ?? {}

    messages.value.push({
        role: 'info',
        content: '',
        contactCard: { type, data },
    })

}

function pushContactDisambiguationMessage(type, matches, originalQuery) {
    messages.value.push({
        role: 'info',
        content: `Mehrere Treffer für "${originalQuery}". Bitte auswählen:`,
        contactChoices: { type, matches },
    })
}


/**
 * Text-Fallback (wird angezeigt, selbst wenn du später eine “echte Card” renderst)
 */
function formatContactText(type, data) {
    if (type === 'branch') {
        const filialenNr = data.filialenNr ?? ''
        const anschrift  = data.anschrift ?? ''
        const strasse    = data.strasse ?? ''
        const plz        = data.plz ?? ''
        const ort        = data.ort ?? ''
        const telefon    = data.telefon ?? ''
        const email      = data.email ?? ''
        const zusatz     = data.zusatz ?? ''
        const gln        = data.gln ?? ''
        const ecTerminalId = data.ecTerminalId ?? ''

        const addressLine = [strasse, [plz, ort].filter(Boolean).join(' ')].filter(Boolean).join(', ')

        return [
            `Filiale: ${filialenNr}${anschrift ? ' – ' + anschrift : ''}`,
            addressLine ? `Adresse: ${addressLine}${zusatz ? ' (' + zusatz + ')' : ''}` : null,
            telefon ? `Telefon: ${telefon}` : null,
            email ? `E-Mail: ${email}` : null,
            gln ? `GLN: ${gln}` : null,
            ecTerminalId ? `EC-Terminal: ${ecTerminalId}` : null,
        ].filter(Boolean).join('\n')
    }

    if (type === 'person') {
        // bleibt wie bisher
        const first = data.first_name ?? ''
        const last = data.last_name ?? ''
        const dept = data.department ?? ''
        const loc = data.location ?? ''
        const phone = data.phone ?? ''
        const email = data.email ?? ''

        return [
            `Kontakt: ${first} ${last}`,
            (dept || loc) ? `Bereich: ${dept}${dept && loc ? ' – ' : ''}${loc}` : null,
            phone ? `Telefon: ${phone}` : null,
            email ? `E-Mail: ${email}` : null,
        ].filter(Boolean).join('\n')
    }

    return 'Kontakt gefunden.'
}


/**
 * DB-only Steps (unverändert, nur minimal robust)
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
