import { mapLabel } from './traceMap'

// mode: "flow" | "debug"
// flow: parent/child tree (parent_span_id)
// debug: flat list
export function buildTree(apiData, { mode = 'flow' } = {}) {
    const spans = Array.isArray(apiData?.spans) ? apiData.spans : []
    spans.sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0))

    if (mode === 'debug') {
        return [{
            id: 'debug.spans',
            label: 'Raw spans (Debug)',
            duration_ms: null,
            meta: {},
            children: spans.map(toNode),
        }]
    }

    const byId = new Map()
    const roots = []

    for (const s of spans) {
        const id = String(s.span_id || '')
        if (!id) continue

        byId.set(id, {
            id,
            label: mapLabel(String(s.name ?? '')),
            duration_ms: Number(s.duration_ms ?? 0),
            meta: (s.meta && typeof s.meta === 'object') ? s.meta : {},
            children: [],
            _parent: s.parent_span_id ? String(s.parent_span_id) : null,
            _seq: Number(s.sequence ?? 0),
        })
    }

    for (const node of byId.values()) {
        if (node._parent && byId.has(node._parent)) {
            byId.get(node._parent).children.push(node)
        } else {
            roots.push(node)
        }
    }

    function sortRec(n) {
        n.children.sort((a, b) => a._seq - b._seq)
        n.children.forEach(sortRec)
    }

    roots.sort((a, b) => a._seq - b._seq)
    roots.forEach(sortRec)

    function strip(n) {
        delete n._parent
        delete n._seq
        n.children.forEach(strip)
    }
    roots.forEach(strip)

    return roots
}

function toNode(s) {
    return {
        id: String(s.span_id || ''),
        label: mapLabel(String(s.name ?? '')),
        duration_ms: Number(s.duration_ms ?? 0),
        meta: (s.meta && typeof s.meta === 'object') ? s.meta : {},
        children: [],
    }
}
