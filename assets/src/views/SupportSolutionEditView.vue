<template>
    <div class="page">
        <div class="row">
            <h1>Bearbeiten: {{ form.title || `#${id}` }}</h1>
            <router-link class="btn" to="/kb">← Zurück</router-link>
        </div>

        <div v-if="loading">Lade…</div>
        <div v-if="error" class="error">{{ error }}</div>

        <div v-if="!loading">
            <!-- Stammdaten -->
            <section class="card">
                <h2>1) SupportSolution</h2>

                <label>Titel</label>
                <input class="input" v-model="form.title" />

                <label>Symptome</label>
                <textarea class="textarea" v-model="form.symptoms"></textarea>

                <label>Kontext / Notizen</label>
                <textarea class="textarea" v-model="form.contextNotes"></textarea>

                <div class="row">
                    <div>
                        <label>Priority</label>
                        <input class="input small" type="number" v-model.number="form.priority" />
                    </div>

                    <label class="row" style="gap:8px;">
                        <input type="checkbox" v-model="form.active" />
                        aktiv
                    </label>

                    <button class="btn primary" @click="saveSolution" :disabled="saving">
                        Stammdaten speichern
                    </button>
                </div>

                <div v-if="savedMsg" class="ok">{{ savedMsg }}</div>
            </section>

            <!-- Keywords -->
            <section class="card">
                <h2>2) Keywords</h2>

                <div class="row">
                    <button class="btn" @click="addKeywordRow">+ Keyword</button>
                    <button class="btn primary" @click="saveKeywords" :disabled="savingKeywords">
                        Keywords speichern
                    </button>
                </div>

                <div class="grid">
                    <div class="kwRow" v-for="(k, idx) in keywords" :key="k._key">
                        <input class="input" v-model="k.keyword" placeholder="z.B. warteschlange" />
                        <input class="input small" type="number" min="1" max="10" v-model.number="k.weight" />
                        <button class="btn danger" @click="removeKeyword(idx)">x</button>
                    </div>
                </div>

                <div v-if="kwMsg" class="ok">{{ kwMsg }}</div>
            </section>

            <!-- Steps -->
            <section class="card">
                <h2>3) Steps</h2>

                <div class="row">
                    <button class="btn" @click="addStepRow">+ Step</button>
                    <button class="btn primary" @click="saveSteps" :disabled="savingSteps">
                        Steps speichern
                    </button>
                </div>

                <div class="stepCard" v-for="(st, idx) in steps" :key="st._key">
                    <div class="row" style="justify-content: space-between;">
                        <strong>Step {{ st.stepNo }}</strong>
                        <button class="btn danger" @click="removeStep(idx)">Entfernen</button>
                    </div>

                    <label>Instruction</label>
                    <textarea class="textarea" v-model="st.instruction"></textarea>

                    <div class="row">
                        <div style="flex:1">
                            <label>Expected Result (optional)</label>
                            <textarea class="textarea" v-model="st.expectedResult"></textarea>
                        </div>
                        <div style="flex:1">
                            <label>Next if failed (optional)</label>
                            <textarea class="textarea" v-model="st.nextIfFailed"></textarea>
                        </div>
                    </div>
                </div>

                <div v-if="stMsg" class="ok">{{ stMsg }}</div>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'

const route = useRoute()
const id = computed(() => route.params.id)

const loading = ref(true)
const error = ref('')

const saving = ref(false)
const savingKeywords = ref(false)
const savingSteps = ref(false)

const savedMsg = ref('')
const kwMsg = ref('')
const stMsg = ref('')

const form = ref({
    title: '',
    symptoms: '',
    contextNotes: '',
    priority: 0,
    active: true,
})

const keywords = ref([]) // [{id?, keyword, weight, _key}]
const steps = ref([])    // [{id?, stepNo, instruction, expectedResult, nextIfFailed, _key}]

function key() {
    return crypto.randomUUID()
}

async function loadAll() {
    loading.value = true
    error.value = ''
    try {
        const sol = await axios.get(`/api/support_solutions/${id.value}`)
        // Wenn API Platform deine Felder anders benennt, passe hier an.
        form.value = {
            title: sol.data.title ?? '',
            symptoms: sol.data.symptoms ?? '',
            contextNotes: sol.data.contextNotes ?? sol.data.context_notes ?? '',
            priority: sol.data.priority ?? 0,
            active: sol.data.active ?? true,
        }

        // Keywords & Steps separat laden (stabil, vermeidet Join-Probleme)
        // Du brauchst dafür Filter auf solution_id oder solution IRI. Falls nicht vorhanden: kurz ergänzen.
        const kwRes = await axios.get('/api/support_solution_keywords', {
            params: { solution: `/api/support_solutions/${id.value}` },
        })
        const kwItems = kwRes.data.member ?? kwRes.data
        keywords.value = kwItems.map(k => ({
            id: k.id,
            keyword: k.keyword ?? '',
            weight: k.weight ?? 1,
            _key: key(),
        }))

        const stRes = await axios.get('/api/support_solution_steps', {
            params: { solution: `/api/support_solutions/${id.value}` },
        })
        const stItems = stRes.data.member ?? stRes.data
        steps.value = stItems
            .map(s => ({
                id: s.id,
                stepNo: s.stepNo ?? 1,
                instruction: s.instruction ?? '',
                expectedResult: s.expectedResult ?? '',
                nextIfFailed: s.nextIfFailed ?? '',
                _key: key(),
            }))
            .sort((a, b) => a.stepNo - b.stepNo)

    } catch (e) {
        error.value = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Laden'
    } finally {
        loading.value = false
    }
}

