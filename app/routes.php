<?php
declare(strict_types=1);

function despachar(string $rota): void
{
    $m = metodo();

    // ---------------- publico ----------------
    if ($rota === '/login')   { rota_login($m); return; }
    if ($rota === '/logout')  { fazer_logout(); redirecionar('/login'); }

    // Callback do n8n: autenticado por token, nao por sessao.
    if ($rota === '/api/callback' && $m === 'POST') { rota_callback(); return; }

    // ---------------- exige login ----------------
    $u = exigir_login();

    if ($rota === '/')          { rota_inicio($u); return; }
    if ($rota === '/escanear')  { ver('escanear', [], 'Escanear nota'); }
    if ($rota === '/bipar')     { ver('bipar', [], 'Bipar produto'); }
    if ($rota === '/notas')     { rota_notas($u); return; }
    if ($rota === '/produtos')  { rota_produtos($u); return; }
    if ($rota === '/manual')    { rota_manual($u, $m); return; }

    if ($rota === '/api/notas' && $m === 'POST')   { rota_api_nota_nova($u); return; }
    if ($rota === '/api/produto' && $m === 'GET')  { rota_api_produto($u); return; }

    if (preg_match('#^/api/notas/(\d+)/status$#', $rota, $mm)) {
        rota_api_nota_status($u, (int) $mm[1]);
        return;
    }
    if (preg_match('#^/notas/(\d+)$#', $rota, $mm)) {
        rota_nota_detalhe($u, (int) $mm[1]);
        return;
    }
    if (preg_match('#^/produtos/(\d+)$#', $rota, $mm)) {
        rota_produto_detalhe($u, (int) $mm[1]);
        return;
    }
    if (preg_match('#^/produtos/(\d+)/ean$#', $rota, $mm) && $m === 'POST') {
        rota_produto_vincular_ean($u, (int) $mm[1]);
        return;
    }

    http_response_code(404);
    ver('erro', ['codigo' => 404, 'mensagem' => 'Pagina nao encontrada'], 'Nao encontrado');
}

// =====================================================================
// Autenticacao
// =====================================================================

function rota_login(string $m): void
{
    if (usuario_atual()) {
        redirecionar('/');
    }
    if ($m === 'POST') {
        exigir_csrf();
        $email = (string) ($_POST['email'] ?? '');
        $senha = (string) ($_POST['senha'] ?? '');
        if (tentar_login($email, $senha)) {
            iniciar_sessao();
            $destino = $_SESSION['apos_login'] ?? '/';
            unset($_SESSION['apos_login']);
            redirecionar($destino);
        }
        // Atraso pequeno para desestimular forca bruta
        usleep(400000);
        ver('login', ['erro' => 'E-mail ou senha invalidos.', 'email' => $email], 'Entrar');
    }
    ver('login', ['erro' => null, 'email' => ''], 'Entrar');
}

// =====================================================================
// Telas
// =====================================================================

function rota_inicio(array $u): void
{
    $resumo = q1(
        'SELECT COUNT(*) AS notas,
                COALESCE(SUM(valor_total), 0) AS total
           FROM notas WHERE usuario_id = ? AND status = "ok"',
        [$u['id']]
    );
    $itens_total = (int) qv(
        'SELECT COUNT(*) FROM itens i JOIN notas n ON n.id = i.nota_id
          WHERE n.usuario_id = ? AND n.status = "ok"',
        [$u['id']]
    );
    $pendentes = (int) qv(
        'SELECT COUNT(*) FROM notas WHERE usuario_id = ? AND status IN ("pendente","processando")',
        [$u['id']]
    );
    $ultimas = q(
        'SELECT n.id, n.emissao, n.valor_total, n.status, est.nome AS loja
           FROM notas n
      LEFT JOIN estabelecimentos est ON est.id = n.estabelecimento_id
          WHERE n.usuario_id = ?
       ORDER BY n.criado_em DESC LIMIT 8',
        [$u['id']]
    );

    ver('inicio', compact('resumo', 'itens_total', 'pendentes', 'ultimas'), 'Inicio');
}

