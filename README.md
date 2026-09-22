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
  graficos.php     gráficos em HTML/CSS: série diária, semana e barra de pagamento
  routes.php       rotas e controllers
  views/
assets/            css, leitor de câmera, movimento, cartão da capa, lista do mercado, ícones
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

## A caixa que vira unidade

Você compra a caixa e vende a lata. A nota diz **"1 CX C/12 REFRI — R$ 24,00"**, com o
GTIN da caixa; a prateleira precisa de **"12 UN a R$ 2,00"**, com o GTIN da lata. Sem
corrigir isso, o histórico de preço, a margem e o custo do estoque falam de uma unidade
que você não vende.

Na tela da nota cada item abre um **Corrigir item**:

- **Código de barras** — troca o produto vinculado ao item. Digitado ou bipado pela
  câmera (o mesmo `Scanner` de `/bipar`).
- **Quantidade, unidade, total pago e desconto** — o campo **Un. por caixa** + o botão
  **Abrir caixa** multiplicam só a quantidade.

A âncora é o **total pago**, não o unitário: mexer na quantidade nunca muda o que saiu do
caixa, o unitário é recalculado a partir dela. É o que `item_valores()` garante, e é por
isso que "abrir a caixa" não desequilibra a nota.

Sobre o produto:

- EAN que **já existe** → o item passa a apontar para aquele produto.
- Produto atual **sem GTIN** → ele adota o código, e todo o histórico já gravado nele
  passa a responder ao bipe.
- Produto atual **com outro GTIN** → nasce um cadastro novo. Caixa e lata são produtos
  diferentes, com preços diferentes.

O alias `loja + código interno` é reapontado junto: a próxima nota daquela loja com o
mesmo `cProd` já cai no produto certo, sem repetir a correção.

O cabeçalho da nota anda pela **diferença** do item mexido, e não pela soma dos itens —
uma nota pode ter frete ou desconto próprio, que não está em item nenhum.

### Achar e remover itens

Uma nota de mercado passa fácil de 100 linhas. A partir de **8 itens** aparece um campo de
filtro acima da lista, que casa por nome, código de barras (o do produto e o que a nota
mandou) e código interno da loja. Filtra **no cliente**, a cada tecla: tudo já está na
página, então não recarrega, não perde o editor aberto e funciona offline no PWA. Termos
somam — `coca lata` acha a lata de Coca.

O filtro sobrevive a um "Salvar item" (fica em `sessionStorage` e volta só quando a página
carrega com `#item-N`, ou seja, logo depois de salvar). Visita nova começa limpa, senão a
nota abriria escondendo linhas sem explicação.

Cada item também tem **Remover item da nota**, para o que a nota traz e a prateleira não vê:
a sacola cobrada à parte, a linha duplicada pelo caixa, o item devolvido. O cabeçalho desce
pelo valor do item removido, pela mesma razão da correção.

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

## Repor a gôndola (escrever no TouchPay)

Tudo acima é leitura. `/planograma` — a aba **Repor**, dentro de Loja — é a **única
tela do app que escreve na loja de verdade**: o preço digitado ali é o preço que o
cliente paga no caixa. Bipa o produto na gôndola e mexe em preço, quantidade
necessária, crítico e estoque sem abrir o painel deles no celular.

A cascata tem três degraus, e cada um só existe porque o de cima não respondeu:

1. **está no planograma ativo** → altera os quatro campos;
2. **não está no planograma mas está no cadastro** → entra no planograma na hora
   (aí o preço é obrigatório: preço é coisa do planograma, o cadastro não tem);
3. **não está em lugar nenhum** → a tela diz isso e para. Cadastrar produto novo
   continua sendo na mão, no painel: aqui falta foto, categoria, unidade e
   tributação — nada que se preencha de pé no corredor.

Os campos, do jeito que o TouchPay os chama: **necessária** é `quantityToSupply`,
**crítico** é `minimumQuantity`, **preço** é `price`.

### O preço pode nascer de uma conta

Acima dos quatro campos há dois que **não existem do lado de lá**: **custo** e
**taxa**. Custo × taxa preenche o preço, e é só isso que eles fazem — o corpo do
POST continua sendo `preço, estoque, necessária, crítico`, os mesmos de sempre.
Eles não têm `data-campo`, que é o atributo que `lidos()` procura, então não há
como vazarem para o TouchPay nem para o resumo de/para.

