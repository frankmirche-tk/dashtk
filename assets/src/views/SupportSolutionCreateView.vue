<template>
    <div class="page">
        <header class="topbar">
            <h1>Neues Support-Thema</h1>
            <div class="actions">
                <router-link class="btn" to="/">← zurück zum Chat</router-link>
            </div>
        </header>

        <div class="grid">
            <!-- 1) Thema -->
            <section class="card">
                <h2>1) Thema / SupportSolution</h2>

                <label class="label">Titel</label>
                <input class="input" v-model.trim="solution.title" placeholder="z.B. Papierstau beheben – HP LaserJet ..." />

                <label class="label">Symptome</label>
                <textarea class="textarea" v-model.trim="solution.symptoms" rows="3"
                          placeholder="z.B. Meldung Papierstau, Druck bricht ab, Papier klemmt ..." />

                <label class="label">Kontext / Notizen</label>
                <textarea class="textarea" v-model.trim="solution.contextNotes" rows="3"
                          placeholder="z.B. Häufig nach Papierwechsel. Keywords: ... (optional)" />

                <div class="row">
                    <div>
                        <label class="label">Priority</label>
                        <input class="input" type="number" v-model.number="solution.priority" min="0" max="100" />
                    </div>
                    <div class="checkbox">
                        <input id="active" type="checkbox" v-model="solution.active" />
                        <label for="active">aktiv</label>
                    </div>
                </div>

                <div class="row">
                    <button class="btn primary" :disabled="saving || createdSolutionId" @click="createSolution">
                        {{ createdSolutionId ? `✅ Angelegt (#${createdSolutionId})` : 'SupportSolution anlegen' }}
                    </button>
                    <span class="hint" v-if="createdSolutionId">Jetzt Keywords & Steps hinzufügen.</span>
                </div>

                <p class="error" v-if="errors.solution">{{ errors.solution }}</p>
            </section>

            <!-- 2) Keywords -->
            <section class="card">
                <h2>2) Keywords</h2>

                <div class="hint">
                    Pro Keyword optional Gewicht (1–10). Beispiel: <code>wlan</code>, <code>drucker offline</code>, <code>warteschlange</code>
                </div>

                <div class="list">
                    <div v-for="(k, idx) in keywords" :key="idx" class="list-row">
                        <input class="input" v-model.trim="k.keyword" placeholder="keyword" />
                        <input class="input small" type="number" v-model.number="k.weight" min="1" max="10" />
                        <button class="btn danger" @click="removeKeyword(idx)">✕</button>
                    </div>
                </div>

                <div class="row">
                    <button class="btn" @click="addKeyword">+ Keyword</button>
                    <button class="btn primary" :disabled="saving || !createdSolutionId || keywords.length === 0" @click="saveKeywords">
                        Keywords speichern
                    </button>
                </div>

                <p class="error" v-if="errors.keywords">{{ errors.keywords }}</p>
                <p class="ok" v-if="saved.keywords">✅ Keywords gespeichert</p>
            </section>

            <!-- 3) Steps -->
            <section class="card full">
                <h2>3) Steps</h2>

                <div class="hint">
                    Steps werden mit <code>stepNo</code> gespeichert (1..n). Empfehlung: klare Aktion + erwartetes Ergebnis + nächster Schritt falls fehlgeschlagen.
                    <br>
                    Optional kannst du pro Step eine Datei hochladen (Bild/GIF/PDF/MP4). Diese wird später als „Bildhilfe / PDF“ angezeigt.
                </div>

                <div class="steps">
                    <div v-for="(s, idx) in steps" :key="idx" class="step">
                        <div class="step-head">
                            <strong>Step {{ s.stepNo }}</strong>
                            <button class="btn danger" @click="removeStep(idx)">Entfernen</button>
                        </div>

                        <label class="label">Instruction</label>
                        <textarea class="textarea" v-model.trim="s.instruction" rows="2" placeholder="Was soll der Mitarbeiter tun?" />

                        <div class="two">
                            <div>
                                <label class="label">Expected Result (optional)</label>
                                <textarea class="textarea" v-model.trim="s.expectedResult" rows="2" placeholder="Woran erkennt man Erfolg?" />
                            </div>
                            <div>
                                <label class="label">Next if failed (optional)</label>
                                <textarea class="textarea" v-model.trim="s.nextIfFailed" rows="2" placeholder="Wenn es nicht klappt: was als nächstes?" />
                            </div>
                        </div>

                        <!-- Media Upload erst NACH dem Speichern (wenn Step-ID existiert) -->
                        <div class="media" v-if="createdSolutionId">
                            <div class="media-hint">
                                Media pro Step: erst „Steps speichern“, dann kannst du Upload/Replace machen (da Step-ID benötigt wird).
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <button class="btn" @click="addStep">+ Step</button>
                    <button class="btn primary" :disabled="saving || !createdSolutionId || steps.length === 0" @click="saveSteps">
                        Steps speichern
                    </button>
                </div>

                <p class="error" v-if="errors.steps">{{ errors.steps }}</p>
                <p class="ok" v-if="saved.steps">✅ Steps gespeichert – jetzt kannst du im Edit-Mode Media hochladen.</p>

                <div v-if="saved.steps" class="row" style="margin-top: 10px;">
                    <router-link class="btn primary" :to="`/kb/${createdSolutionId}`">
                        Jetzt Steps + Media bearbeiten
                    </router-link>
                </div>
            </section>
        </div>

        <section class="card">
            <h2>Fertig / Export</h2>
            <div class="row">
                <button class="btn" @click="resetAll">Neues Thema anfangen</button>
                <router-link class="btn primary" to="/">Zum Chat</router-link>
            </div>
            <div class="hint" v-if="createdSolutionId">
                Angelegte Solution-ID: <strong>#{{ createdSolutionId }}</strong>
            </div>
        </section>
    </div>
