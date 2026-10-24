// Recebe o disparo do Mercadinho e normaliza o que o resto do fluxo precisa.
const bruto = $input.first().json;
const corpo = bruto.body && typeof bruto.body === 'object' ? bruto.body : bruto;

// O firewall da SEFAZ (F5) responde "Request Rejected" quando o "|" do p= vai
// cru, e o HTTP Request do n8n manda a URL como veio do QR. O navegador manda
// %7C, entao o fluxo manda igual.
const qrcode = String(corpo.qrcode || '').trim().replace(/\|/g, '%7C');

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
