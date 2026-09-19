<?php
declare(strict_types=1);

/**
 * O nome de um codigo de barras, para a tela Mercado.
 *
 * Na gondola do supermercado quase nada e conhecido: a base do app so sabe o
 * que voce ja comprou. Entao o nome vem de uma cascata, e cada degrau so
 * existe porque o de cima nao respondeu:
 *
 *  1. o nome que VOCE digitou para aquele codigo — ganha de todos, inclusive
 *     das bases externas: foi escolhido para esta finalidade;
 *  2. as suas notas (produtos.ean), que e a sua verdade;
 *  3. o espelho da loja (loja_itens.ean);
 *  4. o cache do que a Open Food Facts ja respondeu antes;
 *  5. a Open Food Facts ao vivo — gratis, sem chave, boa em alimento e bebida
 *     embalados e praticamente vazia fora disso no Brasil.
 *
 * O cache (`ean_nomes`) e uma tabela a parte de proposito: a tela Mercado nao
 * pode criar produto no catalogo nem linha no espelho da loja. Ela guarda
 * nome, e so.
 */

/** Limite de paciencia da consulta externa. Bipar o proximo item vale mais. */
const OFF_TIMEOUT = 4;

/**
 * A chave do cache: so digitos, no maximo 14.
 *
 * Nao passa pelo ean_normalizado() de proposito — o codigo interno de balanca
 * (o "2" na frente, peso embutido) nao tem tamanho de GTIN e mesmo assim
 * precisa poder guardar um nome.
 */
function mercado_chave($v): ?string
{
    $d = so_digitos((string) $v);
    if (strlen($d) < 6) {
        return null;
    }
    return substr($d, 0, 14);
}

/**
 * O nome dentro da resposta da Open Food Facts. Funcao pura.
 *
 * Prefere o nome em portugues. A quantidade entra junto quando ainda nao
 * esta no nome: numa lista de compras, "Coca-Cola" e "Coca-Cola 2 L" sao
 * itens diferentes, e o preco que se confere depende de qual e.
 */
function off_nome(array $resposta): ?string
{
    if ((int) ($resposta['status'] ?? 0) !== 1 || !is_array($resposta['product'] ?? null)) {
        return null;
    }
    $p = $resposta['product'];

    $nome = trim((string) ($p['product_name_pt'] ?? ''));
    if ($nome === '') {
        $nome = trim((string) ($p['product_name'] ?? ''));
    }
    if ($nome === '') {
        return null;
    }

    $qtd = trim((string) ($p['quantity'] ?? ''));
    if ($qtd !== '') {
        // "2l" dentro de "Coca-Cola 2Lt" ja conta como dito: a comparacao
        // ignora espaco e caixa, senao a quantidade entraria duas vezes.
        $achatar = static fn (string $s): string =>
            strtolower(str_replace(' ', '', $s));
        if (strpos($achatar($nome), $achatar($qtd)) === false) {
            $nome .= ' ' . $qtd;
        }
    }

    return mb_substr($nome, 0, 255);
}

/**
 * Pergunta o nome a Open Food Facts.
 *
 * Eles pedem um User-Agent que identifique o app e um jeito de falar com quem
 * o fez — por isso o dominio, e nao um navegador fingido. Devolve null em
 * qualquer tropeco: nome de produto e enfeite, e a compra nao pode parar
 * porque uma API de fora esta lenta.
 */
function off_buscar(string $ean): ?string
{
    $url = 'https://world.openfoodfacts.org/api/v2/product/' . rawurlencode($ean)
         . '.json?fields=product_name,product_name_pt,quantity';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => OFF_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'AlphaMarket/1.0 (+https://mercadinho.bryanzendron.com.br)',
    ]);
    $corpo = curl_exec($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if (!is_string($corpo) || $http !== 200) {
        return null;
    }
    $json = json_decode($corpo, true);
    return is_array($json) ? off_nome($json) : null;
}

/** Guarda o nome daquele codigo. 'usuario' nunca e sobrescrito por 'off'. */
function ean_nome_gravar(string $ean, string $nome, string $fonte): void
{
    $nome = trim($nome);
    if ($nome === '') {
        return;
    }
    exec_sql(
        'INSERT INTO ean_nomes (ean, nome, fonte, atualizado_em) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
              nome = IF(fonte = ? AND VALUES(fonte) <> ?, nome, VALUES(nome)),
              fonte = IF(fonte = ? AND VALUES(fonte) <> ?, fonte, VALUES(fonte)),
              atualizado_em = VALUES(atualizado_em)',
        [$ean, mb_substr($nome, 0, 255), $fonte, date('Y-m-d H:i:s'),
         'usuario', 'usuario', 'usuario', 'usuario']
    );
}

/**
 * A cascata inteira.
 *
 * @return array{codigo:?string, nome:?string, fonte:?string, ultimo:?float}
 */
function mercado_nome(string $codigo, int $usuario_id): array
{
    $ean = mercado_chave($codigo);
    $vazio = ['codigo' => $ean, 'nome' => null, 'fonte' => null, 'ultimo' => null];
    if ($ean === null) {
        return $vazio;
    }

    $cache = q1('SELECT nome, fonte FROM ean_nomes WHERE ean = ?', [$ean]);

    // 1. o que voce mesmo digitou para este codigo
    if ($cache && $cache['fonte'] === 'usuario') {
        return ['codigo' => $ean, 'nome' => $cache['nome'], 'fonte' => 'usuario',
                'ultimo' => mercado_ultimo_pago($ean, $usuario_id)];
    }

    // 2. as suas notas
    $p = produto_por_ean($ean);
    if ($p) {
        $st = produto_estatisticas(produto_historico((int) $p['id'], $usuario_id));
        return ['codigo' => $ean, 'nome' => $p['descricao'], 'fonte' => 'notas',
                'ultimo' => $st['n'] > 0 ? (float) $st['ultimo'] : null];
    }

    // 3. o espelho da loja
    $naLoja = q1(
        'SELECT li.descricao
           FROM loja_itens li
           JOIN loja_pdvs p ON p.id = li.pdv_id
          WHERE li.ean = ? AND p.ativo = 1 AND p.unificado_para IS NULL
          LIMIT 1',
        [$ean]
    );
    if ($naLoja) {
        return ['codigo' => $ean, 'nome' => $naLoja['descricao'], 'fonte' => 'loja', 'ultimo' => null];
    }

    // 4. o que a base de fora ja respondeu antes
    if ($cache) {
        return ['codigo' => $ean, 'nome' => $cache['nome'], 'fonte' => $cache['fonte'], 'ultimo' => null];
    }

    // 5. a base de fora, agora
    $nome = off_buscar($ean);
    if ($nome !== null) {
        ean_nome_gravar($ean, $nome, 'off');
        return ['codigo' => $ean, 'nome' => $nome, 'fonte' => 'off', 'ultimo' => null];
    }

    return $vazio;
}

/** Quanto voce pagou da ultima vez, quando ha historico. */
function mercado_ultimo_pago(string $ean, int $usuario_id): ?float
{
    $p = produto_por_ean($ean);
    if (!$p) {
        return null;
    }
    $st = produto_estatisticas(produto_historico((int) $p['id'], $usuario_id));
    return $st['n'] > 0 ? (float) $st['ultimo'] : null;
}
