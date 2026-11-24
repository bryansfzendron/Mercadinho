/* Configuracoes > Avisos: inscrever/cancelar este aparelho nas notificacoes. */
(function () {
    'use strict';
    const estado = document.getElementById('av-estado');
    const ativar = document.getElementById('av-ativar');
    const desativar = document.getElementById('av-desativar');
    if (!estado || !ativar) return;

    function b64ParaBytes(b64) {
        const pad = '='.repeat((4 - b64.length % 4) % 4);
        const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(raw, function (c) { return c.charCodeAt(0); });
    }
    function csrf() {
        const m = document.querySelector('meta[name="csrf"]');
        return m ? m.getAttribute('content') : '';
    }
    function enviar(url, corpo) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify(corpo),
        });
    }

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        estado.textContent = 'Este navegador não recebe notificações. No iPhone, instale o app na tela inicial primeiro.';
        return;
    }

    async function atualizar() {
        const reg = await navigator.serviceWorker.getRegistration('/');
        const sub = reg ? await reg.pushManager.getSubscription() : null;
        if (Notification.permission === 'denied') {
            estado.textContent = 'As notificações estão bloqueadas para este site. Libere nas configurações do navegador.';
            ativar.hidden = true; desativar.hidden = true;
            return;
        }
        ativar.hidden = !!sub;
        desativar.hidden = !sub;
        estado.textContent = sub ? 'Este aparelho está recebendo os avisos.' : 'Este aparelho ainda não recebe avisos.';
    }

    ativar.addEventListener('click', async function () {
        try {
            const perm = await Notification.requestPermission();
            if (perm !== 'granted') { estado.textContent = 'Permissão negada.'; return; }
            const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: b64ParaBytes(ativar.dataset.chave),
            });
            const r = await enviar('/api/push/inscrever', sub.toJSON());
            if (!r.ok) throw new Error('O servidor recusou a inscrição (' + r.status + ').');
            estado.textContent = 'Pronto. Use "Enviar notificação de teste" para conferir.';
        } catch (e) {
            estado.textContent = 'Não deu certo: ' + (e && e.message ? e.message : e);
        }
        atualizar();
    });

    desativar.addEventListener('click', async function () {
        const reg = await navigator.serviceWorker.getRegistration('/');
        const sub = reg ? await reg.pushManager.getSubscription() : null;
        if (sub) {
            try { await enviar('/api/push/cancelar', { endpoint: sub.endpoint }); } catch (e) {}
            await sub.unsubscribe();
        }
        atualizar();
    });

    atualizar();
})();
