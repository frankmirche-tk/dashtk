<template>
    <div class="composer">
        <!-- Hint (grüner Pfeil + Text) -->
        <div v-if="showHint" class="composer-hint">
            <span class="arrow">➜</span>
            <span class="hint-text">Hier starten, wobei können wir dir helfen?</span>
        </div>

        <div class="row">
            <input
                ref="inputEl"
                class="input"
                :class="{ attention: pulse }"
                :disabled="disabled"
                :value="modelValue"
                :placeholder="placeholderText"
                autocomplete="off"
                @input="$emit('update:modelValue', $event.target.value)"
                @keydown.enter.prevent="onSubmit()"
            />

            <button class="btn" type="button" :disabled="disabled || !String(modelValue).trim()" @click="onSubmit()">
                Senden
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue'

const props = defineProps({
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },

    // Steuerung von außen (ChatView):
    showAttention: { type: Boolean, default: true }, // hint + pulse anzeigen?
    attentionKey: { type: Number, default: 0 },      // erhöht sich bei "Neuer Chat" -> triggert erneut

    placeholder: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'submit', 'attention-consumed'])

const inputEl = ref(null)
const showHint = ref(false)
const pulse = ref(false)

const placeholderText = computed(() => {
    return props.placeholder || 'Beschreibe hier dein Problem (z. B. „Drucker druckt nicht“)'
})

function triggerAttention() {
    if (!props.showAttention) return

    showHint.value = true
    pulse.value = true

    // Pulse nach kurzem Zeitpunkt wieder entfernen (Animation läuft einmal)
    window.setTimeout(() => (pulse.value = false), 2200)
}

function consumeAttention() {
    showHint.value = false
    pulse.value = false
    emit('attention-consumed')
}

function onSubmit() {
    const text = String(props.modelValue || '').trim()
    if (!text || props.disabled) return

    consumeAttention()
    emit('submit', text)
}

onMounted(async () => {
    await nextTick()
    triggerAttention()
    // Optional: direkt Fokus setzen (sehr hilfreich)
    inputEl.value?.focus?.()
})

watch(
    () => props.attentionKey,
    async () => {
        await nextTick()
        triggerAttention()
        inputEl.value?.focus?.()
    }
)

// Wenn der User anfängt zu tippen, kann man den Hint direkt ausblenden
watch(
    () => props.modelValue,
    (v) => {
        if (showHint.value && String(v || '').length > 0) {
            showHint.value = false
        }
    }
)
</script>

<style scoped>
.composer {
    margin-top: 14px;
}

.row {
    display: flex;
    gap: 12px;
    align-items: center;
}

.composer-hint {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 8px 2px;
    color: #16a34a; /* grün, business */
    font-weight: 750;
}

.arrow {
    font-size: 22px;
    line-height: 1;
}

.hint-text {
    font-size: 14px;
}

/* Input */
.input {
    flex: 1;
    border: 1px solid #d1d5db;
    border-radius: 14px;
    padding: 14px 16px;
    font-size: 16px;
    background: #fff;
    outline: none;
    transition: box-shadow .15s ease, border-color .15s ease;
}

.input:focus {
    border-color: #111;
    box-shadow: 0 0 0 4px rgba(0,0,0,.06);
}

@keyframes focusPulse {
    0%   { box-shadow: 0 0 0 0 rgba(34,197,94,.55); border-color: #22c55e; }
    70%  { box-shadow: 0 0 0 10px rgba(34,197,94,0); border-color: #22c55e; }
    100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); border-color: #d1d5db; }
}

.input.attention {
    animation: focusPulse 2s ease-out 1;
}

/* Button: übernimmt deine Optik */
.btn{
    appearance: none;
    border: 1px solid #111;
    background: #111;
    color: #fff;
    border-radius: 999px;
    padding: 12px 18px;
    font-weight: 650;
    cursor: pointer;
    transition: transform .05s ease, background .15s ease, border-color .15s ease, opacity .15s ease;
    line-height: 1;
    white-space: nowrap;
}
.btn:hover{ background:#000; }
.btn:active{ transform: translateY(1px); }
.btn:disabled{ opacity:.55; cursor:not-allowed; }
</style>
