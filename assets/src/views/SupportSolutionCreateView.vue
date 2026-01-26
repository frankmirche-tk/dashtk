<template>
    <div class="page">
        <div class="row top">
            <h1>Neues Support-Thema</h1>
            <router-link class="btn" to="/kb">← zurück zum Chat</router-link>
        </div>

        <div v-if="error" class="error">{{ error }}</div>

        <div class="grid">
            <!-- 1) Thema -->
            <section class="card">
                <h2>1) Thema / SupportSolution</h2>

                <div class="row" style="align-items:flex-end; gap:14px;">
                    <div style="min-width: 220px;">
                        <label class="label">Typ</label>
                        <select class="input" v-model="solution.type">
                            <option value="SOP">SOP (mit Steps)</option>
                            <option value="FORM">FORM (externes Formular / Dokument)</option>
                        </select>
                    </div>

                    <label class="row" style="gap:8px; margin-left:auto;">
                        <input type="checkbox" v-model="solution.active" />
                        aktiv
                    </label>

                    <div style="min-width: 140px;">
                        <label class="label">Priority</label>
                        <input class="input" type="number" v-model.number="solution.priority" />
                    </div>
                </div>

                <label class="label">Titel</label>
                <input class="input" v-model="solution.title" placeholder="z.B. Papierstau beheben – HP LaserJet ..." />

                <label class="label">
                    Symptome
                    <span v-if="solution.type === 'FORM'" class="hint">(optional)</span>
                </label>
                <textarea
                    class="textarea"
                    v-model="solution.symptoms"
                    :placeholder="solution.type === 'FORM'
                        ? 'Optional: Wann ist dieses Formular relevant?'
                        : 'z.B. Meldung Papierstau, Druck bricht ab …'"
                ></textarea>

                <label class="label">Kontext / Notizen</label>
                <textarea class="textarea" v-model="solution.contextNotes" placeholder="Optional: Hinweise / Kontext"></textarea>

                <div v-if="errors.solution" class="error">{{ errors.solution }}</div>

                <div class="row" style="margin-top:12px;">
                    <button class="btn primary" @click="createSolution" :disabled="saving  || !!createdSolutionId">
                        SupportSolution speichern
                    </button>

                    <div v-if="createdSolutionId" class="ok">
                        ✅ Angelegt: ID {{ createdSolutionId }}
                        <router-link class="btn" :to="`/kb/edit/${createdSolutionId}`">Jetzt bearbeiten</router-link>
                    </div>
                </div>
                <!-- neuer Code -->
                <!-- neuer Code -->
                <div v-if="solution.type === 'FORM'">
                    <h3 style="margin-top:16px;">Dokument-Typ (optional)</h3>
                    <p class="hint">
                        Wenn es sich um einen Newsletter handelt, füge bitte unbedingt Keywords wie „newsletter“, „kw“, „sale“, „rabatt“ hinzu.
                        Die Zeitfilterung läuft im Chat über createdAt (Montag der KW) bzw. das Datum im Titel.
                    </p>
                </div>



            </section>

            <!-- 2) Keywords -->
            <section class="card">
                <h2>2) Keywords</h2>

                <div class="hint">Pro Keyword optional Gewicht (1–10). Beispiel: wlan, drucker offline, warteschlange</div>

                <div class="grid">
                    <div class="kwRow" v-for="(k, idx) in keywords" :key="idx">
                        <input class="input" v-model="k.keyword" placeholder="keyword" />
                        <input class="input small" type="number" min="1" max="10" v-model.number="k.weight" />
                        <button class="btn danger" @click="removeKeywordRow(idx)">x</button>
                    </div>
                </div>

                <div class="row" style="margin-top:10px;">
                    <button class="btn" @click="addKeywordRow">+ Keyword</button>
                    <button class="btn primary" @click="saveKeywords" :disabled="savingKeywords">
                        Keywords speichern
                    </button>
                </div>



                <div v-if="errors.keywords" class="error">{{ errors.keywords }}</div>
                <div v-if="kwMsg" class="ok">{{ kwMsg }}</div>
            </section>

            <!-- 3) Formular (nur FORM) -->
            <section class="card full" v-if="solution.type === 'FORM'">
                <h2>3) Formular</h2>

                <!-- neuer Code: Newsletter-Metadaten -->
                <div class="row" style="gap:14px; align-items:flex-end; margin-top:10px;">
                    <div style="min-width: 240px;">
                        <label class="label">Kategorie</label>
                        <select class="input" v-model="solution.category">
                            <option value="GENERAL">GENERAL</option>
                            <option value="NEWSLETTER">NEWSLETTER</option>
                        </select>
                    </div>

                    <div v-if="solution.category === 'NEWSLETTER'" style="min-width: 160px;">
                        <label class="label">Jahr</label>
                        <input class="input" type="number" v-model.number="solution.newsletterYear" placeholder="2026" />
                    </div>

                    <div v-if="solution.category === 'NEWSLETTER'" style="min-width: 160px;">
                        <label class="label">KW</label>
                        <input class="input" type="number" min="1" max="53" v-model.number="solution.newsletterKw" placeholder="5" />
                    </div>

                    <div v-if="solution.category === 'NEWSLETTER'" style="min-width: 200px;">
                        <label class="label">Edition</label>
                        <select class="input" v-model="solution.newsletterEdition">
                            <option value="STANDARD">STANDARD</option>
                            <option value="TEIL_1">TEIL_1</option>
                            <option value="TEIL_2">TEIL_2</option>
                            <option value="SPECIAL">SPECIAL</option>
                        </select>
                    </div>
                </div>

                <div v-if="solution.category === 'NEWSLETTER'" class="hint" style="margin-top:6px;">
                    Tipp: Titel enthält idealerweise „Newsletter KW x/yyyy“ oder „Specialnewsletter KW x“.
                </div>
                <!-- neuer Code: publishedAt Preview -->
                <div v-if="solution.category === 'NEWSLETTER'" class="hint" style="margin-top:6px;">
                    Veröffentlichungsdatum (Montag der KW): <code>{{ solution.publishedAt ? solution.publishedAt.slice(0, 10) : '—' }}</code>
                </div>

                <div class="hint">
                    Hier hinterlegst du den Link zu einem externen Formular/Dokument (z.B. Google Drive).
                    Auswahl wird automatisch gespeichert (auch bei Wechsel).
                </div>

                <div class="row" style="gap:14px; align-items:flex-end; margin-top:10px;">
                    <div style="min-width: 240px;">
                        <label class="label">Media Type</label>
                        <select class="input" v-model="solution.mediaType">
                            <option value="external">external (Link)</option>
                            <option value="internal" disabled>internal (Upload) – später</option>
                        </select>
                    </div>

                    <div style="min-width: 240px;">
                        <label class="label">Provider</label>
                        <select class="input" v-model="solution.externalMediaProvider">
                            <option value="google_drive">google_drive</option>
                        </select>
                    </div>
                </div>

                <!-- Ordner -->
                <div class="row" style="align-items:flex-end; gap:14px; margin-top:10px;">
                    <div style="flex:1">
                        <label class="label">Google Drive Ordner</label>
                        <select
                            class="input"
                            v-model="selectedDriveFolderId"
                            :disabled="driveFoldersLoading"
                        >
                            <option value="">
                                {{ driveFoldersLoading ? 'Lade Ordner…' : '— bitte auswählen —' }}
                            </option>
                            <option v-for="f in driveFolders" :key="f.id" :value="f.id">
                                {{ f.name }} ({{ f.mimeType }})
                            </option>
                        </select>

                        <div class="hint" style="margin-top:6px;">
                            Quelle: <code>/api/forms/google-drive/folders</code> (Root: <code>GOOGLE_DRIVE_FOLDER_ID</code>)
                        </div>
                    </div>

                    <button class="btn" @click="loadDriveFolders" :disabled="driveFoldersLoading">
                        Ordner aktualisieren
                    </button>
                </div>

                <!-- Dateien -->
                <div class="row" style="align-items:flex-end; gap:14px; margin-top:10px;">
                    <div style="flex:1">
                        <label class="label">Google Drive Datei</label>
                        <select
                            class="input"
                            v-model="selectedDriveFileId"
                            :disabled="driveFilesLoading || !selectedDriveFolderId || driveFiles.length === 0"
                            @change="onPickDriveFile"
                        >
                            <option value="">
                                {{
                                    !selectedDriveFolderId
                                        ? '— zuerst Ordner wählen —'
                                        : (driveFilesLoading ? 'Lade Dateien…' : '— bitte auswählen —')
                                }}
                            </option>

                            <option v-for="f in driveFiles" :key="f.id" :value="f.id">
                                {{ f.name }} ({{ f.mimeType }})
                            </option>
                        </select>

                        <div class="hint" style="margin-top:6px;">
                            Quelle: <code>/api/forms/google-drive?folderId={{ selectedDriveFolderId || '...' }}</code>
                        </div>
                    </div>

                    <button class="btn" @click="loadDriveFiles(selectedDriveFolderId)" :disabled="driveFilesLoading || !selectedDriveFolderId">
                        Dateien aktualisieren
                    </button>
                </div>

                <label class="label">Externe URL</label>
                <input class="input" v-model="solution.externalMediaUrl" placeholder="https://drive.google.com/file/d/.../view" />

                <label class="label">Externe ID (optional)</label>
                <input class="input" v-model="solution.externalMediaId" placeholder="z.B. Google Drive fileId" />

                <div class="row" style="margin-top:12px;">
                    <div v-if="formMsg" class="ok">{{ formMsg }}</div>
                    <div v-if="formSavingHint" class="hint">Speichere Formular…</div>
                </div>

                <div class="row" style="margin-top:10px;" v-if="solution.externalMediaUrl">
                    <a class="btn" :href="solution.externalMediaUrl" target="_blank" rel="noreferrer">↗ Link öffnen</a>
                    <div class="hint">Tipp: Für In-App Preview später <code>/preview</code> nutzen.</div>
                </div>

                <div v-if="errors.form" class="error">{{ errors.form }}</div>
            </section>

            <!-- 3) SOP Steps (nur SOP) -->
            <section class="card full" v-else>
                <h2>3) Steps</h2>

                <div class="row">
                    <button class="btn" @click="addStepRow">+ Step</button>
                    <button class="btn primary" @click="saveSteps" :disabled="savingSteps">
                        Steps speichern
                    </button>
                </div>

                <div class="stepCard" v-for="(st, idx) in steps" :key="idx">
                    <div class="row" style="justify-content: space-between;">
                        <strong>Step {{ st.stepNo }}</strong>
                        <button class="btn danger" @click="removeStepRow(idx)">Entfernen</button>
                    </div>

                    <label class="label">Instruction</label>
                    <textarea class="textarea" v-model="st.instruction"></textarea>

                    <div class="row">
                        <div style="flex:1">
                            <label class="label">Expected Result (optional)</label>
                            <textarea class="textarea" v-model="st.expectedResult"></textarea>
                        </div>
                        <div style="flex:1">
                            <label class="label">Next if failed (optional)</label>
                            <textarea class="textarea" v-model="st.nextIfFailed"></textarea>
                        </div>
                    </div>
                </div>

                <div v-if="errors.steps" class="error">{{ errors.steps }}</div>
                <div v-if="stMsg" class="ok">{{ stMsg }}</div>
            </section>
        </div>
    </div>
