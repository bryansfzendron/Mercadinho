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
  loja.php         espelho de preço e estoque do TouchPay
  vendas.php       vendas do TouchPay: disparo, callback e gravação em lote
  routes.php       rotas e controllers
  views/
assets/            css, leitor de câmera, ícones
sql/schema.sql     schema MySQL (idempotente)
n8n/
  nfce-sp-mercadinho.workflow.json   workflow da NFC-e, pronto para importar
  touchpay-mercadinho.workflow.json  workflow do preço e estoque
  touchpay-vendas.workflow.json      workflow das vendas
  codigo/*.js                        o JS de cada Code node
  montar-workflow.js                 gera o .json da NFC-e a partir do codigo/
  montar-touchpay.js                 idem, preço e estoque
  montar-vendas.js                   idem, vendas
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
node n8n/montar-workflow.js   # regenera o .workflow.json da NFC-e
node n8n/montar-touchpay.js   # idem, preço e estoque
node n8n/montar-vendas.js     # idem, vendas
node n8n/teste-parser.js      # roda o parser contra um HTML sintético
```

Os dois fluxos do TouchPay compartilham `tp-01-normalizar-entrada.js` e
`tp-02-pegar-token.js`, então **regenere os dois** depois de mexer no login.

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

### Testando a câmera sem servidor

As telas `/escanear` e `/bipar` rodam fora do PHP e do MySQL: `testes/servidor-camera.js`
serve as views com as tags PHP trocadas por valores fixos, e `testes/camera.js` abre tudo
num Chromium com **câmera falsa**. Ele confere que a câmera abre sozinha quando a permissão
já está dada, que `getUserMedia` é chamado **uma única vez** (é o que evita o navegador
perguntar de novo), que o caminho ZXing (sem `BarcodeDetector`, como no iOS) também sobe, e
guarda screenshots em `testes/telas/`.

```bash
npm i playwright && npx playwright install chromium   # só na primeira vez
node testes/servidor-camera.js &
node testes/camera.js
```

### Sobre a permissão da câmera

O navegador guarda a permissão por origem — mas o **Safari do iPhone pergunta a cada
carregamento de página** enquanto o site não estiver marcado como permitido. Para parar de
perguntar: **aA** na barra de endereço → *Configurações do Site* → *Câmera* → *Permitir*.
Instalar pela *Adicionar à Tela de Início* também mantém a permissão (iOS 16.4+). No
Chrome/Android basta permitir uma vez; se estiver perguntando sempre, o site provavelmente
está sendo aberto dentro de um navegador embutido (WhatsApp, Instagram) em vez do Chrome.

De qualquer forma o app faz a parte dele: abre o stream uma vez só, mantém a câmera viva
entre uma leitura e outra, e volta a ligar sozinha na próxima visita.

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

## Espelho da loja (TouchPay)

Além de "quanto eu paguei", a tela de bipar mostra **por quanto a loja vende** e
**quanto tem em estoque agora**. Esses dados vêm do painel TouchPay (AMLabs) por um
segundo workflow n8n, `n8n/touchpay-mercadinho.workflow.json`.

O que foi descoberto sobre essa API, tudo conferido contra a conta real:

- `POST /account/login` responde **200 com corpo vazio**: o JWT vem no header
  `authorization`. Procurar no body devolve nada. Vale ~1 hora.
- `GET /api/PointsOfSale?hasInventory=true&hideSecondary=true&hasActivePlanogram=false`
  lista os PDVs, com `inventoryId` e `currentPlanogramId`.
- **Preço de venda está no planograma**, não no inventário:
  `GET /api/Planograms/{id}` → `entries.items[].price`.
- **Estoque e código de barras estão no inventário**, não no planograma:
  `GET /api/web/inventory/items?inventoryIds={id}&inventoryTypes=pointOfSale&pageSize=500&page=N`
  → `quantity`, `productBarCode`, `averageCost`. Paginado; 500 por página.
- O casamento entre os dois é por `productId`. Como o planograma **não traz EAN**, há uma
  segunda via: o `productCode` sem o prefixo **`OM`** que o TouchPay gruda no código de
  barras (`OM7896007811021` → `7896007811021`). Na conta real isso bateu com o
  `productBarCode` em 499 de 500 itens — o único diferente eram dois GTINs do mesmo
  produto, e aí o `productBarCode` ganha.

O fluxo devolve **um POST por ponto de venda** em `/api/loja/callback`, e o PHP troca as
linhas daquele PDV numa transação (INSERT em blocos de 200; são mais de mil itens e a
hospedagem corta em 30s). Cada item é ligado ao produto do Mercadinho pelo EAN, então o
histórico de compras e o preço de venda aparecem juntos ao bipar.

As credenciais do TouchPay ficam **só no `config.php`** (`touchpay_email`,
`touchpay_senha`), que não vai para o git — o PHP as manda no corpo do disparo em vez de
elas viverem dentro do workflow. O botão *Atualizar preços e estoque* fica na tela inicial.

## Vendas (TouchPay)

O espelho acima diz por quanto a loja vende hoje. As **vendas que aconteceram** vêm de
outro endpoint e de um terceiro workflow, `n8n/touchpay-vendas.workflow.json`, porque a
cadência é outra: preço e estoque são uma foto do agora, venda é histórico.

- `GET /api/Transactions` devolve tudo numa chamada só — **cada transação já traz os
  itens dentro** (`items[]` com `productId`, `productCode`, `productDescription`,
  `productCategoryName`, `quantity`, `price`, `paymentAmount`), mais data, PDV,
  `result`, `paymentMethod` (Credit/Debit/Pix/Voucher) e `cardBrand`. Não precisa de
  `/api/PointsOfSale`: o PDV vem na própria transação.
- Filtros aceitos pela API: `minDate`, `maxDate`, `minTime`, `maxTime`, `pointOfSaleId`,
  `localId`, `customerId`, `paymentMethod`, `minAmount`, `maxAmount`, `productId`, `cpf`,
  mais `page`/`pageSize`/`sortOrder`/`descending` e `timezoneOffset=180`.
  `pageSize=1000` funciona (1000 transações em ~600ms).
- **`price` e `paymentAmount` do item são o TOTAL DA LINHA, não o unitário.** Um item com
  `quantity: 4` veio com `price: 15.56` (unitário 3,89). Somar `paymentAmount` das linhas
  bate com o total da transação em 1000 de 1000 casos; multiplicar por quantidade erra em
  213. O app guarda o total como veio e calcula o unitário dividindo.
- `subtractedItems[]` são os produtos que o cliente **pegou e devolveu**: não entram em
  `items` nem no total, e o coletor ignora.
- `costOfSale` e `profits` vêm sempre **zero** — o TouchPay não sabe o custo. É por isso
  que o relatório mora aqui: quem sabe o custo é a NFC-e.

O fluxo devolve **um POST por lote de 500 vendas** em `/api/vendas/callback`. A gravação é
idempotente pelo par `(fonte, externo_id)`: as vendas do lote são apagadas antes de entrar
de novo, então reimportar a mesma janela corrige em vez de duplicar. Por isso o sync
incremental volta 3 dias — transação recente ainda pode ser reconciliada.

Na tela inicial, *Importar 12 meses de vendas* faz a carga inicial (~12,5 mil transações,
13 páginas) e depois o botão vira *Buscar vendas novas*, que puxa só o que falta.
O webhook novo precisa de `n8n_webhook_vendas` no `config.php`.

## O que sobra da venda

A tela `/vendas` (link no topo da Loja) cruza o que o TouchPay vendeu com o que a NFC-e diz
que você pagou. Filtros: período, ponto de venda, forma de pagamento e busca por produto,
código ou categoria; agrupamento por produto, categoria, dia, mês, PDV, forma de pagamento,
hora do dia ou dia da semana.

O custo entra em três camadas, porque elas se comportam de forma diferente:

1. **Mercadoria (CMV)** — por produto, o último valor unitário líquido pago naquele produto
   segundo a NFC-e. Produto que ainda não tem nota cai no percentual padrão (55%), e a linha
   aparece marcada como *estimado*. O cartão do topo diz quanto do faturamento tem custo de
   nota fiscal de verdade — quanto mais nota escaneada, menos palpite.
2. **Percentual sobre o faturamento** — condomínio, franquia e a taxa da maquininha. A taxa
   sai da forma de pagamento de cada venda (débito, crédito, Pix, voucher), não de uma média
   chutada.
3. **Fixos do mês** — energia e sistema, rateados por dia no período filtrado. Não entram no
   resultado por produto: ratear energia por item vendido seria invenção. Por isso a linha do
   produto mostra **contribuição** (receita − mercadoria − os percentuais) e o cartão do topo
   mostra o **lucro** do período.

As taxas e os valores fixos ficam na tabela `custos_parametros`, editáveis no fim da própria
tela — taxa de maquininha muda com o faturamento e com o fim da promoção.

## Cores

A paleta segue a da [Alpha3](https://alpha3consultoria.com.br): dourado `#eab308` sobre
neutro escuro `#1c1917`. Os tokens estão no topo do `assets/app.css` e têm nome de papel,
não de cor (`--marca`, `--marca-forte`, `--sobre-marca`), justamente para uma troca de
paleta não deixar comentários mentindo.

Dois detalhes que não são estéticos:

- **O texto dourado não é o mesmo dourado do preenchimento.** `#eab308` em fundo claro dá
  contraste de 1,9:1 e é ilegível, então texto e ícone usam `--marca` (`#a16207`) e só o
  preenchimento usa `--marca-forte`. Sobre o preenchimento dourado a tinta é escura
  (`--sobre-marca`), nunca branca.
- **Lucro continua verde e prejuízo vermelho** (`--positivo` / `--vermelho`). Sinal
  financeiro não é marca: com tudo dourado, o relatório perde a leitura de um relance.

## A seção Loja

O item **Loja R$** do menu de baixo tem três telas, num submenu no feitio do segmented
control do iOS — o padrão de quem precisa de mais função do que cabe numa aba só:

- **Catálogo** (`/loja`) — tudo que está nos PDVs. Filtro de estoque em pílulas
  (todos / com estoque / sem estoque); "sem estoque" é a lista de reposição.
- **Vendas** (`/vendas`) — o relatório acima, com atalhos de período (mês corrente,
  mês passado, 30 dias, hoje).
- **Metas** (`/metas`) — meta de faturamento e de lucro do mês, com barra de progresso,
  ritmo diário, projeção de fechamento e quanto falta por dia. Zero desliga a meta e
  deixa só a projeção. As metas moram na mesma tabela dos parâmetros de custo.

Ali também se **liga e desliga cada ponto de venda**. Um PDV pode estar na mesma conta do
TouchPay sem ser do dono do app — foi o caso durante uma transição. Desmarcado, ele sai do
app inteiro (catálogo, bipe, vendas, metas e tela inicial) via `loja_pdvs.ativo`; o sync
continua trazendo os dados, as telas é que ignoram. É por isso que toda leitura de
`loja_itens` passa por `loja_pdvs` — sem o JOIN, o preço de venda de um PDV alheio
apareceria ao bipar.

**Lote perdido não passa mais calado.** O POST de volta usa `neverError`, então um 401
(token diferente entre app e disparo) ou um 500 no meio da carga era engolido e o
relatório nascia faltando venda. O último node do fluxo confere o status de cada lote e,
no último deles, compara quantas transações a API prometeu na janela com quantas o banco
tem — qualquer buraco vira execução com erro, que fica guardada.

## Testes

```bash
php testes/helpers.php      # número BR, data, EAN, chave do QR, formatação
php testes/callback.php     # os formatos aceitos no callback e o cálculo do líquido
php testes/loja.php         # normalização do callback do TouchPay e o prefixo OM
php testes/vendas.php       # callback das vendas, fuso da data e o unitário calculado
php testes/custos.php       # taxa por forma de pagamento, resultado do período e CMV
php testes/nav.php          # o menu de baixo acende um item por rota
node n8n/teste-parser.js    # o parser da NFC-e contra HTML sintético
node n8n/teste-touchpay.js  # o coletor do TouchPay contra uma API falsa
node n8n/teste-vendas.js    # o coletor de vendas: lotes, devolução e total da linha
```

Os que precisam de navegador (`npm i playwright && npx playwright install chromium`,
com `node testes/servidor-camera.js &` ligado):

```bash
node testes/camera.js       # câmera, permissão e o visual das telas de leitura
node testes/puxar.js        # o gesto de puxar para atualizar no app instalado
```

## Limites conhecidos

- **Só NFC-e de São Paulo.** Cada estado tem portal próprio.
- **Consulta por chave de acesso não funciona** — tem captcha. Só o QR Code, que já vem
  assinado com o CSC do emitente. Ou seja: precisa do cupom em mãos.
- **Depende do HTML da SEFAZ.** Se mudarem o layout, o parser do n8n quebra.
- **Leitura de EAN-13 pela câmera** é menos confiável que QR. Há campo para digitar.
