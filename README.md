# Mercadinho

App web (PHP 8 + MySQL) para escanear o QR Code de cupons **NFC-e de São Paulo**, guardar os
itens num banco e responder a pergunta que interessa: **quanto eu já paguei nesse produto?**

Bipa o código de barras na prateleira e o app mostra o histórico de preços por loja.
Notas sem QR Code entram pelo lançamento manual.

- Produção: <https://mercadinho.bryanzendron.com.br>
- Hospedagem: Hostinger (compartilhada, só PHP — sem Node)
- Raspagem da SEFAZ: workflow n8n `5jtmXgsBWN7tdbMW` na instância `biomega-n8n`

## Como funciona

```
Celular (PWA)  --scan QR-->  POST /api/notas       (nota entra como "pendente")
                                  |
                                  +--dispara-->  webhook do n8n  (fire-and-forget)
                                                      |
                             POST /api/callback  <----+  itens em JSON
                                  |
                               MySQL
                                  |
Frontend faz polling em /api/notas/{id}/status  ->  "109 itens gravados"
```

O trabalho pesado (3 requisições à SEFAZ + parse de ~1,7 MB de HTML) fica no n8n, fora
da hospedagem compartilhada — por isso o limite de 30s do PHP não atrapalha.

### Identificação de produtos

No NFC-e o campo `cEAN` vem como **"SEM GTIN"** com frequência (hortifruti, itens pesados
na loja, emitentes desleixados). Por isso o produto é identificado em três níveis:

1. **EAN**, quando existe — chave global, funciona entre lojas. É o que você bipa.
2. **Loja + código interno** (`cProd`) — sempre existe, mas só vale naquela loja.
3. **Descrição normalizada** — fallback.

Todo item grava o alias `loja + código interno`. Quando o mesmo produto aparecer depois
**com** EAN, o cadastro antigo é adotado e o histórico inteiro passa a responder ao bip.
Na tela do produto dá para vincular um EAN à mão a um produto que nasceu sem GTIN.

## Estrutura

```
index.php          front controller
setup.php          diagnóstico do ambiente + criação do schema e do 1º usuário
manifest.json      PWA (instalável no celular)
.htaccess          HTTPS forçado, rewrite, bloqueio de /app, /sql e .git
app/
  config.php       NÃO versionado — nasce no servidor a partir do config.example.php
  bootstrap.php    config, erros, sessão
  db.php           PDO + helpers de query
  helpers.php      número BR, data, normalização, EAN, CSRF, views
  auth.php         login por sessão
  produtos.php     casamento de produtos e histórico de preços
  notas.php        criação, disparo ao n8n e ingestão do callback
  routes.php       rotas e controllers
  views/
assets/            css, leitor de câmera, ícones
sql/schema.sql     schema MySQL (idempotente)
n8n/
  nfce-sp-mercadinho.workflow.json   workflow pronto para importar
  codigo/*.js                        o JS de cada Code node
  montar-workflow.js                 gera o .json a partir do codigo/
  teste-parser.js                    regressão do parser
```

## Deploy na Hostinger

### Primeira vez

```bash
ssh -p 65002 SEU_USUARIO@SEU_IP

cd ~/domains/mercadinho.bryanzendron.com.br/public_html
rm -f default.php index.html                 # limpa a página padrão
git clone https://github.com/bryansfzendron/Mercadinho.git .

cp app/config.example.php app/config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # gere 2 tokens
nano app/config.php
```

Preencha no `app/config.php`: dados do banco (hPanel → Bancos de Dados MySQL),
`n8n_token` e `setup_token`.

As credenciais do banco **não vêm deste repositório** — você as cria no hPanel, em
*Bancos de Dados → Bancos MySQL*. Lá aparecem o nome do banco e o usuário (ambos com
prefixo, tipo `u123456789_mercadinho`); a senha é a que você definiu ao criar, e pode
ser trocada no mesmo lugar.

Depois abra `https://mercadinho.bryanzendron.com.br/setup.php?token=SEU-SETUP-TOKEN`.
A página valida PHP, extensões, HTTPS e banco, cria as tabelas e o primeiro usuário.

### Criando as tabelas

Três caminhos, em ordem de preferência:

1. **`setup.php`** — botão "Criar as tabelas que faltam". Aplica o `sql/schema.sql`
   e mostra tabela por tabela o que existe.
2. **phpMyAdmin** (hPanel → Bancos de Dados → phpMyAdmin) → aba *Importar* →
   envie `sql/schema.sql`.
