<?php
declare(strict_types=1);

function despachar(string $rota): void
{
    $m = metodo();

    // ---------------- publico ----------------
    if ($rota === '/login')   { rota_login($m); return; }
    if ($rota === '/logout')  { fazer_logout(); redirecionar('/login'); }

    // Callbacks do n8n: autenticados por token, nao por sessao.
    if ($rota === '/api/callback' && $m === 'POST') { rota_callback(); return; }
    if ($rota === '/api/loja/callback' && $m === 'POST') { rota_loja_callback(); return; }
    if ($rota === '/api/vendas/callback' && $m === 'POST') { rota_vendas_callback(); return; }

    // ---------------- exige login ----------------
    $u = exigir_login();

    if ($rota === '/')          { rota_inicio($u); return; }
    if ($rota === '/dashboard') { rota_dashboard($u); return; }
    if ($rota === '/escanear')  { ver('escanear', [], 'Escanear nota'); }
    if ($rota === '/bipar')     { ver('bipar', [], 'Bipar produto'); }
    if ($rota === '/notas')     { rota_notas($u); return; }
    if ($rota === '/produtos')  { rota_produtos($u); return; }
    if ($rota === '/loja')      { rota_loja($u); return; }
    if ($rota === '/margens')   { rota_margens($u); return; }
    if ($rota === '/vendas')    { rota_vendas($u); return; }
    if ($rota === '/vendas/transacoes') { rota_vendas_transacoes($u); return; }
    if ($rota === '/metas')     { rota_metas($u); return; }
    if ($rota === '/config' || str_starts_with($rota, '/config/')) { rota_config($u, $m); return; }
    if ($rota === '/manual')    { rota_manual($u, $m); return; }

    if ($rota === '/api/notas' && $m === 'POST')   { rota_api_nota_nova($u); return; }
    if ($rota === '/api/produto' && $m === 'GET')  { rota_api_produto($u); return; }
    if ($rota === '/api/sync/estado' && $m === 'GET') { rota_api_sync_estado($u); return; }
    if ($rota === '/api/loja/sincronizar' && $m === 'POST') { rota_loja_sincronizar($u); return; }
    if ($rota === '/api/vendas/sincronizar' && $m === 'POST') { rota_vendas_sincronizar($u); return; }

    if (preg_match('#^/api/notas/(\d+)/status$#', $rota, $mm)) {
        rota_api_nota_status($u, (int) $mm[1]);
        return;
    }
    if (preg_match('#^/notas/(\d+)$#', $rota, $mm)) {
        rota_nota_detalhe($u, (int) $mm[1]);
        return;
    }
    if (preg_match('#^/notas/(\d+)/excluir$#', $rota, $mm) && $m === 'POST') {
        rota_nota_excluir($u, (int) $mm[1]);
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
           FROM notas WHERE usuario_id = ? AND status = ?',
        [$u['id'], 'ok']
    );
    $itens_total = (int) qv(
        'SELECT COUNT(*) FROM itens i JOIN notas n ON n.id = i.nota_id
          WHERE n.usuario_id = ? AND n.status = ?',
        [$u['id'], 'ok']
    );
    $pendentes = (int) qv(
        'SELECT COUNT(*) FROM notas WHERE usuario_id = ? AND status IN (?, ?)',
        [$u['id'], 'pendente', 'processando']
    );
    $ultimas = q(
        'SELECT n.id, n.emissao, n.valor_total, n.status, est.nome AS loja
           FROM notas n
      LEFT JOIN estabelecimentos est ON est.id = n.estabelecimento_id
          WHERE n.usuario_id = ?
       ORDER BY n.criado_em DESC LIMIT 8',
        [$u['id']]
    );

    $loja = loja_resumo();
    $vendas = vendas_resumo();

    ver('inicio', compact('resumo', 'itens_total', 'pendentes', 'ultimas', 'loja', 'vendas'), 'Inicio');
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

function rota_nota_excluir(array $u, int $id): void
{
    exigir_csrf();
    if (nota_excluir($id, (int) $u['id'])) {
        flash('ok', 'Nota removida. Pode escanear o cupom de novo.');
    } else {
        flash('erro', 'Nao encontrei essa nota.');
    }
    redirecionar('/notas');
}

function rota_produtos(array $u): void
{
    $busca = trim((string) ($_GET['q'] ?? ''));
    // A ordem importa: o "?" do JOIN vem antes do "?" do WHERE.
    $params = ['ok', $u['id']];
    $filtro = '';
    if ($busca !== '') {
        $filtro = ' AND (p.descricao_norm LIKE ? OR p.ean = ?)';
        $params[] = '%' . normalizar_texto($busca) . '%';
        $params[] = ean_normalizado($busca) ?? '__nada__';
    }

    $produtos = q(
        'SELECT p.id, p.ean, p.descricao, p.unidade,
                COUNT(i.id)                       AS compras,
                MIN(i.valor_unitario_liquido)     AS menor,
                MAX(i.valor_unitario_liquido)     AS maior,
                MAX(n.emissao)                    AS ultima_compra
           FROM produtos p
           JOIN itens i ON i.produto_id = p.id
           JOIN notas n ON n.id = i.nota_id AND n.status = ?
          WHERE n.usuario_id = ?' . $filtro . '
       GROUP BY p.id, p.ean, p.descricao, p.unidade
       ORDER BY ultima_compra DESC
          LIMIT 300',
        $params
    );
    // Preco de venda e estoque da loja para a lista inteira, numa consulta so.
    $loja = loja_por_produtos(array_column($produtos, 'id'));

    ver('produtos_lista', compact('produtos', 'busca', 'loja'), 'Produtos');
}

/** Catalogo da loja: tudo que veio do TouchPay, nao so o que voce ja comprou. */
function rota_loja(array $u): void
{
    $busca   = trim((string) ($_GET['q'] ?? ''));
    $pdv_id  = (int) ($_GET['pdv'] ?? 0);
    $ordem   = (string) ($_GET['ordem'] ?? 'nome');
    // "1" e o link antigo, de quando o filtro era so uma caixa de marcar.
    $estoque = (string) ($_GET['estoque'] ?? '');
    $estoque = $estoque === '1' ? 'com' : (in_array($estoque, ['com', 'sem'], true) ? $estoque : '');

    $itens  = loja_listar($busca, $pdv_id, $ordem, $estoque);
    $totais = loja_totais($busca, $pdv_id, $estoque);
    $pdvs   = loja_resumo();

    ver(
        'loja_lista',
        compact('itens', 'totais', 'pdvs', 'busca', 'pdv_id', 'ordem', 'estoque'),
        'Loja'
    );
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
    // Mesma informacao que a tela de bipar mostra: por quanto a loja vende e
    // quanto tem em estoque agora.
    $loja = loja_por_ean($produto['ean'], $id);
    ver('produto_detalhe', compact('produto', 'historico', 'stats', 'loja'), $produto['descricao']);
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
                // Lancamento manual: o valor digitado ja e o que foi pago.
                'valor_total_liquido'    => $vtot,
                'valor_unitario_liquido' => $vu,
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
        $existente = q1('SELECT status FROM notas WHERE id = ?', [$r['nota_id']]);

        // Nota que travou em "processando" (ou deu erro) nao pode bloquear
        // uma nova tentativa: reabre e dispara de novo.
        if ($existente && $existente['status'] !== 'ok') {
            nota_reabrir($r['nota_id'], (int) $u['id']);
            $d = nota_disparar_n8n($r['nota_id'], $qr);
            json_resposta([
                'nota_id'    => $r['nota_id'],
                'duplicada'  => false,
                'reprocessa' => true,
                'ok'         => $d['ok'],
                'erro'       => $d['erro'],
            ], $d['ok'] ? 202 : 502);
        }

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
        // Nunca comprado nao quer dizer desconhecido: a loja pode ter o
        // produto na prateleira, e o preco de venda ja ajuda.
        json_resposta([
            'encontrado' => false,
            'ean'        => ean_normalizado($ean),
            'loja'       => loja_resposta_api($ean, null),
            'custos'     => custos_variavel_atual(),
            'minimos'    => margens_minimos(),
        ]);
    }
    $hist  = produto_historico((int) $p['id'], (int) $u['id']);
    $stats = produto_estatisticas($hist);
    $loja  = loja_resposta_api($p['ean'], (int) $p['id']);
    // O custo que interessa na prateleira e o do que esta la, nao o da ultima
    // nota: ver produto_custo_estoque().
    $estoque = 0.0;
    foreach ($loja as $l) {
        $estoque += (float) ($l['estoque'] ?? 0);
    }
    $custo_estoque = produto_custo_estoque($hist, $estoque);
    if (!$hist) {
        json_resposta([
            'encontrado' => false,
            'ean'        => $p['ean'],
            'mensagem'   => 'Produto conhecido, mas voce ainda nao comprou.',
            'loja'       => $loja,
            'custos'     => custos_variavel_atual(),
            'minimos'    => margens_minimos(),
        ]);
    }
    json_resposta([
        'encontrado' => true,
        // Para a conta de "vale a pena comprar por X" acontecer na hora, sem
        // uma ida ao servidor por tecla digitada.
        'custos'     => custos_variavel_atual(),
        'minimos'    => margens_minimos(),
        'custo_estoque' => $custo_estoque + ['estoque' => $estoque],
        'produto_id' => (int) $p['id'],
        'descricao'  => $p['descricao'],
        'ean'        => $p['ean'],
        'url'        => '/produtos/' . $p['id'],
        'stats'      => $stats,
        'loja'       => $loja,
        'ultimas'    => array_map(static fn($h) => [
            'data'     => data_fmt($h['emissao']),
            'loja'     => $h['loja'] ?? '-',
            'unitario' => (float) $h['valor_unitario'],
            'qtd'      => (float) $h['quantidade'],
            'unidade'  => $h['unidade'],
        ], array_slice($hist, 0, 5)),
    ]);
}

