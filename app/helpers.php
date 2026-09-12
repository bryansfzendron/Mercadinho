<?php
declare(strict_types=1);

/** Escapa para HTML. */
function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * A SEFAZ devolve descricoes com entidade HTML dupla: "D&amp;#39;ORO".
 * Decodifica ate estabilizar (no maximo 3 passadas, para nao entrar em loop).
 */
function decodificar_html(?string $s): string
{
    $s = (string) $s;
    for ($i = 0; $i < 3; $i++) {
        $novo = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($novo === $s) {
            break;
        }
        $s = $novo;
    }
    return trim($s);
}

/** Converte "1.234,56", "1234.56", 1234.56 ou "" em float. */
function num_br($v): float
{
    if ($v === null || $v === '') {
        return 0.0;
    }
    if (is_int($v) || is_float($v)) {
        return (float) $v;
    }
    $s = trim((string) $v);
    $s = str_replace(["\xc2\xa0", ' ', 'R$'], '', $s);
    if ($s === '') {
        return 0.0;
    }
    // Formato brasileiro: a virgula e o separador decimal.
    if (strpos($s, ',') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float) $s : 0.0;
}

/** Data AAAA-MM-DD que existe de verdade, ou null. Nada mais entra em filtro. */
function data_iso($v): ?string
{
    $texto = trim((string) $v);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m)) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $texto : null;
}

