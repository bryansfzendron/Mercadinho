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
    $titulo = $titulo ?? 'Mercadinho';
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
 * Submenu da secao Loja. Tres telas dentro do mesmo item do menu de baixo,
 * porque cabem juntas: catalogo, o que vendeu e para onde ele quer chegar.
 */
function abas_loja(string $atual): string
{
    $abas = ['/loja' => 'Catálogo', '/vendas' => 'Vendas', '/metas' => 'Metas'];
    $html = '<nav class="abas">';
    foreach ($abas as $href => $rotulo) {
        $html .= '<a class="aba' . ($href === $atual ? ' ativo' : '') . '" href="' . $href . '">'
               . e($rotulo) . '</a>';
    }
    return $html . '</nav>';
}