/** Preco de venda e estoque da loja, no formato que a tela de bipar espera. */
function loja_resposta_api(?string $ean, ?int $produto_id): array
{
    $linhas = loja_por_ean($ean, $produto_id);
    return array_map(static fn($l) => [
        'pdv'        => $l['pdv'],
        'preco'      => $l['preco_venda'] === null ? null : (float) $l['preco_venda'],
        'estoque'    => (float) $l['estoque'],
        'reservado'  => (float) $l['reservado'],
        'descricao'  => $l['descricao'],
        'atualizado' => data_fmt($l['atualizado_em']),
    ], $linhas);
}

/** Dispara a sincronizacao do TouchPay. */
function rota_loja_sincronizar(array $u): void
{
    exigir_csrf();
    $r = loja_disparar_sync();
    json_resposta($r, $r['ok'] ? 200 : 422);
}

/** Quais produtos estao com o preco baixo demais para pagar a conta. */
function rota_margens(array $u): void
{
    $filtro = (string) ($_GET['filtro'] ?? '');
    if (!in_array($filtro, ['', 'prejuizo', 'aperto', 'ok', 'sem_custo'], true)) {
        $filtro = '';
    }
    $busca = trim((string) ($_GET['q'] ?? ''));

    $itens = margens_listar($filtro, $busca);
    $conta = margens_contagem($busca);
    $min   = margens_minimos();

    ver('margens', compact('itens', 'conta', 'min', 'filtro', 'busca'), 'Margens');
}