/** Tenta interpretar uma data em varios formatos e devolve 'Y-m-d H:i:s' ou null. */
function data_mysql($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    $s = trim((string) $v);
    // Remove o fuso descritivo entre parenteses, tipo "(Horario de Brasilia)".
    $s = preg_replace('/\s*\(.*\)$/', '', $s);

    // A emissao da NFC-e vem com o deslocamento colado no fim:
    // "04/09/2026 22:26:38-03:00". O horario ja e o local, entao o
    // deslocamento e cortado antes de tentar os formatos — sem isso o
    // createFromFormat acusa "trailing data" e a data cai no strtotime, que
    // le 04/09 no formato americano e grava 9 de abril.
    $candidatos = [$s];
    $sem_fuso = preg_replace('/(\d:\d{2}(?::\d{2})?)\s*(?:Z|[+-]\d{2}:?\d{2})$/i', '$1', $s);
    if ($sem_fuso !== null && $sem_fuso !== $s && $sem_fuso !== '') {
        $candidatos[] = $sem_fuso;
    }

    // O "!" zera os campos nao informados. Sem ele, uma data sem hora
    // herda a hora atual do servidor.
    $formatos = [
        '!d/m/Y H:i:s',
        '!d/m/Y H:i',
        '!d/m/Y',
        '!Y-m-d H:i:s',
        '!Y-m-d\TH:i:sP',
        '!Y-m-d\TH:i:s',
        '!Y-m-d\TH:i',
        '!Y-m-d',
    ];
    foreach ($candidatos as $texto) {
        foreach ($formatos as $f) {
            $dt = DateTime::createFromFormat($f, $texto);
            if ($dt instanceof DateTime) {
                $erros = DateTime::getLastErrors();
                if (!$erros || (empty($erros['warning_count']) && empty($erros['error_count']))) {
                    return $dt->format('Y-m-d H:i:s');
                }
            }
        }
    }
    $ts = strtotime($s);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/** Normaliza descricao para comparacao: maiuscula, sem acento, sem pontuacao. */
function normalizar_texto(?string $s): string
{
    $s = decodificar_html($s);
    $s = mb_strtoupper($s, 'UTF-8');

    $de = ['Á','À','Â','Ã','Ä','É','È','Ê','Ë','Í','Ì','Î','Ï','Ó','Ò','Ô','Õ','Ö','Ú','Ù','Û','Ü','Ç','Ñ'];
    $para = ['A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','C','N'];
    $s = str_replace($de, $para, $s);

    $s = preg_replace('/[^A-Z0-9 ]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim((string) $s);
}

/** So os digitos de uma string. */
function so_digitos(?string $s): string
{
    return preg_replace('/\D+/', '', (string) $s) ?? '';
}

/**
 * Devolve o EAN normalizado, ou null quando o emitente nao informou GTIN.
 * No NFC-e isso vem como "SEM GTIN" com muita frequencia.
 */
function ean_normalizado($v): ?string
{
    $s = strtoupper(trim((string) $v));
    if ($s === '' || strpos($s, 'SEM GTIN') !== false || strpos($s, 'SEMGTIN') !== false) {
        return null;
    }
    $d = so_digitos($s);
    if ($d === '' || (int) $d === 0) {
        return null;
    }
    if (!in_array(strlen($d), [8, 12, 13, 14], true)) {
        return null;
    }
    return $d;
}

/**
 * Confere o digito verificador de um GTIN-8/12/13/14 (modulo 10, pesos 3 e 1).
 */
function gtin_valido(string $d): bool
{
    $n = strlen($d);
    if (!in_array($n, [8, 12, 13, 14], true) || !ctype_digit($d)) {
        return false;
    }
    $soma = 0;
    $peso = 3;
    for ($i = $n - 2; $i >= 0; $i--) {
        $soma += (int) $d[$i] * $peso;
        $peso  = $peso === 3 ? 1 : 3;
    }
    return ((10 - $soma % 10) % 10) === (int) $d[$n - 1];
}

/**
 * Tabelas de modulos do EAN/UPC: L (esquerda impar), G (esquerda par) e R
 * (direita), onde '1' e barra e '0' e espaco. P e o truque do EAN-13: o
 * primeiro digito nao vira barra nenhuma, ele escolhe o padrao L/G dos seis
 * digitos da esquerda.
 */
function barras_tabelas(): array
{
    static $t = null;
    if ($t === null) {
        $l = ['0001101','0011001','0010011','0111101','0100011',
              '0110001','0101111','0111011','0110111','0001011'];
        $t = [
            'L' => $l,
            'G' => ['0100111','0110011','0011011','0100001','0011101',
                    '0111001','0000101','0010001','0001001','0010111'],
            'R' => array_map(static fn(string $c): string => strtr($c, '01', '10'), $l),
            'P' => ['LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG',
                    'LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'],
        ];
    }
    return $t;
}

/**
 * Desenha o GTIN como codigo de barras de verdade, em SVG inline.
 *
 * Preto no branco nos dois temas: leitor de codigo vive desse contraste, entao
 * o simbolo nao acompanha o modo escuro do resto da tela. Sai com viewBox e sem
 * largura fixa — quem chama escolhe o tamanho pelo CSS e a proporcao segue.
 *
 * Entende EAN-13, UPC-A (12 digitos: e um EAN-13 com zero na frente), EAN-8 e
 * GTIN-14 (ITF-14). Devolve null quando o numero nao vira simbolo.
 */
function codigo_barras_svg($valor): ?string
{
    $d = ean_normalizado($valor);
    if ($d === null) {
        return null;
    }
    if (strlen($d) === 12) {
        $d = '0' . $d;
    }
    // GTIN-14 comecando em zero e um EAN-13 embalado em caixa. Vale desenhar o
    // EAN-13, que e o que esta impresso na unidade que vai pra prateleira.
    if (strlen($d) === 14 && $d[0] === '0') {
        $d = substr($d, 1);
    }

    $n = strlen($d);
    if ($n === 13 || $n === 8) {
        return barras_ean_svg($d);
    }
    if ($n === 14) {
        return barras_itf_svg($d);
    }
    return null;
}

/** Fita de modulos -> retangulos pretos. $guia marca as barras que descem mais. */
function barras_retangulos(string $fita, string $guia, float $x0, float $y, float $h, float $hg): string
{
    $svg = '';
    $n   = strlen($fita);
    for ($i = 0; $i < $n; $i++) {
        if ($fita[$i] === '0') {
            continue;
        }
        $j = $i;
        while ($j < $n && $fita[$j] === '1') {
            $j++;
        }
        $altura = $guia[$i] === '1' ? $hg : $h;
        $svg .= '<rect x="' . ($x0 + $i) . '" y="' . $y . '" width="' . ($j - $i)
              . '" height="' . $altura . '"/>';
        $i = $j - 1;
    }
    return $svg;
}

/**
 * Casca comum dos simbolos: fundo branco, tinta preta e rotulo pra leitor de tela.
 *
 * $escala e quantos pixels vale um modulo (a barra mais fina). E o que decide se
 * o simbolo e legivel: abaixo de ~0,3mm por modulo nenhum leitor acerta. O CSS
 * so encolhe isso quando a tela e estreita demais pra largura pedida.
 */
function barras_moldura(float $largura, float $altura, float $escala, string $numero, string $conteudo): string
{
    $rotulo = 'Código de barras ' . $numero
            . (gtin_valido($numero) ? '' : ' (dígito verificador não confere)');

    return '<svg class="codigo-barras" viewBox="0 0 ' . $largura . ' ' . $altura . '"'
         . ' width="' . round($largura * $escala) . '" height="' . round($altura * $escala) . '"'
         . ' role="img" aria-label="' . e($rotulo) . '">'
         . '<title>' . e($rotulo) . '</title>'
         . '<rect width="' . $largura . '" height="' . $altura . '" rx="2" fill="#fff"/>'
         . '<g fill="#000">' . $conteudo . '</g></svg>';
}

/** EAN-13 / EAN-8 (o UPC-A ja chegou aqui virado em EAN-13). */
function barras_ean_svg(string $d): string
{
    $t     = barras_tabelas();
    $treze = strlen($d) === 13;
    $meio  = $treze ? 6 : 4;

    $fita = '';
    $guia = '';
    $por  = static function (string $bits, bool $eh_guia) use (&$fita, &$guia): void {
        $fita .= $bits;
        $guia .= str_repeat($eh_guia ? '1' : '0', strlen($bits));
    };

    $esq = $treze ? substr($d, 1, 6) : substr($d, 0, 4);
    $dir = substr($d, -$meio);
    $par = $treze ? $t['P'][(int) $d[0]] : 'LLLL';

    $por('101', true);                                  // guia de inicio
    for ($k = 0; $k < $meio; $k++) {
        $por($t[$par[$k]][(int) $esq[$k]], false);
    }
    $por('01010', true);                                // guia do meio
    for ($k = 0; $k < $meio; $k++) {
        $por($t['R'][(int) $dir[$k]], false);
    }
    $por('101', true);                                  // guia de fim

    // Zona de silencio: sem essa margem branca o leitor nao acha o comeco do
    // simbolo. No EAN-13 a da esquerda e maior porque abriga o primeiro digito.
    $margem  = $treze ? 11 : 7;
    $largura = $margem + strlen($fita) + 7;

    $topo = 2;
    $h    = 38;
    $hg   = 42;                                         // a guia desce e separa os numeros
    $base = 52;
    $alt  = 55;

    $fonte = ' font-family="ui-monospace,Menlo,monospace" font-size="9" text-anchor="middle"';
    $txt   = $treze ? '<text x="5" y="' . $base . '"' . $fonte . '>' . $d[0] . '</text>' : '';

    $x_esq = $margem + 3;
    $x_dir = $margem + 3 + $meio * 7 + 5;
    for ($k = 0; $k < $meio; $k++) {
        $txt .= '<text x="' . ($x_esq + $k * 7 + 3.5) . '" y="' . $base . '"' . $fonte . '>'
              . $esq[$k] . '</text>'
              . '<text x="' . ($x_dir + $k * 7 + 3.5) . '" y="' . $base . '"' . $fonte . '>'
              . $dir[$k] . '</text>';
    }

    // 1,25px por modulo poe o simbolo perto do tamanho impresso de verdade
    // (um EAN-13 tem 37mm de largura, e 1 modulo e 0,33mm).
    return barras_moldura(
        $largura,
        $alt,
        1.25,
        $d,
        barras_retangulos($fita, $guia, $margem, $topo, $h, $hg) . $txt
    );
}

/**
 * ITF-14: o codigo da caixa fechada. Sao pares de digitos intercalados — o
 * primeiro do par vira barra, o segundo vira o espaco logo depois dela — e a
 * moldura preta em volta e exigida pelo padrao, nao e enfeite.
 */
function barras_itf_svg(string $d): string
{
    static $p = ['nnwwn','wnnnw','nwnnw','wwnnn','nnwnw',
                 'wnwnn','nwwnn','nnnww','wnnwn','nwnwn'];

    $fita = '1010';                                     // inicio, tudo estreito
    for ($i = 0; $i < 14; $i += 2) {
        $barra  = $p[(int) $d[$i]];
        $espaco = $p[(int) $d[$i + 1]];
        for ($k = 0; $k < 5; $k++) {
            $fita .= str_repeat('1', $barra[$k]  === 'w' ? 3 : 1);
            $fita .= str_repeat('0', $espaco[$k] === 'w' ? 3 : 1);
        }
    }
    $fita .= '11101';                                   // fim: larga, estreito, estreita

    $borda   = 4;
    $margem  = 10;
    $x0      = $borda + $margem;
    $largura = $x0 + strlen($fita) + $margem + $borda;
    $h       = 24;
    $base    = $borda * 2 + $h + 9;
    $alt     = $base + 3;

    $moldura = '<rect x="2" y="2" width="' . ($largura - 4) . '" height="' . ($borda + $h)
             . '" fill="none" stroke="#000" stroke-width="' . $borda . '"/>';

    $txt = '<text x="' . ($largura / 2) . '" y="' . $base . '"'
         . ' font-family="ui-monospace,Menlo,monospace" font-size="9"'
         . ' letter-spacing="1.5" text-anchor="middle">' . $d . '</text>';

    // O ITF-14 pede modulo mais gordo que o EAN pra ser lido, e ele ja tem quase
    // 50% mais modulos: por isso sai bem mais largo na tela.
    return barras_moldura(
        $largura,
        $alt,
        2.0,
        $d,
        $moldura . barras_retangulos($fita, str_repeat('0', strlen($fita)), $x0, $borda, $h, $h) . $txt
    );
}

/** Extrai a chave de acesso (44 digitos) de uma URL de QR Code de NFC-e. */
function chave_do_qrcode(string $qr): ?string
{
    if (preg_match('/[?&]p=([^&]+)/i', $qr, $m)) {
        $partes = explode('|', urldecode($m[1]));
        $chave  = so_digitos($partes[0] ?? '');
        if (strlen($chave) === 44) {
            return $chave;
        }
    }
    $d = so_digitos($qr);
    return strlen($d) === 44 ? $d : null;
}

function moeda($v): string
{
    return 'R$ ' . number_format((float) $v, 2, ',', '.');
}

/** Rotulo direto de grafico: 1234,56 -> "R$ 1,2 mil". Abaixo de mil, o valor cheio. */
function moeda_compacta(float $v): string
{
    if (abs($v) >= 1000) {
        return 'R$ ' . number_format($v / 1000, 1, ',', '.') . ' mil';
    }
    return moeda($v);
}

/**
 * Quantas vezes o preco de venda cobre o custo: 1,94 (a tela escreve o "x"
 * em letra menor). Quem chama garante custo > 0 — sem custo nao ha fator.
 */
function fator_fmt(float $venda, float $custo): string
{
    return number_format($venda / $custo, 2, ',', '.');
}

function qtd_fmt($v): string
{
    $f = (float) $v;
    $s = number_format($f, 3, ',', '.');
    // Tira zeros decimais inuteis: 2,000 -> 2 ; 1,500 -> 1,5
    if (strpos($s, ',') !== false) {
        $s = rtrim(rtrim($s, '0'), ',');
    }
    return $s;
}

function data_fmt(?string $v, bool $com_hora = false): string
{
    if (!$v) {
        return '-';
    }
    $ts = strtotime($v);
    if (!$ts) {
        return '-';
    }
    return date($com_hora ? 'd/m/Y H:i' : 'd/m/Y', $ts);
}

function cnpj_fmt(?string $c): string
{
    $d = so_digitos($c);
    if (strlen($d) !== 14) {
        return $c ?: '-';
    }
    return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d);
}

// ---------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------

function redirecionar(string $caminho): never
{
    header('Location: ' . $caminho, true, 302);
    exit;
}

function json_resposta(array $dados, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Le o corpo JSON da requisicao. */
function corpo_json(): array
{
    $bruto = file_get_contents('php://input') ?: '';
    $d = json_decode($bruto, true);
    return is_array($d) ? $d : [];
}

function metodo(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

/** Caminho da rota atual, sem query string e sem barra final. */
function rota_atual(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $uri = parse_url($uri, PHP_URL_PATH) ?: '/';
    $uri = rtrim($uri, '/');
    return $uri === '' ? '/' : $uri;
}

// ---------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------

function csrf_token(): string
{
    iniciar_sessao();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function exigir_csrf(): void
{
    iniciar_sessao();
    $enviado = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($enviado) || !hash_equals($_SESSION['csrf'] ?? '', $enviado)) {
        http_response_code(419);
        exit('Sessao expirada. Recarregue a pagina e tente de novo.');
    }
}

// ---------------------------------------------------------------------
// Mensagens flash
// ---------------------------------------------------------------------

function flash(string $tipo, string $msg): void
{
    iniciar_sessao();
    $_SESSION['flash'][] = ['tipo' => $tipo, 'msg' => $msg];
}

function flash_pegar(): array
{
    iniciar_sessao();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------

function ver(string $view, array $dados = [], ?string $titulo = null): never
{
    extract($dados, EXTR_SKIP);
    $titulo = $titulo ?? 'Alpha Market';
    $view_arquivo = APP . '/views/' . $view . '.php';
    if (!is_file($view_arquivo)) {
        http_response_code(500);
        exit('View nao encontrada: ' . e($view));
    }
    ob_start();
    require $view_arquivo;
    $conteudo = ob_get_clean();
    require APP . '/views/layout.php';
    exit;
}

function url(string $caminho = '/'): string
{
    return rtrim((string) cfg('base_url', ''), '/') . $caminho;
}

/**
 * Submenu de uma secao: varias telas dentro do mesmo lugar do menu principal.
 *
 * @param array<string,string> $abas  href => rotulo
 */
function abas(array $abas, string $atual): string
{
    $html = '<nav class="abas">';
    foreach ($abas as $href => $rotulo) {
        $html .= '<a class="aba' . ($href === $atual ? ' ativo' : '') . '" href="' . e($href) . '">'
               . e($rotulo) . '</a>';
    }
    return $html . '</nav>';
}

/** Loja: catalogo, o que vendeu e para onde ele quer chegar. */
function abas_loja(string $atual): string
{
    return abas([
        '/loja'    => 'Catálogo',
        '/margens' => 'Margens',
        '/vendas'  => 'Vendas',
        '/metas'   => 'Metas',
    ], $atual);
}

/** Configuracoes: tudo que e botao e ajuste, longe das telas de numero. */
function abas_config(string $atual): string
{
    return abas([
        '/config'         => 'Sincronizar',
        '/config/pdvs'    => 'PDVs',
        '/config/metas'   => 'Metas',
        '/config/taxas'   => 'Taxas',
    ], $atual);
}

/**
 * A cor da moldura de um KPI do dashboard.
 *
 * Sem periodo anterior a variacao vem nula, e ai nao ha veredito: pintar de
 * verde por padrao fazia um prejuizo aparecer emoldurado de bom.
 *
 * @param bool $menor_melhor para custo, subir e ruim
 */
function kpi_moldura(?float $variacao, bool $menor_melhor = false): string
{
    if ($variacao === null) {
        return 'kpi-neutro';
    }
    $bom = $menor_melhor ? $variacao <= 0 : $variacao >= 0;
    return $bom ? 'kpi-up' : 'kpi-down';
}
