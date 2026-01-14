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
/* KB links */
.kb-item a{
    color:#111;
    text-decoration: none;
    font-weight: 650;
}
.kb-item a:hover{
    text-decoration: underline;
    text-underline-offset: 3px;
}
/* Optional: Actions etwas luftiger */
.kb-actions{
    display:flex;
    gap:10px;
    margin-top:8px;
}

/* Buttons */
.btn{
    appearance: none;
    border: 1px solid #111;
    background: #111;
    color: #fff;
    border-radius: 999px;
    padding: 9px 14px;
    font-weight: 650;
    cursor: pointer;
    transition: transform .05s ease, background .15s ease, border-color .15s ease, opacity .15s ease;
    line-height: 1;
}
.btn:hover{ background:#000; }
.btn:active{ transform: translateY(1px); }
.btn:disabled{ opacity:.55; cursor:not-allowed; }

.btn.small{
    padding: 7px 12px;
    font-size: 13px;
}

/* Ghost-Variante */
.btn.ghost{
    background: transparent;
    color: #111;
    border-color: #bbb;
}
.btn.ghost:hover{
    border-color:#111;
    background: rgba(0,0,0,.03);
}



/* "Steps API" Link wie Button */
a.btn{
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.stepsBox { margin-top: 12px; padding: 10px; border: 1px dashed #ddd; border-radius: 10px; background: #fff; }
.stepsTitle { font-weight: 700; margin-bottom: 6px; }
.stepsList { margin: 0; padding-left: 18px; }
.stepText{
    font-size: 15px;
    line-height: 1.5;
}
.stepMedia { margin-left: 6px; }
</style>
