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

                <div class="row" style="align-items:flex-end; gap:14px;">
                    <div style="min-width: 220px;">
                        <label>Typ</label>
                        <select class="input" v-model="form.type">
                            <option value="SOP">SOP (mit Steps)</option>
                            <option value="FORM">FORM (externes Formular / Dokument)</option>
                        </select>
                    </div>

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

                <label>Titel</label>
                <input class="input" v-model="form.title" />

                <label>
                    Symptome
                    <span v-if="form.type === 'FORM'" class="hint">(optional)</span>
                </label>
                <textarea class="textarea" v-model="form.symptoms"></textarea>

                <label>Kontext / Notizen</label>
                <textarea class="textarea" v-model="form.contextNotes"></textarea>

                <div v-if="savedMsg" class="ok">{{ savedMsg }}</div>
            </section>

            <!-- FORM -->
            <section class="card" v-if="form.type === 'FORM'">
                <h2>2) Formular</h2>

                <div class="hint">
                    Hier hinterlegst du den Link zu einem externen Formular/Dokument (z.B. Google Drive).
                    In Phase 1 reicht „URL + Provider + optional File-ID“.
                </div>

                <div class="row" style="gap:14px; align-items:flex-end; margin-top:10px;">
                    <div style="min-width: 240px;">
                        <label>Media Type</label>
                        <select class="input" v-model="form.mediaType">
                            <option value="external">external (Link)</option>
                            <option value="internal" disabled>internal (Upload) – später</option>
                        </select>
                    </div>

                    <div style="min-width: 240px;">
                        <label>Provider</label>
                        <select class="input" v-model="form.externalMediaProvider">
                            <option value="google_drive">google_drive</option>
                        </select>
                    </div>



                </div>

                <!-- Ordner -->
                <div class="row" style="align-items:flex-end; gap:14px; margin-top:12px;">
                    <div style="flex:1">
                        <label>Google Drive Ordner (optional)</label>
                        <select
                            class="input"
                            v-model="selectedFolderId"
                            :disabled="foldersLoading || driveFolders.length === 0"
                            @change="onPickFolder"
                        >
                            <option value="">
                                {{ foldersLoading ? 'Lade Ordner…' : '— bitte auswählen —' }}
                            </option>
                            <option v-for="f in driveFolders" :key="f.id" :value="f.id">
                                {{ f.name }} ({{ f.mimeType }})
                            </option>
                        </select>

                        <div class="hint" style="margin-top:6px;">
                            Quelle: <code>/api/forms/google-drive/folders</code> (Root: GOOGLE_DRIVE_FOLDER_ID)
                        </div>
                    </div>



                    <button class="btn" @click="loadDriveFolders" :disabled="foldersLoading">
                        Ordner aktualisieren
                    </button>
                </div>

                <!-- Dateien -->
                <div class="row" style="align-items:flex-end; gap:14px; margin-top:12px;">
                    <div style="flex:1">
                        <label>Google Drive Datei (optional)</label>
                        <select
                            class="input"
                            v-model="selectedFileId"
                            :disabled="filesLoading || driveFiles.length === 0"
                            @change="onPickDriveFile"
                        >
                            <option value="">
                                {{ filesLoading ? 'Lade Dateien…' : '— bitte auswählen —' }}
                            </option>
                            <option v-for="f in driveFiles" :key="f.id" :value="f.id">
                                {{ f.name }} ({{ f.mimeType }})
                            </option>
                        </select>

                        <div class="hint" style="margin-top:6px;">
                            Quelle:
                            <code>/api/forms/google-drive?folderId={{ selectedFolderId || '(root)' }}</code>
                        </div>
                    </div>

                    <button class="btn" @click="loadDriveFiles" :disabled="filesLoading">
                        Dateien aktualisieren
                    </button>
                </div>

                <label>Externe URL</label>
                <input class="input" v-model="form.externalMediaUrl" placeholder="https://drive.google.com/file/d/.../view" />

                <label>Externe ID (optional)</label>
                <input class="input" v-model="form.externalMediaId" placeholder="z.B. Google Drive fileId" />

                <button
                    class="btn primary"
                    @click="swapFormFromSelection"
                    :disabled="savingForm || (!selectedFileId && !form.externalMediaUrl)"
                    title="Übernimmt die aktuell ausgewählte Drive-Datei (oder die URL im Feld) und speichert"
                >
                    Formular tauschen
                </button>

                <button
                    class="btn danger"
                    v-if="form.externalMediaUrl || form.externalMediaId"
                    @click="removeForm"
                    :disabled="savingForm"
                >
                    Entfernen
                </button>

                <div v-if="formMsg" class="ok">{{ formMsg }}</div>
                <div class="row" style="margin-top:10px;">
                    <a
                        v-if="form.externalMediaUrl"
                        class="btn"
                        :href="form.externalMediaUrl"
                        target="_blank"
                        rel="noreferrer"
                    >
                        ↗ Link öffnen
                    </a>

                    <div class="hint">Tipp: Für In-App Preview später <code>/preview</code> nutzen.</div>
                </div>
            </section>

            <!-- Keywords -->
            <section class="card">
                <h2>3) Keywords</h2>

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

            <!-- Steps (nur SOP) -->
            <section class="card" v-if="form.type !== 'FORM'">
                <h2>4) Steps</h2>

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

                    <!-- Media Upload -->
                    <div class="mediaBox">
                        <div class="mediaRow">
                            <div class="mediaLeft">
                                <div class="mediaTitle">Media (optional)</div>

                                <div v-if="st.mediaUrl" class="mediaExisting">
                                    <a :href="st.mediaUrl" target="_blank" rel="noreferrer">
                                        Vorhandene Datei öffnen
                                        <span v-if="st.mediaMimeType">({{ st.mediaMimeType }})</span>
                                    </a>
                                    <div class="mediaMeta" v-if="st.mediaOriginalName">
                                        {{ st.mediaOriginalName }}
                                    </div>
                                </div>

                                <div v-else class="mediaMeta">
                                    Keine Datei hinterlegt.
                                </div>
                            </div>

                            <div class="mediaRight">
                                <input
                                    type="file"
                                    class="file"
                                    accept="image/png,image/jpeg,image/webp,image/gif,application/pdf,video/mp4"
                                    @change="onPickFile(st, $event)"
                                />

                                <div class="row" style="margin-top: 8px;">
                                    <button class="btn primary"
                                            :disabled="!st._pendingFile || uploadingStepId === st.id"
                                            @click="doUpload(st)">
                                        {{ st.mediaUrl ? 'Austauschen' : 'Upload' }}
                                    </button>

                                    <button class="btn danger"
                                            v-if="st.mediaUrl"
                                            :disabled="uploadingStepId === st.id"
                                            @click="removeMedia(st)">
                                        Entfernen
                                    </button>
                                </div>

                                <div v-if="uploadingStepId === st.id" class="mediaMeta">
                                    Upload läuft…
                                </div>
                            </div>
                        </div>

                        <div class="mediaHint">
                            Erlaubt: PNG/JPG/WEBP/GIF, PDF, MP4. Empfehlung: kurze GIFs oder PDF für längere Anleitungen.
                        </div>
                    </div>
                </div>

                <div v-if="stMsg" class="ok">{{ stMsg }}</div>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import { uuid } from '../utils/uuid'

