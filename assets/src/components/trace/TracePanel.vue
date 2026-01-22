<!-- assets/src/components/trace/TracePanel.vue -->
<template>
    <div class="panel">
        <div class="toolbar">
            <div class="left">
                <strong>Trace:</strong> <span class="mono">{{ traceId }}</span>
            </div>

            <div class="right">
                <button class="btn" @click="mode = 'flow'" :class="{ active: mode === 'flow' }">Flow</button>
                <button class="btn" @click="mode = 'debug'" :class="{ active: mode === 'debug' }">Debug</button>

                <label class="toggle">
                    <input type="checkbox" v-model="showMeta" />
                    Meta
                </label>
            </div>
        </div>

        <div v-if="loading" class="muted">lade…</div>
        <div v-else-if="error" class="error">{{ error }}</div>

        <div v-else>
            <TraceTreeNode v-for="r in roots" :key="r.id" :node="r" :show-meta="showMeta" />
            <div v-if="!roots.length" class="muted">Keine Tree-Daten (roots=0).</div>
        </div>
    </div>
</template>

<script setup>
import axios from 'axios'
import { ref, watch } from 'vue'
import TraceTreeNode from './TraceTreeNode.vue'
import { buildTree } from './traceTree'

const props = defineProps({
    traceId: { type: String, required: true },
})

const loading = ref(false)
const error = ref('')
const mode = ref('flow') // flow | debug
const showMeta = ref(false)
const roots = ref([])

async function load() {
    if (!props.traceId) return

    loading.value = true
    error.value = ''

    const url = `/api/ai-traces/${props.traceId}`
    console.log('[TracePanel] loading', { url, mode: mode.value })

    try {
        const { data } = await axios.get(url)
        console.log('[TracePanel] loaded 200', data)

        const tree = buildTree(data, { mode: mode.value })
        console.log('[TracePanel] tree len=', tree?.length, tree)

        roots.value = tree
    } catch (e) {
        console.error('[TracePanel] load failed', e)
        error.value = 'Konnte Trace nicht laden.'
        roots.value = []
    } finally {
        loading.value = false
    }
}

watch(() => props.traceId, load, { immediate: true })
watch(mode, load)
</script>

<style scoped>
.panel { padding: 12px; }
.toolbar { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
.btn {
    padding: 6px 10px;
    border: 1px solid rgba(0,0,0,0.25);
    background: white;
    border-radius: 6px;
    cursor: pointer;
}
.btn.active { border-color: rgba(0,0,0,0.6); font-weight: 700; }
.toggle { display:flex; align-items:center; gap:6px; margin-left:10px; }
.muted { opacity: 0.7; margin-top: 8px; }
.error { color: #b00020; }
</style>