function rota_notas(array $u): void
{
    $notas = q(
        'SELECT n.id, n.chave, n.emissao, n.valor_total, n.status, n.origem, n.erro_msg,
                est.nome AS loja, est.municipio, est.uf,
                (SELECT COUNT(*) FROM itens i WHERE i.nota_id = n.id) AS qtd_itens
           FROM notas n
      LEFT JOIN estabelecimentos est ON est.id = n.estabelecimento_id
          WHERE n.usuario_id = ?
       ORDER BY COALESCE(n.emissao, n.criado_em) DESC
          LIMIT 200',
        [$u['id']]
    );
    ver('notas_lista', compact('notas'), 'Minhas notas');
}

function rota_nota_detalhe(array $u, int $id): void
{
    $nota = nota_carregar($id, (int) $u['id']);
    if (!$nota) {
        http_response_code(404);
        ver('erro', ['codigo' => 404, 'mensagem' => 'Nota nao encontrada'], 'Nao encontrado');
    }
    ver('nota_detalhe', compact('nota'), 'Nota');
}

function rota_produtos(array $u): void
{
    $busca = trim((string) ($_GET['q'] ?? ''));
    $params = [$u['id']];
    $filtro = '';
    if ($busca !== '') {
        $filtro = ' AND (p.descricao_norm LIKE ? OR p.ean = ?)';
        $params[] = '%' . normalizar_texto($busca) . '%';
        $params[] = ean_normalizado($busca) ?? '__nada__';
    }

    $produtos = q(
        'SELECT p.id, p.ean, p.descricao, p.unidade,
                COUNT(i.id)              AS compras,
                MIN(i.valor_unitario)    AS menor,
                MAX(i.valor_unitario)    AS maior,
                MAX(n.emissao)           AS ultima_compra
           FROM produtos p
           JOIN itens i ON i.produto_id = p.id
           JOIN notas n ON n.id = i.nota_id AND n.status = "ok"
          WHERE n.usuario_id = ?' . $filtro . '
       GROUP BY p.id, p.ean, p.descricao, p.unidade
       ORDER BY ultima_compra DESC
          LIMIT 300',
        $params
    );
    ver('produtos_lista', compact('produtos', 'busca'), 'Produtos');
}

function rota_produto_detalhe(array $u, int $id): void
{
    $produto = q1('SELECT * FROM produtos WHERE id = ?', [$id]);
    if (!$produto) {
        http_response_code(404);
        ver('erro', ['codigo' => 404, 'mensagem' => 'Produto nao encontrado'], 'Nao encontrado');
    }
    $historico = produto_historico($id, (int) $u['id']);
    if (!$historico) {
        http_response_code(404);
        ver('erro', ['codigo' => 404, 'mensagem' => 'Voce nao tem compras desse produto'], 'Nao encontrado');
    }
    $stats = produto_estatisticas($historico);
    ver('produto_detalhe', compact('produto', 'historico', 'stats'), $produto['descricao']);
}

function rota_produto_vincular_ean(array $u, int $id): void
{
    exigir_csrf();
    $ean = (string) ($_POST['ean'] ?? '');
    if (produto_definir_ean($id, $ean)) {
        flash('ok', 'EAN vinculado. Agora bipar esse codigo encontra este produto.');
    } else {
        flash('erro', 'Nao deu para vincular: codigo invalido ou ja usado por outro produto.');
    }
    redirecionar('/produtos/' . $id);
}

// =====================================================================
// Lancamento manual
// =====================================================================

