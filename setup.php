<?php
/**
 * Instalacao e diagnostico do Mercadinho.
 *
 *   https://mercadinho.bryanzendron.com.br/setup.php?token=SEU-SETUP-TOKEN
 *
 * Mostra o que esta faltando no ambiente, cria as tabelas e o primeiro usuario.
 * Depois de instalar, apague este arquivo ou troque o setup_token.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

$cfg_existe = is_file(__DIR__ . '/app/config.php');
$erro_fatal = null;

if ($cfg_existe) {
    try {
        require __DIR__ . '/app/bootstrap.php';
    } catch (Throwable $e) {
        $erro_fatal = $e->getMessage();
    }
}

/** Nomes das tabelas do banco atual (SHOW TABLES devolve a coluna com nome variavel). */
function listar_tabelas(): array
{
    $nomes = [];
    foreach (q('SHOW TABLES') as $linha) {
        $nomes[] = (string) reset($linha);
    }
    return $nomes;
}

/**
 * Colunas acrescentadas depois da primeira versao. O schema.sql cria tabela
 * nova ja completa; aqui e o caminho de quem instalou antes.
 */
function migracoes(): array
{
    return [
        'itens' => [
            'valor_total_liquido'    => 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER desconto',
            'valor_unitario_liquido' => 'DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER valor_total_liquido',
        ],
    ];
}

function coluna_existe(string $tabela, string $coluna): bool
{
    return (int) qv(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$tabela, $coluna]
    ) > 0;
}

function migracoes_pendentes(): array
{
    $faltando = [];
    foreach (migracoes() as $tabela => $colunas) {
        foreach ($colunas as $coluna => $definicao) {
            if (!coluna_existe($tabela, $coluna)) {
                $faltando[] = [$tabela, $coluna, $definicao];
            }
        }
    }
    return $faltando;
}

function linha(string $rotulo, bool $ok, string $detalhe = '', bool $aviso = false): void
{
    $classe = $ok ? 'ok' : ($aviso ? 'aviso' : 'falha');
    $icone  = $ok ? '&#10003;' : ($aviso ? '!' : '&#10007;');
    echo '<tr class="' . $classe . '"><td class="ic">' . $icone . '</td><td>'
       . htmlspecialchars($rotulo) . '</td><td class="det">'
       . htmlspecialchars($detalhe) . '</td></tr>';
}

