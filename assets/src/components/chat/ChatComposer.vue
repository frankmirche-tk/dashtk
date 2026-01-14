<template>
    <form class="composer" @submit.prevent="onSubmit">
        <input
            v-model="localValue"
            class="input"
            placeholder="Schreib dein Problem…"
            autocomplete="off"
        />
        <button class="btn" type="submit" :disabled="disabled || !localValue.trim()">
            Senden
        </button>
    </form>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'submit'])

const localValue = ref(props.modelValue)

watch(() => props.modelValue, v => (localValue.value = v))
watch(localValue, v => emit('update:modelValue', v))

function onSubmit() {
    emit('submit', localValue.value)
}
</script>
<style scoped>
.composer { display: flex; gap: 10px; margin-top: 14px; }
.input { flex: 1; border: 1px solid #ccc; border-radius: 10px; padding: 12px; font-size: 16px; }
.btn { border: 1px solid #111; background:#111; color:#fff; border-radius: 10px; padding: 10px 14px; cursor: pointer; }
.btn:disabled { opacity: .6; cursor: not-allowed; }
</style>