3. **SSH**: `mysql -u USUARIO -p BANCO < sql/schema.sql`

O schema é idempotente (`CREATE TABLE IF NOT EXISTS`), então rodar de novo não quebra
nada nem apaga dados.

### Atualizações

```bash
cd ~/domains/mercadinho.bryanzendron.com.br/public_html
git pull
```

`app/config.php` está no `.gitignore`, então o pull nunca sobrescreve suas credenciais.

## Workflow do n8n

O workflow pronto para importar está em **`n8n/nfce-sp-mercadinho.workflow.json`**.
No n8n: *Workflows → Import from File*.

Ele é uma cadeia linear de 9 nodes, sem IF:

```
Receber do Mercadinho (webhook, responde na hora)
  → Normalizar Entrada
  → Abrir Sessao na SEFAZ        (GET do QR, colhe cookies)
  → Extrair Cookies
  → Abrir Consulta Resumida      (GET sem seguir redirect, pega __VIEWSTATE)
  → Extrair ViewState
  → Abrir Abas Detalhadas        (POST __EVENTTARGET=btnVisualizarAbas)
  → Extrair Itens do Cupom       (parser; decide status ok/erro no try/catch)
  → Devolver ao Mercadinho       (POST no callback_url)
```

Não há ramo de erro porque o parser **sempre** produz um payload — com
`status: "ok"` ou `status: "erro"`. Assim nenhuma nota fica presa em
"processando" no app.

**Depois de importar:**

1. O webhook usa o caminho **`nfce-sp-mercadinho`**, diferente do `nfce-sp` do fluxo
   antigo, de propósito: os dois podem conviver enquanto você testa. Ajuste
   `n8n_webhook` no `app/config.php` para
   `https://biomega-n8n.bryanzendron.com.br/webhook/nfce-sp-mercadinho`.
2. Ative o workflow (webhook de produção só responde com o workflow ativo).
3. Confira a URL da Consulta Resumida nos nodes `Abrir Consulta Resumida` e
   `Abrir Abas Detalhadas` se a SEFAZ mudar o caminho.

### Editando o código dos nodes

O JavaScript de cada Code node mora em `n8n/codigo/*.js` — escrever JS dentro de
string JSON à mão é fonte garantida de erro de escape. Depois de editar:

```bash
node n8n/montar-workflow.js   # regenera o .workflow.json
node n8n/teste-parser.js      # roda o parser contra um HTML sintético
```

O HTML sintético do teste imita a estrutura real, conferida contra a nota 28048
(109 itens). Ele cobre as armadilhas que já morderam:

- **cada item são duas tabelas irmãs** — a linha resumida em
  `<table class="toggle box">` e os detalhes (EAN, código do produto, valor
  unitário, desconto) em `<table class="toggable box">` logo depois. Casar só
  `toggle` devolve descrição e valor, mas deixa todo o resto vazio;
- **a aba Destinatário (`id="DestRem"`) repete os rótulos do Emitente** com
  valor vazio; por isso a fatia do emitente termina ali e o primeiro rótulo
  vence no `pares()` — senão a razão social vira string vazia;
- o corpo da resposta HTTP vem em **`data`** nesta versão do n8n (era `body` nas
  antigas); os nodes leem os dois;
- linha de cabeçalho em `<label>`, a aba Cobrança que reusa `class="toggle box"`,
  entidade HTML dupla (`D&amp;#39;ORO`), item `SEM GTIN` e o caminho de erro.

### Contrato com o app

O app manda no webhook:

```json
{
  "nota_id": 123,
  "qrcode": "https://www.nfce.fazenda.sp.gov.br/qrcode?p=...",
  "callback_url": "https://mercadinho.bryanzendron.com.br/api/callback",
  "token": "<n8n_token>",
  "guardar_html": true
}
```

`nota_id`, `callback_url` e `token` atravessam o fluxo inteiro a partir do
`Normalizar Entrada`, e voltam no callback.

**O `/api/callback` aceita dois formatos**, então não importa se o seu fluxo monta o
payload aninhado ou no formato de planilha:

| Formato | Como se parece |
|---|---|
| aninhado | `{nota_id, token, status, nota:{...}, itens:[{...}]}` |
| achatado | uma linha por item, com as 27 colunas de cabeçalho repetidas em cada uma — dentro de `itens`/`dados`/`data`, ou como lista na raiz do corpo |