</template>

<script setup>
import axios from 'axios'
import { ref, reactive } from 'vue'

const saving = ref(false)
const createdSolutionId = ref(null)

const errors = reactive({ solution: '', keywords: '', steps: '' })
const saved = reactive({ keywords: false, steps: false })

const solution = reactive({
    title: '',
    symptoms: '',
    contextNotes: '',
    priority: 0,
    active: true,
})

const keywords = ref([{ keyword: '', weight: 5 }])
const steps = ref([{ stepNo: 1, instruction: '', expectedResult: '', nextIfFailed: '' }])

function apiBase() {
    return 'http://127.0.0.1:8000'
}

function clearErrors() {
    errors.solution = ''
    errors.keywords = ''
    errors.steps = ''
}

function addKeyword() {
    keywords.value.push({ keyword: '', weight: 3 })
}
function removeKeyword(idx) {
    keywords.value.splice(idx, 1)
}

function addStep() {
    const nextNo = steps.value.length ? Math.max(...steps.value.map(s => s.stepNo)) + 1 : 1
    steps.value.push({ stepNo: nextNo, instruction: '', expectedResult: '', nextIfFailed: '' })
}
function removeStep(idx) {
    steps.value.splice(idx, 1)
}

async function createSolution() {
    clearErrors()
    saved.keywords = false
    saved.steps = false

    if (!solution.title || !solution.symptoms) {
        errors.solution = 'Titel und Symptome sind Pflicht.'
        return
    }

    saving.value = true
    try {
        const res = await axios.post(`${apiBase()}/api/support_solutions`, {
            title: solution.title,
            symptoms: solution.symptoms,
            contextNotes: solution.contextNotes || null,
            priority: Number(solution.priority || 0),
            active: !!solution.active,
        }, {
            headers: { 'Content-Type': 'application/ld+json', 'Accept': 'application/ld+json' }
        })

        const iri = res.data?.['@id'] || ''
        const id = iri.split('/').pop()
        createdSolutionId.value = id ? Number(id) : null

        if (!createdSolutionId.value) {
            errors.solution = 'Konnte ID nicht aus API Response lesen. Prüfe Response im Network Tab.'
        }
    } catch (e) {
        errors.solution = e?.response?.data?.detail || e.message || 'Unbekannter Fehler'
    } finally {
        saving.value = false
    }
}

async function saveKeywords() {
    clearErrors()
    saved.keywords = false

    if (!createdSolutionId.value) {
        errors.keywords = 'Bitte zuerst SupportSolution anlegen.'
        return
    }

    const cleaned = keywords.value
        .map(k => ({
            keyword: (k.keyword || '').trim().toLowerCase(),
            weight: Math.max(1, Math.min(10, Number(k.weight || 1))),
        }))
        .filter(k => k.keyword.length > 0)

    if (cleaned.length === 0) {
        errors.keywords = 'Bitte mindestens ein Keyword eintragen.'
        return
    }

    saving.value = true
    try {
        for (const k of cleaned) {
            await axios.post(`${apiBase()}/api/support_solution_keywords`, {
                solution: `/api/support_solutions/${createdSolutionId.value}`,
                keyword: k.keyword,
                weight: k.weight,
            }, {
                headers: { 'Content-Type': 'application/ld+json', 'Accept': 'application/ld+json' }
            })
        }
        saved.keywords = true
    } catch (e) {
        errors.keywords = e?.response?.data?.detail || e.message || 'Unbekannter Fehler'
    } finally {
        saving.value = false
    }
}

