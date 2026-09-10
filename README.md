# Alpha Market

*(a marca voltada pro usuário é "Alpha Market" — o projeto, a pasta e o domínio continuam
"mercadinho" por baixo, de propósito, pra não mexer em nada que já está no ar.)*

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

## Os dois pisos de margem

O fator sozinho engana: **1,13x parece lucro e não é**. Vender por V o que custou C sobra
`V − C − V×pct`, e isso zera quando o fator chega em `1/(1−pct)`. Com 11,68% de maquininha,
condomínio e franquia mais a alíquota efetiva do Simples Nacional do momento, o piso sobe —
abaixo dele cada unidade vendida tira dinheiro do bolso.

O segundo piso põe o custo fixo do mês na conta como percentual do faturamento (R$ 449 sobre
~R$ 14,7 mil = 3,05%), e sobe para **1,173x**. Entre os dois, o produto cobre o que sai de
cada venda mas não ajuda a pagar energia e sistema.

Os dois números são calculados dos dados reais — mix de pagamento dos últimos 90 dias e
faturamento do mês projetado —, não cravados no código. Quem quiser conferir: a aba
**Margens** mostra os pisos no topo, com a conta.

A aba lista os produtos **do pior fator para o melhor**, com filtro por veredito (prejuízo,
no aperto, saudáveis, sem custo de nota). E o mesmo aviso aparece inline ao bipar e na tela
do produto, com link para a lista — o alerta chega onde você já está olhando, e a auditoria
tem tela própria.

## O custo é o do que está na prateleira

Ao bipar, o custo comparado com o preço de venda é o das **unidades em estoque**, não o da
última nota. São perguntas diferentes: quem comprou 12 a R$ 1,00 depois de 6 a R$ 0,80 e
tem 15 na prateleira não tem 15 a R$ 1,00.

`produto_custo_estoque()` anda do mais novo para o mais velho até cobrir a quantidade em
estoque e faz a média ponderada — o custo da última camada, que é como estoque de mercearia
se comporta: o que entrou por último está na frente. Quando o histórico de notas não alcança
o estoque inteiro, o custo vale para a parte coberta e a tela escreve *(parte sem nota)* em
vez de fingir precisão. Sem estoque, cai no último preço pago, e a tela diz qual dos dois
está usando.

## Vale a pena comprar por X?

Ao bipar, além do histórico, a tela pergunta **quanto estão cobrando agora** e responde na
hora se compensa. A conta é:

```
sobra por unidade = preço de venda − custo digitado − preço de venda × (maquininha + condomínio + franquia + imposto)
```

```
depois da operação = sobra − preço de venda × (energia + sistema, como % do faturamento)
```

Duas camadas porque elas respondem a perguntas diferentes, e o veredito usa **os mesmos três
degraus da aba Margens** — vale a pena, não paga a operação, não vale. Um produto no meio
não pode receber "vale a pena" ao bipar e "não paga a operação" na listagem.

Os percentuais vêm dos dados reais: mix de pagamento dos últimos 90 dias e peso do custo
fixo sobre o faturamento do mês. Além do veredito, a tela mostra o fator, quanto sobra em
cada camada e como o valor se compara com o último que você pagou naquele produto.

## O que sobra da venda

A tela `/vendas` (link no topo da Loja) cruza o que o TouchPay vendeu com o que a NFC-e diz
que você pagou. Filtros: período, ponto de venda, forma de pagamento, categoria (exata, via
`vendas_categorias()`) e busca livre por produto, código ou categoria; agrupamento por
produto, categoria, dia, mês, PDV, forma de pagamento, hora do dia ou dia da semana.

Na aba Resumo a categoria filtra os **itens** (`vi.categoria = ?`), então "Resultado do
período" continua mostrando o faturamento do período inteiro — igual à busca, ela só recorta
a lista de produtos abaixo. Na aba Transações a compra é no bolo, não por item, então lá o
filtro vira `EXISTS` contra os itens da compra: filtra pela **compra inteira** que levou algo
daquela categoria, não só o item dela.

O custo entra em três camadas, porque elas se comportam de forma diferente:

1. **Mercadoria (CMV)** — por produto, o último valor unitário líquido pago naquele produto
   segundo a NFC-e. Produto que ainda não tem nota cai no percentual padrão (55%), e a linha
   aparece marcada como *estimado*. O cartão do topo diz quanto do faturamento tem custo de
   nota fiscal de verdade — quanto mais nota escaneada, menos palpite.
2. **Percentual sobre o faturamento** — condomínio, franquia, a taxa da maquininha e o
   imposto. A taxa sai da forma de pagamento de cada venda (débito, crédito, Pix, voucher),
   não de uma média chutada; o imposto sai da alíquota efetiva do Simples Nacional do
   momento (ver seção **Imposto** abaixo).
3. **Fixos do mês** — energia, sistema e internet, rateados por dia no período filtrado. Não
   entram no resultado por produto: ratear energia por item vendido seria invenção. Por isso a
   linha do produto mostra **contribuição** (receita − mercadoria − os percentuais) e o cartão
   do topo mostra o **lucro** do período.

As taxas e os valores fixos ficam na tabela `custos_parametros`, editáveis em
Configurações → Taxas — taxa de maquininha muda com o faturamento e com o fim da promoção. O
imposto não é editável ali: ele é calculado, não digitado (ver abaixo).

### Imposto (Simples Nacional)

O CNPJ emite pelo CNAE **4712-1/00** (comércio varejista de mercadorias em geral, com
predominância de produtos alimentícios), que cai no **Anexo I** do Simples Nacional. A
alíquota não é fixa: ela sobe com o **RBT12** — o faturamento bruto acumulado dos últimos 12
meses — e a conta oficial (LC 123/2006, art. 18) usa a alíquota efetiva, não a nominal da
faixa:

```
alíquota efetiva = (RBT12 × alíquota nominal da faixa − parcela a deduzir) / RBT12
```

`custos_rbt12()` soma o faturamento de **todos os PDVs ativos**, sem filtrar por container — o
Simples é apurado por CNPJ, não por ponto de venda, ao contrário de condomínio/franquia/taxa
de maquininha. `custos_imposto_pct()` devolve a alíquota efetiva de hoje, e ela entra no mesmo
percentual variável que desconta maquininha, condomínio e franquia — ao bipar, no relatório de
vendas e no dashboard.

A tabela do Anexo I mora em `custos_simples_anexo1()`. Se a empresa mudar de CNAE, sair do
Simples ou crescer além do teto (R$ 4,8 milhões/ano), essa função é o lugar a ajustar.

**A loja mudou de dono em 01/09/2026** (`custos_inicio_atividade()`), e a receita de antes
disso é de quem tinha o CNPJ antes — mesmo que o TouchPay tenha histórico de venda mais
antigo, `custos_rbt12()` nunca busca antes dessa data. Enquanto não completar 12 meses sob
este CNPJ, o RBT12 não é a soma crua do período: é **anualizado**
(`receita acumulada ÷ meses de atividade × 12`), que é a regra oficial do Simples para
empresa em início de atividade (o mês de abertura já conta como mês 1). Sem isso a alíquota
apareceria artificialmente baixa nos primeiros meses e daria um salto de uma vez quando o
primeiro ano fechasse — a tela de Configurações → Custos avisa enquanto isso durar.

### Cada container tem a sua conta

As camadas 2 e 3 são **por ponto de venda** — com uma exceção. Um container tem a sua conta
de luz, a sua internet e às vezes até um condomínio com percentual diferente; somar a receita
toda e aplicar uma média só daria o mesmo número se todos os PDVs fossem iguais. O imposto é
a exceção: como é apurado por CNPJ, a mesma alíquota efetiva entra igual para todo PDV, em
vez de vir de `custos_parametros_pdv()`.

Por isso a conta é feita por `(PDV, forma de pagamento)`: `vendas_por_pdv_forma()` traz a
receita nessa granularidade e `custos_resultado_pdvs()` soma PDV a PDV, cada um com os seus
parâmetros. A tela continua mostrando por forma de pagamento — `vendas_juntar_formas()` é
quem junta as linhas depois da conta, não antes.