async function saveSolution() {
    saving.value = true
    savedMsg.value = ''
    try {
        await axios.patch(
            `/api/support_solutions/${id.value}`,
            {
                title: form.value.title,
                symptoms: form.value.symptoms,
                contextNotes: form.value.contextNotes,
                priority: form.value.priority,
                active: form.value.active,
            },
            { headers: { 'Content-Type': 'application/merge-patch+json' } }
        )
        savedMsg.value = '✅ Stammdaten gespeichert'
    } catch (e) {
        error.value = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Speichern'
    } finally {
        saving.value = false
    }
}

function addKeywordRow() {
    keywords.value.push({ id: null, keyword: '', weight: 5, _key: key() })
}
async function removeKeyword(idx) {
    const k = keywords.value[idx]
    if (k.id) {
        await axios.delete(`/api/support_solution_keywords/${k.id}`)
    }
    keywords.value.splice(idx, 1)
}

async function saveKeywords() {
    savingKeywords.value = true
    kwMsg.value = ''
    try {
        // 1) normalisieren & leere entfernen
        const cleaned = keywords.value
            .map(k => ({ ...k, keyword: (k.keyword ?? '').trim() }))
            .filter(k => k.keyword.length > 0)

        // 2) upsert (patch/post)
        for (const k of cleaned) {
            const payload = {
                solution: `/api/support_solutions/${id.value}`,
                keyword: k.keyword,
                weight: k.weight ?? 1,
            }
            if (k.id) {
                await axios.patch(`/api/support_solution_keywords/${k.id}`, payload, {
                    headers: { 'Content-Type': 'application/merge-patch+json' },
                })
            } else {
                const res = await axios.post('/api/support_solution_keywords', payload, {
                    headers: { 'Content-Type': 'application/ld+json' },
                })
                k.id = res.data.id
            }
        }

        // aktualisierte Liste zurückschreiben (nur cleaned)
        keywords.value = cleaned.map(k => ({ ...k }))
        kwMsg.value = '✅ Keywords gespeichert'
    } catch (e) {
        error.value = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Speichern der Keywords'
    } finally {
        savingKeywords.value = false
    }
}

function addStepRow() {
    const nextNo = steps.value.length ? Math.max(...steps.value.map(s => s.stepNo)) + 1 : 1
    steps.value.push({
        id: null,
        stepNo: nextNo,
        instruction: '',
        expectedResult: '',
        nextIfFailed: '',
        _key: key(),
    })
}

async function removeStep(idx) {
    const st = steps.value[idx]
    if (st.id) {
        await axios.delete(`/api/support_solution_steps/${st.id}`)
    }
    steps.value.splice(idx, 1)
}

async function saveSteps() {
    savingSteps.value = true
    stMsg.value = ''
    try {
        const cleaned = steps.value
            .map(s => ({
                ...s,
                instruction: (s.instruction ?? '').trim(),
                expectedResult: (s.expectedResult ?? '').trim() || null,
                nextIfFailed: (s.nextIfFailed ?? '').trim() || null,
            }))
            .filter(s => s.instruction.length > 0)
            .sort((a, b) => a.stepNo - b.stepNo)

        for (const s of cleaned) {
            const payload = {
                solution: `/api/support_solutions/${id.value}`,
                stepNo: s.stepNo,
                instruction: s.instruction,
                expectedResult: s.expectedResult,
                nextIfFailed: s.nextIfFailed,
            }
            if (s.id) {
                await axios.patch(`/api/support_solution_steps/${s.id}`, payload, {
                    headers: { 'Content-Type': 'application/merge-patch+json' },
                })
            } else {
                const res = await axios.post('/api/support_solution_steps', payload, {
                    headers: { 'Content-Type': 'application/ld+json' },
                })
                s.id = res.data.id
            }
        }

        steps.value = cleaned.map(s => ({ ...s }))
        stMsg.value = '✅ Steps gespeichert'
    } catch (e) {
        error.value = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Speichern der Steps'
    } finally {
        savingSteps.value = false
    }
}

loadAll()
</script>

<style scoped>
.page { max-width: 1200px; margin: 0 auto; padding: 20px; }
.card { border: 1px solid #eee; border-radius: 14px; padding: 16px; margin: 12px 0; }
.row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.input { padding: 10px; border: 1px solid #ddd; border-radius: 10px; width: 100%; }
.input.small { width: 90px; }
.textarea { padding: 10px; border: 1px solid #ddd; border-radius: 10px; width: 100%; min-height: 80px; }
.btn { padding: 10px 14px; border: 1px solid #ddd; border-radius: 10px; background: #fff; cursor: pointer; text-decoration: none; }
.btn.primary { background: #111; color: #fff; border-color: #111; }
.btn.danger { border-color: #ffb3b3; color: #a10000; }
.error { color: #b00020; margin: 10px 0; }
.ok { color: #087f23; margin-top: 8px; }
.grid { display: grid; gap: 10px; }
.kwRow { display: grid; grid-template-columns: 1fr 100px 60px; gap: 10px; align-items: center; }
.stepCard { border: 1px dashed #ddd; border-radius: 14px; padding: 12px; margin: 10px 0; }
label { display:block; margin: 8px 0 4px; font-weight: 600; }
</style>