/** Placar das duas importacoes, para a tela desenhar a barra de progresso. */
function rota_api_sync_estado(array $u): void
{
    json_resposta([
        'loja'   => sync_estado('loja'),
        'vendas' => sync_estado('vendas'),
    ]);
}

/** Relatorio de vendas: filtros livres e o que sobra depois dos custos. */
/**
 * Os filtros da secao Vendas. As duas telas leem os mesmos, entao trocar de
 * aba nao perde o periodo nem o PDV que a pessoa escolheu.
 */
function vendas_filtros_da_url(): array
{
    return [
        // Sem filtro, os ultimos 30 dias — a pergunta de sempre e "e esse mes?".
        'de'      => (string) ($_GET['de'] ?? date('Y-m-d', strtotime('-29 days'))),
        'ate'     => (string) ($_GET['ate'] ?? date('Y-m-d')),
        'pdv_id'  => (int) ($_GET['pdv_id'] ?? 0),
        'forma'   => (string) ($_GET['forma'] ?? ''),
        'agrupar' => (string) ($_GET['agrupar'] ?? 'produto'),
        'busca'   => trim((string) ($_GET['q'] ?? '')),
    ];
}

/** As formas de pagamento com nome de gente, para os filtros e as listas. */
function vendas_formas_rotulos(): array
{
    return ['Debit' => 'Débito', 'Credit' => 'Crédito', 'Pix' => 'Pix', 'Voucher' => 'Voucher'];
}

