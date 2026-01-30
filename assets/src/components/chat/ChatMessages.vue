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

                    <div v-if="m.newsletterConfirmCard?.sqlPreview" class="stepsBox" style="margin-top:12px;">
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

                <!-- ✅ Treffer-Gruppen: SOP -> Formulare -> Newsletter -->
                <div v-if="m.role === 'assistant' && hasAnyHits(m)" class="kbGroup">

                    <!-- 1) SOPs -->
                    <div v-if="sortedSops(m).length" class="kb">
                        <div class="kb-title">SOPs:</div>
                        <ul class="kb-list">
                            <li v-for="hit in sortedSops(m)" :key="hit._key" class="kb-item">
                                <div class="kb-item-main">
                                    <a :href="hit.url" target="_blank" rel="noreferrer">
                                        {{ hit.title }}
                                        <span v-if="hit.score !== null && hit.score !== undefined"> (Score {{ hit.score }})</span>
                                    </a>

                                    <div v-if="hit.symptoms" class="kb-item-sub">
                                        ↳
                                        <template v-for="(p, pi) in linkifyParts(hit.symptoms)" :key="pi">
                                            <span v-if="p.type === 'text'">{{ p.value }}</span>
                                            <a v-else-if="p.type === 'link'" :href="p.value" target="_blank" rel="noreferrer">
                                                {{ p.label || p.value }}
                                            </a>
                                        </template>
                                    </div>
                                </div>

                                <div class="kb-actions">
                                    <button v-if="hit.id" class="btn small" @click="$emit('db-only', hit.id)">Nur Steps</button>
                                    <a v-if="hit.stepsUrl" class="btn small ghost" :href="hit.stepsUrl" target="_blank" rel="noreferrer">Steps API</a>
                                    <button v-if="hit._choiceIndex !== null" class="btn small" @click="$emit('choose', hit._choiceIndex + 1)">Öffnen</button>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- 2) Formulare -->
                    <div v-if="sortedForms(m).length" class="kb">
                        <div class="kb-title">Formulare:</div>
                        <ul class="kb-list">
                            <li v-for="x in sortedForms(m)" :key="x.idx" class="kb-item">
                                <div class="kb-item-main">
                                    <div class="kb-item-title">{{ x.idx + 1 }}) {{ x.choice.label }}</div>

                                    <div v-if="x.choice.payload?.symptoms" class="kb-item-sub">
                                        ↳
                                        <template v-for="(p, pi) in linkifyParts(stripDriveBullets(x.choice.payload.symptoms))" :key="pi">
                                            <span v-if="p.type === 'text'">{{ p.value }}</span>
                                            <a v-else-if="p.type === 'link'" :href="p.value" target="_blank" rel="noreferrer">
                                                {{ p.label || p.value }}
                                            </a>
                                        </template>
                                    </div>

                                    <!-- optional: externer Link (Drive) falls vorhanden -->
                                    <div v-if="choiceDriveUrl(x)" class="kb-item-sub">
                                        ↳
                                        <a :href="choiceDriveUrl(x)" target="_blank" rel="noreferrer">
                                            {{ choiceDriveLabel(x) }}
                                        </a>
                                    </div>
                                </div>

                                <div class="kb-actions">
                                    <button class="btn small" @click="$emit('choose', x.idx + 1)">Öffnen</button>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- 3) Newsletter -->
                    <div v-if="sortedNewsletters(m).length" class="kb">
                        <div class="kb-title">Newsletter:</div>
                        <ul class="kb-list">
                            <li v-for="x in sortedNewsletters(m)" :key="x.idx" class="kb-item">
                                <div class="kb-item-main">
                                    <div class="kb-item-title">{{ x.idx + 1 }}) {{ x.choice.label }}</div>

                                    <div v-if="x.choice.payload?.symptoms" class="kb-item-sub">
                                        ↳
                                        <template v-for="(p, pi) in linkifyParts(stripDriveBullets(x.choice.payload.symptoms))" :key="pi">
                                            <span v-if="p.type === 'text'">{{ p.value }}</span>
                                            <a v-else-if="p.type === 'link'" :href="p.value" target="_blank" rel="noreferrer">
                                                {{ p.label || p.value }}
                                            </a>
                                        </template>
                                    </div>

                                    <!-- optional: externer Link (Drive) falls vorhanden -->
                                    <div v-if="choiceDriveUrl(x)" class="kb-item-sub">
                                        ↳
                                        <a :href="choiceDriveUrl(x)" target="_blank" rel="noreferrer">
                                            {{ choiceDriveLabel(x) }}
                                        </a>
                                    </div>
                                </div>

                                <div class="kb-actions">
                                    <button class="btn small" @click="$emit('choose', x.idx + 1)">Öffnen</button>
                                </div>
                            </li>
                        </ul>
                    </div>

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
        // ✅ Markdown-Link über Zeilen zusammenziehen: [Text]\n(URL) -> [Text](URL)
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

