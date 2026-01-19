<template>
    <!-- Avatar/Offer EINMAL über dem Chat -->
    <slot name="avatar" />

    <div class="chat">
        <div v-for="(m, idx) in messages" :key="idx" class="msg" :class="m.role">
            <div class="role">{{ roleLabel(m.role) }}:</div>

            <div class="content">
                <pre v-if="!m.contactCard" class="pre">{{ m.content }}</pre>

                <!-- Kontaktkarte: schön formatiert -->
                <div v-if="m.contactCard" class="contactCard">
                    <div class="contactTitle">
                        <span v-if="m.contactCard.type === 'branch'">🏬</span>
                        <span v-else>👤</span>

                        <strong v-if="m.contactCard.type === 'branch'">
                            {{ m.contactCard.data.filialenId }} – {{ (m.contactCard.data.filialenNr || '') }} – {{ (m.contactCard.data.anschrift || '') }}
                        </strong>

                        <strong v-else>
                            {{ (m.contactCard.data.first_name || '') }} {{ (m.contactCard.data.last_name || '') }}
                        </strong>
                    </div>

                    <!-- Filiale -->
                    <div v-if="m.contactCard.type === 'branch'" class="contactGrid">
                        <div class="row" v-if="m.contactCard.data.strasse || m.contactCard.data.plz || m.contactCard.data.ort">
                            <div class="k">📍 Adresse</div>
                            <div class="v">
                                {{ m.contactCard.data.strasse || '' }}
                                <span v-if="m.contactCard.data.plz || m.contactCard.data.ort">, {{ m.contactCard.data.plz || '' }} {{ m.contactCard.data.ort || '' }}</span>
                                <span v-if="m.contactCard.data.zusatz"> ({{ m.contactCard.data.zusatz }})</span>
                            </div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.telefon">
                            <div class="k">☎️ Telefon</div>
                            <div class="v">
                                <a :href="`tel:${String(m.contactCard.data.telefon).replace(/\\s+/g,'')}`">{{ m.contactCard.data.telefon }}</a>
                            </div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.email">
                            <div class="k">✉️ E-Mail</div>
                            <div class="v">
                                <a :href="`mailto:${m.contactCard.data.email}`">{{ m.contactCard.data.email }}</a>
                            </div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.gln">
                            <div class="k">🏷️ GLN</div>
                            <div class="v">{{ m.contactCard.data.gln }}</div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.ecTerminalId">
                            <div class="k">💳 EC-Terminal</div>
                            <div class="v">{{ m.contactCard.data.ecTerminalId }}</div>
                        </div>
                    </div>

                    <!-- Person / Dienstleister -->
                    <div v-else class="contactGrid">
                        <div class="row" v-if="m.contactCard.data.company">
                            <div class="k">🏢 Firma</div>
                            <div class="v">{{ m.contactCard.data.company }}</div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.department || m.contactCard.data.location">
                            <div class="k">🧩 Bereich</div>
                            <div class="v">
                                {{ m.contactCard.data.department || '' }}
                                <span v-if="m.contactCard.data.department && m.contactCard.data.location"> – </span>
                                {{ m.contactCard.data.location || '' }}
                            </div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.role">
                            <div class="k">🎯 Rolle</div>
                            <div class="v">{{ m.contactCard.data.role }}</div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.address">
                            <div class="k">📍 Adresse</div>
                            <div class="v">{{ m.contactCard.data.address }}</div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.service_hours">
                            <div class="k">🕒 Servicezeiten</div>
                            <div class="v">{{ m.contactCard.data.service_hours }}</div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.phone_mobile">
                            <div class="k">📱 Mobil</div>
                            <div class="v">
                                <a :href="`tel:${String(m.contactCard.data.phone_mobile).replace(/\\s+/g,'')}`">{{ m.contactCard.data.phone_mobile }}</a>
                            </div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.phone_landline">
                            <div class="k">☎️ Festnetz</div>
                            <div class="v">
                                <a :href="`tel:${String(m.contactCard.data.phone_landline).replace(/\\s+/g,'')}`">{{ m.contactCard.data.phone_landline }}</a>
                            </div>
                        </div>

                        <div class="row" v-if="!m.contactCard.data.phone_mobile && !m.contactCard.data.phone_landline && m.contactCard.data.phone">
                            <div class="k">☎️ Telefon</div>
                            <div class="v">
                                <a :href="`tel:${String(m.contactCard.data.phone).replace(/\\s+/g,'')}`">{{ m.contactCard.data.phone }}</a>
                            </div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.email">
                            <div class="k">✉️ E-Mail</div>
                            <div class="v">
                                <a :href="`mailto:${m.contactCard.data.email}`">{{ m.contactCard.data.email }}</a>
                            </div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.notes">
                            <div class="k">📝 Notiz</div>
                            <div class="v">{{ m.contactCard.data.notes }}</div>
                        </div>
                    </div>
                </div>

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
/**
 * ChatMessages.vue
 * Fix:
 * - declare props (messages, roleLabel)
 * - declare emits (db-only, contact-selected)
 */

const props = defineProps({
    messages: {
        type: Array,
        required: true,
        default: () => [],
    },
    roleLabel: {
        type: Function,
        required: false,
        default: (r) => String(r ?? ''),
    },
})

const emit = defineEmits([
    'db-only',
    'contact-selected',
])

/**
 * Optional helper, falls du Buttons o.ä. im Template hast:
 * emit('db-only', solutionId)
 * emit('contact-selected', payload)
 */
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
.kb-item a{ color:#111; text-decoration: none; font-weight: 650; }
.kb-item a:hover{ text-decoration: underline; text-underline-offset: 3px; }
.kb-actions{ display:flex; gap:10px; margin-top:8px; }

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
.btn.small{ padding: 7px 12px; font-size: 13px; }
.btn.ghost{ background: transparent; color: #111; border-color: #bbb; }
.btn.ghost:hover{ border-color:#111; background: rgba(0,0,0,.03); }
a.btn{ display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }

.stepsBox { margin-top: 12px; padding: 10px; border: 1px dashed #ddd; border-radius: 10px; background: #fff; }
.stepsTitle { font-weight: 700; margin-bottom: 6px; }
.stepsList { margin: 0; padding-left: 18px; }
.stepText{ font-size: 15px; line-height: 1.5; }
.stepMedia { margin-left: 6px; }

.contactCard{
    margin-top: 10px;
    border: 1px solid #e6e6e6;
    border-radius: 12px;
    padding: 12px 14px;
    background: #fff;
}
.contactTitle{
    display:flex;
    align-items:center;
    gap:10px;
    font-size: 16px;
    margin-bottom: 10px;
}
.contactGrid .row{
    display:grid;
    grid-template-columns: 140px 1fr;
    gap: 10px;
    padding: 6px 0;
    border-top: 1px dashed #f0f0f0;
}
.contactGrid .row:first-child{ border-top: none; }
.contactGrid .k{ font-weight: 700; color: #111; }
.contactGrid .v{ color: #222; }
.contactGrid a{ color: #0f172a; text-decoration: underline; text-underline-offset: 3px; }
</style>
