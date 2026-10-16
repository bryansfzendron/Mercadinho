<?php
declare(strict_types=1);

/**
 * Importacao de notas por planilha (CSV ou XLSX).
 *
 * Uma linha da planilha e um item. Varias linhas formam uma nota, agrupadas
 * pela coluna "nota" (ou, sem ela, pela chave, ou por loja + data + numero).
 * O resultado sai no formato que nota_processar_callback() ja consome, entao
 * a nota entra pelo mesmo caminho da que veio do QR Code.
 *
 * Tudo aqui e puro (sem banco), menos planilha_gravar(), que so e chamada
 * na confirmacao. XLSX com ZipArchive + DOM: nada de composer.
 */

const PLANILHA_MAX_LINHAS = 2000;
const PLANILHA_MAX_BYTES  = 2 * 1024 * 1024;

/** Colunas do modelo, na ordem em que aparecem. */
const PLANILHA_COLUNAS = [
    'nota', 'loja', 'cnpj', 'municipio', 'uf', 'data_emissao', 'numero', 'serie',
    'chave', 'descricao', 'ean', 'codigo', 'unidade', 'quantidade',
    'valor_unitario', 'valor_total_item', 'desconto_item',
];

/** Colunas que o Excel nao pode transformar em numero (zeros a esquerda, 44 digitos). */
const PLANILHA_COLUNAS_TEXTO = [
    'nota', 'cnpj', 'data_emissao', 'numero', 'serie', 'chave', 'ean', 'codigo',
];

/** Outros nomes de cabecalho aceitos, ja no formato de normalizar_texto(). */
const PLANILHA_SINONIMOS = [
    'nota'             => ['ID NOTA', 'GRUPO', 'NOTA FISCAL'],
    'loja'             => ['EMITENTE', 'ESTABELECIMENTO', 'MERCADO', 'FORNECEDOR'],
    'municipio'        => ['CIDADE'],
    'uf'               => ['ESTADO'],
    'data_emissao'     => ['DATA', 'EMISSAO', 'DATA DA COMPRA', 'DATA COMPRA'],
    'numero'           => ['NUMERO NOTA', 'NUM NOTA', 'NUMERO DA NOTA'],
    'chave'            => ['CHAVE ACESSO', 'CHAVE DE ACESSO'],
    'descricao'        => ['PRODUTO', 'NOME', 'NOME DO PRODUTO'],
    'ean'              => ['CODIGO DE BARRAS', 'COD BARRAS', 'GTIN'],
    'codigo'           => ['COD', 'CODIGO INTERNO'],
    'unidade'          => ['UN', 'UND'],
    'quantidade'       => ['QTD', 'QTDE', 'QTDE ITEM'],
    'valor_unitario'   => ['VALOR UNIT', 'PRECO UNITARIO', 'PRECO UNIT', 'PRECO'],
    'valor_total_item' => ['VALOR TOTAL', 'TOTAL ITEM', 'TOTAL'],
    'desconto_item'    => ['DESCONTO'],
];

// =====================================================================
// Leitura
// =====================================================================

/**
 * Le a planilha e devolve as linhas nao vazias, indexadas pelo numero da linha
 * (1 = primeira) para as mensagens de erro apontarem a linha certa.
 *
 * @return array<int, list<string>>
 * @throws RuntimeException com mensagem pronta para o usuario
 */
function planilha_ler(string $caminho, string $nome_original): array
{
    $ext = strtolower(pathinfo($nome_original, PATHINFO_EXTENSION));
    $cab = (string) @file_get_contents($caminho, false, null, 0, 4);
    $e_zip = str_starts_with($cab, "PK\x03\x04");

    if ($ext === 'xls' || str_starts_with($cab, "\xD0\xCF\x11\xE0")) {
        throw new RuntimeException('Formato .xls antigo não é suportado. Salve como .xlsx ou .csv.');
    }
    if ($ext === 'xlsx' || $e_zip) {
        if (!$e_zip) {
            throw new RuntimeException('O arquivo .xlsx está corrompido ou não é uma planilha Excel.');
        }
        return planilha_ler_xlsx($caminho);
    }
    if ($ext !== 'csv' && $ext !== 'txt') {
        throw new RuntimeException('Envie um arquivo .xlsx ou .csv.');
    }
    return planilha_ler_csv((string) file_get_contents($caminho));
}

