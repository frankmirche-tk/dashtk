export function uuid() {
    const wCrypto = typeof window !== 'undefined' ? window.crypto : undefined;

    if (wCrypto && typeof wCrypto.randomUUID === 'function') {
        return wCrypto.randomUUID();
    }

    // Fallback: sichere Zufallswerte wenn möglich
    if (wCrypto && typeof wCrypto.getRandomValues === 'function') {
        const buf = new Uint8Array(16);
        wCrypto.getRandomValues(buf);

        // RFC4122 v4
        buf[6] = (buf[6] & 0x0f) | 0x40;
        buf[8] = (buf[8] & 0x3f) | 0x80;

        const hex = [...buf].map(b => b.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    }

    // Letzter Fallback: nicht kryptografisch, aber verhindert Crash
    return `fallback-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