</template>

<script setup>
import axios from 'axios'
import { ref, reactive, watch } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()


const saving = ref(false)
const savingKeywords = ref(false)
const savingSteps = ref(false)

const createdSolutionId = ref(null)

const error = ref('')
const kwMsg = ref('')
const stMsg = ref('')

// Autosave Status/Messages
const formMsg = ref('')
const formSavingHint = ref(false)

// Drive Ordner + Dateien
const driveFolders = ref([])
const driveFoldersLoading = ref(false)
const selectedDriveFolderId = ref('')

const driveFiles = ref([])
const driveFilesLoading = ref(false)
const selectedDriveFileId = ref('')

const solution = reactive({
    type: 'SOP',
    title: '',
    symptoms: '',
    contextNotes: '',
    priority: 0,
    active: true,

    // Newsletter / Category
    category: 'GENERAL',
    newsletterYear: null,
    newsletterKw: null,
    newsletterEdition: 'STANDARD',
    publishedAt: null,

    // FORM-spezifisch
    mediaType: null, // 'external'
    externalMediaProvider: null, // 'google_drive'
    externalMediaUrl: '',
    externalMediaId: '',
})

const keywords = ref([{ keyword: '', weight: 5 }])
const steps = ref([{ stepNo: 1, instruction: '', expectedResult: '', nextIfFailed: '' }])