/**
 * ✅ Robust: erkennt Newsletter auch ohne category (nur label/titel),
 * trennt Formulare strikt davon, und liefert SOP-Links separat.
 */
function groupedChoices(m) {
    const list = Array.isArray(m?.choices) ? m.choices : []
    const indexed = list.map((choice, idx) => ({ idx, choice }))

    const getText = (x) => String(
        x?.choice?.label ??
        x?.choice?.title ??
        x?.choice?.payload?.title ??
        ''
    ).toLowerCase()

    const getCategory = (x) => String(
        x?.choice?.payload?.category ??
        x?.choice?.category ??
        x?.choice?.payload?.type ??
        ''
    ).toUpperCase()

    const getKind = (x) => String(
        x?.choice?.kind ??
        x?.choice?.type ??
        ''
    ).toLowerCase()

    // Heuristik: "newsletter" oder "kw 5/2026" etc.
    const looksLikeNewsletter = (x) => {
        const t = getText(x)
        const cat = getCategory(x)
        const kind = getKind(x)
        return (
            cat === 'NEWSLETTER' ||
            kind === 'newsletter' ||
            t.includes('newsletter') ||
            /kw\s*\d{1,2}\s*\/\s*\d{4}/i.test(t) ||
            /kw\s*\d{1,2}\s*-\s*\d{4}/i.test(t)
        )
    }

    const newsletters = indexed.filter(looksLikeNewsletter)

    // Formulare: alles was "form" ist, aber NICHT newsletter
    const forms = indexed.filter(x => {
        if (newsletters.some(n => n.idx === x.idx)) return false
        const cat = getCategory(x)
        const kind = getKind(x)
        // Kategorie vorhanden und nicht Newsletter oder Kind form
        return (cat !== '' && cat !== 'NEWSLETTER') || kind === 'form'
    })

    // SOP: alles übrige mit URL
    const sops = indexed
        .filter(x => !newsletters.some(n => n.idx === x.idx) && !forms.some(f => f.idx === x.idx))
        .map(x => {
            const url = x?.choice?.payload?.url || x?.choice?.url || ''
            const label = x?.choice?.label || x?.choice?.title || 'SOP'
            return { idx: x.idx, choice: x.choice, url, label }
        })
        .filter(x => String(x.url || '').startsWith('http'))

    return { newsletters, forms, sops }
}
function hasAnyHits(m) {
    return (Array.isArray(m?.matches) && m.matches.length) ||
        (Array.isArray(m?.choices) && m.choices.length)
}

function parseDateTs(v) {
    if (!v) return 0
    const s = String(v).trim()
    // akzeptiert "YYYY-MM-DD" und "YYYY-MM-DD HH:mm:ss"
    const iso = s.includes('T') ? s : s.replace(' ', 'T')
    const t = Date.parse(iso)
    return Number.isFinite(t) ? t : 0
}

function choiceTs(choice) {
    const p = choice?.payload || {}
    return Math.max(
        parseDateTs(p.updated_at),
        parseDateTs(p.published_at),
        parseDateTs(p.created_at),
        parseDateTs(choice?.updated_at),
        parseDateTs(choice?.published_at),
        parseDateTs(choice?.created_at),
    )
}

function newsletterRank(choice) {
    // Fallback falls keine Dates: Jahr/KW => sortierbar
    const p = choice?.payload || {}
    const y = Number(p.newsletter_year || p.year || 0)
    const kw = Number(p.newsletter_kw || p.kw || 0)
    if (y > 0 && kw > 0) return y * 100 + kw
    return 0
}

/**
 * SOPs: bevorzugt echte DB matches.
 * Falls SOPs als choices kommen: "other" mit url => SOP.
 */
