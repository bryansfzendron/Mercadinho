<?php
/**
 * Copie este arquivo para app/config.php NO SERVIDOR e preencha.
 * app/config.php esta no .gitignore e nunca deve ser commitado.
 *
 *   cp app/config.example.php app/config.php && nano app/config.php
 */

return [
    // ---------------------------------------------------------------
    // Banco de dados (hPanel > Bancos de Dados MySQL)
    // ---------------------------------------------------------------
    'db_host' => 'localhost',
    'db_nome' => 'uXXXXXXXX_mercadinho',
    'db_user' => 'uXXXXXXXX_mercadinho',
    'db_senha' => '',
    'db_porta' => 3306,

    // ---------------------------------------------------------------
    // URL publica da aplicacao, sem barra no final.
    // Usada para montar o callback_url que o n8n vai chamar de volta.
    // ---------------------------------------------------------------
    'base_url' => 'https://mercadinho.bryanzendron.com.br',

    // ---------------------------------------------------------------
    // Integracao com o n8n
    // ---------------------------------------------------------------
    // Webhook de producao do workflow "NFC-e SP - Itens do Cupom"
    'n8n_webhook' => 'https://biomega-n8n.bryanzendron.com.br/webhook/nfce-sp',

    // Segredo compartilhado: o PHP manda no disparo e exige de volta no
    // callback. Gere com:  php -r "echo bin2hex(random_bytes(32));"
    // ou:  openssl rand -hex 32
    'n8n_token' => 'TROQUE-ME',

    // Guardar o HTML bruto da consulta (comprimido) para reprocessar sem
    // precisar bipar a nota de novo. ~150 KB por nota depois do gzip.
    'guardar_html' => true,

    // ---------------------------------------------------------------
    // Token da pagina de instalacao/diagnostico (setup.php).
    // Acesse: /setup.php?token=SEU-TOKEN
    // Depois de instalar, troque por uma string aleatoria ou remova o arquivo.
    // ---------------------------------------------------------------
    'setup_token' => 'TROQUE-ME-TAMBEM',

    // Mostrar erros PHP na tela. Deixe true ate o app estabilizar,
    // depois mude para false.
    'debug' => true,
];
