<!-- Graph mit Bubbles -->

<template>
    <div class="trace-graph">
        <div class="toolbar">
            <div>
                <strong>Trace:</strong> {{ traceId }}
                <span v-if="data"> · <strong>Total:</strong> {{ data.total_ms }} ms</span>
            </div>

            <div class="toolbar-actions">
                <button @click="fit">Fit</button>
                <button @click="reload">Reload</button>
            </div>
        </div>

        <div class="content">
            <div ref="graphEl" class="graph"></div>

            <div class="sidepanel" v-if="selected">
                <h3 style="margin: 0 0 8px 0;">{{ selected.label }}</h3>
                <div><strong>Dauer:</strong> {{ selected.duration_ms }} ms</div>

                <div v-if="selected.meta && Object.keys(selected.meta).length" style="margin-top: 10px;">
                    <strong>Meta</strong>
                    <pre class="meta">{{ JSON.stringify(selected.meta, null, 2) }}</pre>
                </div>

                <div v-else style="margin-top: 10px; opacity: 0.7;">
                    Keine Meta-Daten
                </div>
            </div>
        </div>

        <div v-if="error" class="error">
            {{ error }}
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, watch, onBeforeUnmount } from "vue";
import axios from "axios";
import cytoscape from "cytoscape";
import dagre from "cytoscape-dagre";

cytoscape.use(dagre);

const props = defineProps({
    traceId: { type: String, required: true },
    apiBase: { type: String, default: "" }, // falls ihr /api via Proxy anders habt
});

const graphEl = ref(null);
const cy = ref(null);
const data = ref(null);
const selected = ref(null);
const error = ref("");

async function fetchTrace() {
    error.value = "";
    selected.value = null;

    try {
        const res = await axios.get(`${props.apiBase}/api/ai-traces/${props.traceId}`);
        data.value = res.data;
        renderGraph(res.data);
    } catch (e) {
        error.value = `Konnte Trace nicht laden: ${e?.response?.status || ""} ${e?.message || e}`;
    }
}

function renderGraph(trace) {
    if (!graphEl.value) return;

    // alte Instanz zerstören
    if (cy.value) {
        cy.value.destroy();
        cy.value = null;
    }

    const elements = [];

    // Nodes
    for (const n of trace.nodes || []) {
        const label = `${n.label}\n${n.duration_ms} ms`;
        elements.push({
            data: {
                id: n.id,
                label,
                raw: n, // original node for sidepanel
            },
        });
    }

    // Edges
    for (const e of trace.edges || []) {
        elements.push({
            data: {
                id: `${e.source}__${e.target}`,
                source: e.source,
                target: e.target,
            },
        });
    }

    cy.value = cytoscape({
        container: graphEl.value,
        elements,
        layout: {
            name: "dagre",
            rankDir: "TB", // top -> bottom wie im Screenshot
            nodeSep: 40,
            rankSep: 80,
            edgeSep: 10,
        },
        style: [
            {
                selector: "node",
                style: {
                    "label": "data(label)",
                    "text-wrap": "wrap",
                    "text-max-width": 160,
                    "text-valign": "center",
                    "text-halign": "center",
                    "font-size": 12,
                    "width": "label",
                    "height": "label",
                    "padding": 14,
                    "shape": "ellipse",
                    "border-width": 2,
                    "border-color": "#0ea5a4",
                    "background-color": "#14b8a6",
                    "color": "#0b1220",
                },
            },
            {
                selector: "edge",
                style: {
                    "curve-style": "bezier",
                    "target-arrow-shape": "triangle",
                    "target-arrow-color": "#64748b",
                    "line-color": "#94a3b8",
                    "width": 2,
                },
            },
            {
                selector: "node:selected",
                style: {
                    "border-color": "#f59e0b",
                    "border-width": 4,
                },
            },
        ],
        userZoomingEnabled: true,
        userPanningEnabled: true,
    });

    // Klick -> Sidepanel
    cy.value.on("tap", "node", (evt) => {
        const raw = evt.target.data("raw");
        selected.value = raw;
    });

    // Klick ins Leere -> unselect
    cy.value.on("tap", (evt) => {
        if (evt.target === cy.value) {
            selected.value = null;
            cy.value.$(":selected").unselect();
        }
    });

    // initial fit
    setTimeout(() => {
        fit();
    }, 50);
}

function fit() {
    if (cy.value) cy.value.fit(undefined, 30);
}

function reload() {
    fetchTrace();
}

onMounted(fetchTrace);

watch(
    () => props.traceId,
    () => fetchTrace()
);

onBeforeUnmount(() => {
    if (cy.value) cy.value.destroy();
});
</script>

<style scoped>
.trace-graph {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #ffffff;
}
.toolbar-actions {
    display: flex;
    gap: 8px;
}
.toolbar-actions button {
    padding: 6px 10px;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #fff;
    cursor: pointer;
}
.content {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 12px;
    min-height: 520px;
}
.graph {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #ffffff;
    min-height: 520px;
}
.sidepanel {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #ffffff;
    padding: 12px;
    overflow: auto;
}
.meta {
    font-size: 12px;
    background: #0b1220;
    color: #e2e8f0;
    padding: 10px;
    border-radius: 10px;
    overflow: auto;
}
.error {
    padding: 10px 12px;
    border: 1px solid #fecaca;
    background: #fff1f2;
    color: #7f1d1d;
    border-radius: 10px;
}
@media (max-width: 980px) {
    .content {
        grid-template-columns: 1fr;
    }
}
</style>
