<?php
declare(strict_types=1);

/**
 * De que conta TouchPay e a loja que esta requisicao enxerga.
 *
 * A loja (PDVs, catalogo, vendas, custos, metas, promocoes) pertence a uma
 * conta TouchPay, nao a um usuario do app: socios com o mesmo login veem a
 * mesma loja. Tudo pende do PDV — loja_pdvs.conta_id e a raiz, e o resto
 * (itens, vendas, custos do PDV, log do repor, promocoes) chega por pdv_id.
 * O que nao e de PDV nenhum (taxas e metas padrao) tem conta_id proprio.
 *
 * REGRA: toda consulta a uma tabela da loja leva conta_sql() ou
 * conta_pdv_sql(), inclusive as que ja filtram por um pdv_id vindo da tela —
 * um id de PDV de outra conta, digitado na URL, tem de dar em nada.
 *
 * Qual conta vale:
 *  - requisicao com usuario logado: a que ele conectou em Configuracoes >
 *    TouchPay; sem conexao, nenhuma (a Loja aparece vazia);
 *  - cron: uma de cada vez, todas as que tem como falar com o TouchPay;
 *  - callbacks do n8n e o que mais roda sem usuario: a conta "legada", a do
 *    e-mail do config.php, que ficou com a loja de antes da separacao.
 *
 * Antes do setup.php criar as colunas, tudo responde "1=1": o app continua
 * exatamente como era, uma loja so.
 */

/** @var int|null|false false = ninguem definiu (contexto sem usuario) */
$GLOBALS['__conta_atual'] = false;

/**
 * @param ?int $usuario_id quem esta usando o app agora: e do cookie dele que
 *                         sai a senha do TouchPay (null no cron)
 */
function conta_definir(?int $conta_id, ?int $usuario_id = null): void
{
    $GLOBALS['__conta_atual'] = $conta_id;
    $GLOBALS['__conta_usuario'] = $usuario_id;
}

function conta_usuario(): ?int
{
    return $GLOBALS['__conta_usuario'] ?? null;
}

function conta_atual(): ?int
{
    $c = $GLOBALS['__conta_atual'];
    return $c === false ? conta_sync() : $c;
}

/**
 * A conta legada: a do e-mail do config.php. Ficou com a loja de antes da
 * separacao, com as vendas sem PDV e com o que chega pelos callbacks do n8n.
 */
function conta_sync(): ?int
{
    if (array_key_exists('__conta_sync_forcada', $GLOBALS)) {
        return $GLOBALS['__conta_sync_forcada'];
    }
    static $id = false;
    if ($id !== false) {
        return $id;
    }
    $email = trim((string) cfg('touchpay_email', ''));
    if ($email === '' || !tp_conta_tabelas_ok()) {
        return $id = null;
    }
    try {
        $chave = tp_conta_chave($email, tp_cofre_chave());
        exec_sql('INSERT IGNORE INTO touchpay_contas (chave, criado_em) VALUES (?, ?)',
            [$chave, date('Y-m-d H:i:s')]);
        $l = q1('SELECT id FROM touchpay_contas WHERE chave = ?', [$chave]);
    } catch (Throwable $e) {
        return $id = null;
    }
    return $id = $l ? (int) $l['id'] : null;
}

/** A conta de agora e a legada (ou o app ainda e uma loja so)? */
function conta_legada(): bool
{
    if (!conta_separada()) {
        return true;
    }
    $c = conta_atual();
    return $c !== null && $c === conta_sync();
}

/**
 * Da para falar com o TouchPay por esta conta agora? Token guardado valendo,
 * a senha no cookie de quem esta usando o app, ou (so a legada) o config.php.
 */
function conta_pode_touchpay(): bool
{
    if (!conta_separada()) {
        return tp_configurado();
    }
    $c = conta_atual();
    return $c !== null && (tp_token_guardado($c) !== null || tp_credenciais($c) !== null);
}

/**
 * As contas que o cron consegue sincronizar agora: as com token valendo e,
 * se o dono deixou a senha no config.php, a legada. As outras esperam alguem
 * abrir o app — a senha delas so existe no celular.
 *
 * @return array<int,?int> null = app ainda sem separacao (uma loja so)
 */
function conta_para_o_cron(): array
{
    if (!conta_separada()) {
        return [null];
    }
    $ids = [];
    try {
        foreach (q('SELECT conta_id FROM touchpay_tokens WHERE expira_em > ?', [date('Y-m-d H:i:s')]) as $l) {
            $ids[] = (int) $l['conta_id'];
        }
    } catch (PDOException $e) {
        // sem a tabela: so a legada, pelo config.php
    }
    $legada = conta_sync();
    if ($legada !== null && tp_configurado()) {
        $ids[] = $legada;
    }
    return array_values(array_unique($ids));
}

