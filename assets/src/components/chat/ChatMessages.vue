<template>
    <!-- Avatar/Offer EINMAL über dem Chat -->
    <slot name="avatar" />

    <div class="chat">
        <div v-for="(m, idx) in messages" :key="idx" class="msg" :class="m.role">
            <div class="role">{{ roleLabel(m.role) }}:</div>

            <div class="content">
                <pre class="pre">{{ m.content }}</pre>

                <!-- Treffer aus KB -->
                <div v-if="m.role === 'assistant' && m.matches?.length" class="kb">
                    <div class="kb-title">Passende SOPs aus der Datenbank:</div>
                    <ul class="kb-list">
                        <li v-for="hit in m.matches" :key="hit.id" class="kb-item">
                            <a :href="hit.url" target="_blank" rel="noreferrer">
                                {{ hit.title }} (Score {{ hit.score }})
                            </a>

                            <div class="kb-actions">
                                <button class="btn small" @click="$emit('db-only', hit.id)">
                                    Nur Steps
                                </button>
                                <a class="btn small ghost" :href="hit.stepsUrl" target="_blank" rel="noreferrer">
                                    Steps API
                                </a>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Steps inkl. Media -->
                <div v-if="m.steps?.length" class="stepsBox">
                    <div class="stepsTitle">Schritte:</div>
                    <ol class="stepsList">
                        <li v-for="s in m.steps" :key="s.id || s.no">
                            <span class="stepText">{{ s.text }}</span>

                            <span v-if="s.mediaUrl" class="stepMedia">
                —
                <a :href="s.mediaUrl" target="_blank" rel="noreferrer">
                  {{ s.mediaMimeType === 'application/pdf' ? 'PDF Hilfe' : 'Bildhilfe' }}
                </a>
              </span>
                        </li>
                    </ol>
                </div>

            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    messages: { type: Array, required: true },
    roleLabel: { type: Function, required: true },
})

defineEmits(['db-only'])
</script>

<style scoped>
.chat { border: 1px solid #ddd; border-radius: 12px; padding: 16px; min-height: 360px; background: #fff; }
.msg { display: grid; grid-template-columns: 110px 1fr; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f1f1f1; }
.msg:last-child { border-bottom: none; }
.role { font-weight: 700; color: #333; }
.pre { white-space: pre-wrap; margin: 0; font-family: inherit; }

.kb { margin-top: 10px; padding: 10px; border: 1px dashed #aaa; border-radius: 10px; background: #fafafa; }
.kb-title { font-weight: 700; margin-bottom: 6px; }
.kb-list { margin: 0; padding-left: 18px; }
.kb-item { margin: 6px 0; }
.kb-actions { display: flex; gap: 8px; margin-top: 6px; }

.btn { border: 1px solid #111; background:#111; color:#fff; border-radius: 10px; padding: 10px 14px; cursor: pointer; }
.btn.small { padding: 6px 10px; border-radius: 8px; font-size: 13px; }
.btn.ghost { background: transparent; color: #111; }

.stepsBox { margin-top: 12px; padding: 10px; border: 1px dashed #ddd; border-radius: 10px; background: #fff; }
.stepsTitle { font-weight: 700; margin-bottom: 6px; }
.stepsList { margin: 0; padding-left: 18px; }
.stepText { }
.stepMedia { margin-left: 6px; }
</style>