Existem porque é no corredor, com a nota do atacado na mão, que se sabe por quanto
o produto entrou. Fazer `9,50 × 1,9` na calculadora e transcrever o resultado é um
passo a mais para errar, e errar aqui é o preço que o cliente paga.

A linha embaixo repete a conta por extenso — `R$ 9,50 × 1,9 = R$ 18,05` — e não só
o resultado: é nela que se vê o custo que entrou como `R$ 950,00` porque a vírgula
não pegou. O preço calculado continua sendo um campo comum, dá para corrigir por
cima na mão, e o resumo do "Salvar" confere o número final de um jeito só — venha
ele da conta ou do dedo.

**Faltando um dos dois, o preço não se mexe.** Apagar o custo não pode apagar o
preço, e custo ou taxa em zero devolve "sem preço" em vez de `R$ 0,00` — mesma
regra do resto da tela: zero aqui seria o produto saindo de graça no caixa. O
arredondamento é no centavo, porque centavo é o que a etiqueta tem.

**A taxa fica lembrada** (`localStorage`), o custo não. Quem repõe trabalha com uma
margem só o dia inteiro e um custo diferente a cada produto; sem isso, seriam trinta
vezes digitando `1,9`.

### Isto não passa pelo n8n

O espelho passa porque é raspagem pesada: dispara, esquece, o callback chega quando
chegar. Repor é o contrário — bipa, vê, corrige, salva, com o carrinho parado no
corredor. Callback assíncrono aqui seria pedir para a pessoa recarregar a página para
descobrir se o preço pegou. Então `app/touchpay.php` fala cURL direto com o painel,
igual ao `off_buscar()` do Mercado. O JWT vale ~1h, mora em `touchpay_sessao` (uma
linha) e não na sessão do PHP: um login por hora para o app inteiro, em vez de um por
aparelho e por aba. 401 renova e repete **uma** vez — mais que isso, um login que
passou a ser recusado viraria laço de tentativas contra o servidor deles.

### O que se aprendeu da API deles

- `PUT /api/PlanogramEntries` altera uma linha e quer o **objeto inteiro de volta**;
  mandar só os campos alterados zera o resto. Por isso o servidor **relê a linha**
  imediatamente antes de gravar, em vez de confiar no que veio do celular.
- `POST /api/PlanogramEntries` inclui, e **não devolve o `inventoryItemId`** — ele
  nasce com a linha. Sem reler depois do POST, não há como mexer no estoque do que
  acabou de entrar.
- `PUT /api/inventory/{posId}/{inventoryItemId}/quantity/{n}` **define** o estoque,
  não soma: `.../quantity/3` faz passar a ser 3, seja qual for o número que estava
  lá. A quantidade vai na URL e o corpo é vazio (daí o `Content-Length: 0`). A tela
  diz isso com todas as letras, e o campo já vem preenchido com o estoque atual.
- A busca deles é por **pedaço de texto**: `search=789` volta meia gôndola, e mesmo
  o EAN inteiro pode trazer o produto irmão. Pegar o primeiro da lista é como se
  troca o preço do produto errado — `pg_casar()` exige `productId` igual, ou código
  igual, ou nada.

### O bipe é resolvido no espelho primeiro

O planograma só tem `productCode`, e parte dos códigos vem com `OM` grudado. O EAN de
verdade só existe do lado do inventário — que é justamente o que `loja_itens` já
guarda. Então o código bipado vira `externo_produto_id` no banco daqui **antes** de
ir ao TouchPay, e a busca lá vai com o `productId` na mão. É o único ponto em que
esta tela acerta mais que a tela deles.

O planograma ativo de cada PDV é perguntado **uma vez, quando a tela abre**
(`planograma_sincronizar_pdvs()`), e guardado em `loja_pdvs.planograma_id`. Repor são
trinta bipes seguidos; perguntar a cada um seriam trinta consultas para descobrir a
mesma coisa trinta vezes. Planograma não troca no meio de uma reposição.

### Nada vai embora no primeiro toque

"Salvar" abre um resumo **de → para** e só o segundo toque manda. É a única chance
de ver que o preço foi de `R$ 9,50` para `R$ 950,00` porque a vírgula não entrou —
quem está de pé no corredor com o celular numa mão erra o alvo, e errar o alvo aqui
custa o preço de um produto.

**Campo vazio não é zero**, é "não encostei neste campo". Sem essa regra, apagar o
preço sem querer deixaria o produto saindo de graça. Zero digitado, esse sim, é zero
de verdade — estoque zerado existe.

