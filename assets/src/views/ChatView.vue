<template>
    <div class="wrap">
        <header class="header">
            <h1>DashTK Support Chat</h1>

            <div class="actions">
                <button class="btn" @click="newChat">Neuer Chat</button>
            </div>
        </header>

        <div class="chat">
            <div v-for="(m, idx) in messages" :key="idx" class="msg" :class="m.role">
                <div class="role">{{ m.role }}:</div>
                <div class="content">
                    <pre class="pre">{{ m.content }}</pre>

                    <div v-if="m.role === 'assistant' && m.matches?.length" class="kb">
                        <div class="kb-title">Passende SOPs aus der Datenbank:</div>
                        <ul class="kb-list">
                            <li v-for="hit in m.matches" :key="hit.id" class="kb-item">
                                <a :href="hit.url" target="_blank" rel="noreferrer">
                                    {{ hit.title }} (Score {{ hit.score }})
                                </a>

                                <div class="kb-actions">
                                    <button class="btn small" @click="useDbStepsOnly(hit.id)">
                                        Nur Steps
                                    </button>
                                    <a class="btn small ghost" :href="hit.stepsUrl" target="_blank" rel="noreferrer">
                                        Steps API
                                    </a>
                                </div>
                            </li>
                        </ul>
                    </div>

                </div>
            </div>
        </div>

        <form class="composer" @submit.prevent="send">
            <input
                v-model="input"
                class="input"
                placeholder="Schreib dein Problem…"
                autocomplete="off"
            />
            <button class="btn" type="submit" :disabled="sending || !input.trim()">
                Senden
            </button>
        </form>
    </div>
</template>

<script setup>
import axios from 'axios'
import { ref } from 'vue'

const input = ref('')
const sending = ref(false)

const sessionId = ref(sessionStorage.getItem('sessionId') || crypto.randomUUID())
sessionStorage.setItem('sessionId', sessionId.value)

const messages = ref([
    { role: 'system', content: 'Willkommen. Beschreibe dein Druckerproblem.' }
])

async function send() {
    const text = input.value.trim()
    if (!text) return

    messages.value.push({ role: 'user', content: text })
    input.value = ''
    sending.value = true

    try {
        const { data } = await axios.post('/api/chat', {
            sessionId: sessionId.value,
            message: text
        })

        messages.value.push({
            role: 'assistant',
            content: data.answer ?? '[leer]',
            matches: data.matches ?? []
        })
    } catch (e) {
        console.error(e)
        messages.value.push({
            role: 'assistant',
            content: 'Fehler beim Senden. Siehe Console/Logs.',
            matches: []
        })
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
            dbOnlySolutionId: solutionId
        })

        messages.value.push({
            role: 'assistant',
            content: data.answer ?? '[leer]',
            matches: data.matches ?? []
        })
    } catch (e) {
        console.error(e)
        messages.value.push({
            role: 'assistant',
            content: 'Fehler beim Laden der Steps (DB-only). Siehe Console/Logs.',
            matches: []
        })
    } finally {
        sending.value = false
    }
}

function newChat() {
    sessionId.value = crypto.randomUUID()
    sessionStorage.setItem('sessionId', sessionId.value)

    messages.value = [
        { role: 'system', content: 'Neuer Chat gestartet. Beschreibe dein Problem.' }
    ]
    input.value = ''
}
</script>

<style scoped>
.wrap { max-width: 900px; margin: 24px auto; padding: 0 16px; font-family: system-ui, sans-serif; }
.header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.actions { display: flex; gap: 8px; }
.chat { border: 1px solid #ddd; border-radius: 12px; padding: 16px; min-height: 360px; background: #fff; }
.msg { display: grid; grid-template-columns: 110px 1fr; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f1f1f1; }
.msg:last-child { border-bottom: none; }
.role { font-weight: 700; text-transform: lowercase; color: #333; }
.pre { white-space: pre-wrap; margin: 0; font-family: inherit; }
.composer { display: flex; gap: 10px; margin-top: 14px; }
.input { flex: 1; border: 1px solid #ccc; border-radius: 10px; padding: 12px; font-size: 16px; }
.btn { border: 1px solid #111; background: #111; color: #fff; border-radius: 10px; padding: 10px 14px; cursor: pointer; }
.btn:disabled { opacity: .6; cursor: not-allowed; }
.btn.small { padding: 6px 10px; border-radius: 8px; font-size: 13px; }
.btn.ghost { background: transparent; color: #111; }
.kb { margin-top: 10px; padding: 10px; border: 1px dashed #aaa; border-radius: 10px; background: #fafafa; }
.kb-title { font-weight: 700; margin-bottom: 6px; }
.kb-list { margin: 0; padding-left: 18px; }
.kb-item { margin: 6px 0; }
.kb-actions { display: flex; gap: 8px; margin-top: 6px; }
</style>
