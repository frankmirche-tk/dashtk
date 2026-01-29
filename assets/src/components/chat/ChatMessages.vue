<template>
    <slot name="avatar" />

    <div class="chat">
        <div v-for="(m, idx) in messages" :key="idx" class="msg" :class="m.role">
            <div class="role">{{ roleLabel(m.role) }}:</div>

            <div class="content">
                <!-- Normaler Chat-Text (nur wenn keine Karten aktiv sind) -->
                <div v-if="!m.contactCard && !m.formCard && !m.newsletterConfirmCard" class="pre">
                    <template v-for="(p, pi) in linkifyParts(m.content)" :key="pi">
                        <span v-if="p.type === 'text'">{{ p.value }}</span>
                        <a
                            v-else-if="p.type === 'link'"
                            :href="p.value"
                            target="_blank"
                            rel="noreferrer"
                        >
                            {{ p.label || p.value }}
                        </a>
                    </template>
                </div>

                <!-- Kontaktkarte -->
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
                                <a :href="`tel:${String(m.contactCard.data.telefon).replace(/\s+/g,'')}`">{{ m.contactCard.data.telefon }}</a>
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
                                <a :href="`tel:${String(m.contactCard.data.phone_mobile).replace(/\s+/g,'')}`">{{ m.contactCard.data.phone_mobile }}</a>
                            </div>
                        </div>

                        <div class="row" v-if="m.contactCard.data.phone_landline">
                            <div class="k">☎️ Festnetz</div>
                            <div class="v">
                                <a :href="`tel:${String(m.contactCard.data.phone_landline).replace(/\s+/g,'')}`">{{ m.contactCard.data.phone_landline }}</a>
                            </div>
                        </div>

                        <div class="row" v-if="!m.contactCard.data.phone_mobile && !m.contactCard.data.phone_landline && m.contactCard.data.phone">
                            <div class="k">☎️ Telefon</div>
                            <div class="v">
                                <a :href="`tel:${String(m.contactCard.data.phone).replace(/\s+/g,'')}`">{{ m.contactCard.data.phone }}</a>
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

                <!-- ✅ Newsletter Confirm Card (preview ODER fields) -->
                <div v-if="newsletterPreview(m)" class="contactCard">
                    <div class="contactTitle">
                        ✅ <strong>Newsletter-Insert – Bitte bestätigen</strong>
                    </div>

                    <div class="contactGrid">
                        <div class="row">
                            <div class="k">📌 Title</div>
                            <div class="v">{{ newsletterPreview(m).title }}</div>
                        </div>

                        <div class="row">
                            <div class="k">🗓️ Jahr / KW</div>
                            <div class="v">
                                {{ newsletterPreview(m).newsletter_year }} / {{ newsletterPreview(m).newsletter_kw }}
                            </div>
                        </div>

                        <div class="row">
                            <div class="k">🕒 created_at</div>
                            <div class="v">{{ newsletterPreview(m).created_at }}</div>
                        </div>

                        <div class="row">
                            <div class="k">🕒 updated_at</div>
                            <div class="v">{{ newsletterPreview(m).updated_at }}</div>
                        </div>

                        <div class="row">
                            <div class="k">📅 published_at</div>
                            <div class="v">{{ newsletterPreview(m).published_at }}</div>
                        </div>

                        <div class="row">
                            <div class="k">🔗 Drive</div>
                            <div class="v">
                                <a
                                    :href="newsletterPreview(m).drive_url || newsletterPreview(m).external_media_url"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    {{ newsletterPreview(m).drive_url || newsletterPreview(m).external_media_url }}
                                </a>
                                <div style="opacity:.8;font-size:13px;margin-top:4px;">
                                    ID: {{ newsletterPreview(m).drive_id || newsletterPreview(m).external_media_id }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="kb-actions" style="margin-top:10px;">
                        <button
                            class="btn small"
                            @click="$emit('newsletter-confirm', m.newsletterConfirmCard.draft_id || m.newsletterConfirmCard.draftId)"
                        >
                            OK einfügen
                        </button>
                        <button
                            class="btn small ghost"
                            @click="$emit('newsletter-edit', m.newsletterConfirmCard.draft_id || m.newsletterConfirmCard.draftId)"
                        >
                            Änderungen senden (per Chat)
                        </button>
                    </div>

                    <div v-if="m.newsletterConfirmCard.sqlPreview" class="stepsBox" style="margin-top:12px;">
                        <div class="stepsTitle">SQL Vorschau:</div>
                        <div class="pre">{{ m.newsletterConfirmCard.sqlPreview }}</div>
                    </div>
                </div>

                <!-- ✅ Fallback: Card existiert, aber preview/fields fehlen -->
                <div v-else-if="m.newsletterConfirmCard" class="contactCard">
                    <div class="contactTitle">
                        ⏳ <strong>Newsletter-Insert – Daten werden geladen…</strong>
                    </div>
                    <div class="pre" style="opacity:.8">
                        Draft-ID: {{ m.newsletterConfirmCard.draftId || m.newsletterConfirmCard.draft_id }}
                    </div>
                </div>
                <!-- ✅ Fallback: Card existiert, aber Preview ist noch nicht da -->
                <div v-else-if="m.newsletterConfirmCard" class="contactCard">
                    <div class="contactTitle">
                        ⏳ <strong>Newsletter-Insert – Daten werden geladen…</strong>
                    </div>
                    <div class="pre" style="opacity:.8">
                        Draft-ID: {{ m.newsletterConfirmCard.draftId || m.newsletterConfirmCard.draft_id }}
                    </div>
                </div>
                <!-- ✅ formCard -->
                <div v-if="m.formCard" class="contactCard">
                    <div class="contactTitle">
                        📄 <strong>{{ m.formCard.title }}</strong>
                    </div>

                    <div class="contactGrid">
                        <div class="row" v-if="m.formCard.updatedAt">
                            <div class="k">🕒 Stand</div>
                            <div class="v">{{ m.formCard.updatedAt }}</div>
                        </div>

                        <div class="row" v-if="m.formCard.symptoms">
                            <div class="k">📝 Hinweis</div>
                            <div class="v">
                                <template v-for="(p, pi) in linkifyParts(m.formCard.symptoms)" :key="pi">
                                    <span v-if="p.type === 'text'">{{ p.value }}</span>
                                    <a
                                        v-else-if="p.type === 'link'"
                                        :href="p.value"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        {{ p.label || p.value }}
                                    </a>
                                </template>
                            </div>
                        </div>

                        <div class="row" v-if="m.formCard.provider">
                            <div class="k">🔌 Provider</div>
                            <div class="v">{{ m.formCard.provider }}</div>
                        </div>

                        <div class="row" v-if="m.formCard.url">
                            <div class="k">🔗 Vorschau</div>
                            <div class="v">
                                <a :href="m.formCard.url" target="_blank" rel="noreferrer">Formular öffnen</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ✅ Choices klickbar (Newsletter / Formulare getrennt) -->
                <div v-if="m.role === 'assistant' && m.choices?.length">
                    <!-- Newsletter -->
                    <div v-if="groupedChoices(m).newsletters.length" class="kb">
                        <div class="kb-title">Newsletter:</div>
                        <ul class="kb-list">
                            <li v-for="x in groupedChoices(m).newsletters" :key="x.idx" class="kb-item">
                                <div class="kb-item-main">
                                    <div class="kb-item-title">
                                        {{ x.idx + 1 }}) {{ x.choice.label }}
                                    </div>

                                    <div v-if="x.choice.payload?.symptoms" class="kb-item-sub">
                                        ↳
                                        <template v-for="(p, pi) in linkifyParts(x.choice.payload.symptoms)" :key="pi">
                                            <span v-if="p.type === 'text'">{{ p.value }}</span>
                                            <a v-else-if="p.type === 'link'" :href="p.value" target="_blank" rel="noreferrer">
                                                {{ p.label || p.value }}
                                            </a>
                                        </template>
                                    </div>
                                </div>

                                <div class="kb-actions">
                                    <button class="btn small" @click="$emit('choose', x.idx + 1)">Öffnen</button>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- Formulare -->
                    <div v-if="groupedChoices(m).forms.length" class="kb">
                        <div class="kb-title">Formulare:</div>
                        <ul class="kb-list">
                            <li v-for="x in groupedChoices(m).forms" :key="x.idx" class="kb-item">
                                <div class="kb-item-main">
                                    <div class="kb-item-title">
                                        {{ x.idx + 1 }}) {{ x.choice.label }}
                                    </div>

                                    <div v-if="x.choice.payload?.symptoms" class="kb-item-sub">
                                        ↳
                                        <template v-for="(p, pi) in linkifyParts(x.choice.payload.symptoms)" :key="pi">
                                            <span v-if="p.type === 'text'">{{ p.value }}</span>
                                            <a v-else-if="p.type === 'link'" :href="p.value" target="_blank" rel="noreferrer">
                                                {{ p.label || p.value }}
                                            </a>
                                        </template>
                                    </div>
                                </div>

                                <div class="kb-actions">
                                    <button class="btn small" @click="$emit('choose', x.idx + 1)">Öffnen</button>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- Optional: sonstige Auswahl (falls später neue kinds kommen) -->
                    <div v-if="groupedChoices(m).other.length" class="kb">
                        <div class="kb-title">Weitere Treffer:</div>
                        <ul class="kb-list">
                            <li v-for="x in groupedChoices(m).other" :key="x.idx" class="kb-item">
                                <div class="kb-item-main">
                                    <div class="kb-item-title">
                                        {{ x.idx + 1 }}) {{ x.choice.label }}
                                    </div>
                                </div>
                                <div class="kb-actions">
                                    <button class="btn small" @click="$emit('choose', x.idx + 1)">Öffnen</button>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- SOP Treffer -->
                <div v-if="m.role === 'assistant' && m.matches?.length" class="kb">
                    <div class="kb-title">Passende Schritt für Schritt Anleitungen:</div>
                    <ul class="kb-list">
                        <li v-for="hit in m.matches" :key="hit.id" class="kb-item">
                            <div class="kb-item-main">
                                <a :href="hit.url" target="_blank" rel="noreferrer">
                                    {{ hit.title }} (Score {{ hit.score }})
                                </a>
                            </div>

                            <div class="kb-actions">
                                <button class="btn small" @click="$emit('db-only', hit.id)">Nur Steps</button>
                                <a class="btn small ghost" :href="hit.stepsUrl" target="_blank" rel="noreferrer">Steps API</a>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Steps -->
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