E se alguém mexeu no mesmo campo enquanto a tela estava aberta, a gravação **para**
(HTTP 409) em vez de desfazer o trabalho do outro calada. A comparação é só nos
campos que **esta** pessoa está mudando: preço alheio não atrapalha quem só repõe
estoque.

### O diário

O painel deles não diz quem mexeu nem o que havia antes. `planograma_log` diz: uma
linha por campo, com de/para, usuário, PDV e hora. Uma linha **por campo** e não por
salvamento porque planograma e estoque são duas chamadas sem transação entre elas —
quando uma metade cai, dá para ver exatamente qual foi. As últimas dez aparecem no pé
da tela.

**Tabelas novas:** rode `/setup.php?token=...` e aplique o schema e as migrações
(`loja_pdvs` ganhou `planograma_id` e `inventario_id`).

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

## Movimento e resposta ao toque

O visual já era de vidro; o que faltava era ele **se mexer** como coisa física.
`assets/movimento.js` é a camada de interação — ~300 linhas, sem nenhuma biblioteca (uma
dependência de animação custaria mais bytes do que o CSS inteiro do app).

O motor é uma **mola**, não uma transição. Mola não tem duração: tem *resposta* (em quanto
tempo alcança o alvo) e *amortecimento* (1 = chega sem passar do ponto, abaixo de 1 = passa
e volta). São os dois números com que a Apple substituiu massa/rigidez/atrito, e é por isso
que os valores de referência (0,4s para mover, 0,3s para gaveta) fazem sentido escritos
assim.

O ganho não é a curva — é a **interrupção**. Uma transição de CSS agarrada no meio salta,
porque ela anima do valor lógico. A mola guarda posição e velocidade, então trocar o alvo no
meio do movimento é só trocar o alvo: ela continua de onde está, com a velocidade que já
tinha. É o que faz um gesto revertido não bater numa parede.

O que isso virou na tela:

- **A resposta acontece no apertar, não no soltar.** Linha de lista, chip, aba e item da
  barra acendem no `pointerdown`; arrastar para fora cancela e voltar reacende. Esperar o
  clique para dar sinal é o que faz uma tela parecer travada — e, no iPhone, `:active` só
  pinta depois que o Safari decide que o gesto não era rolagem. Quanto encolher depende do
  tamanho: a linha de lista quase não se mexe, o chip se mexe mais.
- **As sanfonas (`<details>`) crescem em vez de pular.** A altura vira uma mola. Aqui mora
  uma armadilha: o `<details>` nativo *inverte* o `open` **depois** que os handlers rodam,
  então mexer no `open` sem `preventDefault()` abre e fecha na mesma batida. Quem manda no
  `open` passa a ser o `movimento.js`, do início ao fim.
- **O aviso sai no empurrão.** Durante o arrasto acompanha o dedo 1:1 (respeitando onde o
  dedo pegou); no soltar, a *inércia* decide. O destino vem de onde o arremesso pararia
  sozinho (`projetar()`, a mesma conta da rolagem do iOS), não do pixel onde o dedo saiu —
  por isso um peteleco curto e rápido já manda embora. A velocidade do dedo entra na mola,
  senão dá para ver a emenda entre arrastar e voar.
- **O puxar-para-atualizar resiste em vez de travar.** Até o gatilho continua metade do
  dedo (mexer nisso mudaria o gesto que já se conhece); depois dele entra borracha, e o
  valor se *aproxima* dos 130px sem nunca encostar. O teto seco antigo lia como app
  congelado; a borracha diz a verdade — "estou respondendo, mas não tem mais nada por aqui".
- **A borda do topo é da rolagem, não fixa.** Um risco permanente embaixo do cabeçalho
  aparece mesmo sem nada passando por baixo dele, e aí vira enfeite. `IntersectionObserver`
  com uma sentinela, não um listener de scroll: nenhum trabalho por quadro rolado.

No CSS, duas coisas andam junto com isso:

- **Troca de tela com View Transitions** (`@view-transition { navigation: auto }`). O app é
  multipágina, e sem isso cada aba pisca em branco e joga fora a noção de que o topo e a
  barra são os *mesmos* em toda tela. Com `view-transition-name` no topo, na barra e no
  conteúdo, só o conteúdo troca — e a pílula do item ativo **desliza** até o destino, em vez
  de apagar aqui e acender lá. Por isso a pílula é um `::before` e não o fundo do próprio
  link: quem desliza é só o vidro, e cada rótulo fica parado no lugar dele. O nome sai do
  helper `abas()`, numerado por grupo, porque `/vendas` tem dois grupos de abas na mesma
  tela e nome repetido quebra a transição inteira.
