<?php
declare(strict_types=1);

define('APP', dirname(__DIR__) . '/app');
require APP . '/helpers.php';

$ok = 0; $falhou = 0;

function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) {
        $ok++;
        return;
    }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

// ---- num_br: valores como a SEFAZ manda ----
checar('num_br 1.234,56',  num_br('1.234,56'), 1234.56);
checar('num_br 0,99',      num_br('0,99'), 0.99);
checar('num_br 12',        num_br('12'), 12.0);
checar('num_br float',     num_br(3.5), 3.5);
checar('num_br vazio',     num_br(''), 0.0);
checar('num_br null',      num_br(null), 0.0);
checar('num_br R$ 15,90',  num_br('R$ 15,90'), 15.90);
checar('num_br ponto dec', num_br('7.25'), 7.25);
checar('num_br milhar',    num_br('1.234.567,89'), 1234567.89);

// ---- data_mysql ----
checar('data br completa', data_mysql('05/09/2026 19:32:11'), '2026-09-05 19:32:11');
checar('data br sem hora', data_mysql('05/09/2026'), '2026-09-05 00:00:00');
checar('data iso',         data_mysql('2026-09-05 19:32:11'), '2026-09-05 19:32:11');
checar('data vazia',       data_mysql(''), null);
checar('data lixo',        data_mysql('nao e data'), null);
// A emissao da NFC-e vem com o fuso colado: sem cortar, o strtotime lia
// 04/09 como 9 de abril.
checar('data br com fuso',     data_mysql('04/09/2026 22:26:38-03:00'), '2026-09-04 22:26:38');
checar('data br fuso sem seg', data_mysql('04/09/2026 22:26-03:00'), '2026-09-04 22:26:00');
checar('data iso com fuso',    data_mysql('2026-09-04T22:26:38-03:00'), '2026-09-04 22:26:38');
checar('data com Z',           data_mysql('04/09/2026 22:26:38Z'), '2026-09-04 22:26:38');

// ---- normalizar_texto ----
checar('norm acentos',   normalizar_texto('Pão de Açúcar'), 'PAO DE ACUCAR');
checar('norm pontuacao', normalizar_texto('ARROZ 5KG - TIPO 1'), 'ARROZ 5KG TIPO 1');
checar('norm entidade',  normalizar_texto('D&amp;#39;ORO'), 'D ORO');
checar('norm espacos',   normalizar_texto('  LEITE    INTEGRAL '), 'LEITE INTEGRAL');

// ---- decodificar_html: a armadilha da entidade dupla ----
checar('entidade dupla', decodificar_html('D&amp;#39;ORO'), "D'ORO");
checar('entidade simples', decodificar_html('CAF&Eacute;'), 'CAFÉ');
checar('texto limpo', decodificar_html('ARROZ'), 'ARROZ');

// ---- ean_normalizado ----
checar('ean 13',        ean_normalizado('7891000315507'), '7891000315507');
checar('ean SEM GTIN',  ean_normalizado('SEM GTIN'), null);
checar('ean semgtin',   ean_normalizado('SEMGTIN'), null);
checar('ean vazio',     ean_normalizado(''), null);
checar('ean zeros',     ean_normalizado('0000000000000'), null);
checar('ean curto',     ean_normalizado('12345'), null);
checar('ean 8',         ean_normalizado('12345670'), '12345670');
checar('ean com traco', ean_normalizado('789-1000-315507'), '7891000315507');

// ---- chave_do_qrcode: a nota real 28048 da memoria ----
$chave = '35260946029724000673651010000280481783880108';
$qr = 'https://www.nfce.fazenda.sp.gov.br/qrcode?p=' . $chave . '|2|1|1|A1B2C3D4E5F6';
checar('chave via QR', chave_do_qrcode($qr), $chave);
checar('chave crua',   chave_do_qrcode($chave), $chave);
checar('chave curta',  chave_do_qrcode('https://x/qrcode?p=123|2|1'), null);
checar('sem chave',    chave_do_qrcode('https://exemplo.com'), null);

// ---- formatacao ----
checar('moeda',   moeda(1234.5), 'R$ 1.234,50');
// Data de filtro: so AAAA-MM-DD que existe. O resto nao chega ao SQL.
checar('data iso',            data_iso('2026-09-06'), '2026-09-06');
checar('data iso com espaco', data_iso(' 2026-09-06 '), '2026-09-06');
checar('dia que nao existe',  data_iso('2026-02-30'), null);
checar('mes que nao existe',  data_iso('2026-13-01'), null);
checar('formato errado',      data_iso('06/09/2026'), null);
checar('data vazia',          data_iso(''), null);
checar('data nula',           data_iso(null), null);
checar('injecao nao passa',   data_iso("2026-09-06' OR '1"), null);
checar('fator',        fator_fmt(1.94, 1.0),  '1,94');
checar('fator arredonda', fator_fmt(3.99, 2.06), '1,94');
checar('fator abaixo do custo', fator_fmt(0.85, 1.0), '0,85');
checar('qtd 2',   qtd_fmt(2.0), '2');
checar('qtd 1,5', qtd_fmt(1.5), '1,5');
checar('qtd 0,25', qtd_fmt(0.25), '0,25');
checar('cnpj',    cnpj_fmt('46029724000673'), '46.029.724/0006-73');

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
