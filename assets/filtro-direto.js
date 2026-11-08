/*
 * Filtro que aplica na hora: escolheu, filtrou. Sem botao "Filtrar".
 *
 * Com o botao, a data escolhida na gaveta ficava so no campo ate alguem
 * lembrar de apertar "Filtrar" — fechar a gaveta ou tocar num atalho de
 * periodo jogava a escolha fora, e a tela seguia mostrando outro periodo
 * com cara de que tinha aceitado. Aqui cada campo manda o formulario sozinho:
 *
 *  - lista (PDV, pagamento, categoria, agrupar): ao trocar;
 *  - data: ao escolher uma data inteira e valida. Digitando no teclado do
 *    computador o campo muda a cada pedaco ("0002", "0020"...), entao espera
 *    a pessoa parar um instante e ignora ano que ainda nao e ano;
 *  - busca: no Enter (o "Buscar" do teclado do celular) ou ao sair do campo
 *    com o texto mudado.
 *
 * A pagina recarrega com o filtro novo e a gaveta de onde veio a mudanca
 * reabre: quem acabou de escolher o "De" quase sempre quer escolher o "Ate"
 * logo em seguida, e ter de abrir a gaveta de novo seria o mesmo tropeço
 * que o botao causava.
 *
 * Vale para todo <form data-filtro-direto>.
 */
(function (global) {
    'use strict';

    const doc = global.document;
    const CHAVE = 'filtro-direto:gaveta:';
    const ESPERA_DATA = 700; // ms sem mexer antes de valer a data digitada

    function guardar(chave, valor) {
        try { global.sessionStorage.setItem(chave, valor); } catch (e) { /* sem storage, so nao reabre */ }
    }
    function tirar(chave) {
        try {
            const v = global.sessionStorage.getItem(chave);
            global.sessionStorage.removeItem(chave);
            return v;
        } catch (e) {
            return null;
        }
    }

    function dataValida(v) {
        return /^\d{4}-\d{2}-\d{2}$/.test(v) && Number(v.slice(0, 4)) >= 2000;
    }

    doc.querySelectorAll('form[data-filtro-direto]').forEach((form, n) => {
        const chave = CHAVE + global.location.pathname + '#' + n;
        const gavetas = Array.prototype.slice.call(form.querySelectorAll('details'));

        // Volta aberta a gaveta de onde saiu a ultima mudanca.
        const aberta = tirar(chave);
        if (aberta !== null && gavetas[Number(aberta)]) gavetas[Number(aberta)].open = true;

        let enviando = false;
        // Voltar pelo navegador pode trazer a pagina do cache do jeito que
        // ficou: apagada e travada no meio do envio.
        global.addEventListener('pageshow', (ev) => {
            if (!ev.persisted) return;
            enviando = false;
            form.classList.remove('enviando');
            form.removeAttribute('aria-busy');
        });

        function enviar(campo) {
            if (enviando) return;
            enviando = true;
            const gaveta = campo ? campo.closest('details') : null;
            if (gaveta) guardar(chave, String(gavetas.indexOf(gaveta)));
            form.classList.add('enviando');
            form.setAttribute('aria-busy', 'true');
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        }

        let relogio = null;
        form.addEventListener('change', (ev) => {
            const campo = ev.target;
            if (!campo || !campo.name) return;

            if (campo.type === 'date') {
                if (!dataValida(campo.value)) return;
                // De depois do Ate (ou o contrario) viraria periodo vazio:
                // puxa o outro junto, que e o que a pessoa quis dizer.
                const de = form.elements.de;
                const ate = form.elements.ate;
                if (de && ate && dataValida(de.value) && dataValida(ate.value) && de.value > ate.value) {
                    if (campo === de) ate.value = de.value; else de.value = ate.value;
                }
                clearTimeout(relogio);
                relogio = setTimeout(() => enviar(campo), ESPERA_DATA);
                return;
            }
            enviar(campo);
        });

        // Com mais de um campo de texto/data, o navegador nao manda o
        // formulario no Enter sozinho.
        form.addEventListener('keydown', (ev) => {
            const campo = ev.target;
            if (ev.key !== 'Enter' || !campo || campo.tagName !== 'INPUT' || campo.type === 'date') return;
            ev.preventDefault();
            enviar(campo);
        });
    });
})(window);