No formato achatado o cabeçalho (`emitente`, `cnpj`, `municipio`, `valor_total_nota`…)
é lido da primeira linha, e `nota_id`/`token` podem vir no envelope ou repetidos nas
linhas. O corpo também pode vir embrulhado em `body`.

O `Devolver ao Mercadinho` posta em `{{ $json.callback_url }}`:

```json
{
  "nota_id": 123,
  "token": "<n8n_token>",
  "status": "ok",
  "nota": {
    "chave": "35260946029724000673651010000280481783880108",
    "emitente": "HIGA PRODUTOS ALIMENTICIOS LTDA",
    "nome_fantasia": "",
    "cnpj": "46029724000673",
    "inscricao_estadual": "798552003114",
    "endereco": "AV. JUVENAL DE CAMPOS, 550",
    "bairro": "JARDIM FACULDADE",
    "cep": "18030-280",
    "municipio": "SOROCABA",
    "codigo_municipio": "3552205",
    "uf": "SP",
    "modelo": "65",
    "serie": "101",
    "numero_nota": "28048",
    "emissao": "04/09/2026 22:26:38-03:00",
    "natureza_operacao": "venda",
    "valor_total_produtos": "1.595,140",
    "desconto_total_nota": "62,040",
    "valor_total_nota": "1.533,100",
    "url_consulta": "https://..."
  },
  "itens": [
    {
      "item": 1, "codigo": "359894", "descricao": "SAND FAROESTE BURGER 145G",
      "quantidade": "3,0000", "unidade": "UN",
      "valor_unitario": "6,9800000000", "valor_total_item": "20,940",
      "desconto_item": "0,870",
      "ean": "7891164026974", "ean_tributavel": "7891164026974",
      "ncm": "16029000", "cest": "1707900", "cfop": "5405",
      "valor_tributos": "5,110", "origem": "0 - Nacional"
    }
  ]
}
```

O app usa `emitente`, `cnpj`, `municipio`, `uf` e os totais no cabeçalho, e
`codigo`, `ean`, `ncm`, `cest`, `cfop`, quantidade, unidade, valores e
`desconto_item` em cada produto. Campos além desses (endereço, tributos,
origem) vêm junto para uso futuro e são ignorados sem erro. Números podem ir em
formato brasileiro (`1.234,56`) ou como float — o PHP aceita os dois. Datas
aceitas em `dd/mm/aaaa hh:mm:ss`, com ou sem o fuso colado (`-03:00`), ou ISO.

Quando algo falha — sessão recusada, `__VIEWSTATE` ausente, nenhum item extraído —
o mesmo node posta:

```json
{ "nota_id": 123, "token": "<n8n_token>", "status": "erro", "erro": "descrição do problema" }
```

Se `guardar_html` estiver ligado no `config.php`, o payload leva junto o HTML bruto da
Consulta Completa no campo `html`. O app comprime com gzip (~150 KB por nota) e guarda,
para reprocessar sem precisar bipar o cupom de novo caso o parser mude.

## Descontos e o preço que o app mostra

A nota traz `desconto_item` por produto. O app guarda os dois valores:

- `valor_total` / `valor_unitario` — o que estava marcado na nota
- `valor_total_liquido` / `valor_unitario_liquido` — **o que foi pago de fato**

Todo o histórico, as estatísticas (último/menor/maior/média) e o resultado do bipe usam
o **líquido**, porque a pergunta do app é "quanto paguei", não "quanto estava marcado".
Na tela da nota o valor cheio aparece riscado ao lado quando houve desconto.

Quando a nota não informa o total de um item, ele é reconstruído de
`quantidade × valor_unitario`; quando não informa o total da nota, usa-se a soma dos
líquidos.

## Testes

```bash
php testes/helpers.php      # número BR, data, EAN, chave do QR, formatação
php testes/callback.php     # os formatos aceitos no callback e o cálculo do líquido
node n8n/teste-parser.js    # o parser do Code node contra HTML sintético
```

## Limites conhecidos

- **Só NFC-e de São Paulo.** Cada estado tem portal próprio.
- **Consulta por chave de acesso não funciona** — tem captcha. Só o QR Code, que já vem
  assinado com o CSC do emitente. Ou seja: precisa do cupom em mãos.
- **Depende do HTML da SEFAZ.** Se mudarem o layout, o parser do n8n quebra.
- **Leitura de EAN-13 pela câmera** é menos confiável que QR. Há campo para digitar.
