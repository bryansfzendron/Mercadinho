<?php
declare(strict_types=1);

/**
 * As pecas de tela que se repetem, no arranjo que nasceu na tela de Vendas:
 * icones, titulo com icone, cabeca de cartao com icone, o cartao de filtros
 * (atalhos, gavetas que abrem, busca + Filtrar) e o "Ver mais".
 *
 * Tudo devolve HTML pronto. O que vem de fora passa por e(); os SVG sao do
 * proprio app.
 */

/** Um icone de traco, no feitio dos da barra de baixo. */
function icone(string $nome, string $classe = ''): string
{
    static $miolos = null;
    $miolos ??= [
        'loja'      => '<path d="M4 9.5V19a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9.5"/><path d="M3 9.5 4.6 4.7A1 1 0 0 1 5.5 4h13a1 1 0 0 1 .9.7L21 9.5"/><path d="M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M9.5 20v-5h5v5"/>',
        'caixa'     => '<path d="M12 3 4 7v10l8 4 8-4V7z"/><path d="m4 7 8 4 8-4"/><path d="M12 11v10"/>',
        'repor'     => '<path d="M20 11a8 8 0 0 0-14.3-4.9L4 8"/><path d="M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14.3 4.9L20 16"/><path d="M20 20v-4h-4"/>',
        'etiqueta'  => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.4"/>',
        'barras'    => '<rect x="4" y="13" width="3.5" height="7" rx="1"/><rect x="10.25" y="8" width="3.5" height="12" rx="1"/><rect x="16.5" y="4" width="3.5" height="16" rx="1"/>',
        'grafico'   => '<path d="M6 19v-5M12 19V9M18 19V5"/>',
        'carrinho'  => '<path d="M3 4h2.2l2 10.5a1.5 1.5 0 0 0 1.5 1.2h7.9a1.5 1.5 0 0 0 1.5-1.2L19.5 8H6"/><circle cx="9.5" cy="19.5" r="1.3"/><circle cx="16.5" cy="19.5" r="1.3"/>',
        'alvo'      => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.8"/><circle cx="12" cy="12" r="1.2"/><path d="m15.5 8.5 4-4"/>',
        'calendario'=> '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'filtros'   => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
        'funil'     => '<path d="M4 5h16l-6 7.5V19l-4-2v-4.5z"/>',
        'busca'     => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'cartao'    => '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h3"/>',
        'nota'      => '<path d="M6.5 3h7l4 4v13a1 1 0 0 1-1 1h-10a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M13.5 3v4h4"/><path d="M8.5 12.5h7M8.5 16h5"/>',
        'produto'   => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
        'casa'      => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 9.5V20h13V9.5"/>',
        'painel'    => '<rect x="4" y="12" width="4" height="8" rx="1"/><rect x="10" y="8" width="4" height="12" rx="1"/><rect x="16" y="4" width="4" height="16" rx="1"/>',
        'engrenagem'=> '<circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 9 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 9a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'sync'      => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        // Ponto de venda: a mesma lojinha do titulo da Loja, menor.
        'pdv'       => '<path d="M4 9.5V19a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9.5"/><path d="M3 9.5 4.6 4.7A1 1 0 0 1 5.5 4h13a1 1 0 0 1 .9.7L21 9.5"/><path d="M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M9.5 20v-5h5v5"/>',
        'codigo'    => '<path d="M4 6v12M7 6v12M10.5 6v12M13 6v12M16.5 6v12M20 6v12"/>',
        'percentual'=> '<path d="m6 18 12-12"/><circle cx="7.5" cy="7.5" r="2"/><circle cx="16.5" cy="16.5" r="2"/>',
        'cadeado'   => '<rect x="5" y="11" width="14" height="9.5" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'relogio'   => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'alerta'    => '<path d="M12 4 2.8 19.5h18.4z"/><path d="M12 10v4.5M12 17.2v.1"/>',
        'dinheiro'  => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6.5 9.5v5M17.5 9.5v5"/>',
        'pessoa'    => '<circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        'lista'     => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r=".9"/><circle cx="4.5" cy="12" r=".9"/><circle cx="4.5" cy="18" r=".9"/>',
        'camera'    => '<path d="M3 9a2 2 0 0 1 2-2h1.6l1.2-2h6.4l1.2 2H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="3.4"/>',
        'seta-baixo'=> '<path d="m6 9 6 6 6-6"/>',
        'seta-dir'  => '<path d="m9 6 6 6-6 6"/>',
        'validade'  => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4M9 15l2 2 4-4"/>',
    ];
    $miolo = $miolos[$nome] ?? $miolos['lista'];
    return '<svg class="icone' . ($classe !== '' ? ' ' . e($classe) : '') . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $miolo . '</svg>';
}

/** O titulo da tela, com o icone na frente. */
function titulo_icone(string $texto, string $icone): string
{
    return '<h1 class="titulo-icone">' . icone($icone) . e($texto) . '</h1>';
}

