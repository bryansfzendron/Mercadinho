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

Depois abra `https://mercadinho.bryanzendron.com.br/setup.php?token=SEU-SETUP-TOKEN`.
A página valida PHP, extensões, HTTPS e banco, cria as tabelas e o primeiro usuário.

### Atualizações

```bash
cd ~/domains/mercadinho.bryanzendron.com.br/public_html
git pull
```

`app/config.php` está no `.gitignore`, então o pull nunca sobrescreve suas credenciais.

## O que mudar no workflow do n8n

O fluxo hoje termina gravando no Google Sheets. Para alimentar o app:

**1. Node `Receber QR Code` (webhook)** — mude *Respond* para **Immediately**.
Sem isso o PHP fica bloqueado esperando a raspagem inteira.

O app manda:

```json
{
  "nota_id": 123,
  "qrcode": "https://www.nfce.fazenda.sp.gov.br/qrcode?p=...",
  "callback_url": "https://mercadinho.bryanzendron.com.br/api/callback",
  "token": "<n8n_token>",
  "guardar_html": true
}
```

**2. Carregue `nota_id`, `callback_url` e `token`** até o fim do fluxo
(no `Normalizar Entrada`, guarde-os no item).

**3. Troque `Gravar Itens na Planilha`** por um HTTP Request `POST {{$json.callback_url}}`
com este corpo:

```json
{
  "nota_id": 123,
  "token": "<n8n_token>",
  "status": "ok",
  "nota": {
    "chave": "35260946029724000673651010000280481783880108",
    "emitente": "HIGA PRODUTOS ALIMENTICIOS",
    "cnpj": "46029724000673",
    "municipio": "SAO PAULO",
    "uf": "SP",
    "modelo": "65",
    "serie": "101",
    "numero_nota": "28048",
    "emissao": "05/09/2026 19:32:11",
    "valor_total_produtos": "1.234,56",
    "desconto_total_nota": "0,00",
    "valor_total_nota": "1.234,56",
    "url_consulta": "https://..."
  },
  "itens": [
    {
      "item": 1, "codigo": "7291", "descricao": "ARROZ TIPO 1 5KG",
      "quantidade": "1,000", "unidade": "UN",
      "valor_unitario": "24,90", "valor_total_item": "24,90",
      "desconto_item": "0,00",
      "ean": "7891000315507", "ncm": "10063021", "cest": "", "cfop": "5102"
    }
  ]
}
```

São exatamente as 27 colunas que o fluxo já extrai. Números podem ir em formato
brasileiro (`1.234,56`) ou como float — o PHP aceita os dois. Datas aceitas em
`dd/mm/aaaa hh:mm:ss` ou ISO.

**4. No ramo de erro** (`Itens Encontrados?` = false), poste no mesmo callback:

```json
{ "nota_id": 123, "token": "<n8n_token>", "status": "erro", "erro": "descrição do problema" }
```

**5. Opcional** — se `guardar_html` for true, inclua o HTML bruto da Consulta Completa
no campo `html`. O app comprime com gzip (~150 KB por nota) e guarda, para reprocessar
sem precisar bipar o cupom de novo caso o parser mude.

## Limites conhecidos

- **Só NFC-e de São Paulo.** Cada estado tem portal próprio.
- **Consulta por chave de acesso não funciona** — tem captcha. Só o QR Code, que já vem
  assinado com o CSC do emitente. Ou seja: precisa do cupom em mãos.
- **Depende do HTML da SEFAZ.** Se mudarem o layout, o parser do n8n quebra.
- **Leitura de EAN-13 pela câmera** é menos confiável que QR. Há campo para digitar.