defineEmits(['db-only', 'contact-selected', 'choose', 'newsletter-confirm', 'newsletter-edit'])


function normalizeStarList(text) {
    const s = String(text ?? '')

    return s
        .replace(/\r\n/g, '\n')

        // ✅ Markdown-Link über Zeilen zusammenziehen
        // [Text]\n(URL) -> [Text](URL)
        .replace(/\]\s*\n\s*\(/g, '](')

        // Sterne normalisieren
        .replace(/\s\*\s/g, '\n* ')
        .replace(/^\s*\*\s*/, '* ')
}



// Helferfunktion Links rendern: unterstützt [Label](URL) + normale URLs
function linkifyParts(text) {
    const s = normalizeStarList(text)
    const parts = []

    // 1) [Label](URL)
    const md = /\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g
    let last = 0
    let m

    while ((m = md.exec(s)) !== null) {
        const label = m[1]
        const url = m[2]
        const idx = m.index

        if (idx > last) parts.push({ type: 'text', value: s.slice(last, idx) })
        parts.push({ type: 'link', value: url, label })
        last = idx + m[0].length
    }

    const tail = s.slice(last)

    // 2) normale URLs im Rest (Satzzeichen am Ende abschneiden)
    const reUrl = /(https?:\/\/[^\s<]+[^\s<\.,;:!?"')\]])/g
    let last2 = 0
    let u

    while ((u = reUrl.exec(tail)) !== null) {
        const url = u[0]
        const idx = u.index

        if (idx > last2) parts.push({ type: 'text', value: tail.slice(last2, idx) })
        parts.push({ type: 'link', value: url, label: url })
        last2 = idx + url.length
    }

    if (last2 < tail.length) parts.push({ type: 'text', value: tail.slice(last2) })

    return parts
}

function newsletterPreview(m) {
    const nc = m?.newsletterConfirmCard
    if (!nc) return null
    return nc.preview || nc.fields || null
}

function groupedChoices(m) {
    const list = Array.isArray(m?.choices) ? m.choices : []
    const indexed = list.map((choice, idx) => ({ idx, choice }))

    const newsletters = indexed.filter(x =>
        x.choice?.kind === 'form' &&
        String(x.choice?.payload?.category || '').toUpperCase() === 'NEWSLETTER'
    )

    const forms = indexed.filter(x =>
        x.choice?.kind === 'form' &&
        String(x.choice?.payload?.category || '').toUpperCase() !== 'NEWSLETTER'
    )

    const other = indexed.filter(x =>
        x.choice?.kind !== 'form'
    )

    return { newsletters, forms, other }
}

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
.kb-item { display:flex; justify-content: space-between; gap: 16px; align-items: flex-start; margin: 10px 0; }
.kb-item a{ color:#111; text-decoration: none; font-weight: 650; }
.kb-item a:hover{ text-decoration: underline; text-underline-offset: 3px; }

.kb-item-main { flex: 1; min-width: 0; }
.kb-item-title { font-weight: 650; }
.kb-item-sub { margin-top: 6px; opacity: 0.8; font-size: 0.95em; line-height: 1.35; white-space: pre-wrap; }

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
.contactGrid .v{ color: #222;  white-space: pre-wrap; }
.contactGrid a{ color: #0f172a; text-decoration: underline; text-underline-offset: 3px; }
</style>