/**
 * Cada compra do periodo, com os produtos que sairam nela.
 *
 * O resumo responde "o que vende mais"; esta tela responde "o que saiu agora ha
 * pouco" e "o que essa pessoa levou junto".
 */
function rota_vendas_transacoes(array $u): void
{
    $f = vendas_filtros_da_url();

    $pag = vendas_paginacao(vendas_transacoes_contar($f), (int) ($_GET['p'] ?? 1));
    $linhas = vendas_transacoes($f, $pag);
    $itens = vendas_itens_das(array_column($linhas, 'id'));

    $pdvs = vendas_pdvs();
    $formas = vendas_formas_rotulos();

    ver('vendas_transacoes', compact('linhas', 'itens', 'pag', 'f', 'pdvs', 'formas'), 'Transações');
}

function rota_vendas(array $u): void
{
    $f = vendas_filtros_da_url();

    $r = vendas_relatorio($f);
    $pdvs = vendas_pdvs();
    $formas = vendas_formas_rotulos();

    ver('vendas_relatorio', compact('r', 'f', 'pdvs', 'formas'), 'Vendas');
}

/** Dashboard de vendas: KPIs principais + cards visuais. */
function rota_dashboard(array $u): void
{
    $periodos = vendas_periodos();
    $chave = $_GET['periodo'] ?? 'mes';
    $periodo = $periodos[$chave] ?? $periodos['mes'];
    [$rotulo, $de, $ate] = $periodo;

    $pdv_id = (int) ($_GET['pdv_id'] ?? 0);

    $f = ['de' => $de, 'ate' => $ate, 'agrupar' => 'produto'];
    if ($pdv_id) {
        $f['pdv_id'] = $pdv_id;
    }
    $r = vendas_relatorio($f);
    $res = $r['resultado'];

    // Comparação com período anterior (mesmo tamanho)
    $dias = (int) $res['dias'];
    $de_ant = date('Y-m-d', strtotime($de . ' -' . $dias . ' days'));
    $ate_ant = date('Y-m-d', strtotime($de . ' -1 day'));
    $r_ant = vendas_relatorio(['de' => $de_ant, 'ate' => $ate_ant, 'agrupar' => 'produto'] + ($pdv_id ? ['pdv_id' => $pdv_id] : []));
    $res_ant = $r_ant['resultado'];

    // Variações
    $var = static fn(float $atual, float $anterior): ?float => $anterior > 0 ? (($atual - $anterior) / $anterior * 100) : null;
    $variacao = [
        'receita'  => $var($res['receita'], $res_ant['receita']),
        'cmv'      => $var($res['cmv'], $res_ant['cmv']),
        'taxa'     => $var($res['taxa'], $res_ant['taxa']),
        'fixos'    => $var($res['fixos'], $res_ant['fixos']),
        'lucro'    => $var($res['lucro'], $res_ant['lucro']),
        'margem'   => $var($res['margem'], $res_ant['margem']),
        'vendas'   => $var($res['vendas'], $res_ant['vendas']),
    ];

    // Top produtos por contribuição
    $top = array_slice($r['linhas'], 0, 5);

    // Formas de pagamento para mini cards
    $formas_map = ['Debit' => 'Débito', 'Credit' => 'Crédito', 'Pix' => 'Pix', 'Voucher' => 'Voucher'];

    $pdvs = vendas_pdvs();

    ver('dashboard', compact('res', 'res_ant', 'variacao', 'top', 'formas_map', 'r', 'chave', 'rotulo', 'de', 'ate', 'periodos', 'pdv_id', 'pdvs'), 'Dashboard');
}