/** @return array<int, list<string>> */
function planilha_ler_csv(string $texto): array
{
    if (str_starts_with($texto, "\xEF\xBB\xBF")) {
        $texto = substr($texto, 3);
    }
    // Excel em portugues salva "CSV" em Windows-1252 quando nao e "UTF-8".
    if (!mb_check_encoding($texto, 'UTF-8')) {
        $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }

    $primeira = strtok($texto, "\r\n") ?: '';
    $contagem = [';' => substr_count($primeira, ';'), ',' => substr_count($primeira, ','),
                 "\t" => substr_count($primeira, "\t")];
    arsort($contagem);
    $delim = (string) array_key_first($contagem);

    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $texto);
    rewind($fh);

    $linhas = [];
    $n = 0;
    while (($campos = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        $n++;
        $campos = array_map(static fn($c) => trim((string) $c), $campos);
        if (!array_filter($campos, static fn($c) => $c !== '')) {
            continue;
        }
        $linhas[$n] = $campos;
    }
    fclose($fh);
    return $linhas;
}

/** "AB" -> 27 (indice a partir de 0: A=0). */
function planilha_col_indice(string $letras): int
{
    $n = 0;
    foreach (str_split(strtoupper($letras)) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** 0 -> "A", 27 -> "AB". */
function planilha_col_letra(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/** @return array<int, list<string>> */
function planilha_ler_xlsx(string $caminho): array
{
    $zip = new ZipArchive();
    if ($zip->open($caminho) !== true) {
        throw new RuntimeException('Não consegui abrir o arquivo .xlsx.');
    }

    $ler = static function (string $parte) use ($zip): ?DOMDocument {
        $st = $zip->statName($parte);
        if ($st === false) {
            return null;
        }
        if ($st['size'] > 20 * 1024 * 1024) { // zip bomb
            throw new RuntimeException('A planilha é grande demais.');
        }
        $xml = $zip->getFromName($parte);
        if ($xml === false) {
            return null;
        }
        $doc = new DOMDocument();
        return @$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) ? $doc : null;
    };
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $xp = static function (DOMDocument $d) use ($ns): DOMXPath {
        $x = new DOMXPath($d);
        $x->registerNamespace('m', $ns);
        $x->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        return $x;
    };

    // Textos compartilhados
    $compartilhadas = [];
    if ($ss = $ler('xl/sharedStrings.xml')) {
        foreach ($xp($ss)->query('//m:si') as $si) {
            $txt = '';
            foreach ($xp($ss)->query('.//m:t[not(ancestor::m:rPh)]', $si) as $t) {
                $txt .= $t->textContent;
            }
            $compartilhadas[] = $txt;
        }
    }

    // Qual aba: a chamada "Itens", senao a primeira.
    $parte_aba = 'xl/worksheets/sheet1.xml';
    $wb = $ler('xl/workbook.xml');
    $rels = $ler('xl/_rels/workbook.xml.rels');
    if ($wb && $rels) {
        $alvos = [];
        foreach ($rels->getElementsByTagName('Relationship') as $rel) {
            $alvos[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
        }
        $escolhida = null;
        foreach ($xp($wb)->query('//m:sheets/m:sheet') as $i => $sheet) {
            $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if ($i === 0 || strcasecmp($sheet->getAttribute('name'), 'Itens') === 0) {
                if (isset($alvos[$rid]) && ($escolhida === null || strcasecmp($sheet->getAttribute('name'), 'Itens') === 0)) {
                    $escolhida = $alvos[$rid];
                }
            }
        }
        if ($escolhida !== null) {
            $parte_aba = str_starts_with($escolhida, '/') ? ltrim($escolhida, '/') : 'xl/' . $escolhida;
        }
    }

    $aba = $ler($parte_aba);
    $zip->close();
    if (!$aba) {
        throw new RuntimeException('Não encontrei a aba de dados na planilha.');
    }

    $x = $xp($aba);
    $linhas = [];
    $seq_linha = 0;
    foreach ($x->query('//m:sheetData/m:row') as $row) {
        $seq_linha++;
        $n = (int) $row->getAttribute('r') ?: $seq_linha;
        $seq_linha = $n;

        $celulas = [];
        $seq_col = -1;
        foreach ($x->query('m:c', $row) as $c) {
            $seq_col++;
            $ref = $c->getAttribute('r');
            if ($ref !== '' && preg_match('/^([A-Z]+)/i', $ref, $mm)) {
                $seq_col = planilha_col_indice($mm[1]);
            }
            $tipo = $c->getAttribute('t');
            $v = '';
            if ($tipo === 'inlineStr') {
                foreach ($x->query('.//m:t', $c) as $t) {
                    $v .= $t->textContent;
                }
            } else {
                $vn = $x->query('m:v', $c)->item(0);
                $v = $vn ? $vn->textContent : '';
                if ($tipo === 's') {
                    $v = $compartilhadas[(int) $v] ?? '';
                } elseif ($tipo === '' || $tipo === 'n') {
                    // 7,89123E+12 -> digitos inteiros (EAN digitado sem formato texto)
                    if (preg_match('/^-?\d+(?:\.\d+)?[eE]\+?\d+$/', $v)) {
                        $v = number_format((float) $v, 0, '', '');
                    }
                }
            }
            $celulas[$seq_col] = trim($v);
        }
        if (!$celulas) {
            continue;
        }
        $lista = [];
        for ($i = 0, $max = max(array_keys($celulas)); $i <= $max; $i++) {
            $lista[] = $celulas[$i] ?? '';
        }
        if (array_filter($lista, static fn($c) => $c !== '')) {
            $linhas[$n] = $lista;
        }
    }
    return $linhas;
}

// =====================================================================
// Interpretacao
// =====================================================================

/** Serial do Excel (dias desde 1899-12-30), "15/10/2026 18:30" ou ISO -> 'Y-m-d H:i:s'. */
function planilha_data($v): ?string
{
    $s = trim((string) $v);
    if ($s === '') {
        return null;
    }
    $num = str_replace(',', '.', $s);
    if (is_numeric($num) && (float) $num > 30000 && (float) $num < 80000) {
        $ts = (int) round(((float) $num - 25569) * 86400);
        return gmdate('Y-m-d H:i:s', $ts);
    }
    return data_mysql($s);
}

/** Mapa de cabecalho normalizado -> nome canonico da coluna. */
function planilha_mapa_cabecalhos(array $colunas = PLANILHA_COLUNAS, array $sinonimos = PLANILHA_SINONIMOS): array
{
    $mapa = [];
    foreach ($colunas as $canon) {
        $mapa[normalizar_texto(str_replace('_', ' ', $canon))] = $canon;
    }
    foreach ($sinonimos as $canon => $outros) {
        foreach ($outros as $o) {
            $mapa[normalizar_texto($o)] ??= $canon;
        }
    }
    return $mapa;
}

/**
 * Casa a primeira linha (cabecalho) com as colunas conhecidas e devolve cada
 * linha de dados como array associativo, indexada pelo numero da linha.
 * Serve a qualquer modelo (notas, repor): quem chama diz as colunas, os
 * sinonimos e quais sao obrigatorias.
 *
 * @param array<int, list<string>> $linhas saida de planilha_ler()
 * @return array{linhas:array<int,array<string,string>>, erros:list<array{linha:int,msg:string}>}
 */
function planilha_linhas_assoc(array $linhas, array $colunas, array $sinonimos, array $obrigatorias): array
{
    if (!$linhas) {
        return ['linhas' => [], 'erros' => [['linha' => 1, 'msg' => 'A planilha está vazia.']]];
    }

    $num_cab = array_key_first($linhas);
    $mapa = planilha_mapa_cabecalhos($colunas, $sinonimos);
    $mapeadas = []; // indice -> canonico
    foreach ($linhas[$num_cab] as $i => $titulo) {
        $canon = $mapa[normalizar_texto($titulo)] ?? null;
        if ($canon !== null && !in_array($canon, $mapeadas, true)) {
            $mapeadas[$i] = $canon;
        }
    }

    $erros = [];
    foreach ($obrigatorias as $obrig) {
        if (!in_array($obrig, $mapeadas, true)) {
            $erros[] = ['linha' => $num_cab, 'msg' => 'Falta a coluna "' . $obrig . '" no cabeçalho.'];
        }
    }
    if ($erros) {
        return ['linhas' => [], 'erros' => $erros];
    }
    unset($linhas[$num_cab]);

    $saida = [];
    foreach ($linhas as $n => $celulas) {
        $r = array_fill_keys($colunas, '');
        foreach ($mapeadas as $i => $canon) {
            $r[$canon] = trim((string) ($celulas[$i] ?? ''));
        }
        $saida[$n] = $r;
    }
    return ['linhas' => $saida, 'erros' => []];
}

/**
 * Agrupa as linhas em notas no formato de nota_processar_callback().
 *
 * Qualquer erro numa linha entra em "erros" e quem chama recusa o arquivo
 * inteiro: gravar so metade de uma nota seria pior do que nao gravar.
 *
 * @param array<int, list<string>> $linhas saida de planilha_ler()
 * @return array{notas:list<array>, erros:list<array{linha:int,msg:string}>}
 */
function planilha_agrupar(array $linhas): array
{
    $erros = [];
    if (!$linhas) {
        return ['notas' => [], 'erros' => [['linha' => 1, 'msg' => 'A planilha está vazia.']]];
    }
    if (count($linhas) > PLANILHA_MAX_LINHAS + 1) {
        return ['notas' => [], 'erros' => [['linha' => 1,
            'msg' => 'Máximo de ' . PLANILHA_MAX_LINHAS . ' itens por planilha.']]];
    }

    $lido = planilha_linhas_assoc($linhas, PLANILHA_COLUNAS, PLANILHA_SINONIMOS,
        ['loja', 'data_emissao', 'descricao', 'valor_unitario']);
    if ($lido['erros']) {
        return ['notas' => [], 'erros' => $lido['erros']];
    }

    // 1) agrupa as linhas
    $grupos = []; // chave do grupo => [rotulo, linhas: [n => assoc]]
    foreach ($lido['linhas'] as $n => $r) {
        $chave44 = so_digitos($r['chave']);
        if ($r['nota'] !== '') {
            $gk = 'n:' . normalizar_texto($r['nota']);
            $rotulo = 'Nota ' . $r['nota'];
        } elseif (strlen($chave44) === 44) {
            $gk = 'c:' . $chave44;
            $rotulo = 'Chave ' . substr($chave44, -8);
        } else {
            $gk = 'l:' . normalizar_texto($r['loja']) . '|' . $r['data_emissao'] . '|' . $r['numero'];
            $rotulo = $r['loja'] !== '' ? $r['loja'] : 'Nota sem loja';
        }
        $grupos[$gk]['rotulo'] ??= $rotulo;
        $grupos[$gk]['linhas'][$n] = $r;
    }

    // 2) monta cada nota
    $notas = [];
    $chaves_vistas = [];
    foreach ($grupos as $g) {
        $rotulo = $g['rotulo'];
        $primeira = array_key_first($g['linhas']);

        // Cabecalho: o primeiro valor preenchido vale; um valor diferente depois e erro.
        $cab = ['loja' => '', 'cnpj' => '', 'municipio' => '', 'uf' => '', 'data_emissao' => '',
                'numero' => '', 'serie' => '', 'chave' => ''];
        $origem = [];
        foreach ($g['linhas'] as $n => $r) {
            foreach ($cab as $campo => $atual) {
                $novo = $r[$campo];
                if ($novo === '') {
                    continue;
                }
                $norm = static fn(string $v): string => match ($campo) {
                    'cnpj', 'chave' => so_digitos($v),
                    'data_emissao'  => (string) planilha_data($v),
                    default         => normalizar_texto($v),
                };
                if ($atual === '') {
                    $cab[$campo] = $novo;
                    $origem[$campo] = $n;
                } elseif ($norm($atual) !== $norm($novo)) {
                    $erros[] = ['linha' => $n, 'msg' => $rotulo . ': "' . $campo
                        . '" diverge da linha ' . $origem[$campo] . ' (mesma nota).'];
                }
            }
        }

        if ($cab['loja'] === '') {
            $erros[] = ['linha' => $primeira, 'msg' => $rotulo . ': informe a loja.'];
        }
        $emissao = planilha_data($cab['data_emissao']);
        if ($emissao === null) {
            $erros[] = ['linha' => $origem['data_emissao'] ?? $primeira, 'msg' => $rotulo
                . ($cab['data_emissao'] === ''
                    ? ': informe a data de emissão.'
                    : ': data inválida "' . $cab['data_emissao'] . '" (use dd/mm/aaaa).')];
        }
        $chave = so_digitos($cab['chave']);
        if ($cab['chave'] !== '' && strlen($chave) !== 44) {
            $erros[] = ['linha' => $origem['chave'] ?? $primeira, 'msg' => $rotulo
                . ': a chave precisa ter 44 dígitos (veio com ' . strlen($chave) . ').'];
        }
        if (strlen($chave) === 44) {
            if (isset($chaves_vistas[$chave])) {
                $erros[] = ['linha' => $primeira, 'msg' => $rotulo . ': a mesma chave aparece em outra nota da planilha.'];
            }
            $chaves_vistas[$chave] = true;
        }

        // Itens
        $itens = [];
        $bruto_nota = $desc_nota = $liquido_nota = 0.0;
        foreach ($g['linhas'] as $n => $r) {
            if ($r['descricao'] === '') {
                $erros[] = ['linha' => $n, 'msg' => 'Item sem descrição.'];
                continue;
            }
            $qtd  = round(num_br($r['quantidade']), 4);
            $qtd  = $qtd > 0 ? $qtd : 1.0;
            $vu   = round(num_br($r['valor_unitario']), 4);
            $vtot = round(num_br($r['valor_total_item']), 2);
            $desc = round(num_br($r['desconto_item']), 2);
            if ($vu < 0 || $vtot < 0 || $desc < 0) {
                $erros[] = ['linha' => $n, 'msg' => '"' . $r['descricao'] . '": valores não podem ser negativos.'];
                continue;
            }
            if ($vu <= 0 && $vtot <= 0) {
                $erros[] = ['linha' => $n, 'msg' => '"' . $r['descricao'] . '": informe o valor unitário.'];
                continue;
            }
            if ($vtot <= 0) {
                $vtot = round($qtd * $vu, 2);
            } elseif ($vu <= 0) {
                $vu = round($vtot / $qtd, 4);
            }
            if ($desc > $vtot) {
                $erros[] = ['linha' => $n, 'msg' => '"' . $r['descricao'] . '": desconto maior que o valor do item.'];
                continue;
            }
            $itens[] = [
                'item'             => count($itens) + 1,
                'codigo'           => $r['codigo'],
                'descricao'        => $r['descricao'],
                'ean'              => so_digitos($r['ean']) ?: '',
                'unidade'          => $r['unidade'],
                'quantidade'       => $qtd,
                'valor_unitario'   => $vu,
                'valor_total_item' => $vtot,
                'desconto_item'    => $desc,
            ];
            $bruto_nota   += $vtot;
            $desc_nota    += $desc;
            $liquido_nota += $vtot - $desc;
        }

        $notas[] = [
            'rotulo' => $rotulo,
            'linha'  => $primeira,
            'chave'  => strlen($chave) === 44 ? $chave : null,
            'total'  => round($liquido_nota, 2),
            'cab'    => [
                'chave'                => strlen($chave) === 44 ? $chave : '',
                'emitente'             => $cab['loja'],
                'cnpj'                 => so_digitos($cab['cnpj']),
                'municipio'            => $cab['municipio'],
                'uf'                   => strtoupper(substr($cab['uf'], 0, 2)),
                'numero_nota'          => $cab['numero'],
                'serie'                => $cab['serie'],
                'emissao'              => $emissao ?? '',
                'valor_total_produtos' => round($bruto_nota, 2),
                'desconto_total_nota'  => round($desc_nota, 2),
                'valor_total_nota'     => round($liquido_nota, 2),
            ],
            'itens'  => $itens,
        ];
    }

    usort($erros, static fn($a, $b) => $a['linha'] <=> $b['linha']);
    return ['notas' => $notas, 'erros' => $erros];
}

// =====================================================================
// Gravacao (unica funcao com banco)
// =====================================================================

/**
 * Grava uma nota agrupada. Reusa nota_processar_callback(), o mesmo caminho
 * da nota que vem do n8n: loja, produto, desconto e preco liquido saem iguais.
 *
 * @return array{status:'importada'|'ignorada'|'erro', nota_id:?int, mensagem:string}
 */
function planilha_gravar(array $nota, int $usuario_id): array
{
    $criada = false;
    $nota_id = null;

    if ($nota['chave'] !== null) {
        $ja = q1('SELECT id, status FROM notas WHERE usuario_id = ? AND chave = ?', [$usuario_id, $nota['chave']]);
        if ($ja && $ja['status'] === 'ok') {
            return ['status' => 'ignorada', 'nota_id' => (int) $ja['id'], 'mensagem' => 'já importada'];
        }
        if ($ja) {
            $nota_id = (int) $ja['id']; // travada em pendente/erro: reaproveita
        }
    }
    if ($nota_id === null) {
        $nota_id = inserir('notas', [
            'usuario_id' => $usuario_id,
            'chave'      => $nota['chave'],
            'origem'     => 'manual',
            'status'     => 'processando',
        ]);
        $criada = true;
    }

    $r = nota_processar_callback([
        'nota_id' => $nota_id,
        'status'  => 'ok',
        'erro'    => '',
        'cab'     => $nota['cab'],
        'itens'   => $nota['itens'],
        'html'    => null,
    ]);
    if (!$r['ok'] || $r['itens'] === 0) {
        if ($criada) {
            nota_excluir($nota_id, $usuario_id); // nao deixa nota de erro orfa
        }
        return ['status' => 'erro', 'nota_id' => null, 'mensagem' => $r['mensagem']];
    }
    return ['status' => 'importada', 'nota_id' => $nota_id, 'mensagem' => ''];
}

// =====================================================================
// Modelo para baixar
// =====================================================================

/** Linhas de exemplo: uma nota com dois itens, o segundo com desconto. */
function planilha_exemplo(): array
{
    return [
        ['1', 'Mercado Exemplo', '12345678000190', 'Sorocaba', 'SP', '15/10/2026 18:30', '1234', '1', '',
         'ARROZ TIPO 1 5KG', '7891000100103', '101', 'UN', 2, 25.9, '', ''],
        ['1', '', '', '', '', '', '', '', '',
         'FEIJAO CARIOCA 1KG', '', '102', 'UN', 1, 8.49, '', 0.5],
    ];
}

/** Explicacao de cada coluna: [coluna, obrigatoria?, ajuda]. */
function planilha_instrucoes(): array
{
    return [
        ['nota', 'recomendado', 'Número livre (1, 2, 3...) que junta as linhas da mesma nota. Sem ele, o app agrupa pela chave ou por loja + data + número.'],
        ['loja', 'sim', 'Nome do mercado/emitente. Só a primeira linha da nota precisa preencher; as outras podem ficar vazias.'],
        ['cnpj', 'não', 'Com ou sem pontuação. Ajuda a reconhecer a mesma loja em notas diferentes.'],
        ['municipio', 'não', ''],
        ['uf', 'não', 'Sigla com 2 letras.'],
        ['data_emissao', 'sim', 'dd/mm/aaaa, com hora se quiser: 15/10/2026 18:30.'],
        ['numero', 'não', 'Número da nota fiscal.'],
        ['serie', 'não', ''],
        ['chave', 'não', '44 dígitos. Se a nota já existir no app com essa chave, ela é ignorada em vez de duplicar.'],
        ['descricao', 'sim', 'Nome do produto, uma linha por item.'],
        ['ean', 'não', 'Código de barras. Se o app já conhece esse EAN, liga ao produto existente.'],
        ['codigo', 'não', 'Código interno da loja.'],
        ['unidade', 'não', 'UN, KG, CX...'],
        ['quantidade', 'não', 'Se vazio, vale 1.'],
        ['valor_unitario', 'sim', 'Preço de uma unidade (use vírgula: 25,90). Pode ficar vazio se informar valor_total_item.'],
        ['valor_total_item', 'não', 'Se vazio, vale quantidade × valor_unitario.'],
        ['desconto_item', 'não', 'Desconto em reais sobre o total do item; o app guarda o preço líquido pago.'],
    ];
}

/**
 * O modelo das notas. Outros modelos (repor) passam o proprio spec com as
 * mesmas chaves: colunas, texto (colunas que o Excel nao pode virar numero),
 * larguras, exemplo, instrucoes, intro.
 */
function planilha_spec_notas(): array
{
    return [
        'colunas'    => PLANILHA_COLUNAS,
        'texto'      => PLANILHA_COLUNAS_TEXTO,
        'larguras'   => ['descricao' => 34, 'loja' => 24, 'chave' => 24],
        'exemplo'    => planilha_exemplo(),
        'instrucoes' => planilha_instrucoes(),
        'titulo'     => 'Como preencher a aba "Itens"',
        'intro'      => 'Uma linha por item. Linhas com o mesmo número em "nota" formam uma nota; pode haver várias notas no arquivo. Apague as 2 linhas de exemplo antes de enviar.',
    ];
}

function planilha_modelo_csv(?array $spec = null): string
{
    $spec ??= planilha_spec_notas();
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $spec['colunas'], ';', '"', '');
    foreach ($spec['exemplo'] as $linha) {
        // Decimais com virgula, como o Excel brasileiro espera
        $linha = array_map(static fn($c) => is_float($c) ? str_replace('.', ',', (string) $c) : $c, $linha);
        fputcsv($fh, $linha, ';', '"', '');
    }
    rewind($fh);
    $csv = (string) stream_get_contents($fh);
    fclose($fh);
    return "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $csv);
}

function planilha_xml_texto(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Uma <row>; estilos[i] = indice do estilo da coluna i (0 padrao, 1 texto, 2 negrito). */
function planilha_xml_linha(int $n, array $celulas, array $estilos = []): string
{
    $xml = '<row r="' . $n . '">';
    foreach ($celulas as $i => $v) {
        $ref = planilha_col_letra($i) . $n;
        $s = $estilos[$i] ?? 0;
        $attr = $s ? ' s="' . $s . '"' : '';
        if ($v === '' || $v === null) {
            $xml .= '<c r="' . $ref . '"' . $attr . '/>';
        } elseif (is_int($v) || is_float($v)) {
            $xml .= '<c r="' . $ref . '"' . $attr . '><v>' . $v . '</v></c>';
        } else {
            $xml .= '<c r="' . $ref . '"' . $attr . ' t="inlineStr"><is><t xml:space="preserve">'
                . planilha_xml_texto((string) $v) . '</t></is></c>';
        }
    }
    return $xml . '</row>';
}

/** Monta o .xlsx (duas abas: Itens e Instrucoes) e devolve o binario. */
function planilha_modelo_xlsx(?array $spec = null): string
{
    $spec ??= planilha_spec_notas();
    $colunas = $spec['colunas'];
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $cab = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

    // Itens
    $estilos_col = [];
    foreach ($colunas as $i => $c) {
        $estilos_col[$i] = in_array($c, $spec['texto'], true) ? 1 : 0;
    }
    $cols = '<cols>';
    foreach ($colunas as $i => $c) {
        $larg = $spec['larguras'][$c] ?? 16;
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $larg . '" customWidth="1" style="'
            . $estilos_col[$i] . '"/>';
    }
    $cols .= '</cols>';

    $dados = planilha_xml_linha(1, $colunas, array_fill(0, count($colunas), 2));
    foreach ($spec['exemplo'] as $k => $linha) {
        $dados .= planilha_xml_linha($k + 2, $linha, $estilos_col);
    }
    $sheet1 = $cab . '<worksheet xmlns="' . $ns . '"><sheetViews><sheetView workbookViewId="0">'
        . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . $cols . '<sheetData>' . $dados . '</sheetData></worksheet>';

    // Instrucoes
    $linhas = [planilha_xml_linha(1, [$spec['titulo']], [2])];
    $linhas[] = planilha_xml_linha(2, [$spec['intro']]);
    $linhas[] = planilha_xml_linha(4, ['coluna', 'obrigatória', 'o que colocar'], [2, 2, 2]);
    foreach ($spec['instrucoes'] as $k => $l) {
        $linhas[] = planilha_xml_linha($k + 5, $l);
    }
    $sheet2 = $cab . '<worksheet xmlns="' . $ns . '"><cols>'
        . '<col min="1" max="1" width="20" customWidth="1"/><col min="2" max="2" width="14" customWidth="1"/>'
        . '<col min="3" max="3" width="110" customWidth="1"/></cols><sheetData>'
        . implode('', $linhas) . '</sheetData></worksheet>';

    $styles = $cab . '<styleSheet xmlns="' . $ns . '">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $partes = [
        '[Content_Types].xml' => $cab . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => $cab . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => $cab . '<workbook xmlns="' . $ns . '" xmlns:r="' . $rel . '"><sheets>'
            . '<sheet name="Itens" sheetId="1" r:id="rId1"/><sheet name="Instruções" sheetId="2" r:id="rId2"/>'
            . '</sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $cab . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="' . $rel . '/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="' . $rel . '/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $sheet1,
        'xl/worksheets/sheet2.xml' => $sheet2,
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'mdl');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Não consegui montar o modelo .xlsx (extensão zip ausente?).');
    }
    foreach ($partes as $nome => $conteudo) {
        $zip->addFromString($nome, $conteudo);
    }
    $zip->close();
    $bin = (string) file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}