async function saveSteps() {
    clearErrors()
    saved.steps = false

    if (!createdSolutionId.value) {
        errors.steps = 'Bitte zuerst SupportSolution anlegen.'
        return
    }

    const cleaned = steps.value
        .map(s => ({
            stepNo: Number(s.stepNo || 0),
            instruction: (s.instruction || '').trim(),
            expectedResult: (s.expectedResult || '').trim() || null,
            nextIfFailed: (s.nextIfFailed || '').trim() || null,
        }))
        .filter(s => s.stepNo > 0 && s.instruction.length > 0)

    if (cleaned.length === 0) {
        errors.steps = 'Bitte mindestens einen Step mit StepNo + Instruction ausfüllen.'
        return
    }

    const stepNos = cleaned.map(s => s.stepNo)
    const dup = stepNos.find((n, i) => stepNos.indexOf(n) !== i)
    if (dup) {
        errors.steps = `StepNo ${dup} ist doppelt. Bitte eindeutig machen.`
        return
    }

    saving.value = true
    try {
        for (const s of cleaned) {
            await axios.post(`${apiBase()}/api/support_solution_steps`, {
                solution: `/api/support_solutions/${createdSolutionId.value}`,
                stepNo: s.stepNo,
                instruction: s.instruction,
                expectedResult: s.expectedResult,
                nextIfFailed: s.nextIfFailed,
            }, {
                headers: { 'Content-Type': 'application/ld+json', 'Accept': 'application/ld+json' }
            })
        }
        saved.steps = true
    } catch (e) {
        errors.steps = e?.response?.data?.detail || e.message || 'Unbekannter Fehler'
    } finally {
        saving.value = false
    }
}

function resetAll() {
    createdSolutionId.value = null
    solution.title = ''
    solution.symptoms = ''
    solution.contextNotes = ''
    solution.priority = 0
    solution.active = true
    keywords.value = [{ keyword: '', weight: 5 }]
    steps.value = [{ stepNo: 1, instruction: '', expectedResult: '', nextIfFailed: '' }]
    saved.keywords = false
    saved.steps = false
    clearErrors()
}
</script>

<style scoped>
.page { padding: 16px; max-width: 1100px; margin: 0 auto; }
.topbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom: 12px; }
.actions { display:flex; gap:10px; }
.grid { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.card { background: #fff; border: 1px solid #e6e6e6; border-radius: 14px; padding: 14px; }
.card.full { grid-column: 1 / -1; }
.label { display:block; font-size: 12px; margin-top: 10px; margin-bottom: 6px; color:#444; }
.input { width:100%; padding:10px 12px; border:1px solid #ccc; border-radius: 10px; }
.input.small { width: 110px; }
.textarea { width:100%; padding:10px 12px; border:1px solid #ccc; border-radius: 10px; resize: vertical; }
.row { display:flex; gap: 10px; align-items:center; margin-top: 12px; flex-wrap: wrap; }
.checkbox { display:flex; gap: 8px; align-items:center; margin-top: 28px; }
.btn { display:inline-flex; align-items:center; gap:6px; padding:8px 12px; border:1px solid #ccc; border-radius:10px; background:white; cursor:pointer; text-decoration:none; }
.btn:hover { background:#f7f7f7; }
.btn.primary { background: #111; color: #fff; border-color:#111; }
.btn.primary:hover { background:#000; }
.btn.danger { border-color:#f0b4b4; }
.hint { color:#666; font-size: 13px; }
.error { color:#b00020; margin-top: 10px; }
.ok { color:#0a7a2f; margin-top: 10px; }
.list { margin-top: 10px; display:flex; flex-direction:column; gap: 8px; }
.list-row { display:flex; gap: 8px; align-items:center; }
.steps { margin-top: 10px; display:flex; flex-direction:column; gap: 12px; }
.step { border:1px dashed #ddd; border-radius: 12px; padding: 12px; }
.step-head { display:flex; justify-content:space-between; align-items:center; gap: 10px; }
.two { display:grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.media { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; }
.media-hint { font-size: 12px; color: #666; }
@media (max-width: 900px) {
    .grid { grid-template-columns: 1fr; }
    .two { grid-template-columns: 1fr; }
}
</style>
