<template>
    <div class="composer">

        <!-- Newsletter Tools Gate -->
        <div class="newsletter-tools-slot">
            <div v-if="!newsletterToolsUnlocked" class="newsletter-tools-gate">
                <button
                    type="button"
                    class="gate-link"
                    @click.prevent="openNewsletterTools"
                >
                    Newsletter-Tools öffnen
                </button>
            </div>

            <div v-else class="newsletter-tools">
                <ChatNewsletterUpload
                    :fileName="fileName"
                    :driveUrl="driveUrl"
                    @file-selected="(f) => emit('file-selected', f)"
                    @file-cleared="() => emit('file-cleared')"
                    @update:driveUrl="(v) => emit('update:driveUrl', v)"
                />

            <div style="display:flex; gap:10px; align-items:center; margin: 0 0 10px;">
                   <label style="font-weight:700; font-size:14px;">Kategorie</label>
                   <select
                       class="select"
                       :value="category"
                       @change="emit('update:category', $event.target.value)"
                   >
                       <option value="GENERAL">GENERAL (Dokument)</option>
                       <option value="NEWSLETTER">NEWSLETTER</option>
                   </select>
            </div>

                <button type="button" class="btn ghost" @click="lockNewsletterTools">
                    Schließen
                </button>
            </div>
        </div>


        <!-- PIN Modal -->
        <div v-if="showPinPrompt" class="pin-modal-backdrop" @click.self="cancelPin">
            <div class="pin-modal">
                <div class="pin-title">PIN erforderlich</div>

                <input
                    v-model="pinInput"
                    inputmode="numeric"
                    maxlength="4"
                    placeholder="4-stellige PIN"
                    @keydown.enter.prevent="confirmPin"
                    class="pin-input"
                    autocomplete="one-time-code"
                />

                <div v-if="pinError" class="pin-error">{{ pinError }}</div>

                <div class="pin-actions">
                    <button type="button" class="btn ghost" @click="cancelPin">Abbrechen</button>
                    <button type="button" class="btn" @click="confirmPin">OK</button>
                </div>
            </div>
        </div>

        <!-- Hint (grüner Pfeil + Text) -->
        <div v-if="showHint" class="composer-hint">
            <span class="arrow">➜</span>
            <span class="hint-text">Hier starten, wobei können wir dir helfen? Du kannst auch enfach "Tipps" eingeben für Anwendungsbeispele</span>
        </div>

        <!-- Eingabe + Senden -->
        <div class="row">
            <input
                ref="inputEl"
                class="input"
                :class="{ attention: pulse }"
                :placeholder="placeholderText"
                :disabled="disabled"
                :value="modelValue"
                @input="emit('update:modelValue', $event.target.value)"
                @focus="consumeAttention"
                @keydown.enter.prevent="onSubmit"
            />

            <button
                class="btn"
                type="button"
                :disabled="disabled || !String(modelValue).trim()"
                @click="onSubmit"
            >
                Senden
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import ChatNewsletterUpload from '@/components/chat/ChatNewsletterUpload.vue'

const props = defineProps({
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },

    showAttention: { type: Boolean, default: true },
    attentionKey: { type: Number, default: 0 },

    placeholder: { type: String, default: '' },

    // Newsletter Tools (vom Parent)
    driveUrl: { type: String, default: '' },
    fileName: { type: String, default: '' },
    category: { type: String, default: 'GENERAL' },
})

const emit = defineEmits([
    'update:modelValue',
    'submit',
    'attention-consumed',

    // Newsletter Tools passthrough
    'update:driveUrl',
    'file-selected',
    'file-cleared',
    'update:category',
])

const ignoreNextFocus = ref(false)

/**
 * Attention UI
 */
const inputEl = ref(null)
const showHint = ref(false)
const pulse = ref(false)

const placeholderText = computed(() => {
    return props.placeholder || 'Beschreibe hier dein Problem (z. B. „Drucker druckt nicht“) Oder gebe "Tipps" ein für Nutzerhinweise'
})

