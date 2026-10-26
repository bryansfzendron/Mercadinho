<?php
declare(strict_types=1);

/*
 * Consulta da NFC-e na SEFAZ-SP, sem o n8n: o mesmo caminho do workflow
 * "NFC-e SP -> Mercadinho" (n8n/codigo/*.js), feito em cURL.
 *
 *   GET  ConsultaQRCode.aspx?p=...          a pagina ja traz __VIEWSTATE
 *   POST ConsultaResumidaRJFrame_v400.aspx  __EVENTTARGET=btnVisualizarAbas
 *   302  ConsultaPublica.aspx               a Consulta Completa, com EAN e NCM
 *
 * Nada aqui toca o banco: quem grava e nota_coletar_local(), em notas.php.
 *
 * O firewall da SEFAZ (F5, desde 2026-10-24) recusa com "Request Rejected" e
 * HTTP 200 quem nao manda cabecalho de navegador, e bloqueia o IP por uns 10
 * minutos depois de ~15 consultas seguidas. Os cabecalhos abaixo sao os que
 * passaram nos testes de 2026-10-26: manter letra por letra.
 */

const SEFAZ_CONSULTA_RESUMIDA =
    'https://www.nfce.fazenda.sp.gov.br/NFCeConsultaPublica/Paginas/ConsultaResponsiva/ConsultaResumidaRJFrame_v400.aspx';

const SEFAZ_CABECALHOS = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/126.0.0.0 Safari/537.36',
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
    'Accept-Language: pt-BR,pt;q=0.9',
    'Sec-Fetch-Dest: document',
    'Sec-Fetch-Mode: navigate',
    'Sec-Fetch-Site: none',
    'Upgrade-Insecure-Requests: 1',
];

/**
 * Abre o QR Code e clica em "Visualizar em Abas". Devolve o HTML da Consulta
 * Completa ou lanca RuntimeException com uma frase que o usuario entende.
 */
function sefaz_consultar(string $qr_url): string
{
    $url = sefaz_url_valida($qr_url);

    $ch = curl_init();
    curl_setopt_array($ch, [
        // Cookie jar em memoria, vivo enquanto o handle existir: a sessao
        // ASP.NET e os cookies TS... do firewall passam sozinhos entre as
        // requisicoes, inclusive os que chegam no meio de um redirecionamento.
        CURLOPT_COOKIEFILE      => '',
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 5,
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_CONNECTTIMEOUT  => 8,
        // Duas viagens de ate 12s cabem no teto de 30s da hospedagem.
        CURLOPT_TIMEOUT         => 12,
    ]);

    try {
        $pagina = sefaz_requisitar($ch, $url, SEFAZ_CABECALHOS, null);
        sefaz_checar_recusa($pagina, 'a abertura do QR Code');

        $viewstate = sefaz_campo_oculto($pagina, '__VIEWSTATE');
        if ($viewstate === '') {
            $erro = preg_match('/Erro\(s\)[\s\S]{0,200}/i', $pagina, $m)
                ? ' — a SEFAZ respondeu: ' . trim(preg_replace('/<[^>]*>/', ' ', $m[0]) ?? '')
                : '';
            throw new RuntimeException('Não achei o __VIEWSTATE na página do QR Code' . $erro . '.');
        }

        $corpo = http_build_query([
            '__EVENTTARGET'        => 'btnVisualizarAbas',
            '__EVENTARGUMENT'      => '',
            '__VIEWSTATE'          => $viewstate,
            // 2CB5E186 e o gerador observado nessa pagina; serve de reserva.
            '__VIEWSTATEGENERATOR' => sefaz_campo_oculto($pagina, '__VIEWSTATEGENERATOR') ?: '2CB5E186',
            '__EVENTVALIDATION'    => sefaz_campo_oculto($pagina, '__EVENTVALIDATION'),
        ], '', '&', PHP_QUERY_RFC3986);

        $completa = sefaz_requisitar($ch, SEFAZ_CONSULTA_RESUMIDA, array_merge(SEFAZ_CABECALHOS, [
            'Referer: ' . SEFAZ_CONSULTA_RESUMIDA,
            'Content-Type: application/x-www-form-urlencoded',
        ]), $corpo);
        sefaz_checar_recusa($completa, 'a Consulta Completa');

        return $completa;
    } finally {
        curl_close($ch);
    }
}

