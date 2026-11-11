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

    // Por onde passa a NOTA FISCAL:
    //  'n8n' (padrao): dispara o webhook acima e espera o /api/callback.
    //  'local': este servidor consulta a SEFAZ e grava na mesma requisicao,
    //           sem n8n (app/sefaz.php). Antes de ligar, rode "Testar
    //           consulta a SEFAZ" no setup.php: o firewall da SEFAZ precisa
    //           aceitar o IP da hospedagem.
    // Voltar para 'n8n' e so trocar esta linha, enquanto o workflow existir.
    'nfce_modo' => 'n8n',

    // Guardar o HTML bruto da consulta (comprimido) para reprocessar sem
    // precisar bipar a nota de novo. ~150 KB por nota depois do gzip.
    'guardar_html' => true,

    // ---------------------------------------------------------------
    // Espelho do TouchPay (preco de venda e estoque da loja)
    // ---------------------------------------------------------------
    // Webhook de producao do workflow "TouchPay -> Mercadinho".
    'n8n_webhook_touchpay' => 'https://biomega-n8n.bryanzendron.com.br/webhook/touchpay-mercadinho',

    // Webhook de producao do workflow "TouchPay Vendas -> Mercadinho".
    'n8n_webhook_vendas' => 'https://biomega-n8n.bryanzendron.com.br/webhook/touchpay-vendas',

    // Login do painel do TouchPay. Fica SO aqui: este arquivo nao vai para o
    // git, e o n8n recebe as credenciais no corpo do disparo em vez de
    // guarda-las dentro do workflow.
    // 'local' (padrao): o PHP colhe preco, estoque e vendas direto do
    // TouchPay. 'n8n': volta a disparar os webhooks antigos — util so
    // enquanto os workflows continuarem existindo, desligados, la.
    // A NOTA FISCAL nao entra nesta chave: ela tem a sua, o nfce_modo.
    'sync_modo' => 'local',

    // A conta TouchPay "legada": a que ficou com a loja de antes da separacao
    // por conta. O e-mail fica (identifica a conta e recebe os callbacks do
    // n8n). A senha e opcional: com ela, o cron sincroniza essa conta 24h;
    // vazia, a conta sincroniza como as outras, enquanto o dono usa o app
    // (a senha fica so no celular, em Configuracoes > TouchPay).
    'touchpay_email' => '',
    'touchpay_senha' => '',
    // Chave que cifra o e-mail/senha do TouchPay que cada usuario guarda no
    // proprio aparelho (Configuracoes > TouchPay) e que identifica cada conta
    // no banco. Qualquer texto longo e aleatorio. DEFINA UMA VEZ E NAO TROQUE:
    // trocar desliga cada usuario da loja dele ate reconectar. Obrigatoria
    // para separar a loja por conta (setup.php).
    'touchpay_cofre_chave' => '',

    // ---------------------------------------------------------------
    // Sincronizacao automatica (cron.php)
    // ---------------------------------------------------------------
    // No hPanel da Hostinger, Avancado > Cron Jobs, a cada 5 minutos:
    //   php ~/domains/bryanzendron.com.br/public_html/mercadinho/cron.php
    //
    // Chamando por HTTP em vez do CLI, exige este token:
    //   curl "https://mercadinho.bryanzendron.com.br/cron.php?token=..."
    // Gere com: php -r "echo bin2hex(random_bytes(16));"
    'cron_token' => 'TROQUE-ME',

    // Intervalo minimo de cada fonte, em minutos. O cron pode rodar de 5 em 5
    // que o resto e ignorado: venda muda o tempo todo, preco e estoque nao, e
    // a coleta do estoque e pesada (o inventario inteiro de cada PDV).
    'cron_vendas_min' => 5,
    'cron_loja_min'   => 15,

    // ---------------------------------------------------------------
    // Skill da Alexa ("Alexa, pergunta pro mercadinho quanto vendeu hoje")
    // ---------------------------------------------------------------
    // A Amazon chama POST /alexa. Os dois campos abaixo sao obrigatorios;
    // vazios, a rota recusa tudo.
    //  - alexa_skill_id: o ID da skill no console da Alexa
    //    (amzn1.ask.skill....). Pedido de outra skill e recusado.
    //  - alexa_usuario_id: o id (tabela usuarios) de quem e a loja que a
    //    Alexa le — a mesma conta TouchPay que esse usuario ve no app.
    //  - alexa_user_ids (opcional): userIds da Alexa aceitos. So precisa se
    //    a skill sair do modo de desenvolvimento.
    'alexa_skill_id'   => '',
    'alexa_usuario_id' => 0,
    'alexa_user_ids'   => [],

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