function triggerAttention() {
    console.log('triggerAttention', { showAttention: props.showAttention, attentionKey: props.attentionKey })
    if (!props.showAttention) return
    showHint.value = true
    pulse.value = true
    window.setTimeout(() => (pulse.value = false), 2200)
}

function consumeAttention() {
    if (ignoreNextFocus.value) {
        ignoreNextFocus.value = false
        return
    }
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

    // 👇 wir fokussieren automatisch – diesen Focus nicht als "User hat gelesen" werten
    ignoreNextFocus.value = true
    inputEl.value?.focus?.()
})

watch(
    () => props.attentionKey,
    async () => {
        await nextTick()
        triggerAttention()

        // 👇 wichtig: Programmatic focus beim "New Chat" nicht als User-Aktion werten
        ignoreNextFocus.value = true
        inputEl.value?.focus?.()
    }
)

watch(
    () => props.modelValue,
    (v) => {
        if (showHint.value && String(v || '').length > 0) {
            showHint.value = false
        }
    }
)

/**
 * Newsletter PIN Gate (UI-only)
 */
const newsletterToolsUnlocked = ref(false)
const showPinPrompt = ref(false)
const pinInput = ref('')
const pinError = ref('')

function openNewsletterTools() {
    showPinPrompt.value = true
    pinInput.value = ''
    pinError.value = ''
}

function cancelPin() {
    showPinPrompt.value = false
    pinInput.value = ''
    pinError.value = ''
}

function confirmPin() {
    const EXPECTED = '2014'
    if (pinInput.value.trim() === EXPECTED) {
        newsletterToolsUnlocked.value = true
        showPinPrompt.value = false
    } else {
        pinError.value = 'PIN ist falsch.'
    }
}

function lockNewsletterTools() {
    newsletterToolsUnlocked.value = false

    // optional: beim Schließen direkt resetten
    emit('file-cleared')
    emit('update:driveUrl', '')
}
</script>

<style scoped>
.composer { margin-top: 14px; }

.row { display: flex; gap: 12px; align-items: center; }

.composer-hint {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 8px 2px;
    color: #16a34a;
    font-weight: 750;
}

.arrow { font-size: 22px; line-height: 1; }
.hint-text { font-size: 14px; }


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
.input.attention { animation: focusPulse 2s ease-out 1; }

/* Button */
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
.btn.ghost{ background:transparent; color:#111; border-color:#bbb; }
.btn.ghost:hover{ border-color:#111; background: rgba(0,0,0,.03); }

/* Abstand zwischen Newsletter-Link und Hint */
.newsletter-tools-slot {
    margin: 12px 0 22px; /* ⬅️ größerer Abstand nach unten */
}

/* Gate-Link Styling */
.newsletter-tools-gate {
    display: flex;
    align-items: center;
}

.gate-link {
    appearance: none;
    background: transparent;
    border: none;
    padding: 0;

    color: #111;              /* schwarz */
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;

    text-decoration: none;    /* kein Unterstrich */
}

.gate-link:hover {
    text-decoration: underline; /* optional: nur bei Hover */
}

/* PIN Modal */
.pin-modal-backdrop{
    position:fixed; inset:0; background:rgba(0,0,0,.35);
    display:flex; align-items:center; justify-content:center; z-index:9999;
}
.pin-modal{
    width:320px; background:#fff; padding:16px;
    border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.2);
}
.pin-title{ font-weight: 750; margin-bottom: 10px; }
.pin-input{
    width:100%;
    border:1px solid #d1d5db;
    border-radius:12px;
    padding:10px 12px;
}
.pin-error{ color:#b00020; margin-top:8px; }
.pin-actions{ display:flex; gap:8px; justify-content:flex-end; margin-top:12px; }

.select{
    border: 1px solid #bbb;
    border-radius: 999px;
    padding: 10px 12px;
    background: #fff;
}

</style>