const errors = reactive({ solution: '', keywords: '', steps: '', form: '' })

async function loadDriveFolders() {
    driveFoldersLoading.value = true
    try {
        const { data } = await axios.get('/api/forms/google-drive/folders')
        driveFolders.value = Array.isArray(data?.folders) ? data.folders : []
    } catch (e) {
        driveFolders.value = []
    } finally {
        driveFoldersLoading.value = false
    }
}

async function loadDriveFiles(folderId) {
    if (!folderId) {
        driveFiles.value = []
        selectedDriveFileId.value = ''
        return
    }

    driveFilesLoading.value = true
    try {
        const { data } = await axios.get('/api/forms/google-drive', { params: { folderId } })
        driveFiles.value = Array.isArray(data?.files) ? data.files : []
    } catch (e) {
        driveFiles.value = []
    } finally {
        driveFilesLoading.value = false
    }
}

function clearErrors() {
    errors.solution = ''
    errors.keywords = ''
    errors.steps = ''
    errors.form = ''
    error.value = ''
}

/**
 * FORM Autosave:
 * - wenn noch keine Solution existiert -> createSolution() (POST)
 * - wenn Solution existiert -> PATCH Media Felder
 */
async function autoPersistForm() {
    if (solution.type !== 'FORM') return

    // klare UX-Messages
    const title = (solution.title ?? '').trim()
    if (title === '') {
        errors.solution = 'Titel noch nicht vergeben.'
        return
    }

    const url = (solution.externalMediaUrl ?? '').trim()
    if (url === '') {
        errors.form = 'Bitte erst Formular auswählen.'
        return
    }

    // Defaults
    solution.mediaType ||= 'external'
    solution.externalMediaProvider ||= 'google_drive'

    // Wenn noch nicht angelegt -> anlegen
    if (!createdSolutionId.value) {
        await createSolution()
        return // createSolution redirected already
    }


    // Schon vorhanden -> PATCH
    formSavingHint.value = true
    try {
        await axios.patch(
            `/api/support_solutions/${createdSolutionId.value}`,
            {

                category: solution.category ?? 'GENERAL',
                newsletterYear: solution.category === 'NEWSLETTER' ? (solution.newsletterYear ?? null) : null,
                newsletterKw: solution.category === 'NEWSLETTER' ? (solution.newsletterKw ?? null) : null,
                newsletterEdition: solution.category === 'NEWSLETTER' ? (solution.newsletterEdition ?? 'STANDARD') : null,
                publishedAt: solution.category === 'NEWSLETTER' ? (solution.publishedAt ?? null) : null,

                mediaType: solution.mediaType ?? 'external',
                externalMediaProvider: solution.externalMediaProvider ?? 'google_drive',
                externalMediaUrl: solution.externalMediaUrl || null,
                externalMediaId: solution.externalMediaId || null,
            },
            { headers: { 'Content-Type': 'application/merge-patch+json' } }
        )
        formMsg.value = '✅ Formular gespeichert'
    } catch (e) {
        errors.form = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Speichern des Formulars'
    } finally {
        formSavingHint.value = false
    }
}