- **Três preferências de acessibilidade, não uma.** `prefers-reduced-motion` troca
  deslocamento por opacidade (opacidade não provoca enjoo e é o que responde ao toque);
  `prefers-reduced-transparency` tira o desfoque e deixa a hierarquia por borda;
  `prefers-contrast: more` fecha as bordas e escurece o texto de apoio — mas **sem** igualá-lo
  ao texto principal, senão o rótulo "itens" passa a gritar tanto quanto o número. Mais
  contraste é para enxergar melhor, não para achatar a leitura.

O espaçamento entre letras também deixou de ser um valor só: texto grande aperta (`-.021em`
no `h1`), texto miúdo abre. Um `letter-spacing` fixo está errado em algum tamanho.

## A câmera pedindo permissão toda hora

Era uma queixa real, e tinha quatro causas — uma delas um bug que anulava a defesa
principal do `assets/scanner.js`.

**O cache de stream não funcionava justo no iPhone.** O módulo abre a câmera uma vez e
guarda o stream, para `getUserMedia` não ser chamado de novo (no iOS, cada chamada nova
pode virar um pedido de permissão novo). Só que o leitor de reserva, o ZXing, **para as
tracks do stream que recebe** quando faz `reset()` — então entregar o stream do módulo a
ele matava o cache no primeiro `parar()`. E o iPhone sempre cai no ZXing, porque
`BarcodeDetector` só existe no Android/Chrome. Medido antes do conserto: a track ia de
`live` para `ended` e o `iniciar()` seguinte chamava `getUserMedia` de novo. A correção é
entregar `stream.clone()`: tracks independentes da *mesma* câmera, o ZXing encerra as dele
e o original segue vivo — a câmera só fecha quando a última morre.

**A tela abria a câmera sozinha, sem ninguém tocar em nada.** `abrirSozinha()` tratava
`'desconhecido'` como `'granted'`, e `'desconhecido'` é o que o Safari sempre devolve (ele
não implementa `permissions.query({name:'camera'})`). Resultado: abrir `/bipar` só para
digitar um código na mão já fazia o aparelho perguntar. Agora o automático depende de
`sessionStorage` — que some quando o app é fechado e sobrevive a navegar entre as telas,
que é exatamente a janela em que o iOS lembra a permissão já dada. Na primeira câmera da
sessão quem manda abrir é o dedo; da segunda em diante abre sozinha sem perguntar nada.

**Bipar uma nota soltava a câmera a cada item.** `nota_detalhe.php` e `manual.php` chamavam
`parar()` (que libera) depois de cada leitura — 100 itens, 100 `getUserMedia`. Passaram a
usar `parar({ liberar: false })` entre os itens. O preço é o LED ficar aceso enquanto se
digita, então quem guarda marca a hora: 45s sem ninguém pedir a câmera de volta e ela é
solta de verdade, além de `pagehide` e troca de app, que soltam na hora.

**E a instrução que o app dava era impossível de seguir.** `comoLiberar()` mandava tocar no
`aA` da barra de endereço — mas o app roda aberto pela tela de início, onde não há barra de
endereço, nem `aA`, nem "Configurações do Site". Agora ele detecta o modo instalado e conta
o que de fato vale ali: o iPhone **não guarda** essa permissão entre aberturas do app, então
deixá-lo em segundo plano (em vez de fechar no seletor de apps) é o que evita a pergunta.

Essa última parte é da Apple, não do app: em web app instalado a permissão de câmera não
persiste entre aberturas. O piso é uma pergunta por abertura — eram as três causas acima que
transformavam isso em "toda hora".

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

### Puxar para atualizar também busca dados novos

No app instalado, o puxão não recarrega só a página: ele **pede as duas coletas** e segura o
indicador girando até elas acabarem. Recarregar sem isso mostraria de novo exatamente os
mesmos números — que é o contrário do que o gesto promete.

Quem decide é o servidor, em `POST /api/sincronizar`, com a **mesma** `sync_disparar_pendentes()`
do cron: puxar a tela dez vezes seguidas não vira dez idas ao TouchPay, porque as duas travas
continuam valendo. Quando nada está na hora de rodar, a resposta diz `disparou: false` e o
gesto só recarrega, como antes.