Os valores em `custos_parametros` são o **padrão**. A tabela `custos_pdv` guarda só a
**exceção**: `(pdv_id, chave, valor)`. Um PDV sem exceção nenhuma não ocupa uma linha, e
mudar o padrão mexe em todos de uma vez — que é o que se quer quando a taxa da maquininha
muda. Na tela, o campo em branco segue o padrão (o valor cinza é o que vale hoje) e apagar
um campo desfaz a exceção.

O custo fixo vem da **lista de PDVs ativos**, não das vendas: container parado o mês inteiro
continua pagando energia, sistema e internet, e um mês ruim não pode ficar bonito por falta
de venda. Filtrando um PDV no relatório, só o fixo dele entra.

## Logo e cores

A marca é a **Alpha Market**: os arquivos originais (completo e simplificado) estão em
`logo/`. Os que o app de fato serve ficam em `assets/` — gerados a partir dos originais
(recortados, redimensionados e, para os ícones, achatados sobre fundo branco):

- `assets/logo/completo.png` — logo com a palavra, usado na tela de login e na Início.
- `assets/logo/simplificado.png` — só o símbolo, fundo transparente, usado no topo (barra
  de cima) em tamanho pequeno.
- `assets/icone-192.png` / `assets/icone-512.png` — ícone do manifest (Android/Chrome).
- `assets/icone-apple.png` — ícone do "Adicionar à Tela de Início" do iPhone
  (`apple-touch-icon` no `<head>`, ver `app/views/layout.php`). O iOS ignora os ícones do
  manifest para isso, então esse link é quem manda.
- `assets/favicon.png` — aba do navegador.

A paleta saiu do próprio logo (marinho `#071c3d` + azul `#0a5fc4` + ciano `#12aefb`), no
lugar do dourado da Alpha3 que o app usava antes. Os tokens estão no topo do
`assets/app.css` e têm nome de papel, não de cor (`--marca`, `--marca-forte`,
`--sobre-marca`), justamente para uma troca de paleta não deixar comentários mentindo — foi
essa mesma convenção que tornou a troca para o azul uma edição de poucas linhas.

Dois detalhes que não são estéticos:

- **O texto azul não é o mesmo azul do preenchimento.** O ciano vivo (`--marca-forte`,
  `#12aefb`) não tem contraste suficiente como texto em fundo claro, então texto e ícone
  usam `--marca` (`#0a5fc4`, mais escuro) e só o preenchimento usa `--marca-forte`. Sobre o
  preenchimento ciano a tinta é o marinho do logo (`--sobre-marca`), nunca branca — mesma
  lógica que o dourado tinha, só com outro tom.
- **Lucro continua verde e prejuízo vermelho** (`--positivo` / `--vermelho`), sem trocar
  pelo azul da marca. Sinal financeiro não é marca: com tudo azul, o relatório perde a
  leitura de um relance. (O verde do relatório nem precisou mudar — já é parecido com o
  verde que aparece no gráfico de barras do logo.)

## O plano diário da meta

A aba Metas mostra, dia a dia, duas colunas que respondem perguntas diferentes:

- **Meta acumulada** é a linha reta do mês (`meta ÷ dias do mês × dia`). É onde você
  deveria estar hoje, e por isso fecha exatamente na meta no último dia. É contra ela que
  a diferença compara.
- **Meta do dia** é o plano recalculado: o que falta dividido pelos dias que restam. Sobe
  quando se fica para trás, cai quando se adianta.

A primeira versão somava o déficit **em cima** da meta base e realimentava o resultado no
dia seguinte, então a meta compunha sozinha: com R$ 3.000 de meta e nada vendido, a meta
acumulada terminava em R$ 46.500 e o dia 30 pedia R$ 23.300. Também dividia a meta pelos
dias da **janela** (que no mês corrente termina hoje) em vez dos dias do **mês**, o que
inflava a meta diária quanto mais cedo no mês você abrisse a tela. `testes/breakdown.php`
trava as duas propriedades que impedem isso de voltar.

## Sincronização automática

`cron.php` dispara as duas cargas sozinho. No hPanel da Hostinger, **Avançado → Cron Jobs**,
a cada 5 minutos:

```
*/5 * * * *  php /home/uXXXXXXXX/domains/bryanzendron.com.br/public_html/mercadinho/cron.php
```

Caminho absoluto, não `~` — o campo do hPanel já é assim.