const route = useRoute()
const id = computed(() => route.params.id)

const loading = ref(true)
const error = ref('')

const saving = ref(false)
const savingForm = ref(false)
const savingKeywords = ref(false)
const savingSteps = ref(false)

const savedMsg = ref('')
const formMsg = ref('')
const kwMsg = ref('')
const stMsg = ref('')

const uploadingStepId = ref(null)

// Google Drive (Edit)
const driveFolders = ref([])   // [{id,name,mimeType}]
const driveFiles = ref([])     // [{id,name,mimeType,webViewLink}]
const foldersLoading = ref(false)
const filesLoading = ref(false)
const selectedFolderId = ref('')
const selectedFileId = ref('')

const form = ref({
    type: 'SOP',
    title: '',
    symptoms: '',
    contextNotes: '',
    priority: 0,
    active: true,

    // FORM fields
    mediaType: null,
    externalMediaProvider: null,
    externalMediaUrl: '',
    externalMediaId: '',
})

const keywords = ref([]) // [{id?, keyword, weight, _key}]
const steps = ref([])    // [{id?, stepNo, instruction, expectedResult, nextIfFailed, mediaUrl?, mediaMimeType?, mediaOriginalName?, _key, _pendingFile?}]

function key() { return uuid() }

/** ===== Google Drive helpers ===== */
async function loadDriveFolders() {
    foldersLoading.value = true
    try {
        const { data } = await axios.get('/api/forms/google-drive/folders')
        driveFolders.value = Array.isArray(data?.folders) ? data.folders : []
    } catch (e) {
        driveFolders.value = []
    } finally {
        foldersLoading.value = false
    }
}

async function loadDriveFiles() {
    filesLoading.value = true
    try {
        const params = {}
        if (selectedFolderId.value) params.folderId = selectedFolderId.value
        const { data } = await axios.get('/api/forms/google-drive', { params })
        driveFiles.value = Array.isArray(data?.files) ? data.files : []

        // falls beim Edit schon eine ID gesetzt ist -> preselect
        const currentId = (form.value.externalMediaId || '').trim()
        if (currentId && driveFiles.value.some(f => f.id === currentId)) {
            selectedFileId.value = currentId
        }
    } catch (e) {
        driveFiles.value = []
    } finally {
        filesLoading.value = false
    }
}

