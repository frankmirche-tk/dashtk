// assets/src/components/trace/traceTree.js
import { mapLabel } from './traceMap'

// mode: "flow" | "debug"
// - flow: gruppiert nach Prefix (ui / support_chat / ai / gateway / adapter / ...)
// - debug: flach (alle spans als children unter einem Root)
export function buildTree(apiData, { mode = 'flow' } = {}) {
    const spans = Array.isArray(apiData?.spans) ? apiData.spans : []

    // defensive: wenn API mal nur nodes liefert
    const normalized = spans.length
        ? spans
        : (Array.isArray(apiData?.nodes)
            ? apiData.nodes.map(n => ({
                sequence: n.sequence ?? 0,
                name: n.id ?? '',
                duration_ms: n.duration_ms ?? 0,
                meta: n.meta ?? {},
            }))
            : [])

    // sort by sequence (wichtig!)
    normalized.sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0))

    // Debug logs nur innerhalb der Funktion (nie auf Module-Level!)
    console.log('[traceTree] mode=', mode, 'normalized=', normalized.length)
    if (normalized.length) console.log('[traceTree] first=', normalized[0])

    if (mode === 'debug') {
        return [
            {
                id: 'debug.spans',
                label: 'Raw spans (Debug)',
                duration_ms: null,
                meta: {},
                children: normalized.map(s => toNode(s)),
            },
        ]
    }

    // FLOW mode: prefix tree
    const rootMap = new Map()

    for (const s of normalized) {
        // wichtig: "name" muss existieren, fallback auf id
        const name = String(s.name ?? s.id ?? '')
        if (!name) continue

        const parts = name.split('.')
        let currentMap = rootMap
        let currentPath = ''

        for (let i = 0; i < parts.length; i++) {
            const part = parts[i]
            currentPath = currentPath ? `${currentPath}.${part}` : part

            if (!currentMap.has(part)) {
                currentMap.set(part, {
                    id: currentPath,
                    label: part,
                    duration_ms: null,
                    meta: {},
                    childrenMap: new Map(),
                    leaf: null,
                })
            }

            const node = currentMap.get(part)

            if (i === parts.length - 1) {
                // leaf = echter Span
                node.leaf = toNode({ ...s, name })
            }

            currentMap = node.childrenMap
        }
    }

    // Map -> Array + merge leaf/meta
    const roots = [...rootMap.values()].map(v => materialize(v))
    console.log('[traceTree] roots=', roots.length)
    return roots
}

function toNode(span) {
    const id = String(span.name ?? span.id ?? '')

    // Meta: bevorzugt "meta" object, fallback auf meta_json string
    const meta =
        span.meta && typeof span.meta === 'object'
            ? span.meta
            : (span.meta_json && typeof span.meta_json === 'string'
                ? (safeJson(span.meta_json) ?? {})
                : {})

    return {
        id,
        label: mapLabel(id), // Mapping (schöne Namen)
        duration_ms: Number(span.duration_ms ?? 0),
        meta,
        children: [],
    }
}

function materialize(entry) {
    const children = [...entry.childrenMap.values()].map(v => materialize(v))

    // wenn leaf existiert: leaf wird “Knoten” + children darunter
    if (entry.leaf) {
        return {
            ...entry.leaf,
            children,
        }
    }

    // nur group node
    return {
        id: entry.id,
        label: entry.label,
        duration_ms: null,
        meta: {},
        children,
    }
}

function safeJson(s) {
    try {
        return JSON.parse(s)
    } catch {
        return null
    }
}