/** Metas do mes: quanto entrou, quanto falta e se o ritmo chega la. */
function rota_metas(array $u): void
{
    $p = custos_parametros();

    // Filtro de mes (padrao: mes corrente)
    $mes_ref = $_GET['mes'] ?? date('Y-m');
    $de      = date('Y-m-01', strtotime($mes_ref . '-01'));
    $ate     = date('Y-m-t', strtotime($mes_ref . '-01'));
    $hoje    = date('Y-m-d');
    if ($ate > $hoje) {
        $ate = $hoje;
    }

    $pdv_id = (int) ($_GET['pdv_id'] ?? 0);

    $f = ['de' => $de, 'ate' => $ate, 'agrupar' => 'produto'];
    if ($pdv_id) {
        $f['pdv_id'] = $pdv_id;
    }
    $r = vendas_relatorio($f);
    [$dia, $no_mes] = metas_dias($ate);

    $fat = meta_progresso($r['resultado']['receita'], (float) $p['meta_faturamento'], $dia, $no_mes)
         + ['dia' => $dia, 'no_mes' => $no_mes];
    $luc = meta_progresso($r['resultado']['lucro'], (float) $p['meta_lucro'], $dia, $no_mes)
         + ['dia' => $dia, 'no_mes' => $no_mes];

    // Breakdown diario para faturamento e lucro
    $breakdown_fat = metas_breakdown_diario((float) $p['meta_faturamento'], $r['resultado']['receita'], $de, $ate, $pdv_id ?: null);
    $breakdown_luc = metas_breakdown_diario((float) $p['meta_lucro'], $r['resultado']['lucro'], $de, $ate, $pdv_id ?: null);

    $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
              'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $mes_num = (int) date('n', strtotime($mes_ref));
    $mes = $meses[$mes_num] . ' de ' . date('Y', strtotime($mes_ref));

    // Meses disponiveis para o select (ultimos 12 meses)
    $meses_disponiveis = [];
    for ($i = 0; $i < 12; $i++) {
        $d = date('Y-m', strtotime("-$i months"));
        $meses_disponiveis[$d] = $meses[(int) date('n', strtotime($d . '-01'))] . ' de ' . date('Y', strtotime($d . '-01'));
    }

    $pdvs = vendas_pdvs();

    ver('metas', compact('fat', 'luc', 'p', 'mes', 'mes_ref', 'meses_disponiveis', 'breakdown_fat', 'breakdown_luc', 'pdv_id', 'pdvs', 'de', 'ate'), 'Metas');
}

/**
 * Configuracoes: tudo que e botao e ajuste, junto e longe das telas de numero.
 * Cada aba grava na propria rota e volta para ela.
 */
function rota_config(array $u, string $metodo): void
{
    $aba = ['/config' => 'sync', '/config/pdvs' => 'pdvs',
            '/config/metas' => 'metas', '/config/taxas' => 'taxas'][rota_atual()] ?? null;
    if ($aba === null) {
        http_response_code(404);
        ver('erro', ['codigo' => 404, 'mensagem' => 'Pagina nao encontrada'], 'Nao encontrado');
    }

    if ($metodo === 'POST') {
        exigir_csrf();

        // Quais pontos de venda contam no app. Vem a lista dos marcados; quem
        // nao veio, desliga.
        if ($aba === 'pdvs' && isset($_POST['pdvs_enviados'])) {
            $ligados = array_map('intval', (array) ($_POST['pdvs'] ?? []));
            foreach (loja_pdvs_todos() as $pdv) {
                loja_pdv_ativo((int) $pdv['id'], in_array((int) $pdv['id'], $ligados, true));
            }
            flash('ok', 'Pontos de venda atualizados.');
        } elseif ($aba === 'taxas' && (int) ($_POST['pdv_id'] ?? 0) > 0) {
            // Custo de um container so. Campo em branco apaga a excecao e
            // devolve aquele custo ao padrao.
            $id = (int) $_POST['pdv_id'];
            $n = custos_salvar_pdv($id, $_POST);
            flash($n > 0 ? 'ok' : 'erro', $n > 0 ? 'Salvo.' : 'Nada para salvar.');
            redirecionar('/config/taxas?pdv=' . $id);
        } else {
            $n = custos_salvar($_POST);
            flash($n > 0 ? 'ok' : 'erro', $n > 0 ? 'Salvo.' : 'Nada para salvar.');
        }

        redirecionar(rota_atual());
    }

    $p      = custos_parametros();
    $pdvs   = loja_pdvs_todos();

    // Qual PDV a aba Taxas esta editando. Zero e o padrao, que vale para todos
    // os que nao tem excecao. So PDV ativo entra: os desligados nao pagam nada
    // no app.
    $ativos    = array_column(array_filter($pdvs, static fn (array $x): bool => (int) $x['ativo'] === 1), 'nome', 'id');
    $pdv_taxas = (int) ($_GET['pdv'] ?? 0);
    $pdv_taxas = isset($ativos[$pdv_taxas]) ? $pdv_taxas : 0;
    $overrides = $pdv_taxas > 0 ? custos_overrides_pdv($pdv_taxas) : [];
    $loja   = loja_resumo();
    $vendas = vendas_resumo();
    // Estado inicial no proprio HTML: recarregar no meio de uma importacao ja
    // mostra a barra andando, sem esperar o primeiro polling.
    $sync   = ['loja' => sync_estado('loja'), 'vendas' => sync_estado('vendas')];
    $cron   = cron_formatar(q1('SELECT * FROM sync_estado WHERE fonte = ?', ['cron']));

    ver('config', compact('aba', 'p', 'pdvs', 'loja', 'vendas', 'sync', 'cron', 'ativos', 'pdv_taxas', 'overrides'), 'Configuracoes');
}