function rota_manual(array $u, string $m): void
{
    if ($m !== 'POST') {
        $lojas = q('SELECT id, nome, cnpj, municipio, uf FROM estabelecimentos ORDER BY nome LIMIT 500');
        ver('manual', ['lojas' => $lojas], 'Lancar nota manual');
    }

    exigir_csrf();

    $loja_id   = (int) ($_POST['loja_id'] ?? 0);
    $loja_nome = trim((string) ($_POST['loja_nome'] ?? ''));
    $cnpj      = so_digitos($_POST['cnpj'] ?? '');
    $municipio = trim((string) ($_POST['municipio'] ?? ''));
    $uf        = trim((string) ($_POST['uf'] ?? ''));
    $emissao   = data_mysql($_POST['emissao'] ?? '') ?? date('Y-m-d H:i:s');
    $itens_in  = is_array($_POST['itens'] ?? null) ? $_POST['itens'] : [];

    $itens = [];
    foreach ($itens_in as $it) {
        $desc = trim((string) ($it['descricao'] ?? ''));
        $vu   = num_br($it['valor_unitario'] ?? '');
        if ($desc === '' && $vu <= 0) {
            continue; // linha vazia do formulario
        }
        if ($desc === '') {
            flash('erro', 'Todo item precisa de descricao.');
            redirecionar('/manual');
        }
        $itens[] = $it;
    }

    if (!$itens) {
        flash('erro', 'Informe pelo menos um item.');
        redirecionar('/manual');
    }
    if ($loja_id <= 0 && $loja_nome === '') {
        flash('erro', 'Escolha uma loja existente ou informe o nome de uma nova.');
        redirecionar('/manual');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $estab_id = $loja_id > 0
            ? $loja_id
            : estabelecimento_resolver($cnpj ?: null, $loja_nome, $municipio ?: null, $uf ?: null);

        $nota_id = inserir('notas', [
            'usuario_id'         => (int) $u['id'],
            'estabelecimento_id' => $estab_id,
            'emissao'            => $emissao,
            'origem'             => 'manual',
            'status'             => 'ok',
            'processado_em'      => date('Y-m-d H:i:s'),
        ]);

        $total = 0.0;
        $n = 0;
        foreach ($itens as $it) {
            $n++;
            $qtde = num_br($it['quantidade'] ?? 1);
            if ($qtde <= 0) {
                $qtde = 1.0;
            }
            $vu    = num_br($it['valor_unitario'] ?? 0);
            $vtot  = round($qtde * $vu, 2);
            $total += $vtot;

            $produto_id = produto_resolver([
                'descricao' => $it['descricao'] ?? '',
                'ean'       => $it['ean'] ?? null,
                'codigo'    => $it['codigo'] ?? null,
                'unidade'   => $it['unidade'] ?? null,
            ], $estab_id);

            inserir('itens', [
                'nota_id'            => $nota_id,
                'produto_id'         => $produto_id,
                'item_num'           => $n,
                'descricao_original' => mb_substr(trim((string) $it['descricao']), 0, 255),
                'cod_interno'        => mb_substr(trim((string) ($it['codigo'] ?? '')), 0, 60) ?: null,
                'ean_original'       => mb_substr(trim((string) ($it['ean'] ?? '')), 0, 20) ?: null,
                'quantidade'         => $qtde,
                'unidade'            => mb_substr(trim((string) ($it['unidade'] ?? '')), 0, 10) ?: null,
                'valor_unitario'     => $vu,
                'valor_total'        => $vtot,
            ]);
        }

        exec_sql('UPDATE notas SET valor_produtos = ?, valor_total = ? WHERE id = ?', [$total, $total, $nota_id]);
        $pdo->commit();

        flash('ok', 'Nota lancada com ' . $n . ' item(ns).');
        redirecionar('/notas/' . $nota_id);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('erro', 'Falha ao salvar: ' . $ex->getMessage());
        redirecionar('/manual');
    }
}

// =====================================================================
// API
// =====================================================================