O app fica num subdiretório do `public_html` do domínio raiz, e o subdomínio
`mercadinho.` aponta para lá — por isso o caminho do arquivo e a URL não se
parecem. **É onde o `/mercadinho` cai fora sem ninguém notar**: apontando para
`public_html/cron.php` a tarefa roda todo dia, num arquivo que não existe, e o
"Could not open input file" some no e-mail do cron. Se o cartão *Automático*
disser que ele nunca passou, confira o caminho antes de qualquer outra coisa.

Também responde por HTTP, para quem preferir chamar de fora — aí exige o `cron_token`:

```
curl "https://mercadinho.bryanzendron.com.br/cron.php?token=SEU-CRON-TOKEN"
```

Por que aqui e não um Schedule Trigger no n8n: as credenciais do TouchPay moram só no
`config.php` e vão no corpo do disparo. Um gatilho de horário dentro do n8n não teria
corpo nenhum, e a senha passaria a viver lá dentro — que é justamente o que o desenho
evita.

Duas travas, ambas em `sync_motivo_para_pular()`:

- **carga correndo não ganha companhia** — e uma que travou (sem notícia há mais de 10
  minutos) não segura a fila para sempre;
- **intervalo mínimo por fonte**, contado do início da última carga: `cron_vendas_min`
  (5 min) e `cron_loja_min` (30 min). Venda muda o tempo todo; preço e estoque não, e a
  coleta do estoque é pesada — é o inventário inteiro de cada PDV. Assim o cron pode
  bater de 5 em 5 que o resto se ignora sozinho.

Cada execução escreve uma linha do que fez ou por que pulou. `?forcar=1` (ou `--forcar`
no CLI) ignora o intervalo.

### Saber se ele está rodando

Um cron que não roda não roda em silêncio — e a tela fica igual à de um cron que roda e
não acha nada novo. Por isso cada execução deixa um batimento em `sync_estado` com
`fonte = 'cron'`, e **Configurações → Sincronizar** mostra "última passagem há X min" mais
o que ele fez. Passando de 20 minutos (três rodadas perdidas), a tela avisa.

A primeira coisa que esse batimento revelou: o cron **nunca** tinha funcionado. O laço
das fontes usava `$cfg` como variável, e `$cfg` no escopo global de um script é o mesmo
`$cfg` que guardava o `config.php` inteiro — a primeira volta do `foreach` apagava a
configuração, e a conexão seguinte ia ao MySQL com usuário e senha vazios. Com
`display_errors` em 0, isso acontecia em silêncio. Hoje a configuração mora dentro da
própria `cfg()`, num `static`, então não há global para ninguém pisar.

Quando ele não passa, a outra causa comum é o **PHP do cron**: a hospedagem tem mais de
uma versão instalada e o `php` do agendador nem sempre é o do site. O app precisa de 8.0
para cima; num PHP velho ele morria no meio de um `require` e o cron mandava um e-mail em
branco. Agora `cron.php` confere a versão na primeira linha e diz qual está rodando, e no
CLI liga o `display_errors` — o `config.php` deixa ele desligado para o site, e sem isso
qualquer erro sumia junto.

Para ver na hora:

```
php ~/domains/bryanzendron.com.br/public_html/mercadinho/cron.php
```

Se aparecer a mensagem da versão, troque o `php` do Cron Job pelo caminho completo do
binário certo (`/usr/bin/php8.2`, `/opt/alt/php82/usr/bin/php` — o hPanel mostra qual).
Disparo que falha devolve **saída 1**, para o agendador saber que deu errado.

## Configurações

Engrenagem no topo, não um quinto item na barra de baixo — configuração não é destino
frequente e a barra já tem quatro. Quatro abas, com o mesmo submenu da Loja:

- **Sincronizar** — atualizar preços e estoque, e buscar vendas novas. Os dois com o
  estado atual do lado (o que já entrou, de quando é) e **barra de progresso de verdade**:
  o fluxo diz "lote 7 de 25" em cada callback, a tabela `sync_estado` guarda o placar e a
  tela pergunta a cada 1,5s. Sem saber o total ainda, a barra fica listrada em vez de
  mostrar percentual chutado; sem notícia há 10 minutos, ela diz que o fluxo se perdeu em
  vez de girar para sempre. Recarregar no meio da importação continua acompanhando, e o
  relógio para sozinho quando ninguém está importando.
