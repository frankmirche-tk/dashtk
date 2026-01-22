<template>
    <div class="node">
        <div class="row">
            <button
                v-if="hasChildren"
                class="toggle"
                @click="open = !open"
                :title="open ? 'zuklappen' : 'aufklappen'"
            >
                {{ open ? '▾' : '▸' }}
            </button>

            <span v-else class="dot">•</span>

            <span class="name">{{ node.label }}</span>

            <span v-if="showDurations && node.duration_ms !== null" class="dur">
        {{ node.duration_ms }} ms
      </span>
        </div>

        <div v-if="showMeta && hasMeta" class="meta">
            <pre>{{ JSON.stringify(node.meta, null, 2) }}</pre>
        </div>

        <div v-if="open && hasChildren" class="children">
            <TraceTreeNode
                v-for="c in node.children"
                :key="c.id"
                :node="c"
                :show-meta="showMeta"
                :show-durations="showDurations"
            />
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'

const props = defineProps({
    node: { type: Object, required: true },
    showMeta: { type: Boolean, default: false },
    showDurations: { type: Boolean, default: true },
})

const open = ref(true)
const hasChildren = computed(() => Array.isArray(props.node.children) && props.node.children.length > 0)
const hasMeta = computed(() => props.node.meta && Object.keys(props.node.meta).length > 0)
</script>

<style scoped>
.node { margin-left: 10px; }
.row { display:flex; align-items:center; gap: 8px; padding: 3px 0; }
.toggle { width: 26px; text-align:center; background: none; border: none; cursor: pointer; font-size: 14px; }
.dot { width: 26px; text-align:center; opacity: .7; }
.name { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
.dur { margin-left: auto; opacity: .8; font-family: ui-monospace, monospace; }
.children { margin-left: 16px; border-left: 1px dashed #ddd; padding-left: 10px; }
.meta pre { margin: 6px 0 10px; background:#fafafa; padding: 8px; border-radius: 8px; border: 1px solid #eee; overflow:auto; }
</style>
