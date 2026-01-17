<template>
    <div v-if="suggestions.length">
        <div>Mehrere Treffer – bitte auswählen:</div>

        <div>
            <button
                v-for="m in suggestions"
                :key="m.id"
                type="button"
                @click="select(m)"
            >
                {{ m.label }}
            </button>

            <button type="button" @click="sendWithout">
                Ohne Auswahl senden
            </button>
        </div>
    </div>
</template>

<script setup>
import axios from 'axios'
import { ref } from 'vue'

const emit = defineEmits([
    'resolved',     // { text, context }
    'fallback',    // { text }
])

const props = defineProps({
    text: { type: String, required: true },
    disabled: { type: Boolean, default: false },
})

const suggestions = ref([])
const resolveType = ref(null)
const pendingText = ref('')

async function resolve() {
    if (!props.text || props.disabled) return

    pendingText.value = props.text

    try {
        const { data } = await axios.post('/api/contact/resolve', {
            query: props.text,
            limit: 5,
        })

        const matches = Array.isArray(data?.matches) ? data.matches : []
        resolveType.value = data?.type ?? null

        if (matches.length === 0) {
            emit('fallback', { text: props.text })
            reset()
            return
        }

        if (matches.length === 1) {
            emit('resolved', {
                text: props.text,
                context: { resolved_contact: { type: resolveType.value, id: matches[0].id } },
            })
            reset()
            return
        }

        suggestions.value = matches
    } catch (e) {
        console.error('resolve error:', e)
        emit('fallback', { text: props.text })
        reset()
    }
}

function select(m) {
    emit('resolved', {
        text: pendingText.value,
        context: { resolved_contact: { type: resolveType.value, id: m.id } },
    })
    reset()
}

function sendWithout() {
    emit('fallback', { text: pendingText.value })
    reset()
}

function reset() {
    suggestions.value = []
    resolveType.value = null
    pendingText.value = ''
}

defineExpose({ resolve })
</script>