function onPickDriveFile() {
    clearErrors()
    formMsg.value = ''

    const f = driveFiles.value.find(x => x.id === selectedDriveFileId.value)
    if (!f) return

    solution.mediaType = 'external'
    solution.externalMediaProvider = 'google_drive'
    solution.externalMediaUrl = f.webViewLink ?? (f.id ? `https://drive.google.com/file/d/${f.id}/view` : '')
    solution.externalMediaId = f.id ?? ''

    // Autosave sofort (auch beim "Vertan" und neu wählen)
    autoPersistForm()
}

watch(
    () => selectedDriveFolderId.value,
    async (folderId) => {
        selectedDriveFileId.value = ''
        await loadDriveFiles(folderId)
    }
)

watch(
    () => solution.type,
    (t) => {
        clearErrors()
        formMsg.value = ''

        if (t === 'FORM') {
            solution.mediaType ||= 'external'
            solution.externalMediaProvider ||= 'google_drive'
            if (driveFolders.value.length === 0 && !driveFoldersLoading.value) {
                loadDriveFolders()
            }
        } else {
            selectedDriveFolderId.value = ''
            selectedDriveFileId.value = ''
            driveFiles.value = []

            // FORM-Felder sauber resetten
            solution.mediaType = null
            solution.externalMediaProvider = null
            solution.externalMediaUrl = ''
            solution.externalMediaId = ''
        }
    },
    { immediate: true }
)

