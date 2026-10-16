<?php
declare(strict_types=1);

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
// So funcoes puras: planilha_gravar() (banco) nao e chamada aqui.
require APP . '/planilha.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

$tmp = sys_get_temp_dir() . '/planilha-teste-' . getmypid();
@mkdir($tmp);

// ---------- CSV ----------
$csv = "\xEF\xBB\xBFnota;loja;data_emissao;descricao;quantidade;valor_unitario;desconto_item\r\n"
     . "1;Mercado A;15/10/2026 18:30;ARROZ;2;25,90;\r\n"
     . "2;Padaria B;16/10/2026;PAO;10;0,80;\r\n"
     . "1;;;FEIJAO;1;8,49;0,50\r\n";
file_put_contents("$tmp/a.csv", $csv);
$linhas = planilha_ler("$tmp/a.csv", 'a.csv');
checar('csv: 4 linhas lidas', count($linhas), 4);
checar('csv: BOM removido do cabecalho', $linhas[1][0], 'nota');
checar('csv: numeros de linha preservados', array_keys($linhas), [1, 2, 3, 4]);

$r = planilha_agrupar($linhas);
checar('agrupar: sem erros', $r['erros'], []);
checar('agrupar: 2 notas, intercaladas', count($r['notas']), 2);
$a = $r['notas'][0];
checar('nota 1: loja', $a['cab']['emitente'], 'Mercado A');
checar('nota 1: emissao', $a['cab']['emissao'], '2026-10-15 18:30:00');
checar('nota 1: 2 itens (linha 4 herda o cabecalho)', count($a['itens']), 2);
checar('nota 1: total liquido 2x25,90 + 8,49 - 0,50', $a['total'], 59.79);
checar('nota 1: desconto total', $a['cab']['desconto_total_nota'], 0.5);
checar('nota 1: item 2 numerado', $a['itens'][1]['item'], 2);
checar('nota 2: data sem hora', $r['notas'][1]['cab']['emissao'], '2026-10-16 00:00:00');
checar('nota 2: total 10 x 0,80', $r['notas'][1]['total'], 8.0);

// CSV com virgula e Windows-1252
$cp = "loja,data,produto,qtd,preco\n" . mb_convert_encoding("Açougue Zé,01/02/2026,Picanha,1,\"89,90\"\n", 'Windows-1252', 'UTF-8');
file_put_contents("$tmp/b.csv", $cp);
$r2 = planilha_agrupar(planilha_ler("$tmp/b.csv", 'b.csv'));
checar('csv virgula + cp1252: sem erros', $r2['erros'], []);
checar('csv cp1252: acento preservado', $r2['notas'][0]['cab']['emitente'], 'Açougue Zé');
checar('sinonimos de cabecalho (data/produto/qtd/preco)', $r2['notas'][0]['itens'][0]['valor_unitario'], 89.9);

// ---------- modelo XLSX: escreve e le de volta ----------
file_put_contents("$tmp/modelo.xlsx", planilha_modelo_xlsx());
$lx = planilha_ler("$tmp/modelo.xlsx", 'modelo.xlsx');
checar('xlsx: le a aba Itens (cabecalho + 2 exemplos)', count($lx), 3);
checar('xlsx: cabecalho', $lx[1], PLANILHA_COLUNAS);
checar('xlsx: texto com zeros/EAN preservado', $lx[2][10], '7891000100103');
$rx = planilha_agrupar($lx);
checar('xlsx: modelo agrupa sem erros', $rx['erros'], []);
checar('xlsx: 1 nota de exemplo', count($rx['notas']), 1);
checar('xlsx: total do exemplo 51,80 + 8,49 - 0,50', $rx['notas'][0]['total'], 59.79);

// o modelo CSV tambem le
file_put_contents("$tmp/modelo.csv", planilha_modelo_csv());
$rc = planilha_agrupar(planilha_ler("$tmp/modelo.csv", 'modelo.csv'));
checar('modelo csv: sem erros', $rc['erros'], []);
checar('modelo csv: mesmo total', $rc['notas'][0]['total'], 59.79);

// ---------- datas ----------
checar('data: serial do Excel com hora', planilha_data('46310.75'), '2026-10-15 18:00:00');
checar('data: dd/mm/aaaa', planilha_data('05/09/2026'), '2026-09-05 00:00:00');
checar('data: lixo', planilha_data('ontem'), null);

// ---------- erros ----------
$base = "nota;loja;data_emissao;descricao;quantidade;valor_unitario;chave\n";
$e = static fn(string $corpo) => planilha_agrupar(planilha_ler_csv($base . $corpo));

$x = $e("1;Mercado A;15/10/2026;;1;5\n");
checar('erro: item sem descricao aponta a linha', $x['erros'][0], ['linha' => 2, 'msg' => 'Item sem descrição.']);

$x = $e("1;Mercado A;15/10/2026;LEITE;1;\n");
checar('erro: item sem valor', str_contains($x['erros'][0]['msg'], 'valor unitário'), true);

$x = $e("1;Mercado A;15/10/2026;LEITE;1;5\n1;Mercado B;15/10/2026;SUCO;1;7\n");
checar('erro: loja diverge dentro da nota', [$x['erros'][0]['linha'], str_contains($x['erros'][0]['msg'], 'diverge da linha 2')], [3, true]);

$x = $e("1;Mercado A;99/99/2026;LEITE;1;5\n");
checar('erro: data invalida', str_contains($x['erros'][0]['msg'], 'data inválida'), true);

$x = $e("1;Mercado A;15/10/2026;LEITE;1;5;123\n");
checar('erro: chave com tamanho errado', str_contains($x['erros'][0]['msg'], '44 dígitos'), true);

$chave = str_repeat('1', 44);
$x = $e("1;Mercado A;15/10/2026;LEITE;1;5;$chave\n2;Mercado A;15/10/2026;SUCO;1;5;$chave\n");
checar('erro: mesma chave em duas notas', str_contains($x['erros'][0]['msg'], 'mesma chave'), true);

$x = $e("1;Mercado A;15/10/2026;LEITE;1;-5\n");
checar('erro: valor negativo', str_contains($x['erros'][0]['msg'], 'negativos'), true);

$x = planilha_agrupar([1 => ['loja', 'descricao']]);
checar('erro: faltam colunas obrigatorias', count($x['erros']), 2);

checar('erro: planilha vazia', planilha_agrupar([])['erros'][0]['msg'], 'A planilha está vazia.');

// sem coluna "nota": agrupa pela chave e depois por loja+data+numero
$x = $e(";Mercado A;15/10/2026;LEITE;1;5;$chave\n;;;SUCO;1;7;$chave\n");
checar('agrupa por chave quando nota vem vazia', [count($x['notas']), count($x['notas'][0]['itens'] ?? [])], [1, 2]);
checar('chave valida vai para a nota', $x['notas'][0]['chave'], $chave);

// valor total sem unitario deriva o unitario
$x = planilha_agrupar(planilha_ler_csv("loja;data_emissao;descricao;quantidade;valor_unitario;valor_total_item\nA;01/01/2026;QUEIJO;4;;20\n"));
checar('unitario derivado do total', $x['notas'][0]['itens'][0]['valor_unitario'], 5.0);

// formatos nao suportados
foreach (['x.xls' => 'xls antigo', 'x.pdf' => 'extensao estranha'] as $nome => $rotulo) {
    file_put_contents("$tmp/$nome", 'conteudo');
    try { planilha_ler("$tmp/$nome", $nome); $pegou = false; } catch (RuntimeException) { $pegou = true; }
    checar("recusa $rotulo", $pegou, true);
}

array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
