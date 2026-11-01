<?php
declare(strict_types=1);

/*
 * Discrepancias das notas: caixa lancada como unidade.
 *
 * Os casos sao os de outubro de 2026: a caixa de lamen (1 CXA a R$ 114,50)
 * vinculada ao pacote que vende a R$ 5,70, e o Tang (1 EMB a R$ 26,46) no
 * saquinho de R$ 2,36. Nada aqui toca o MySQL.
 *
 *   php testes/discrepancias.php
 */

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';
require APP . '/discrepancias.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

// ------------------------------------------------- o codigo da unidade na caixa
checar('digito do EAN-13', gtin_digito('789107900022'), 9);
checar('a caixa do lamen esconde o pacote', gtin_unidade_da_caixa('17891079000226'), '7891079000229');
checar('qualquer indicador de 1 a 8 vale', gtin_unidade_da_caixa('27891079000223'), '7891079000229');
checar('EAN-13 nao e caixa', gtin_unidade_da_caixa('7622210571854'), null);
checar('indicador 9 e peso variavel, nao caixa', gtin_unidade_da_caixa('97891079000220'), null);
checar('sem codigo nao inventa', gtin_unidade_da_caixa(null), null);

// ------------------------------------------------------------------- o motivo
checar('custo acima do preco de venda', discrepancia_motivo(114.50, 5.70, 2.42, null),
    'Custou R$ 114,50 a unidade, mas a unidade vende a R$ 5,70.');
checar('muitas vezes o custo de sempre, sem preco de venda',
    discrepancia_motivo(26.46, null, 1.47, null), 'Custou R$ 26,46, 18 vezes o que costuma custar (R$ 1,47).');
checar('caixa ja aberta antes manda', str_contains((string) discrepancia_motivo(114.50, null, null, 50.0), 'caixa de 50'), true);
checar('custo normal nao e discrepancia', discrepancia_motivo(2.29, 5.70, 2.42, null), null);
checar('o dobro do de sempre ainda nao e caixa', discrepancia_motivo(4.80, null, 2.42, null), null);
checar('sem nada para comparar nao acusa', discrepancia_motivo(114.50, null, null, null), null);

// --------------------------------------------------- quantas vem na caixa
checar('a conta arredonda para tamanhos de caixa', discrepancia_por_caixa(26.46, 1.47, 'REFR.PO TANG'), [18, 20]);
checar('o lamen fica entre 48 e 50', discrepancia_por_caixa(114.50, 2.42, 'MAC.NISSIN LAMEN'), [48, 50]);
checar('a descricao vem antes da conta', discrepancia_por_caixa(36.0, 3.0, 'REFRI LATA C/12'), [12, 10]);
checar('12X350ML na descricao', discrepancia_por_caixa(40.0, null, 'CERVEJA 12X350ML'), [12]);
checar('caixa ja aberta antes vem primeiro', discrepancia_por_caixa(114.50, 2.42, 'MAC.NISSIN LAMEN', 50.0), [50, 48]);
checar('sem pista nenhuma nao chuta', discrepancia_por_caixa(114.50, null, 'MAC.NISSIN LAMEN'), []);

// ------------------------------------------------------- nome parecido
checar('abreviacao da nota casa com o nome por extenso',
    discrepancia_parecido('MAC.NISSIN LAMEN', 'MACARRAO INSTANTANEO NISSIN GALINHA CAIPIRA 80G'), 2);
checar('palavra de embalagem nao conta', discrepancia_parecido('CXA UND PCT', 'CXA UND PCT'), 0);
checar('nome que nada tem a ver', discrepancia_parecido('MAC.NISSIN LAMEN', 'CHOCOLATE KIT KAT'), 0);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
