<template>
    <div class="page">
        <TracePanel v-if="traceId" :trace-id="traceId" />
        <div v-else class="muted">Trace-ID fehlt.</div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import TracePanel from '@/components/trace/TracePanel.vue'

const route = useRoute()
const traceId = computed(() => String(route.params.traceId || ''))
</script>

<style scoped>
.page { padding: 16px; }
.muted { opacity: 0.7; }
</style>