/**
 * Aceita so endereco da SEFAZ-SP: o servidor nao pode virar um navegador que
 * abre qualquer URL que chegue no campo do QR Code.
 */
function sefaz_url_valida(string $qr_url): string
{
    // O navegador manda o "|" do p= como %7C; o cURL mandaria cru.
    $url = str_replace('|', '%7C', trim($qr_url));
    $partes = parse_url($url);
    $host = strtolower((string) ($partes['host'] ?? ''));
    $esquema = strtolower((string) ($partes['scheme'] ?? ''));

    if (!in_array($esquema, ['http', 'https'], true)
        || ($host !== 'fazenda.sp.gov.br' && !str_ends_with($host, '.fazenda.sp.gov.br'))) {
        throw new RuntimeException('O QR Code não aponta para a SEFAZ de São Paulo.');
    }
    return $url;
}

/** Uma viagem no handle compartilhado. GET quando $corpo e null. */
function sefaz_requisitar(CurlHandle $ch, string $url, array $cabecalhos, ?string $corpo): string
{
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $cabecalhos);
    if ($corpo === null) {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } else {
        // POST com 302 vira GET no redirecionamento, como no navegador.
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $corpo);
    }

    $html = curl_exec($ch);
    if ($html === false) {
        throw new RuntimeException('Falha ao falar com a SEFAZ: ' . curl_error($ch));
    }
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($http >= 400) {
        throw new RuntimeException('A SEFAZ respondeu HTTP ' . $http . '.');
    }
    return (string) $html;
}

/** O firewall recusa com HTTP 200 e uma pagina de 245 bytes. */
function sefaz_checar_recusa(string $html, string $etapa): void
{
    if (preg_match('/<title>\s*Request Rejected/i', $html)) {
        $id = preg_match('/support ID is:\s*<?(\d+)/i', $html, $m) ? ', support ID ' . $m[1] : '';
        throw new RuntimeException('O firewall da SEFAZ recusou ' . $etapa . ' (Request Rejected' . $id . ').');
    }
}

function sefaz_campo_oculto(string $html, string $nome): string
{
    if (!preg_match('/<input[^>]*(?:name|id)="' . preg_quote($nome, '/') . '"[^>]*>/i', $html, $tag)) {
        return '';
    }
    if (!preg_match('/value="([^"]*)"/i', $tag[0], $valor)) {
        return '';
    }
    return str_replace(['&quot;', '&#39;', '&lt;', '&gt;', '&amp;'], ['"', "'", '<', '>', '&'], $valor[1]);
}

// =====================================================================
// Parser da Consulta Completa — porte de n8n/codigo/04-extrair-itens.js.
// As armadilhas estao descritas la e no README; os nomes seguem o JS para
// que os dois possam ser lidos lado a lado.
// =====================================================================

/**
 * Extrai cabecalho e itens no formato que o n8n devolvia em "payload":
 * ['nota' => [...], 'itens' => [[...], ...]]. Lanca RuntimeException.
 */