- **PDVs** — liga e desliga cada ponto de venda no app.
- **Metas** — os alvos do mês; o progresso continua em Loja → Metas.
- **Taxas** — maquininha por forma de pagamento, condomínio, franquia, os fixos do mês
  (energia, sistema e internet) e o CMV padrão. Os chips no topo escolhem entre o padrão e
  cada ponto de venda; no PDV, campo em branco segue o padrão.

A regra é: **tela de número não tem botão de ajuste**. Antes disso os dois sync viviam na
tela inicial, as metas num formulário embaixo do progresso e as taxas num `<details>` no pé
do relatório — cada coisa num canto, e o dono do app não achava.

## A seção Loja

O item **Loja R$** do menu de baixo tem quatro telas, num submenu no feitio do segmented
control do iOS — o padrão de quem precisa de mais função do que cabe numa aba só:

- **Catálogo** (`/loja`) — tudo que está nos PDVs. Filtro de estoque em pílulas
  (todos / com estoque / sem estoque); "sem estoque" é a lista de reposição.
- **Vendas** (`/vendas`) — o relatório acima, com atalhos de período (mês corrente,
  mês passado, 30 dias, hoje). Tem um segundo segmented control por dentro:
  **Resumo** e **Transações**.
- **Metas** (`/metas`) — meta de faturamento e de lucro do mês, com barra de progresso,
  ritmo diário, projeção de fechamento e quanto falta por dia. Zero desliga a meta e
  deixa só a projeção. As metas moram na mesma tabela dos parâmetros de custo.

Ali também se **liga e desliga cada ponto de venda**. Um PDV pode estar na mesma conta do
TouchPay sem ser do dono do app — foi o caso durante uma transição. Desmarcado, ele sai do
app inteiro (catálogo, bipe, vendas, metas e tela inicial) via `loja_pdvs.ativo`; o sync
continua trazendo os dados, as telas é que ignoram. É por isso que toda leitura de
`loja_itens` passa por `loja_pdvs` — sem o JOIN, o preço de venda de um PDV alheio
apareceria ao bipar.

### Transações, uma a uma

`/vendas/transacoes` lista cada compra do período, da mais recente para a mais antiga, e
abre mostrando os produtos que saíram nela — um `<details>` nativo, sem JavaScript. O
Resumo responde *o que vende mais*; esta tela responde *o que saiu agora há pouco* e *o que
essa pessoa levou junto*.

Os filtros são os mesmos das duas telas (`vendas_filtros_da_url()`), então trocar de aba
não perde o período nem o PDV. A busca aqui procura **dentro** da compra: digitar um
produto traz as compras que o levaram, com tudo o que foi junto. É `EXISTS` e não `JOIN` —
com `JOIN`, a mesma compra apareceria uma vez por item que casasse.

Página de 50, e `vendas_paginacao()` é função pura porque é ali que mora o erro de um:
página zero, página além do fim e lista vazia precisam todas devolver algo que a tela saiba
desenhar.

**Ainda não dá para agrupar por cliente.** O TouchPay tem `customerId`, `cardHolder` e
`cpf` na transação, mas o coletor não traz nenhum dos três e a tabela `vendas` não tem onde
guardá-los. Cada linha é uma compra, não uma pessoa. Para ligar compras do mesmo cliente
seriam três passos: colunas novas em `vendas`, o mapeamento no `n8n/codigo/vd-03`, e uma
reimportação para preencher o que já está gravado.

**Reconferir um período.** O sync automático só volta 3 dias da última venda gravada, então
um buraco no meio do mês — de um lote perdido, por exemplo — é inalcançável por mais que se
clique em "buscar vendas novas". O botão *Reconferir este período no TouchPay*, na aba
Vendas, rebusca exatamente a janela filtrada. Regravar não duplica.

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
php testes/sync.php         # a conta da barra de progresso e o fluxo dado por perdido
php testes/breakdown.php    # o plano diário da meta: linha reta e meta recalculada
php testes/nav.php          # o menu de baixo acende um item por rota
php testes/config.php       # a configuração sobrevive a um $cfg no escopo global
#   (transações e paginação entram em testes/vendas.php)
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
