<?php
declare(strict_types=1);

/*
 * A parte pura do Repor em lote: ler a planilha, validar cada linha, decidir
 * o que muda e montar o corpo que vai para planograma_salvar(). Nada sai do
 * processo — nem TouchPay, nem banco.
 *
 *   php testes/planilha_repor.php
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/mercado.php';
require APP . '/touchpay.php';
require APP . '/planograma.php';
require APP . '/planilha.php';
require APP . '/planilha_repor.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

$hoje = '2026-10-16';
$pdvs = [
    ['id' => 1, 'nome' => 'Geladeira Bloco A', 'planograma_id' => 10],
    ['id' => 2, 'nome' => 'Armário B', 'planograma_id' => 11],
    ['id' => 3, 'nome' => 'Sem Plano', 'planograma_id' => null],
];
$ler = static function (string $csv, ?int $pdv = 1, float $taxa = 1.9) use ($pdvs, $hoje): array {
    $l = planilha_linhas_assoc(planilha_ler_csv($csv), REPOR_COLUNAS, REPOR_SINONIMOS, ['codigo']);
    if ($l['erros']) { return ['itens' => [], 'erros' => $l['erros']]; }
    return repor_ler_linhas($l['linhas'], $pdv, $taxa, $pdvs, $hoje);
};
$H = "codigo;descricao;entrada;validade;preco;custo;taxa;necessaria;critico;pdv\n";

// ---------- o preco: mesma conta do precoSugerido() do JS ----------
checar('preco digitado ganha', repor_preco(9.5, 5.0, 2.0), 9.5);
checar('custo x taxa', repor_preco(null, 10.0, 1.9), 19.0);
checar('arredonda em centavos', repor_preco(null, 3.33, 1.9), 6.33);
checar('sem custo nem preco', repor_preco(null, null, 1.9), null);
checar('custo zero nao vale', repor_preco(null, 0.0, 1.9), null);
checar('preco zero cai para custo x taxa', repor_preco(0.0, 4.0, 2.0), 8.0);

// ---------- leitura ----------
$r = $ler($H . "7891000100103;Arroz;12;15/03/2027;;21,5;;12;4;\n");
checar('linha valida: sem erros', $r['erros'], []);
$it = $r['itens'][0];
checar('codigo', $it['codigo'], '7891000100103');
checar('entrada inteira', $it['entrada'], 12.0);
checar('validade em ISO', $it['validade'], '2027-03-15');
checar('custo x taxa padrao (21,5 x 1,9)', $it['preco'], 40.85);
checar('pdv padrao da tela', [$it['pdv_id'], $it['pdv']], [1, 'Geladeira Bloco A']);
checar('necessaria e critico', [$it['necessaria'], $it['critico']], [12.0, 4.0]);

$r = $ler($H . "7891000100103;;5;;12,90;;;;;armario b\n");
checar('pdv da linha vale (sem acento/caixa)', $r['itens'][0]['pdv_id'], 2);
checar('preco digitado', $r['itens'][0]['preco'], 12.9);

$r = $ler($H . "7891000100103;;5;;;10;2,5;;;\n");
checar('taxa da linha vence a padrao', $r['itens'][0]['preco'], 25.0);

$r = $ler("EAN;Qtd;Vencimento;Preço de venda\n7891000100103;3;01/12/2026;9,90\n");
checar('sinonimos de cabecalho', [$r['erros'], $r['itens'][0]['entrada'], $r['itens'][0]['preco']], [[], 3.0, 9.9]);

$r = $ler($H . "7891000100103;;5;46100;;;;;;\n");
checar('validade como serial do Excel', $r['itens'][0]['validade'] !== null, true);

// ---------- erros ----------
$erro = static fn(string $linha, ?int $pdv = 1) => $ler($GLOBALS['H'] . $linha . "\n", $pdv)['erros'][0]['msg'] ?? null;
checar('codigo curto', str_contains((string) $erro("123;;5;;;;;;;"), 'inválido'), true);
checar('entrada fracionada', str_contains((string) $erro("7891000100103;;2,5;;;;;;;"), 'inteiro'), true);
checar('entrada negativa', str_contains((string) $erro("7891000100103;;-3;;;;;;;"), 'zero ou mais'), true);
checar('texto onde era numero', str_contains((string) $erro("7891000100103;;5;;abc;;;;;"), 'não é número'), true);
checar('validade invalida', str_contains((string) $erro("7891000100103;;5;31/02/2027;;;;;;"), 'inválida'), true);
checar('validade absurda (ano 5027)', str_contains((string) $erro("7891000100103;;5;01/01/5027;;;;;;"), 'fora do razoável'), true);
checar('linha sem nada para fazer', str_contains((string) $erro("7891000100103;so descricao;;;;;;;;"), 'nada para fazer'), true);
checar('pdv desconhecido lista os validos', str_contains((string) $erro("7891000100103;;5;;;;;;;Lugar Nenhum"), 'Geladeira Bloco A'), true);
checar('sem pdv nenhum', str_contains((string) $erro("7891000100103;;5;;;;;;;", null), 'Informe o PDV'), true);
checar('pdv sem planograma', str_contains((string) $erro("7891000100103;;5;;;;;;;Sem Plano"), 'planograma ativo'), true);
checar('preco zero', str_contains((string) $erro("7891000100103;;5;;0;;;;;"), 'maior que zero'), true);

$r = $ler($H . "7891000100103;;5;;;;;;;\n7891000100103;;3;;;;;;;\n");
checar('codigo repetido no mesmo pdv', str_contains($r['erros'][0]['msg'] ?? '', 'repetido'), true);
$r = $ler($H . "7891000100103;;5;;;;;;;\n7891000100103;;3;;;;;;;Armário B\n");
checar('mesmo codigo em pdvs diferentes pode', $r['erros'], []);

checar('planilha sem produtos', $ler($H)['erros'][0]['msg'], 'A planilha não tem nenhum produto.');
$grande = $H . str_repeat("7891000100103;;1;;;;;;;\n", REPOR_MAX_LINHAS + 1);
checar('limite de linhas', str_contains($ler($grande)['erros'][0]['msg'], 'Máximo'), true);
$f = planilha_linhas_assoc(planilha_ler_csv("descricao;entrada\nArroz;3\n"), REPOR_COLUNAS, REPOR_SINONIMOS, ['codigo']);
checar('cabecalho sem a coluna codigo', $f['erros'][0]['msg'], 'Falta a coluna "codigo" no cabeçalho.');

// ---------- o modelo: escreve, le de volta e passa na validacao ----------
$tmp = sys_get_temp_dir() . '/repor-' . getmypid();
@mkdir($tmp);
foreach (['xlsx' => planilha_modelo_xlsx(repor_spec()), 'csv' => planilha_modelo_csv(repor_spec())] as $ext => $bin) {
    file_put_contents("$tmp/m.$ext", $bin);
    $linhas = planilha_ler("$tmp/m.$ext", "m.$ext");
    $l = planilha_linhas_assoc($linhas, REPOR_COLUNAS, REPOR_SINONIMOS, ['codigo']);
    $r = repor_ler_linhas($l['linhas'], 1, 1.9, $pdvs, $hoje);
    checar("modelo $ext: cabecalho", $linhas[1], REPOR_COLUNAS);
    checar("modelo $ext: 2 exemplos validos", [$r['erros'], count($r['itens'])], [[], 2]);
    checar("modelo $ext: codigo nao vira numero", $r['itens'][0]['codigo'], '7891000100103');
}
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);

// ---------- o plano: onde achou e o que muda ----------
$item = static fn(array $x = []) => $x + ['pdv_id' => 1, 'codigo' => '7891000100103', 'descricao' => 'Arroz',
    'entrada' => 12.0, 'validade' => null, 'preco' => null, 'necessaria' => null, 'critico' => null];
$no_plano = ['onde' => 'planograma', 'entrada' => [
    'produto_id' => 77, 'descricao' => 'ARROZ 5KG', 'preco' => 20.0, 'estoque' => 5.0,
    'necessaria' => 10.0, 'critico' => 3.0, 'validade' => '2027-01-10']];

$p = repor_plano($no_plano, $item());
checar('planograma: estoque soma a entrada', $p['mudancas'], [['campo' => 'estoque', 'de' => 5.0, 'para' => 17.0]]);
checar('planograma: classe e produto', [$p['classe'], $p['produto_id']], ['planograma', 77]);
checar('planograma: pronto para gravar', repor_status_do_plano($p), 'planograma');

$p = repor_plano($no_plano, $item(['preco' => 22.5, 'entrada' => null]));
checar('so preco muda', array_column($p['mudancas'], 'campo'), ['preco']);

$p = repor_plano($no_plano, $item(['entrada' => null]));
checar('nada a mudar vira sem_mudanca', [$p['classe'], $p['mudancas']], ['sem_mudanca', []]);

$p = repor_plano($no_plano, $item(['entrada' => null, 'validade' => '2027-06-01']));
checar('validade que vence depois: mantem a do estoque', [$p['mudancas'], $p['nota'] !== null], [[], true]);
$p = repor_plano($no_plano, $item(['entrada' => null, 'validade' => '2026-12-01']));
checar('validade que vence antes: grava', $p['mudancas'], [['campo' => 'validade', 'de' => '2027-01-10', 'para' => '2026-12-01']]);

$no_cadastro = ['onde' => 'cadastro', 'produto' => ['produto_id' => 88, 'descricao' => 'REFRI 2L', 'codigo' => '789', 'ean' => '789']];
$p = repor_plano($no_cadastro, $item(['preco' => 11.9]));
checar('cadastro: entra no planograma com preco', [$p['classe'], repor_status_do_plano($p)], ['cadastro', 'cadastro']);
checar('cadastro: estoque comeca do zero', $p['mudancas'][3], ['campo' => 'estoque', 'de' => 0.0, 'para' => 12.0]);
$p = repor_plano($no_cadastro, $item());
checar('cadastro sem preco vira pendencia', [repor_status_do_plano($p), $p['mudancas']], ['pendencia', []]);

$p = repor_plano(['onde' => 'nenhum'], $item());
checar('sem cadastro', [repor_status_do_plano($p), $p['produto_id']], ['sem_cadastro', 0]);

// ---------- o corpo para planograma_salvar ----------
$p = repor_plano($no_plano, $item());
$m = repor_dados_salvar($p, $item());
checar('salvar: estoque absoluto = lido + entrada', $m['dados']['estoque'], 17.0);
checar('salvar: visto vai junto (conflito)', $m['dados']['visto']['estoque'], 5.0);
checar('salvar: validade em modo auto', $m['dados']['validade_acao'], 'auto');
checar('salvar: guarda base e alvo', [$m['estoque_base'], $m['estoque_alvo']], [5.0, 17.0]);

// A tentativa anterior chegou a gravar o estoque (lido ja e o alvo): nao soma de novo.
$ja = repor_plano(['onde' => 'planograma', 'entrada' => ['estoque' => 17.0] + $no_plano['entrada']], $item());
$m = repor_dados_salvar($ja, $item(), ['estoque_base' => 5.0, 'estoque_alvo' => 17.0]);
checar('retry: estoque ja entrou, nao soma duas vezes', [$m['dados']['estoque'], $m['ja_aplicado']], [null, true]);
// A tentativa anterior nao chegou a gravar (lido continua na base): soma normal.
$m = repor_dados_salvar($p, $item(), ['estoque_base' => 5.0, 'estoque_alvo' => 17.0]);
checar('retry: estoque nao entrou, soma', [$m['dados']['estoque'], $m['ja_aplicado']], [17.0, false]);
// Venda no meio (lido diferente da base e do alvo): soma sobre o que ha agora.
$vendeu = repor_plano(['onde' => 'planograma', 'entrada' => ['estoque' => 3.0] + $no_plano['entrada']], $item());
$m = repor_dados_salvar($vendeu, $item(), ['estoque_base' => 5.0, 'estoque_alvo' => 17.0]);
checar('retry: houve venda, soma sobre o estoque de agora', $m['dados']['estoque'], 15.0);

$pc = repor_plano($no_cadastro, $item(['preco' => 11.9]));
$m = repor_dados_salvar($pc, $item(['preco' => 11.9]));
checar('cadastro: sem visto (nao existe no planograma)', array_key_exists('visto', $m['dados']), false);
checar('cadastro: estoque = entrada', $m['dados']['estoque'], 12.0);
$m = repor_dados_salvar($p, $item(['entrada' => null]));
checar('sem entrada: estoque fica de fora', $m['dados']['estoque'], null);

// ---------- textos ----------
checar('texto do de/para', repor_mudancas_texto([
    ['campo' => 'estoque', 'de' => 5.0, 'para' => 17.0],
    ['campo' => 'preco', 'de' => 9.5, 'para' => 11.9],
    ['campo' => 'validade', 'de' => null, 'para' => '2027-03-15'],
]), 'estoque 5 → 17; preço 9,50 → 11,90; validade — → 15/03/2027');

$csv = repor_sem_cadastro_csv([['codigo' => '789', 'dados' => ['descricao' => 'REFRI', 'pdv' => 'A', 'entrada' => 3, 'validade' => '2027-03-15']]]);
checar('csv dos sem cadastro', $csv, "\xEF\xBB\xBFcodigo;descricao;pdv;entrada;validade\r\n789;REFRI;A;3;15/03/2027\r\n");

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
