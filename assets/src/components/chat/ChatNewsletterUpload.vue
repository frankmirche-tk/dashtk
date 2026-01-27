<template>
    <div class="uploadWrap">
        <div class="uploadRow">
            <div class="label">File upload</div>

            <input
                class="file"
                type="file"
                accept="application/pdf"
                @change="onFile"
            />

            <input
                class="drive"
                :value="driveUrl"
                @input="$emit('update:driveUrl', $event.target.value)"
                placeholder="Google-Drive-Link (Ordner) – optional, sonst fragt der Bot nach"
                autocomplete="off"
            />

            <button v-if="fileName" class="btn ghost" type="button" @click="clear">
                Entfernen
            </button>
        </div>

        <div v-if="fileName" class="fileInfo">
            📎 {{ fileName }}
        </div>
    </div>
</template>

<script setup>
const props = defineProps({
    driveUrl: { type: String, default: '' },
    fileName: { type: String, default: '' },
})

const emit = defineEmits(['file-selected', 'file-cleared', 'update:driveUrl'])

function onFile(e) {
    const f = e.target.files?.[0] ?? null
    if (!f) return
    emit('file-selected', f)
}

function clear() {
    emit('file-cleared')
}
</script>

<style scoped>
.uploadWrap{ margin-top: 10px; }
.uploadRow{ display:flex; gap:10px; align-items:center; }
.label{ font-weight: 750; color:#111; white-space: nowrap; }
.file{ max-width: 220px; }
.drive{
    flex:1;
    border: 1px solid #d1d5db;
    border-radius: 12px;
    padding: 10px 12px;
    font-size: 14px;
}
.fileInfo{ margin-top: 6px; opacity:.8; font-size: 13px; }

.btn{
    appearance:none;
    border:1px solid #111;
    background:#111;
    color:#fff;
    border-radius:999px;
    padding: 10px 14px;
    font-weight:650;
    cursor:pointer;
}
.btn.ghost{ background:transparent; color:#111; border-color:#bbb; }
.btn.ghost:hover{ border-color:#111; background: rgba(0,0,0,.03); }
</style>