Se alguma coleta foi mesmo disparada, o gesto acompanha `/api/sync/estado` de 1,5 em 1,5
segundos e recarrega quando as duas param — ou aos **22 segundos**, o que vier primeiro. Uma
carga grande passa disso com folga, e prender o app até ela acabar leria como travado; o
resto entra na próxima. Rede caída, sessão vencida ou erro no meio caem todos no mesmo
lugar: recarrega assim mesmo, que é o que o gesto sempre fez.

O token do CSRF sai num `<meta name="csrf">` do layout, só para quem está logado — é a
mesma exigência de qualquer rota que grava.

No navegador comum isto não existe: lá o puxão é do próprio Safari/Chrome e só recarrega.
O gesto do app só é montado em modo standalone, onde o navegador não oferece nenhum.

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

## Mercado: conferir a conta antes do caixa

`/mercado` é uma lista de rascunho para usar **dentro do supermercado**: bipa o produto na
gôndola, digita o preço da etiqueta, e no fim informa o total que o caixa cobrou. O app diz
se bate, e de quanto é a diferença.

Ela **não toca em nada**: não grava nota, não mexe no catálogo, não entra no espelho da loja
nem em relatório nenhum. Vive no `localStorage` do aparelho e some quando você manda limpar.

**Por que no aparelho, e não no banco.** Dentro do mercado o sinal cai. Uma lista que depende
do servidor para aceitar mais um item é uma lista que falha bipando o oitavo produto no
corredor do fundo. Assim ela funciona offline inteira; o que precisa de rede — o nome do
produto, buscado em `/api/produto` — é enfeite, tem 2,5s de paciência e some sozinho quando
não há. O preço continua sendo digitado do mesmo jeito.

**Dinheiro em centavos inteiros**, e a conversão é feita nos dígitos, não em float:
`parseFloat('1,005') * 100` dá 100,49999… e arredonda para baixo, virando R$ 1,00 onde a
etiqueta diz R$ 1,01. Numa tela que existe para dizer se a conta bate, um centavo perdido na
leitura é o bastante para mentir. `testes/mercado.js` cobre isso, a soma e o veredito.

**Um centavo de tolerância** na comparação: item pesado (0,756 kg a R$ 24,90) é arredondado
pela balança e a lista arredonda por conta própria; exigir igualdade exata acusaria diferença
onde não há. Dois centavos já é diferença de verdade e aparece.

O veredito tem três caras, e as duas pontas não dizem a mesma coisa:

- **caixa cobrou mais** — vermelho, e manda conferir o cupom item a item antes de sair;
- **caixa cobrou menos** — só informa: costuma ser promoção que a etiqueta não mostrava, ou
  item que ficou de fora da lista.

O leitor repete o mesmo código enquanto o produto está na mira, então há uma trava de 3
segundos por código: sem ela, um produto parado na frente da câmera entraria na lista várias
vezes enquanto o preço é digitado.

### De onde vem o nome do produto

Na gôndola quase nada é conhecido: a base do app só sabe o que você já comprou. Então o nome
vem de uma cascata (`mercado_nome()`), e cada degrau só existe porque o de cima não respondeu:

1. **o nome que você digitou** para aquele código — ganha de todos, inclusive das bases de
   fora: foi escolhido para esta finalidade;
2. **as suas notas** (`produtos.ean`), que é a sua verdade — e junto vem quanto você pagou da
   última vez;
