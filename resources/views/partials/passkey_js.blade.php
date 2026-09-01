{{-- مساعدُ مفاتيح المرور (WebAuthn) — يُضمَّن حيث تلزم أزرارُه --}}
<script>
window.LynPasskey = (function () {
    const CSRF = '{{ csrf_token() }}';
    function b64uToBuf(s) {
        s = String(s).replace(/-/g, '+').replace(/_/g, '/');
        const pad = s.length % 4; if (pad) s += '='.repeat(4 - pad);
        const bin = atob(s), b = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) b[i] = bin.charCodeAt(i);
        return b.buffer;
    }
    function bufToB64u(buf) {
        const b = new Uint8Array(buf); let s = '';
        for (let i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    async function postJson(url, body) {
        const r = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify(body || {}),
        });
        let data = {}; try { data = await r.json(); } catch (e) {}
        return { ok: r.ok, status: r.status, data };
    }
    async function register(optionsUrl, verifyUrl, label) {
        const o = (await postJson(optionsUrl)).data;
        const cred = await navigator.credentials.create({ publicKey: {
            challenge: b64uToBuf(o.challenge), rp: o.rp,
            user: { id: b64uToBuf(o.user.id), name: o.user.name, displayName: o.user.displayName },
            pubKeyCredParams: o.pubKeyCredParams,
            excludeCredentials: (o.excludeCredentials || []).map(c => ({ type: c.type, id: b64uToBuf(c.id) })),
            authenticatorSelection: o.authenticatorSelection, attestation: o.attestation, timeout: o.timeout,
        } });
        return await postJson(verifyUrl, {
            label: label || '',
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            attestationObject: bufToB64u(cred.response.attestationObject),
        });
    }
    async function assert(optionsUrl, verifyUrl) {
        const o = (await postJson(optionsUrl)).data;
        const cred = await navigator.credentials.get({ publicKey: {
            challenge: b64uToBuf(o.challenge), rpId: o.rpId,
            userVerification: o.userVerification, timeout: o.timeout,
            allowCredentials: (o.allowCredentials || []).map(c => ({ type: c.type, id: b64uToBuf(c.id) })),
        } });
        return await postJson(verifyUrl, {
            id: cred.id,
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            authenticatorData: bufToB64u(cred.response.authenticatorData),
            signature: bufToB64u(cred.response.signature),
            userHandle: cred.response.userHandle ? bufToB64u(cred.response.userHandle) : null,
        });
    }
    return { register, assert, supported: !!(window.PublicKeyCredential) };
})();
</script>