function sortedSops(m) {
    const out = []

    // 1) matches (DB)
    const matches = Array.isArray(m?.matches) ? m.matches : []
    for (const hit of matches) {
        out.push({
            _key: 'm-' + String(hit.id ?? hit.url ?? Math.random()),
            id: hit.id ?? null,
            title: hit.title ?? 'SOP',
            url: hit.url ?? hit.stepsUrl ?? '#',
            stepsUrl: hit.stepsUrl ?? null,
            score: hit.score ?? null,
            symptoms: hit.symptoms ?? hit.snippet ?? null,
            external_media_url: hit.external_media_url ?? null,
            _ts: Math.max(parseDateTs(hit.updated_at), parseDateTs(hit.published_at), parseDateTs(hit.created_at)),
            _choiceIndex: null,
        })
    }

    // 2) fallback: choices-SOPs als SOP (wenn URL vorhanden)
    const gc = groupedChoices(m)
    const other = Array.isArray(gc?.sops) ? gc.sops : []
    for (const x of other) {
        const url = x?.url || x?.choice?.payload?.url || x?.choice?.url
        if (!url) continue

        // Heuristik: wenn category/kind eindeutig newsletter/form ist, NICHT als SOP nehmen
        const cat = String(x?.choice?.payload?.category || x?.choice?.category || '').toUpperCase()
        const kind = String(x?.choice?.kind || x?.choice?.type || '').toLowerCase()
        if (cat === 'NEWSLETTER' || kind === 'newsletter' || kind === 'form') continue

        out.push({
            _key: 'c-' + x.idx,
            id: null,
            title: x?.choice?.label || x?.choice?.title || 'SOP',
            url,
            stepsUrl: x?.choice?.payload?.stepsUrl || null,
            score: x?.choice?.payload?.score ?? null,
            symptoms: x?.choice?.payload?.symptoms ?? null,
            _ts: choiceTs(x.choice),
            _choiceIndex: x.idx,
        })
    }

    // Sort: neueste zuerst, fallback score (höher zuerst)
    out.sort((a, b) => {
        const dt = (b._ts || 0) - (a._ts || 0)
        if (dt !== 0) return dt
        return (b.score || 0) - (a.score || 0)
    })

    return out
}

function sortedForms(m) {
    const gc = groupedChoices(m)
    const arr = Array.isArray(gc?.forms) ? gc.forms.slice() : []
    arr.sort((a, b) => (choiceTs(b.choice) - choiceTs(a.choice)))
    return arr
}

function sortedNewsletters(m) {
    const gc = groupedChoices(m)
    const arr = Array.isArray(gc?.newsletters) ? gc.newsletters.slice() : []
    arr.sort((a, b) => {
        const dt = choiceTs(b.choice) - choiceTs(a.choice)
        if (dt !== 0) return dt
        return newsletterRank(b.choice) - newsletterRank(a.choice)
    })
    return arr
}

function isInternalApiUrl(u) {
    const s = String(u ?? '').trim()
    return (
        s.startsWith('/api/') ||
        s.includes('://127.0.0.1') ||
        s.includes('://localhost') ||
        s.includes('/api/support_solutions')
    )
}
function choiceDriveUrl(x) {
    const p = x?.choice?.payload || {}
    return (
        // ✅ camelCase aus deinem /api/chat Response
        p.externalMediaUrl ||
        p.driveUrl ||

        // ✅ snake_case (falls es an anderer Stelle so kommt)
        p.external_media_url ||
        p.drive_url ||

        // optional: wenn du als letzten Fallback überhaupt irgendwas willst
        // (ich würde API-URLs eher NICHT als "Dokument" anzeigen)
        ''
    )
}



function choiceDriveLabel(x) {
    const p = x?.choice?.payload || {}
    const cat = String(p.category || x?.choice?.category || '').toUpperCase()
    const kind = String(x?.choice?.kind || '').toLowerCase()

    // Du lieferst category aktuell "GENERAL" obwohl es ein Newsletter ist.
    // Daher: Heuristik über Titel/Label.
    const t = String(p.title || x?.choice?.label || '').toLowerCase()
    const isNewsletter = cat === 'NEWSLETTER' || kind === 'newsletter' || t.includes('newsletter')

    return isNewsletter ? 'Newsletter (Drive)' : 'Dokument (Drive)'
}

function stripDriveBullets(text) {
    const s = String(text ?? '')

    // Entfernt Bullet-Zeilen, die einen Drive-Link enthalten (Markdown)
    // z.B. "* [Dokument (Drive)](https://drive.google.com/....)"
    return s
        .split('\n')
        .filter(line => {
            const l = line.trim()
            // bullet + markdown link + drive
            const isDriveMd = /^\*\s*\[[^\]]+\]\((https?:\/\/[^)]+)\)\s*$/.test(l) && /drive\.google\.com/i.test(l)
            // optional auch "↳ Dokument (Drive)" Varianten
            const isDrivePlain = /Dokument\s*\(Drive\)/i.test(l) && /drive\.google\.com/i.test(l)
            return !(isDriveMd || isDrivePlain)
        })
        .join('\n')
}


function pickExternalUrl(...candidates) {
    for (const c of candidates) {
        const u = String(c ?? '').trim()
        if (!u) continue
        if (isInternalApiUrl(u)) continue
        // optional: nur http(s) zulassen
        if (!u.startsWith('http')) continue
        return u
    }
    return ''
}


function symptomsHasDrive(symptoms) {
    const s = String(symptoms ?? '').toLowerCase()
    return s.includes('drive') || s.includes('dokument (drive)') || s.includes('document (drive)')
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
.kbGroup {margin-top: 10px;}

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