/**
 * Dispara a coleta de vendas. Sem corpo, o proprio app decide a janela:
 * primeira carga puxa 12 meses, depois so o que falta.
 */
function rota_vendas_sincronizar(array $u): void
{
    exigir_csrf();
    $corpo = corpo_json();

    // Janela explicita: e o unico jeito de alcancar um buraco no meio do mes.
    // O sync automatico so volta 3 dias da ultima venda, entao lote perdido la
    // atras ficaria inalcancavel por mais que se clique em "buscar novas".
    $de  = data_iso($corpo['de'] ?? null);
    $ate = data_iso($corpo['ate'] ?? null);
    if ($de !== null && $ate !== null) {
        $r = vendas_disparar_sync($de, $ate);
        json_resposta($r, $r['ok'] ? 200 : 422);
    }

    $dias = (int) ($corpo['dias'] ?? 0);
    $r = $dias > 0
        ? vendas_disparar_sync(date('Y-m-d', strtotime('-' . $dias . ' days')), date('Y-m-d'))
        : vendas_disparar_sync();

    json_resposta($r, $r['ok'] ? 200 : 422);
}

/** Callback do fluxo de vendas. Mesmo token compartilhado dos outros fluxos. */
function rota_vendas_callback(): void
{
    $p = vendas_callback_normalizar(corpo_json());

    $esperado = (string) cfg('n8n_token');
    $recebido = $p['token'] !== '' ? $p['token'] : (string) ($_SERVER['HTTP_X_TOKEN'] ?? '');

    if ($esperado === '' || $esperado === 'TROQUE-ME' || !hash_equals($esperado, $recebido)) {
        json_resposta(['erro' => 'token invalido'], 401);
    }

    $r = vendas_processar_callback($p);
    json_resposta($r, $r['ok'] ? 200 : 422);
}

/** Callback do fluxo TouchPay. Mesmo token compartilhado do fluxo da NFC-e. */
function rota_loja_callback(): void
{
    $p = loja_callback_normalizar(corpo_json());

    $esperado = (string) cfg('n8n_token');
    $recebido = $p['token'] !== '' ? $p['token'] : (string) ($_SERVER['HTTP_X_TOKEN'] ?? '');

    if ($esperado === '' || $esperado === 'TROQUE-ME' || !hash_equals($esperado, $recebido)) {
        json_resposta(['erro' => 'token invalido'], 401);
    }

    $r = loja_processar_callback($p);
    json_resposta($r, $r['ok'] ? 200 : 422);
}

/** Callback do n8n. Autenticado pelo token compartilhado. */
function rota_callback(): void
{
    // Normaliza antes de autenticar: no formato achatado o token vem
    // repetido dentro das linhas, nao no envelope.
    $p = callback_normalizar(corpo_json());

    $esperado = (string) cfg('n8n_token');
    $recebido = $p['token'] !== '' ? $p['token'] : (string) ($_SERVER['HTTP_X_TOKEN'] ?? '');

    if ($esperado === '' || $esperado === 'TROQUE-ME' || !hash_equals($esperado, $recebido)) {
        json_resposta(['erro' => 'token invalido'], 401);
    }

    $r = nota_processar_callback($p);
    json_resposta($r, $r['ok'] ? 200 : 422);
}
