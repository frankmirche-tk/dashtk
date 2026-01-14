<template>
    <div class="guide">
        <!-- LEFT -->
        <div class="left">
            <div class="avatar">
                <img :src="avatarImg" alt="Avatar" />

                <!-- Mund-Overlay: bewegt sich nur wenn gesprochen wird -->
                <div class="mouth" :class="{ speaking: isSpeaking }" aria-hidden="true"></div>

                <!-- kleines “spricht” Signal -->
                <div v-if="isSpeaking" class="speaking-dot" aria-hidden="true"></div>
            </div>

            <div class="bubble">
                {{ ttsText || '...' }}
            </div>

            <div class="controls">
                <button class="btn" @click="speak" :disabled="!ttsText">
                    Vorlesen
                </button>
                <button class="btn ghost" @click="stop" :disabled="!canStop">
                    Stop
                </button>
            </div>
        </div>

        <!-- RIGHT -->
        <div class="right">
            <img
                v-if="isImage(mediaUrl)"
                class="media"
                :src="mediaUrl"
                alt="Guide"
            />

            <video
                v-else-if="isVideo(mediaUrl)"
                class="media"
                :src="mediaUrl"
                autoplay
                muted
                loop
                playsinline
                controls
            />

            <div v-else class="placeholder">
                (Noch kein Demo-Medium gesetzt)
            </div>

            <div class="next">
                <div class="question">Bist du bereit für den nächsten Schritt?</div>
                <button class="btn" @click="$emit('next')">Ja, weiter</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, onBeforeUnmount } from 'vue'

const props = defineProps({
    ttsText: { type: String, default: '' },
    mediaUrl: { type: String, default: '' },
    avatarUrl: { type: String, default: '' },
})

defineEmits(['next'])

/**
 * Avatar-Bild:
 * Lege es unter public/guides/avatar/default.png ab,
 * oder übergib avatar-url als Prop.
 */
const avatarImg = computed(() => props.avatarUrl || '/guides/avatar/default.png')

// Pseudo-LipSync (spricht/stop)
const isSpeaking = ref(false)
const canStop = computed(() => isSpeaking.value && typeof window !== 'undefined' && 'speechSynthesis' in window)

function isImage(url) {
    return !!url && /\.(png|jpe?g|gif|webp)$/i.test(url)
}

function isVideo(url) {
    return !!url && /\.(mp4|webm)$/i.test(url)
}

function speak() {
    const text = (props.ttsText || '').trim()
    if (!text) return
    if (!('speechSynthesis' in window)) return

    window.speechSynthesis.cancel()

    const u = new SpeechSynthesisUtterance(text)
    u.lang = 'de-DE'
    u.rate = 1.0
    u.pitch = 1.0

    u.onstart = () => { isSpeaking.value = true }
    u.onend = () => { isSpeaking.value = false }
    u.onerror = () => { isSpeaking.value = false }
    u.onpause = () => { isSpeaking.value = false }
    u.onresume = () => { isSpeaking.value = true }

    window.speechSynthesis.speak(u)
}

function stop() {
    if (!('speechSynthesis' in window)) return
    window.speechSynthesis.cancel()
    isSpeaking.value = false
}

onBeforeUnmount(() => {
    stop()
})
</script>

<style scoped>
.guide {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 16px;
    border: 1px solid #e6e6e6;
    border-radius: 14px;
    padding: 14px;
    margin: 16px 0;
    background: #fff;
}

/* LEFT */
.left { display: grid; gap: 10px; align-content: start; }

.avatar {
    position: relative;
    width: 96px;
    height: 96px;
}

.avatar img {
    width: 96px;
    height: 96px;
    border-radius: 18px;
    border: 1px solid #ddd;
    object-fit: cover;
    display: block;
}

/* ✅ Mund: Pixelwerte anpassbar */
.mouth {
    position: absolute;
    left: 49%;
    top: 46%;           /* ✅ höher */
    width: 12px;        /* ✅ ca. halb so breit */
    height: 4px;        /* ✅ flacher */
    transform: translateX(-50%);
    border-radius: 999px;
    background: rgba(0,0,0,.45); /* ✅ etwas softer */
    opacity: .85;
}


/* Mundbewegung während Sprache */
.mouth.speaking {
    animation: talk 140ms infinite alternate;
    transform-origin: center;
}

@keyframes talk {
    from { height: 6px; }
    to   { height: 16px; }
}

/* optional: “spricht” Punkt */
.speaking-dot {
    position: absolute;
    right: -6px;
    bottom: -6px;
    width: 12px;
    height: 12px;
    border-radius: 999px;
    background: #16a34a;
    border: 2px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,.15);
    animation: pulse 900ms infinite;
}

@keyframes pulse {
    0%   { transform: scale(1); opacity: .9; }
    50%  { transform: scale(1.18); opacity: 1; }
    100% { transform: scale(1); opacity: .9; }
}

.bubble {
    background: #111;
    color: #fff;
    border-radius: 14px;
    padding: 14px;
    font-size: 18px;
}

.controls { display:flex; gap:10px; }

.btn {
    border: 1px solid #111;
    background:#111;
    color:#fff;
    padding:8px 12px;
    border-radius:10px;
    cursor:pointer;
}
.btn:disabled { opacity: .6; cursor: not-allowed; }
.btn.ghost { background: transparent; color:#111; }

/* RIGHT */
.right { display:grid; gap: 10px; }

.media {
    width: 100%;
    max-height: 360px;
    object-fit: contain;
    border: 1px solid #eee;
    border-radius: 12px;
    background:#fafafa;
}

.placeholder {
    border: 1px dashed #bbb;
    border-radius: 12px;
    padding: 18px;
    color:#666;
}

.next {
    display:flex;
    justify-content: space-between;
    align-items:center;
    gap: 12px;
}

.question { font-weight: 700; }
</style>