/**
 * A cabeca de um cartao: icone, titulo, legenda opcional e, a direita, uma
 * acao (um "Ver mais", um link). $direita e HTML pronto.
 */
function cartao_cabeca(string $icone, string $titulo, ?string $legenda = null, string $direita = '', bool $destaque = false): string
{
    return '<div class="cartao-cabeca">'
         . '<span class="cartao-icone' . ($destaque ? '' : ' cartao-icone-plano') . '">' . icone($icone) . '</span>'
         . '<div class="cartao-cabeca-texto"><h2 class="sem-topo">' . e($titulo) . '</h2>'
         . ($legenda !== null && $legenda !== '' ? '<p class="meta sem-margem">' . e($legenda) . '</p>' : '')
         . '</div>' . $direita . '</div>';
}

/**
 * O botao "Ver mais" que mostra os .extra do .recolhivel em volta dele.
 * O liga/desliga e do assets/ver-mais.js; sem JS, nada fica escondido
 * (a classe .recolhido so entra pelo proprio script).
 */
function ver_mais_botao(string $mais = 'Ver mais', string $menos = 'Ver menos'): string
{
    return '<button type="button" class="ver-mais" data-ver-mais aria-expanded="false">'
         . '<span class="fechado">' . e($mais) . '</span><span class="aberto">' . e($menos) . '</span>'
         . icone('seta-dir') . '</button>';
}

/**
 * Abre uma gaveta do cartao de filtros: um campo (largo, com o texto do que
 * esta escolhido) ou um botao (estreito, "Filtros (2)"). Feche com
 * gaveta_fim(). O que vai entre as duas sao os campos, num .vf-painel.
 */
function gaveta_inicio(string $icone, string $texto, bool $largo = false): string
{
    return '<details class="vf-gaveta' . ($largo ? ' vf-larga' : ' vf-mais') . '"><summary>'
         . '<span class="' . ($largo ? 'vf-campo' : 'vf-botao') . '">' . icone($icone)
         . ($largo ? '<span class="vf-texto">' . e($texto) . '</span>' . icone('seta-baixo', 'vf-seta') : e($texto))
         . '</span></summary><div class="vf-painel filtros">';
}

function gaveta_fim(): string
{
    return '</div></details>';
}

/**
 * Um campo do cartao de filtros que ja e a escolha: o toque abre direto a
 * lista (o seletor nativo), sem gaveta no meio, e trocar envia o formulario.
 * Para quando so ha uma pergunta (qual PDV, qual mes); filtros com mais de um
 * campo continuam na gaveta. O select fica por cima de tudo, transparente,
 * para o toque em qualquer ponto do campo abrir a lista.
 *
 * @param array<string|int,string> $opcoes valor => rotulo
 */
function campo_escolha(string $icone, string $nome, array $opcoes, string $atual, string $rotulo, ?string $texto = null): string
{
    $html = '';
    foreach ($opcoes as $valor => $r) {
        $html .= '<option value="' . e((string) $valor) . '"' . ((string) $valor === $atual ? ' selected' : '') . '>' . e($r) . '</option>';
    }
    $texto ??= $opcoes[$atual] ?? (string) reset($opcoes);
    return '<label class="vf-campo vf-larga vf-escolha">' . icone($icone)
         . '<span class="vf-texto">' . e($texto) . '</span>' . icone('seta-baixo', 'vf-seta')
         . '<select name="' . e($nome) . '" aria-label="' . e($rotulo) . '" onchange="this.form.submit()">'
         . $html . '</select></label>';
}

/** A linha da busca: campo com lupa e o botao Filtrar. */
function filtro_busca(string $nome, string $valor, string $dica, string $botao = 'Filtrar'): string
{
    return '<div class="vf-linha"><label class="vf-busca">' . icone('busca')
         . '<input type="text" name="' . e($nome) . '" value="' . e($valor) . '" placeholder="' . e($dica) . '"'
         . ' aria-label="Buscar" autocomplete="off" autocorrect="off" spellcheck="false"></label>'
         . '<button type="submit" class="botao vf-filtrar">' . icone('funil') . e($botao) . '</button></div>';
}

/** "Filtros" com a contagem dos que estao valendo: "Filtros (2)". */
function filtros_rotulo(int $ativos): string
{
    return 'Filtros' . ($ativos > 0 ? ' (' . $ativos . ')' : '');
}

/**
 * Um bloco que abre e fecha com um botao largo: "Ver todos os custos (4
 * mais)" / "Esconder custos". Feche com mais_fim().
 */
function mais_inicio(string $fechado, string $aberto): string
{
    return '<details class="mais"><summary><span class="mais-botao">' . icone('seta-baixo')
         . '<span class="fechado">' . e($fechado) . '</span><span class="aberto">' . e($aberto) . '</span>'
         . '</span></summary>';
}

function mais_fim(): string
{
    return '</details>';
}