/** As colunas da separacao ja existem? Sem elas o app segue como uma loja so. */
function conta_separada(): bool
{
    $forcado = $GLOBALS['__conta_forcada'] ?? null;
    if ($forcado !== null) {
        return $forcado;
    }
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (int) qv(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'conta_id'
                    AND TABLE_NAME IN ('loja_pdvs', 'custos_parametros', 'custos_vigencias')"
            ) === 3;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Para os testes: finge o estado das colunas. */
function conta_separada_forcar(?bool $ok): void
{
    $GLOBALS['__conta_forcada'] = $ok;
}

/** Para os testes: finge qual conta o sync alimenta. */
function conta_sync_forcar(?int $conta_id): void
{
    $GLOBALS['__conta_sync_forcada'] = $conta_id;
}

/**
 * Filtro de conta para uma coluna conta_id: "p.conta_id = 3".
 *
 * O numero vai literal (inteiro, nunca texto de fora) para nao ter de
 * enfiar mais um parametro em cada uma das consultas da loja.
 */
function conta_sql(string $coluna = 'conta_id'): string
{
    if (!conta_separada()) {
        return '1=1';
    }
    $c = conta_atual();
    return $c === null ? '1=0' : $coluna . ' = ' . (int) $c;
}

/** Filtro de conta para quem so tem pdv_id: "v.pdv_id IN (PDVs da conta)". */
function conta_pdv_sql(string $coluna_pdv = 'pdv_id'): string
{
    if (!conta_separada()) {
        return '1=1';
    }
    $c = conta_atual();
    return $c === null
        ? '1=0'
        : $coluna_pdv . ' IN (SELECT cp.id FROM loja_pdvs cp WHERE cp.conta_id = ' . (int) $c . ')';
}

/**
 * Colunas a mais para um INSERT de tabela com conta_id: [] antes da
 * migracao, ['conta_id' => N] depois.
 */
function conta_colunas(?int $conta_id = null): array
{
    if (!conta_separada()) {
        return [];
    }
    return ['conta_id' => $conta_id ?? conta_atual()];
}

/** O PDV e desta conta? Para ids que chegam da tela antes de uma escrita. */
function conta_tem_pdv(int $pdv_id): bool
{
    return $pdv_id > 0 && (bool) q1(
        'SELECT 1 FROM loja_pdvs WHERE id = ? AND ' . conta_sql('conta_id'),
        [$pdv_id]
    );
}

/**
 * Filtro de conta para vendas, que podem vir sem PDV (venda orfa).
 *
 * A orfa veio do sync, e o sync alimenta uma conta so: ela continua contando
 * para essa conta, e para nenhuma outra.
 */
function conta_venda_sql(string $coluna_pdv = 'v.pdv_id'): string
{
    $pdv = conta_pdv_sql($coluna_pdv);
    if ($pdv === '1=1' || $pdv === '1=0') {
        return $pdv;
    }
    return conta_atual() === conta_sync()
        ? '(' . $coluna_pdv . ' IS NULL OR ' . $pdv . ')'
        : $pdv;
}

/**
 * Filtro de conta para as notas que custeiam a loja (CMV, margens).
 *
 * Nota e de usuario, nao de conta: valem as notas de quem esta ligado a conta.
 * Antes, o custo de um produto vinha da nota mais recente de qualquer usuario
 * do app — a compra de uma loja mudava a margem da outra.
 */
function conta_notas_sql(string $coluna_usuario = 'n.usuario_id'): string
{
    if (!conta_separada()) {
        return '1=1';
    }
    $c = conta_atual();
    return $c === null
        ? '1=0'
        : $coluna_usuario . ' IN (SELECT ut.usuario_id FROM usuario_touchpay ut WHERE ut.conta_id = ' . (int) $c . ')';
}

/** O que a tela diz a quem nao tem como falar com o TouchPay agora. */
function conta_aviso_sem_touchpay(): string
{
    return conta_atual() === null
        ? 'Conecte sua conta TouchPay em Configurações > TouchPay.'
        : 'A senha do TouchPay não está neste aparelho. Entre de novo em Configurações > TouchPay.';
}

/**
 * Renova o token da conta quando falta pouco para ele vencer, enquanto o dono
 * usa o app. Um login por ~45 minutos de uso; falhar aqui nao derruba a tela.
 */
function conta_manter_token(): void
{
    if (!conta_separada()) {
        return;
    }
    $c = conta_atual();
    if ($c === null || tp_token_resta($c) > 15 * 60) {
        return;
    }
    $cred = tp_credenciais($c);
    if ($cred === null || $cred['do_config']) {
        // Sem senha neste aparelho; a do config, o cron renova sozinho.
        return;
    }
    try {
        tp_token(true);
    } catch (Throwable $e) {
        error_log('conta_manter_token: ' . $e->getMessage());
    }
}
