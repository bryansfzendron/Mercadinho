<?php
declare(strict_types=1);

define('RAIZ', dirname(__DIR__));
define('APP', __DIR__);

// ---------------------------------------------------------------------
// Configuracao
// ---------------------------------------------------------------------
if (!is_file(APP . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>Falta o config.php</h1>'
       . '<p>Copie <code>app/config.example.php</code> para <code>app/config.php</code> '
       . 'no servidor e preencha os dados do banco.</p>'
       . '<pre>cp app/config.example.php app/config.php'
       . "\n" . 'nano app/config.php</pre>';
    exit;
}

$GLOBALS['cfg'] = require APP . '/config.php';

function cfg(string $chave, $padrao = null) {
    return $GLOBALS['cfg'][$chave] ?? $padrao;
}

// ---------------------------------------------------------------------
// Erros
// ---------------------------------------------------------------------
if (cfg('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
ini_set('log_errors', '1');

date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');

// ---------------------------------------------------------------------
// Sessao
// ---------------------------------------------------------------------
function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('mercadinho');
    session_start();
}

require APP . '/helpers.php';
require APP . '/db.php';
require APP . '/auth.php';
require APP . '/produtos.php';
require APP . '/notas.php';
require APP . '/loja.php';
require APP . '/vendas.php';
require APP . '/custos.php';