// ---------------------------------------------------------------------
// Autorizacao: so exige token quando ja existe config
// ---------------------------------------------------------------------
$autorizado = true;
if ($cfg_existe && !$erro_fatal) {
    $esperado = (string) cfg('setup_token');
    $recebido = (string) ($_GET['token'] ?? '');
    $autorizado = $esperado !== '' && hash_equals($esperado, $recebido);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup · Mercadinho</title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; max-width: 780px; margin: 2rem auto; padding: 0 1rem; color: #1b2019; }
  h1 { font-size: 1.4rem; } h2 { font-size: 1.05rem; margin-top: 2rem; }
  table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
  td { padding: .4rem .5rem; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  .ic { width: 1.6rem; font-weight: 700; text-align: center; }
  tr.ok .ic { color: #15803d; } tr.falha .ic { color: #b91c1c; } tr.aviso .ic { color: #b45309; }
  .det { color: #6b7280; font-size: .85rem; }
  pre { background: #f3f4f6; padding: .8rem; border-radius: 8px; overflow-x: auto; font-size: .85rem; }
  .caixa { border: 1px solid #e5e7eb; border-radius: 10px; padding: 1rem; margin: 1rem 0; }
  .erro { color: #b91c1c; } .bom { color: #15803d; }
  input { padding: .5rem; width: 100%; margin: .2rem 0 .8rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1rem; }
  button { padding: .6rem 1.2rem; background: #14532d; color: #fff; border: 0; border-radius: 6px; font-size: 1rem; cursor: pointer; }
  code { background: #f3f4f6; padding: .1rem .3rem; border-radius: 4px; }
</style>
</head>
<body>
<h1>Setup do Mercadinho</h1>

<?php if (!$cfg_existe): ?>
    <div class="caixa">
        <p class="erro"><strong>Falta o arquivo <code>app/config.php</code>.</strong></p>
        <p>No SSH da Hostinger, dentro da pasta do site:</p>
        <pre>cp app/config.example.php app/config.php
nano app/config.php</pre>
        <p>Preencha banco, <code>n8n_token</code> e <code>setup_token</code>, depois recarregue esta página.</p>
        <p>Para gerar tokens aleatórios:</p>
        <pre>php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"</pre>
    </div>
    </body></html>
<?php exit; endif; ?>

<?php if ($erro_fatal): ?>
    <div class="caixa">
        <p class="erro"><strong>Erro ao carregar a aplicação:</strong></p>
        <pre><?= htmlspecialchars($erro_fatal) ?></pre>
    </div>
    </body></html>
<?php exit; endif; ?>

<?php if (!$autorizado): ?>
    <div class="caixa">
        <p class="erro">Token inválido.</p>
        <p>Acesse com <code>/setup.php?token=SEU-SETUP-TOKEN</code>
           (o valor de <code>setup_token</code> no <code>app/config.php</code>).</p>
    </div>
    </body></html>
<?php exit; endif; ?>

<h2>Ambiente</h2>
<table>
<?php
linha('PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='), 'requer 8.1 ou superior');
foreach (['pdo_mysql', 'curl', 'mbstring', 'json', 'zlib'] as $ext) {
    linha('Extensão ' . $ext, extension_loaded($ext));
}
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
linha('HTTPS ativo', $https, 'a câmera do navegador só funciona em https', !$https);
linha('Tempo máximo de execução: ' . ini_get('max_execution_time') . 's',
      (int) ini_get('max_execution_time') === 0 || (int) ini_get('max_execution_time') >= 30,
      'o disparo ao n8n é assíncrono, 30s bastam');
?>
</table>

<h2>Configuração</h2>
<table>
<?php
linha('base_url: ' . cfg('base_url'), (string) cfg('base_url') !== '');
linha('n8n_webhook: ' . cfg('n8n_webhook'), (string) cfg('n8n_webhook') !== '');
$tok = (string) cfg('n8n_token');
linha('n8n_token definido', $tok !== '' && $tok !== 'TROQUE-ME', 'segredo compartilhado com o n8n');
$stok = (string) cfg('setup_token');
linha('setup_token trocado', $stok !== '' && $stok !== 'TROQUE-ME-TAMBEM');
linha('debug', (bool) cfg('debug'), cfg('debug') ? 'ligado — desligue quando estabilizar' : 'desligado', (bool) cfg('debug'));
?>
</table>

<h2>Banco de dados</h2>
<?php
$db_ok = false;
$tabelas = [];
try {
    db();
    $db_ok = true;
    $tabelas = listar_tabelas();
} catch (Throwable $e) {
    echo '<div class="caixa"><p class="erro">Não conectou:</p><pre>'
       . htmlspecialchars($e->getMessage()) . '</pre>'
       . '<p>Confira host, nome do banco, usuário e senha em <code>app/config.php</code>. '
       . 'Na Hostinger o host costuma ser <code>localhost</code> e o nome vem prefixado, '
       . 'tipo <code>u123456789_mercadinho</code>.</p></div>';
}

if ($db_ok) {
    $esperadas = ['usuarios', 'estabelecimentos', 'produtos', 'produto_aliases', 'notas', 'itens', 'loja_pdvs', 'loja_itens'];
    $faltando  = array_diff($esperadas, $tabelas);

    // ---- aplicar schema ----
    if (($_POST['acao'] ?? '') === 'schema') {
        $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', (string) $sql);
        $comandos = array_filter(array_map('trim', explode(';', $sql)));
        $feitos = 0;
        try {
            foreach ($comandos as $c) {
                db()->exec($c);
                $feitos++;
            }
            echo '<p class="bom">Schema aplicado (' . $feitos . ' comandos).</p>';
            $tabelas = listar_tabelas();
            $faltando = array_diff($esperadas, $tabelas);
        } catch (Throwable $e) {
            echo '<div class="caixa"><p class="erro">Falhou no comando ' . ($feitos + 1) . ':</p><pre>'
               . htmlspecialchars($e->getMessage()) . '</pre></div>';
        }
    }

    echo '<table>';
    linha('Conexão com ' . cfg('db_nome'), true, cfg('db_host'));
    foreach ($esperadas as $t) {
        linha('Tabela ' . $t, in_array($t, $tabelas, true));
    }
    echo '</table>';

    if ($faltando) {
        echo '<form method="post"><input type="hidden" name="acao" value="schema">'
           . '<button type="submit">Criar as tabelas que faltam</button></form>';
    }

    // ---- migracoes de coluna (instalacoes anteriores) ----
    if (!$faltando) {
        if (($_POST['acao'] ?? '') === 'migrar') {
            try {
                foreach (migracoes_pendentes() as [$tabela, $coluna, $definicao]) {
                    db()->exec('ALTER TABLE ' . $tabela . ' ADD COLUMN ' . $coluna . ' ' . $definicao);
                    echo '<p class="bom">Coluna ' . htmlspecialchars($tabela . '.' . $coluna) . ' criada.</p>';
                }
                // Preenche o liquido dos itens que ja estavam gravados.
                $n = exec_sql(
                    'UPDATE itens
                        SET valor_total_liquido = GREATEST(valor_total - desconto, 0),
                            valor_unitario_liquido = CASE WHEN quantidade > 0
                                THEN GREATEST(valor_total - desconto, 0) / quantidade
                                ELSE GREATEST(valor_total - desconto, 0) END
                      WHERE valor_total_liquido = 0 AND valor_total > 0'
                );
                echo '<p class="bom">' . $n . ' item(ns) recalculado(s) com o desconto abatido.</p>';
            } catch (Throwable $e) {
                echo '<div class="caixa"><p class="erro">' . htmlspecialchars($e->getMessage()) . '</p></div>';
            }
        }

        $pendentes = migracoes_pendentes();
        echo '<h2>Migrações</h2><table>';
        foreach (migracoes() as $tabela => $colunas) {
            foreach ($colunas as $coluna => $_) {
                linha('Coluna ' . $tabela . '.' . $coluna, coluna_existe($tabela, $coluna));
            }
        }
        echo '</table>';
        if ($pendentes) {
            echo '<form method="post"><input type="hidden" name="acao" value="migrar">'
               . '<button type="submit">Aplicar migrações e recalcular os líquidos</button></form>';
        }
    }

    // ---- primeiro usuario ----
    if (!$faltando) {
        $qtd = (int) qv('SELECT COUNT(*) FROM usuarios');

        if (($_POST['acao'] ?? '') === 'usuario') {
            $nome  = trim((string) ($_POST['nome'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $senha = (string) ($_POST['senha'] ?? '');
            if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 8) {
                echo '<p class="erro">Preencha nome, e-mail válido e senha de pelo menos 8 caracteres.</p>';
            } else {
                try {
                    criar_usuario($nome, $email, $senha);
                    echo '<p class="bom">Usuário criado. <a href="/login">Ir para o login</a></p>';
                    $qtd++;
                } catch (Throwable $e) {
                    echo '<p class="erro">' . htmlspecialchars($e->getMessage()) . '</p>';
                }
            }
        }

        echo '<h2>Usuários</h2>';
        echo '<p>' . $qtd . ' cadastrado(s).</p>';
        echo '<div class="caixa"><form method="post">'
           . '<input type="hidden" name="acao" value="usuario">'
           . '<label>Nome<input name="nome" required></label>'
           . '<label>E-mail<input name="email" type="email" required></label>'
           . '<label>Senha (mín. 8)<input name="senha" type="password" required></label>'
           . '<button type="submit">Criar usuário</button></form></div>';
    }
}

// ---- teste do n8n ----
if (($_POST['acao'] ?? '') === 'n8n') {
    $ch = curl_init((string) cfg('n8n_webhook'));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['teste' => true, 'token' => cfg('n8n_token')]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    echo '<h2>Teste do n8n</h2><pre>HTTP ' . $http . "\n"
       . ($err ? 'erro: ' . htmlspecialchars($err) . "\n" : '')
       . htmlspecialchars(substr((string) $resp, 0, 800)) . '</pre>';
    echo '<p class="det">HTTP 200 ou 404 "webhook not registered" já provam que o servidor '
       . 'alcança o n8n. 404 costuma significar que o workflow está inativo ou em modo de teste.</p>';
}
?>

<h2>Conectividade</h2>
<form method="post">
    <input type="hidden" name="acao" value="n8n">
    <button type="submit">Testar chamada ao n8n</button>
</form>

<h2>Quando terminar</h2>
<p>Esta página fica protegida pelo <code>setup_token</code>. Troque-o por uma string longa
   e aleatória — apagar o arquivo não adianta, o próximo <code>git pull</code> traz de volta:</p>
<pre>php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"</pre>
<p>E desligue o <code>debug</code> no <code>app/config.php</code> quando o app estabilizar.</p>

</body>
</html>