function rota_api_nota_nova(array $u): void
{
    exigir_csrf();
    $dados = corpo_json();
    $qr = trim((string) ($dados['qrcode'] ?? ''));

    if ($qr === '') {
        json_resposta(['erro' => 'QR Code vazio'], 422);
    }
    if (chave_do_qrcode($qr) === null) {
        json_resposta([
            'erro' => 'Isso nao parece um QR Code de NFC-e. Escaneie o quadradinho impresso no cupom.',
        ], 422);
    }
    if (stripos($qr, 'nfce.fazenda.sp.gov.br') === false) {
        json_resposta([
            'erro' => 'Por enquanto so consigo ler NFC-e de Sao Paulo. Use o lancamento manual.',
        ], 422);
    }

    $r = nota_criar_pendente((int) $u['id'], $qr);
    if ($r['duplicada']) {
        json_resposta([
            'nota_id'   => $r['nota_id'],
            'duplicada' => true,
            'mensagem'  => 'Essa nota ja foi escaneada.',
        ]);
    }

    $d = nota_disparar_n8n($r['nota_id'], $qr);
    json_resposta([
        'nota_id'   => $r['nota_id'],
        'duplicada' => false,
        'ok'        => $d['ok'],
        'erro'      => $d['erro'],
    ], $d['ok'] ? 202 : 502);
}

function rota_api_nota_status(array $u, int $id): void
{
    $n = q1(
        'SELECT n.id, n.status, n.erro_msg, n.valor_total, est.nome AS loja,
                (SELECT COUNT(*) FROM itens i WHERE i.nota_id = n.id) AS itens
           FROM notas n
      LEFT JOIN estabelecimentos est ON est.id = n.estabelecimento_id
          WHERE n.id = ? AND n.usuario_id = ?',
        [$id, $u['id']]
    );
    if (!$n) {
        json_resposta(['erro' => 'nota nao encontrada'], 404);
    }
    json_resposta([
        'nota_id'  => (int) $n['id'],
        'status'   => $n['status'],
        'itens'    => (int) $n['itens'],
        'loja'     => $n['loja'],
        'total'    => $n['valor_total'] !== null ? (float) $n['valor_total'] : null,
        'erro_msg' => $n['erro_msg'],
    ]);
}

function rota_api_produto(array $u): void
{
    $ean = (string) ($_GET['ean'] ?? '');
    $p = produto_por_ean($ean);
    if (!$p) {
        json_resposta(['encontrado' => false, 'ean' => ean_normalizado($ean)]);
    }
    $hist  = produto_historico((int) $p['id'], (int) $u['id']);
    $stats = produto_estatisticas($hist);
    if (!$hist) {
        json_resposta([
            'encontrado' => false,
            'ean'        => $p['ean'],
            'mensagem'   => 'Produto conhecido, mas voce ainda nao comprou.',
        ]);
    }
    json_resposta([
        'encontrado' => true,
        'produto_id' => (int) $p['id'],
        'descricao'  => $p['descricao'],
        'ean'        => $p['ean'],
        'url'        => '/produtos/' . $p['id'],
        'stats'      => $stats,
        'ultimas'    => array_map(static fn($h) => [
            'data'     => data_fmt($h['emissao']),
            'loja'     => $h['loja'] ?? '-',
            'unitario' => (float) $h['valor_unitario'],
            'qtd'      => (float) $h['quantidade'],
            'unidade'  => $h['unidade'],
        ], array_slice($hist, 0, 5)),
    ]);
}

/** Callback do n8n. Autenticado pelo token compartilhado. */
function rota_callback(): void
{
    $p = corpo_json();
    $esperado = (string) cfg('n8n_token');
    $recebido = (string) ($p['token'] ?? ($_SERVER['HTTP_X_TOKEN'] ?? ''));

    if ($esperado === '' || $esperado === 'TROQUE-ME' || !hash_equals($esperado, $recebido)) {
        json_resposta(['erro' => 'token invalido'], 401);
    }

    $r = nota_processar_callback($p);
    json_resposta($r, $r['ok'] ? 200 : 422);
}