function sefaz_extrair(string $html, string $chave, string $qr_url): array
{
    if (strlen($html) < 2000) {
        throw new RuntimeException('A Consulta Completa voltou vazia ou curta demais (' . strlen($html) . ' bytes).');
    }

    $fatia = static function (string $inicio, ?string $fim) use ($html): string {
        $i = strpos($html, 'id="' . $inicio . '"');
        if ($i === false) {
            return '';
        }
        if ($fim === null) {
            return substr($html, $i);
        }
        $j = strpos($html, 'id="' . $fim . '"', $i);
        return $j !== false && $j > $i ? substr($html, $i, $j - $i) : '';
    };

    // ---------- cabecalho ----------
    $dadosNFe = sefaz_pares($fatia('NFe', 'Emitente') ?: substr($fatia('NFe', null), 0, 12000));
    // A aba do emitente termina em id="DestRem": sem esse corte, os rotulos
    // vazios do destinatario apagavam a razao social.
    $dadosEmit = sefaz_pares(
        $fatia('Emitente', 'DestRem')
        ?: $fatia('Emitente', 'Destinatario')
        ?: $fatia('Emitente', 'Prod')
        ?: substr($fatia('Emitente', null), 0, 12000)
    );
    $dadosTotais = sefaz_pares(
        $fatia('Totais', 'Transporte')
        ?: $fatia('Totais', 'Conteudo_pnlNFe_tabTransporte')
        ?: substr($fatia('Totais', null), 0, 20000)
    );

    $cnpj = so_digitos(sefaz_acha($dadosEmit, 'CNPJ') ?: sefaz_acha($dadosNFe, 'CNPJ'));
    $municipio = sefaz_municipio(sefaz_acha($dadosEmit, 'Municipio'));

    $nota = [
        'chave'                => $chave,
        'emitente'             => sefaz_acha($dadosEmit, 'Nome / Razao Social', 'Razao Social', 'Nome')
                                  ?: sefaz_acha($dadosNFe, 'Nome / Razao Social', 'Razao Social'),
        'nome_fantasia'        => sefaz_acha($dadosEmit, 'Nome Fantasia'),
        'cnpj'                 => $cnpj,
        'inscricao_estadual'   => sefaz_acha($dadosEmit, 'Inscricao Estadual') ?: sefaz_acha($dadosNFe, 'Inscricao Estadual'),
        'endereco'             => sefaz_acha($dadosEmit, 'Endereco'),
        'bairro'               => sefaz_acha($dadosEmit, 'Bairro / Distrito', 'Bairro'),
        'cep'                  => sefaz_acha($dadosEmit, 'CEP'),
        'municipio'            => $municipio['nome'],
        'codigo_municipio'     => $municipio['codigo'],
        'uf'                   => sefaz_acha($dadosEmit, 'UF') ?: sefaz_acha($dadosNFe, 'UF'),
        'modelo'               => sefaz_acha($dadosNFe, 'Modelo'),
        'serie'                => sefaz_acha($dadosNFe, 'Serie'),
        'numero_nota'          => sefaz_acha($dadosNFe, 'Numero'),
        'emissao'              => sefaz_acha($dadosNFe, 'Data de Emissao', 'Emissao'),
        'natureza_operacao'    => sefaz_acha($dadosNFe, 'Natureza da Operacao'),
        'valor_total_produtos' => sefaz_acha($dadosTotais, 'Valor Total dos Produtos', 'Valor dos Produtos'),
        'desconto_total_nota'  => sefaz_acha($dadosTotais, 'Valor Total dos Descontos', 'Desconto'),
        'outras_despesas_nota' => sefaz_acha($dadosTotais, 'Outras Despesas Acessorias'),
        'valor_frete_nota'     => sefaz_acha($dadosTotais, 'Valor do Frete'),
        'valor_tributos_nota'  => sefaz_acha($dadosTotais, 'Valor Aproximado dos Tributos'),
        'valor_total_nota'     => sefaz_acha($dadosTotais, 'Valor Total da NFe', 'Valor a Pagar', 'Valor Total da Nota'),
        'url_consulta'         => $qr_url,
        'consultado_em'        => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    // ---------- itens ----------
    // Fatiar "Prod" antes e obrigatorio: a aba Cobranca reusa a mesma classe.
    $blocoProdutos = $fatia('Prod', 'Conteudo_pnlNFe_tabTotais') ?: $fatia('Prod', 'Totais');
    if ($blocoProdutos === '') {
        throw new RuntimeException('Não achei o bloco de produtos (id="Prod") na resposta.');
    }

    // Cada item comeca numa <table class="toggle box"> e vai ate a proxima:
    // assim a <table class="toggable box"> irma (EAN, codigo, valor unitario,
    // desconto) entra no mesmo pedaco.
    preg_match_all('/<table[^>]*class="[^"]*\btoggle\b[^"]*"[^>]*>/i', $blocoProdutos, $aberturas, PREG_OFFSET_CAPTURE);
    sefaz_checar_regex();
    $inicios = array_column($aberturas[0], 1);

    $itens = [];
    foreach ($inicios as $k => $inicio) {
        $fimPedaco = $inicios[$k + 1] ?? strlen($blocoProdutos);
        $pedaco = substr($blocoProdutos, $inicio, $fimPedaco - $inicio);
        // A linha resumida e a primeira tabela do pedaco; o resto e detalhe.
        $fim = strpos($pedaco, '</table>');
        $resumo = $fim !== false ? substr($pedaco, 0, $fim) : $pedaco;

        $numero = sefaz_celula($resumo, 'fixo-prod-serv-numero');
        // A linha de cabecalho usa <label> no lugar de <span>: descartar.
        if ($numero === '' || !ctype_digit($numero)) {
            continue;
        }

        $detalhe = sefaz_pares($pedaco);

        $itens[] = [
            'item'             => (int) $numero,
            'codigo'           => sefaz_acha($detalhe, 'Codigo do Produto', 'Codigo do Prod'),
            'descricao'        => sefaz_celula($resumo, 'fixo-prod-serv-descricao') ?: sefaz_acha($detalhe, 'Descricao'),
            'quantidade'       => sefaz_celula($resumo, 'fixo-prod-serv-qtd') ?: sefaz_acha($detalhe, 'Quantidade Comercial'),
            'unidade'          => sefaz_celula($resumo, 'fixo-prod-serv-uc') ?: sefaz_acha($detalhe, 'Unidade Comercial'),
            'valor_unitario'   => sefaz_acha($detalhe, 'Valor unitario de comercializacao', 'Valor unitario'),
            'valor_total_item' => sefaz_celula($resumo, 'fixo-prod-serv-vb') ?: sefaz_acha($detalhe, 'Valor total bruto'),
            'desconto_item'    => sefaz_acha($detalhe, 'Valor do Desconto'),
            'outras_despesas'  => sefaz_acha($detalhe, 'Outras Despesas Acessorias'),
            'frete_item'       => sefaz_acha($detalhe, 'Valor Total do Frete'),
            'seguro_item'      => sefaz_acha($detalhe, 'Valor do Seguro'),
            'valor_tributos'   => sefaz_acha($detalhe, 'Valor Aproximado dos Tributos'),
            'ean'              => sefaz_acha($detalhe, 'Codigo EAN Comercial', 'GTIN', 'EAN'),
            'ean_tributavel'   => sefaz_acha($detalhe, 'Codigo EAN Tributavel'),
            'ncm'              => sefaz_acha($detalhe, 'Codigo NCM', 'NCM'),
            'cest'             => sefaz_acha($detalhe, 'Codigo CEST', 'CEST'),
            'cfop'             => sefaz_acha($detalhe, 'CFOP'),
            'origem'           => sefaz_acha($detalhe, 'Origem da Mercadoria'),
        ];
    }

    if (!$itens) {
        throw new RuntimeException(
            'Cheguei na Consulta Completa mas não extraí nenhum item — o layout da SEFAZ pode ter mudado. '
            . 'Encontrei ' . count($inicios) . ' tabela(s) no bloco Prod.'
        );
    }

    return ['nota' => $nota, 'itens' => $itens];
}

/**
 * O PCRE do PHP desiste calado ao passar do pcre.backtrack_limit e devolve
 * null/false, que viraria "nenhum item". O JS nao tem esse teto. Melhor um
 * erro claro do que uma nota vazia.
 */
function sefaz_checar_regex(): void
{
    if (preg_last_error() !== PREG_NO_ERROR) {
        throw new RuntimeException('Falha ao ler o HTML da SEFAZ: ' . preg_last_error_msg() . '.');
    }
}

/** Entidade HTML ate 3 vezes (D&amp;#39;ORO -> D'ORO); &nbsp; vira espaco. */
function sefaz_decodificar(string $texto): string
{
    // Nao usar `?:` aqui: mb_chr(48) e "0", que o PHP trata como falso.
    $caractere = static function (int $codigo): string {
        $c = mb_chr($codigo, 'UTF-8');
        return $c === false ? '' : $c;
    };
    for ($i = 0; $i < 3; $i++) {
        $novo = preg_replace_callback('/&#(\d+);/', static fn ($m) => $caractere((int) $m[1]), $texto);
        $novo = preg_replace_callback('/&#x([0-9a-f]+);/i', static fn ($m) => $caractere((int) hexdec($m[1])), (string) $novo);
        $novo = str_replace(['&quot;', '&apos;', '&lt;', '&gt;', '&nbsp;'], ['"', "'", '<', '>', ' '], (string) $novo);
        $novo = str_replace('&amp;', '&', $novo);
        if ($novo === $texto) {
            break;
        }
        $texto = $novo;
    }
    return $texto;
}

/** Sem tags, sem entidades, espacos colapsados. */
function sefaz_limpar(string $trecho): string
{
    $texto = sefaz_decodificar(preg_replace('/<[^>]*>/', ' ', $trecho) ?? '');
    $colapsado = preg_replace('/[\s\x{00A0}]+/u', ' ', $texto) ?? preg_replace('/\s+/', ' ', $texto);
    return trim((string) $colapsado);
}

/**
 * Pares <label>Rotulo</label><span>Valor</span>. O primeiro valor vence:
 * quando a fatia alcanca a aba seguinte, os rotulos repetidos (e vazios) do
 * Destinatario nao apagam os do Emitente.
 */
function sefaz_pares(string $bloco): array
{
    preg_match_all('/<label[^>]*>([\s\S]*?)<\/label>\s*<span[^>]*>([\s\S]*?)<\/span>/i', $bloco, $m, PREG_SET_ORDER);
    sefaz_checar_regex();

    $mapa = [];
    foreach ($m as $par) {
        $rotulo = preg_replace('/:$/', '', sefaz_limpar($par[1]));
        if ($rotulo !== '' && !array_key_exists($rotulo, $mapa)) {
            $mapa[$rotulo] = sefaz_limpar($par[2]);
        }
    }
    return $mapa;
}

function sefaz_sem_acento(string $texto): string
{
    $texto = mb_strtoupper($texto, 'UTF-8');
    $de   = ['Á','À','Â','Ã','Ä','É','È','Ê','Ë','Í','Ì','Î','Ï','Ó','Ò','Ô','Õ','Ö','Ú','Ù','Û','Ü','Ç','Ñ'];
    $para = ['A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','C','N'];
    return str_replace($de, $para, $texto);
}

/** Busca tolerante: primeiro rotulo igual, depois rotulo que contem — sem acento e sem caixa. */
function sefaz_acha(array $mapa, string ...$candidatos): string
{
    $chaves = [];
    foreach (array_keys($mapa) as $chave) {
        $chaves[(string) $chave] = sefaz_sem_acento((string) $chave);
    }
    foreach ($candidatos as $candidato) {
        $alvo = sefaz_sem_acento($candidato);
        foreach ($chaves as $chave => $normal) {
            if ($normal === $alvo) {
                return $mapa[$chave];
            }
        }
    }
    foreach ($candidatos as $candidato) {
        $alvo = sefaz_sem_acento($candidato);
        foreach ($chaves as $chave => $normal) {
            if (str_contains($normal, $alvo)) {
                return $mapa[$chave];
            }
        }
    }
    return '';
}

function sefaz_celula(string $tabela, string $classe): string
{
    $re = '/<t[dh][^>]*class="[^"]*' . preg_quote($classe, '/') . '[^"]*"[^>]*>([\s\S]*?)<\/t[dh]>/i';
    return preg_match($re, $tabela, $m) ? sefaz_limpar($m[1]) : '';
}

/** "3552205 - SOROCABA" -> ['codigo' => '3552205', 'nome' => 'SOROCABA']. */
function sefaz_municipio(string $valor): array
{
    $texto = sefaz_limpar($valor);
    return preg_match('/^(\d{5,7})\s*-\s*(.+)$/u', $texto, $m)
        ? ['codigo' => $m[1], 'nome' => trim($m[2])]
        : ['codigo' => '', 'nome' => $texto];
}