function onPickFolder() {
    selectedFileId.value = ''
    // bei Ordnerwechsel Liste neu laden
    loadDriveFiles()
}

function onPickDriveFile() {
    const f = driveFiles.value.find(x => x.id === selectedFileId.value)
    if (!f) return

    form.value.mediaType = 'external'
    form.value.externalMediaProvider = 'google_drive'
    form.value.externalMediaUrl = f.webViewLink ?? ''
    form.value.externalMediaId = f.id ?? ''
}

/** ===== Load all ===== */
async function loadAll() {
    loading.value = true
    error.value = ''
    try {
        const sol = await axios.get(`/api/support_solutions/${id.value}`)
        form.value = {
            type: sol.data.type ?? 'SOP',
            title: sol.data.title ?? '',
            symptoms: sol.data.symptoms ?? '',
            contextNotes: sol.data.contextNotes ?? sol.data.context_notes ?? '',
            priority: sol.data.priority ?? 0,
            active: sol.data.active ?? true,

            mediaType: sol.data.mediaType ?? null,
            externalMediaProvider: sol.data.externalMediaProvider ?? null,
            externalMediaUrl: sol.data.externalMediaUrl ?? '',
            externalMediaId: sol.data.externalMediaId ?? '',
        }

        // Keywords
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

        // Steps (nur SOP sinnvoll)
        if (form.value.type !== 'FORM') {
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
                    mediaUrl: s.mediaUrl ?? null,
                    mediaMimeType: s.mediaMimeType ?? null,
                    mediaOriginalName: s.mediaOriginalName ?? null,
                    _pendingFile: null,
                    _key: key(),
                }))
                .sort((a, b) => a.stepNo - b.stepNo)
        } else {
            steps.value = []
        }

        // Google Drive: wenn FORM -> Ordner/Dateien vorbereiten
        if (form.value.type === 'FORM' && (form.value.externalMediaProvider || 'google_drive') === 'google_drive') {
            if (driveFolders.value.length === 0) await loadDriveFolders()
            await loadDriveFiles()
        }

    } catch (e) {
        error.value = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Laden'
    } finally {
        loading.value = false
    }
}

/** ===== Save base solution ===== */
async function saveSolution() {
    saving.value = true
    savedMsg.value = ''
    try {
        await axios.patch(
            `/api/support_solutions/${id.value}`,
            {
                type: form.value.type,
                title: form.value.title,
                symptoms: (form.value.symptoms ?? '').trim() === '' ? null : form.value.symptoms,
                contextNotes: (form.value.contextNotes ?? '').trim() === '' ? null : form.value.contextNotes,
                priority: form.value.priority,
                active: form.value.active,
            },
            { headers: { 'Content-Type': 'application/merge-patch+json' } }
        )
        savedMsg.value = '✅ Stammdaten gespeichert'

        // wenn auf FORM umgestellt wurde -> Drive laden
        if (form.value.type === 'FORM') {
            if (driveFolders.value.length === 0) await loadDriveFolders()
            await loadDriveFiles()
        }
    } catch (e) {
        error.value = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Speichern'
    } finally {
        saving.value = false
    }
}

/** ===== Save form fields ===== */
async function saveForm() {
    savingForm.value = true
    formMsg.value = ''
    try {
        await axios.patch(
            `/api/support_solutions/${id.value}`,
            {
                mediaType: form.value.mediaType ?? 'external',
                externalMediaProvider: form.value.externalMediaProvider ?? 'google_drive',
                externalMediaUrl: (form.value.externalMediaUrl ?? '').trim() === '' ? null : form.value.externalMediaUrl,
                externalMediaId: (form.value.externalMediaId ?? '').trim() === '' ? null : form.value.externalMediaId,
            },
            { headers: { 'Content-Type': 'application/merge-patch+json' } }
        )
        formMsg.value = '✅ Formular-Link gespeichert'
    } catch (e) {
        error.value = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Speichern des Formulars'
    } finally {
        savingForm.value = false
    }
}

async function swapFormFromSelection() {
    // Wenn eine Drive-Datei gewählt ist -> Felder übernehmen
    if (selectedFileId.value) {
        const f = driveFiles.value.find(x => x.id === selectedFileId.value)
        if (f) {
            form.value.mediaType = 'external'
            form.value.externalMediaProvider = 'google_drive'
            form.value.externalMediaUrl = f.webViewLink ?? ''
            form.value.externalMediaId = f.id ?? ''
        }
    } else {
        // sonst: User hat manuell URL/ID eingegeben -> nur Defaults setzen
        form.value.mediaType ||= 'external'
        form.value.externalMediaProvider ||= 'google_drive'
    }

    await saveForm()
}

