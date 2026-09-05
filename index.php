<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/routes.php';

try {
    despachar(rota_atual());
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    if (cfg('debug')) {
        echo '<h1>Erro de banco</h1><pre>' . e($e->getMessage()) . '</pre>'
           . '<p>Rode <a href="/setup.php">/setup.php</a> para conferir a instalacao.</p>';
    } else {
        error_log('[mercadinho] ' . $e->getMessage());
        echo '<h1>Erro interno</h1><p>Tente de novo em instantes.</p>';
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    if (cfg('debug')) {
        echo '<h1>Erro</h1><pre>' . e($e->getMessage()) . "\n\n" . e($e->getTraceAsString()) . '</pre>';
    } else {
        error_log('[mercadinho] ' . $e->getMessage());
        echo '<h1>Erro interno</h1><p>Tente de novo em instantes.</p>';
    }
}