// neuer Code: Montag der KW automatisch als publishedAt setzen
watch(
    () => [solution.category, solution.newsletterYear, solution.newsletterKw],
    ([cat, y, kw]) => {
        if (cat !== 'NEWSLETTER') {
            solution.publishedAt = null
            return
        }

        const year = Number(y)
        const week = Number(kw)

        if (!year || !week || week < 1 || week > 53) {
            solution.publishedAt = null
            return
        }

        // ISO Woche: Montag berechnen (UTC, damit es keine TZ-Verschiebung gibt)
        const simple = new Date(Date.UTC(year, 0, 1))
        const dayOfWeek = simple.getUTCDay() || 7
        const isoWeek1Monday = new Date(simple)
        isoWeek1Monday.setUTCDate(simple.getUTCDate() - dayOfWeek + 1)

        const targetMonday = new Date(isoWeek1Monday)
        targetMonday.setUTCDate(isoWeek1Monday.getUTCDate() + (week - 1) * 7)

        // API Platform akzeptiert i.d.R. ISO-String
        solution.publishedAt = targetMonday.toISOString()
    },
    { immediate: true }
)


// Optional: wenn URL manuell geändert wird -> autosave (nur wenn bereits angelegt)
let urlSaveTimer = null
watch(
    () => solution.externalMediaUrl,
    () => {
        if (solution.type !== 'FORM') return
        if (!createdSolutionId.value) return

        if (urlSaveTimer) clearTimeout(urlSaveTimer)
        urlSaveTimer = setTimeout(() => {
            // nur speichern wenn URL wirklich gesetzt ist
            if ((solution.externalMediaUrl ?? '').trim() !== '') {
                autoPersistForm()
            }
        }, 600)
    }
)

async function createSolution() {
    clearErrors()
    saving.value = true

    try {
        const isForm = solution.type === 'FORM'
        const title = (solution.title ?? '').trim()

        if (!title) {
            errors.solution = 'Titel noch nicht vergeben.'
            return
        }

        // SOP braucht Symptome
        if (!isForm && !(solution.symptoms ?? '').trim()) {
            errors.solution = 'Bei SOP sind Symptome Pflicht.'
            return
        }

        // FORM braucht ein Formular (URL oder Auswahl)
        if (isForm) {
            const url = (solution.externalMediaUrl ?? '').trim()
            if (url === '') {
                errors.form = 'Bitte erst Formular auswählen.'
                return
            }
        }

        const payload = {
            type: solution.type,
            title: solution.title,
            symptoms: (solution.symptoms ?? '').trim() === '' ? null : solution.symptoms,
            contextNotes: (solution.contextNotes ?? '').trim() === '' ? null : solution.contextNotes,
            priority: solution.priority ?? 0,
            active: solution.active ?? true,

            // Newsletter Code
            category: solution.type === 'FORM' ? (solution.category ?? 'GENERAL') : 'GENERAL',
            newsletterYear: solution.category === 'NEWSLETTER' ? (solution.newsletterYear ?? null) : null,
            newsletterKw: solution.category === 'NEWSLETTER' ? (solution.newsletterKw ?? null) : null,
            newsletterEdition: solution.category === 'NEWSLETTER' ? (solution.newsletterEdition ?? 'STANDARD') : null,
            publishedAt: solution.category === 'NEWSLETTER' ? (solution.publishedAt ?? null) : null,


            // FORM-Felder
            mediaType: isForm ? (solution.mediaType ?? 'external') : null,
            externalMediaProvider: isForm ? (solution.externalMediaProvider ?? 'google_drive') : null,
            externalMediaUrl: isForm ? (solution.externalMediaUrl || null) : null,
            externalMediaId: isForm ? (solution.externalMediaId || null) : null,
        }

        // ✅ WICHTIG: Wenn bereits angelegt -> PATCH statt POST
        if (createdSolutionId.value) {
            await axios.patch(
                `/api/support_solutions/${createdSolutionId.value}`,
                payload,
                { headers: { 'Content-Type': 'application/merge-patch+json' } }
            )
            return
        }

        // ✅ sonst neu anlegen -> POST
        const res = await axios.post('/api/support_solutions', payload, {
            headers: { 'Content-Type': 'application/ld+json' },
        })

        const iri = res.data?.['@id'] || ''
        const id = iri ? iri.split('/').pop() : (res.data?.id ?? null)
        createdSolutionId.value = id

        // ✅ Nach erstem Speichern direkt in Edit wechseln
        if (id) {
            router.push(`/kb/edit/${id}`)
        }
    } catch (e) {
        error.value = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Speichern'
    } finally {
        saving.value = false
    }
}


