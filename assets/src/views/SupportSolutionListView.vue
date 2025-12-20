<template>
    <div class="page">
        <h1>SupportSolutions</h1>

        <div class="row">
            <input v-model="q" class="input" placeholder="Suchen (Titel/Symptome)..." />
            <button class="btn" @click="load">Suchen</button>
            <router-link class="btn" to="/kb/new">+ Neu</router-link>
        </div>

        <div v-if="loading">Lade…</div>
        <div v-if="error" class="error">{{ error }}</div>

        <div class="list" v-if="items.length">
            <div class="card" v-for="s in items" :key="s.id">
                <div class="title">{{ s.title }}</div>
                <div class="muted">{{ s.symptoms }}</div>

                <div class="row">
                    <router-link class="btn" :to="`/kb/${s.id}`">Bearbeiten</router-link>
                    <a class="btn" :href="`/api/support_solutions/${s.id}`" target="_blank">API</a>
                </div>
            </div>
        </div>

        <div v-else-if="!loading" class="muted">Keine Einträge gefunden.</div>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import axios from 'axios'

const q = ref('')
const loading = ref(false)
const error = ref('')
const items = ref([])

async function load() {
    loading.value = true
    error.value = ''
    try {
        // Standard: API Platform SearchFilter müsste bei SupportSolution konfiguriert sein.
        // Falls du bereits SearchFilter aktiv hast: ?title=... oder ?symptoms=...
        // Einfacher universaler Start: hole erst mal collection und filter clientseitig (für kleine Daten ok).
        const res = await axios.get('/api/support_solutions')

        const raw = res.data['member'] ?? res.data
        const query = q.value.trim().toLowerCase()

        items.value = !query
            ? raw
            : raw.filter(x =>
                (x.title ?? '').toLowerCase().includes(query) ||
                (x.symptoms ?? '').toLowerCase().includes(query)
            )
    } catch (e) {
        error.value = e?.message ?? 'Fehler beim Laden'
    } finally {
        loading.value = false
    }
}

load()
</script>

<style scoped>
.page { max-width: 1100px; margin: 0 auto; padding: 20px; }
.row { display: flex; gap: 10px; align-items: center; margin: 10px 0; flex-wrap: wrap; }
.input { padding: 10px; border: 1px solid #ddd; border-radius: 10px; min-width: 280px; }
.btn { padding: 10px 14px; border: 1px solid #ddd; border-radius: 10px; text-decoration: none; background: #fff; cursor: pointer; }
.card { border: 1px solid #eee; border-radius: 14px; padding: 14px; margin: 10px 0; }
.title { font-weight: 700; font-size: 18px; }
.muted { color: #666; }
.error { color: #b00020; }
</style>