3. **o espelho da loja** (`loja_itens.ean`);
4. **o cache** do que a Open Food Facts já respondeu antes;
5. **a [Open Food Facts](https://world.openfoodfacts.org) ao vivo** — grátis, sem chave, sem
   cadastro.

A Open Food Facts acerta bem em alimento e bebida embalados no Brasil (testado: Leite Moça,
Coca-Cola 2L, açúcar União, café 3 Corações — todos com marca e gramatura) e é praticamente
vazia fora disso: limpeza, higiene e padaria não estão lá. Quem cobre esse buraco é o degrau
1: **o nome que você escreve fica guardado naquele código para sempre**, e a base vai ficando
boa justamente nos produtos que *você* compra. Eles pedem um User-Agent que identifique o app
(vai o domínio), limitam a 15 req/min por IP e publicam os dados sob ODbL.

A quantidade entra no nome quando ainda não está nele: numa lista de compras, "Coca-Cola" e
"Coca-Cola 2 L" são itens de preço diferente. A comparação ignora espaço e caixa, senão
"Refrigerante Coca-Cola 2Lt" viraria "... 2Lt 2l".

O cache mora em `ean_nomes`, **tabela à parte de propósito**: a tela Mercado não pode criar
produto no catálogo nem linha no espelho da loja. Ela guarda nome, e só. `usuario` nunca é
sobrescrito por `off` — quem digitou, mandou.

**Duas memórias.** A do aparelho (`localStorage`) responde primeiro e sem rede — no corredor
do fundo é a única que responde. A do servidor vem depois e por cima, porque é ela que sabe
das suas notas e do que a Open Food Facts respondeu. Nome digitado vai para as duas.

A consulta externa tem 4 segundos de paciência e, falhando, devolve nome nulo: o preço
continua sendo digitado do mesmo jeito. Se nenhum nome nunca aparecer em produção, o
suspeito é o bundle de CA do PHP da hospedagem — sem ele o cURL recusa o HTTPS e a cascata
para no degrau 4, calada.

**O lançamento manual usa a mesma cascata.** Era a segunda tela com um campo de código de
barras ao lado de um campo de nome, e a única onde o app sabia o nome e não dizia — quem
lança o cupom da feira em casa digitava "Leite Moça 395g" com o código já preenchido ao lado.
Bipar ou digitar o código numa linha de item agora traz a descrição junto, e com ela quanto
você pagou da última vez. A procura é por linha, e não uma só para a tela: numa nota de
vinte itens, o nome que chega atrasado para a linha 3 não pode cair na linha 5, que é onde o
dedo está agora. O que a pessoa já escreveu nunca é atropelado — ela está com o cupom na
mão e sabe mais que a Open Food Facts.

O código dessa procura mora em `/assets/ean-nome.js`, carregado pelas duas telas: as duas
memórias, a chave do código e os 4 segundos de paciência existem uma vez só. Se a chave
daqui divergisse da do servidor (`mercado_chave()`), o mesmo produto ficaria em duas gavetas.
Diferença entre as telas: o Mercado **grava** o nome que você digita (é o degrau 1); o
lançamento manual não grava nada, porque a própria nota faz isso melhor — salva, o produto
entra no catálogo com aquele EAN e vira o degrau 2 para todo mundo.

## A capa: quanto vendeu hoje

A tela de início e a de notas eram quase a mesma coisa — os mesmos botões em cima, a mesma
lista embaixo, só que uma mostrava 8 notas e a outra 200. A lista passou a morar num lugar
só (`/notas`, que herdou também os números e os resumos que o início carregava) e o início
virou o que se quer saber abrindo o app: **vendi quanto?**

É um cartão só, com três caras num segmented control:

- **Atual** — o total de hoje em número grande e, embaixo do risco, o mês corrente:
  faturamento, ticket médio e transações. Mês *até hoje*, não o mês inteiro: comparar o que
  já aconteceu com o que ainda nem começou não diz nada. *Ver detalhes* abre `/transacoes`.
- **Semana** — as vendas da semana corrente (domingo a sábado, como o calendário brasileiro
  desenha) e um gráfico de sete barras. **Tocar num dia troca o número para aquele dia**;
  tocar de novo volta para a semana inteira. Sem dia escolhido, o número é o total.
- **Mês** — o mês até hoje, dia a dia, no mesmo gráfico de barras do dashboard, só que
  vestido de painel (tinta branca no lugar da cor de marca, que sobre o azul sumiria).

**Só a semana é tocável.** Sete colunas gordas são alvo de dedo; trinta e uma barras finas
não são — o mês é panorama, e quem quer cavar um dia dele tem o filtro de período em
Loja > Vendas. No dia 1º o gráfico do mês nem aparece: uma barra sozinha não é um gráfico,
então entra a contagem de transações no lugar.

A semana aparece inteira mesmo antes de acontecer: um domingo que só mostrasse o domingo
faria o gráfico *crescer* ao longo da semana, e o sábado grande da semana passada pareceria
o de agora. Dia que ainda não chegou entra com zero e fica apagado.

Nada é calculado no navegador. O `inicio.js` só troca de aba e troca de dia — cada valor
já vem formatado do PHP em `data-moeda`, porque formatar dinheiro em dois lugares é
garantir que um dia os dois vão discordar.

`vendas_painel()` faz **uma** consulta por dia para os três números: hoje é uma linha dela,
a semana é uma fatia e o mês é outra. A janela começa no mais antigo entre o dia 1º e o
domingo da semana — numa virada de mês o domingo cai no mês passado, e cortar no dia 1º
comeria o começo da semana. O corte é o mesmo do relatório (`vendas_filtro_sql()`), senão a
capa do app somaria transação negada e PDV desligado e brigaria com todas as outras telas.

Embaixo do cartão vem **Vendidos hoje**: uma linha por produto que saiu no dia, do que mais
faturou para o que menos faturou, com a quantidade e o preço unitário (o item grava o total
da linha, então o unitário é conta nossa). O agrupamento é o mesmo do relatório — EAN,
senão código interno, senão a descrição —, então o mesmo refrigerante vendido em três
compras é **uma** linha. Produto já casado com o catálogo leva para a ficha dele.

**A lista segue o gráfico.** Tocar numa barra troca os produtos junto com o número: aba
*Atual* mostra hoje, *Semana* sem dia escolhido mostra a semana inteira (e com um dia
escolhido, aquele dia), *Mês* mostra o mês. Voltar para *Atual* volta para hoje — deixar o
sábado na tela faria o número de cima e a lista de baixo contarem coisas diferentes.

Os nove blocos (sete dias + a semana + o mês) **já vêm prontos do servidor**, escondidos, e
o JS só troca qual está visível. É uma consulta só em vez de uma por toque, o preço continua
sendo formatado num lugar só, e trocar de período não espera rede — num mercadinho isso cabe
folgado numa página. (Se um dia couber mal, o caminho é servir os blocos por uma rota
própria e trocar por `fetch`; o HTML continuaria vindo montado do PHP.)

O período **não** é a soma das listas de dia já cortadas: `vendas_produtos_juntar()` reagrupa
o produto dia a dia antes de ordenar, senão um item que vende pouco todo dia ficaria atrás de
um que vendeu uma vez só num dia forte. Ela recebe a janela (`de`, `ate`) porque as mesmas
linhas cruas servem a semana e o mês, e cada uma só pode somar os dias que lhe pertencem. E
se o produto nasceu sem vínculo num dia e ganhou EAN no outro, a linha do período leva o id
que existe — senão o link para a ficha sumiria.

`vendas_produtos_por_dia()` recebe os dias de `vendas_painel()` em vez de chamar `date()` de
novo: entre uma consulta e a outra a meia-noite pode virar, e a lista mostraria um dia que
não bate com o número logo acima dela.

Escanear e bipar **não** ficam aqui: os dois botões já moram na tela de notas, e repetidos
na capa só empurravam a lista do dia para baixo da dobra.

### Dois PDVs que eram o mesmo

Quando a máquina troca de dono, o TouchPay **cadastra o ponto de venda de novo**: id externo
novo, às vezes nome novo ("ITALIA" virou "RESIDENCIAL ITALIA"). Como `loja_pdv_resolver()`
casa por `(fonte, externo_id)`, nascem dois PDVs e o histórico fica partido em dois — as
vendas antigas num, as novas noutro, e nenhuma tela soma os dois.

**Configurações → PDVs → Unificar** junta. O que muda de dono:

- **vendas** — é o histórico, e o motivo de tudo isto;
- **custos por PDV**, mas só as chaves que o sobrevivente ainda não tem: o valor de quem
  fica vale mais do que o de quem sai.

O **espelho** (`loja_itens`) é foto do momento, não histórico — o sync apaga e regrava o PDV
inteiro a cada carga. Então ele só se muda se o sobrevivente estiver vazio (unificação antes
do primeiro sync); tendo espelho próprio, o do antigo vai embora, senão o catálogo mostraria
cada produto duas vezes até o próximo sync passar.

**O PDV antigo não é apagado: vira lápide** (`loja_pdvs.unificado_para`). É o que impede o
próximo sync de recriá-lo pelo `externo_id` e partir tudo outra vez — se o TouchPay ainda
mandar dados naquele id, `loja_pdv_resolver()` segue a seta e entrega ao PDV que ficou. O
nome **não** vem junto nesse caminho: seria o nome antigo desfazendo a unificação a cada
sync. Lápide sai de todas as listagens (`unificado_para IS NULL`) e fica com `ativo = 0`.

Por isso a tela manda **ficar com o que ainda sincroniza**, e mostra vendas, itens e último
sync de cada um para a escolha não ser no chute. Ficar com o morto congelaria preço e
estoque no dia da transferência.

Uma consequência a lembrar: as vendas antigas **passam a contar nos relatórios**. Se parte
delas for de antes de você assumir a loja, faturamento e lucro dos períodos antigos vão
passar a mostrá-las. O imposto não muda — `custos_rbt12()` já ignora tudo que é anterior a
`custos_inicio_atividade()`.

A coluna `unificado_para` é migração: quem já tem o banco criado roda `/setup.php?token=…` e
clica em criar as colunas que faltam.

### Transações de hoje

`/transacoes` é a irmã pobre de `/vendas/transacoes`, de propósito: sem filtro nenhum e só
**o dia de hoje**, cada compra abrindo em sanfona com data, forma de pagamento, código no
TouchPay e os produtos que saíram. É a resposta ao *Ver detalhes* da capa, e o cartão de
onde se clicou fala do dia — abrir trinta dias aqui responderia outra pergunta e faria o
total da lista brigar com o número logo acima do link.

Na linha da compra vai só a **hora**: a data está no topo da tela e é a mesma em todas as
linhas; a data cheia continua dentro do detalhe.

Quando a pergunta cresce — "quanto o PDV X vendeu no Pix em agosto" — o botão flutuante
leva para `/vendas/transacoes`, que continua sendo a tela de análise, com filtros, busca
dentro da compra e paginação. Ele vai **sem** levar o dia de hoje junto: quem clica ali é
justamente porque quer ver além de hoje, e o padrão de lá já são 30 dias. No menu de baixo
as duas acendem lugares diferentes: `/transacoes` é tela de dentro do **Início**,
`/vendas/transacoes` é tela de dentro da **Loja**.

## O dashboard

`/dashboard` usa o mesmo cartão azul da capa, e por isso o controle segmentado precisou
servir a dois casos: na capa são duas abas que trocam no JS, aqui são **quatro períodos e
cada um é uma página**. A pílula então sai de duas variáveis CSS (`--itens` e `--indice`) em
vez de um valor fixo, e ganha `view-transition-name` — o navegador a vê nas duas telas e a
faz *deslizar* até o período novo, igualzinho à da capa, só que atravessando uma navegação.

Os rótulos dos períodos têm versão curta (`vendas_periodos()` devolve um quarto item):
"Mês corrente" em quatro colunas de celular quebra em duas linhas e desalinha a pílula.

O que está dentro do cartão é o que se lê primeiro — faturamento, lucro líquido, margem e
vendas, cada um com a variação contra o período anterior. **Mercadoria** e **Outros custos**
ficam nos dois KPIs brancos logo abaixo: são o que sai do meio do faturamento e do lucro, e
em custo subir é ruim, então a moldura inverte (`kpi_moldura($v, true)`). Sobre o azul o
sinal continua verde e vermelho — em tons claros o bastante para o fundo, não em branco:
apagar a cor de um prejuízo economizaria contraste e custaria a leitura.

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
php testes/callback.php     # formatos do callback, cálculo do líquido e abrir a caixa
php testes/loja.php         # normalização do callback do TouchPay e o prefixo OM
php testes/vendas.php       # callback das vendas, fuso da data e o unitário calculado
php testes/custos.php       # taxa por forma de pagamento, resultado do período e CMV
php testes/sync.php         # a conta da barra de progresso e o fluxo dado por perdido
php testes/breakdown.php    # o plano diário da meta: linha reta e meta recalculada
php testes/nav.php          # o menu de baixo acende um item por rota
php testes/config.php       # a configuração sobrevive a um $cfg no escopo global
php testes/graficos.php     # série diária, altura/pico e as sete barras da semana
php testes/mercado.php      # a chave do código de barras e o nome vindo da Open Food Facts
php testes/planograma.php   # repor: qual item foi o bipado, o de/para e a leitura do JWT
#   (transações e paginação entram em testes/vendas.php)
node testes/mercado.js      # a conta do Mercado: centavos, total e o veredito do caixa
node testes/ean-nome.js     # o nome do código de barras: chave, memória do aparelho e servidor
node testes/planograma.js   # custo x taxa -> preco, e o resumo de/para antes de escrever
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
- **Corrigir item não se repete sozinho quando a nota traz o GTIN da caixa.** O alias
  reapontado só entra em cena quando o emitente manda `SEM GTIN`; vindo o código da
  caixa, `produto_resolver()` casa por EAN antes de olhar o alias e a correção precisa
  ser refeita. Reprocessar a mesma nota (o callback reescreve os itens) também desfaz a
  correção daquela nota.