function addKeywordRow() {
    keywords.value.push({ keyword: '', weight: 5 })
}

function removeKeywordRow(idx) {
    if (keywords.value.length <= 1) return
    keywords.value.splice(idx, 1)
}

async function saveKeywords() {
    clearErrors()
    if (!createdSolutionId.value) {
        errors.keywords = 'Bitte zuerst SupportSolution speichern.'
        return
    }
    savingKeywords.value = true
    try {
        const cleaned = keywords.value
            .map(k => ({ keyword: (k.keyword ?? '').trim(), weight: k.weight ?? 5 }))
            .filter(k => k.keyword.length > 0)

        for (const k of cleaned) {
            await axios.post(
                '/api/support_solution_keywords',
                {
                    solution: `/api/support_solutions/${createdSolutionId.value}`,
                    keyword: k.keyword,
                    weight: k.weight,
                },
                { headers: { 'Content-Type': 'application/ld+json' } }
            )
        }

        kwMsg.value = '✅ Keywords gespeichert'
    } catch (e) {
        errors.keywords = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Speichern der Keywords'
    } finally {
        savingKeywords.value = false
    }
}

function addStepRow() {
    const nextNo = steps.value.length ? Math.max(...steps.value.map(s => s.stepNo)) + 1 : 1
    steps.value.push({ stepNo: nextNo, instruction: '', expectedResult: '', nextIfFailed: '' })
}

function removeStepRow(idx) {
    if (steps.value.length <= 1) return
    steps.value.splice(idx, 1)
}

async function saveSteps() {
    clearErrors()
    if (!createdSolutionId.value) {
        errors.steps = 'Bitte zuerst SupportSolution speichern.'
        return
    }
    savingSteps.value = true
    try {
        const cleaned = steps.value
            .map(s => ({
                stepNo: s.stepNo ?? 1,
                instruction: (s.instruction ?? '').trim(),
                expectedResult: (s.expectedResult ?? '').trim() || null,
                nextIfFailed: (s.nextIfFailed ?? '').trim() || null,
            }))
            .filter(s => s.instruction.length > 0)
            .sort((a, b) => a.stepNo - b.stepNo)

        for (const s of cleaned) {
            await axios.post(
                '/api/support_solution_steps',
                {
                    solution: `/api/support_solutions/${createdSolutionId.value}`,
                    stepNo: s.stepNo,
                    instruction: s.instruction,
                    expectedResult: s.expectedResult,
                    nextIfFailed: s.nextIfFailed,
                },
                { headers: { 'Content-Type': 'application/ld+json' } }
            )
        }

        stMsg.value = '✅ Steps gespeichert'
    } catch (e) {
        errors.steps = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Speichern der Steps'
    } finally {
        savingSteps.value = false
    }
}
</script>

<style scoped>
.page { max-width: 1200px; margin: 0 auto; padding: 20px; }
.row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.top { justify-content: space-between; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.card { border: 1px solid #eee; border-radius: 16px; padding: 16px; background: #fff; }
.card.full { grid-column: 1 / -1; }
.label { display:block; margin: 10px 0 6px; font-weight: 700; }
.input { padding: 10px; border: 1px solid #ddd; border-radius: 10px; width: 100%; }
.input.small { width: 90px; }
.textarea { padding: 10px; border: 1px solid #ddd; border-radius: 10px; width: 100%; min-height: 80px; }
.btn { padding: 10px 14px; border: 1px solid #ddd; border-radius: 10px; background: #fff; cursor: pointer; text-decoration: none; }
.btn.primary { background: #111; color: #fff; border-color: #111; }
.btn.danger { border-color: #ffb3b3; color: #a10000; }
.error { color: #b00020; margin-top: 10px; }
.ok { color: #087f23; margin-top: 10px; }
.kwRow { display: grid; grid-template-columns: 1fr 110px 60px; gap: 10px; align-items: center; }
.stepCard { border: 1px dashed #ddd; border-radius: 14px; padding: 12px; margin: 10px 0; }
.hint { color:#666; font-size: 13px; }
@media (max-width: 1000px) {
    .grid { grid-template-columns: 1fr; }
}
</style>
