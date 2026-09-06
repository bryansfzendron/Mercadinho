// Recebe o disparo do Mercadinho e normaliza o que o resto do fluxo precisa.
const bruto = $input.first().json;
const corpo = bruto.body && typeof bruto.body === 'object' ? bruto.body : bruto;

const qrcode = String(corpo.qrcode || '').trim();

// A chave de acesso ja vem dentro do parametro p= do QR Code.
let chave = '';
const p = qrcode.match(/[?&]p=([^&]+)/i);
if (p) {
    chave = decodeURIComponent(p[1]).split('|')[0].replace(/\D/g, '');
}
if (chave.length !== 44) {
    chave = '';
}

if (!qrcode) {
    throw new Error('QR Code vazio no corpo da requisicao.');
}

return [{
    json: {
        nota_id: corpo.nota_id ?? null,
        token: String(corpo.token || ''),
        callback_url: String(corpo.callback_url || ''),
        guardar_html: corpo.guardar_html === true,
        qrcode,
        chave,
    },
}];