/** ===== Formulare ändern/Tauschen/entfernn ===== */
async function removeForm() {
    savingForm.value = true
    formMsg.value = ''
    try {
        await axios.patch(
            `/api/support_solutions/${id.value}`,
            {
                mediaType: null,
                externalMediaProvider: null,
                externalMediaUrl: null,
                externalMediaId: null,
            },
            { headers: { 'Content-Type': 'application/merge-patch+json' } }
        )

        // UI leeren
        form.value.mediaType = null
        form.value.externalMediaProvider = null
        form.value.externalMediaUrl = ''
        form.value.externalMediaId = ''
        selectedFileId.value = ''

        formMsg.value = '✅ Formular-Link entfernt'
    } catch (e) {
        error.value = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Entfernen'
    } finally {
        savingForm.value = false
    }
}


/** ===== Keywords ===== */
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
        const cleaned = keywords.value
            .map(k => ({ ...k, keyword: (k.keyword ?? '').trim() }))
            .filter(k => k.keyword.length > 0)

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

        keywords.value = cleaned.map(k => ({ ...k }))
        kwMsg.value = '✅ Keywords gespeichert'
    } catch (e) {
        error.value = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Speichern der Keywords'
    } finally {
        savingKeywords.value = false
    }
}

/** ===== Steps ===== */
function addStepRow() {
    const nextNo = steps.value.length ? Math.max(...steps.value.map(s => s.stepNo)) + 1 : 1
    steps.value.push({
        id: null,
        stepNo: nextNo,
        instruction: '',
        expectedResult: '',
        nextIfFailed: '',
        mediaUrl: null,
        mediaMimeType: null,
        mediaOriginalName: null,
        _pendingFile: null,
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
                const iri = res.data?.['@id'] || ''
                const newId = iri ? iri.split('/').pop() : (res.data?.id ?? null)
                s.id = newId
            }
        }

        await loadAll()
        stMsg.value = '✅ Steps gespeichert'
    } catch (e) {
        error.value = e?.response?.data?.detail ?? e?.message ?? 'Fehler beim Speichern der Steps'
    } finally {
        savingSteps.value = false
    }
}

/** ===== Media Upload (multipart/form-data) ===== */
function onPickFile(step, ev) {
    const file = ev.target.files?.[0] ?? null
    step._pendingFile = file
}

async function doUpload(step) {
    if (!step.id || !step._pendingFile) return

    uploadingStepId.value = step.id
    try {
        const fd = new FormData()
        fd.append('file', step._pendingFile)

        const { data } = await axios.post(`/api/support_solution_steps/${step.id}/media`, fd, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })

        step.mediaUrl = data.mediaUrl ?? step.mediaUrl
        step.mediaMimeType = data.mediaMimeType ?? step.mediaMimeType
        step.mediaOriginalName = data.mediaOriginalName ?? step.mediaOriginalName
        step._pendingFile = null
    } catch (e) {
        error.value = e?.response?.data?.error ?? e?.response?.data?.detail ?? e?.message ?? 'Upload fehlgeschlagen'
    } finally {
        uploadingStepId.value = null
    }
}

async function removeMedia(step) {
    if (!step.id) return
    uploadingStepId.value = step.id
    try {
        await axios.delete(`/api/support_solution_steps/${step.id}/media`)
        step.mediaUrl = null
        step.mediaMimeType = null
        step.mediaOriginalName = null
        step._pendingFile = null
    } catch (e) {
        error.value = e?.response?.data?.error ?? e?.response?.data?.detail ?? e?.message ?? 'Löschen fehlgeschlagen'
    } finally {
        uploadingStepId.value = null
    }
}

/** Wenn der User im Edit den Typ umstellt -> Drive-Listen laden */
watch(
    () => form.value.type,
    async (t) => {
        if (t === 'FORM') {
            form.value.mediaType ||= 'external'
            form.value.externalMediaProvider ||= 'google_drive'
            if (driveFolders.value.length === 0) await loadDriveFolders()
            await loadDriveFiles()
        }
    }
)

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

.mediaBox { margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee; }
.mediaRow { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; align-items: start; }
.mediaTitle { font-weight: 700; margin-bottom: 6px; }
.mediaExisting a { color: #111; text-decoration: underline; }
.mediaMeta { font-size: 12px; color: #666; margin-top: 6px; }
.mediaHint { font-size: 12px; color: #666; margin-top: 10px; }
.file { width: 100%; }

.hint { color:#666; font-size: 13px; }

@media (max-width: 900px) {
    .mediaRow { grid-template-columns: 1fr; }
}
</style>
