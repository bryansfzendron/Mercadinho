-- Mercadinho - schema MySQL 8 / MariaDB 10.4+
-- Aplicado pelo setup.php. Idempotente: pode rodar de novo sem quebrar.

CREATE TABLE IF NOT EXISTS usuarios (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome        VARCHAR(120)  NOT NULL,
    email       VARCHAR(190)  NOT NULL,
    senha_hash  VARCHAR(255)  NOT NULL,
    ativo       TINYINT(1)    NOT NULL DEFAULT 1,
    criado_em   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estabelecimentos (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    cnpj       VARCHAR(14)  NULL,
    nome       VARCHAR(190) NOT NULL,
    municipio  VARCHAR(120) NULL,
    uf         CHAR(2)      NULL,
    criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_estab_cnpj (cnpj),
    KEY ix_estab_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Produto "canonico". O EAN e a chave global quando existe; quando o emitente
-- manda SEM GTIN, o produto nasce sem EAN e e identificado pelos aliases.
CREATE TABLE IF NOT EXISTS produtos (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ean            VARCHAR(14)  NULL,
    descricao      VARCHAR(255) NOT NULL,
    descricao_norm VARCHAR(255) NOT NULL,
    unidade        VARCHAR(10)  NULL,
    criado_em      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_produtos_ean (ean),
    KEY ix_produtos_norm (descricao_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Como cada loja chama o produto. E o que permite bipar um EAN e achar
-- compras antigas que vieram SEM GTIN naquela loja.
CREATE TABLE IF NOT EXISTS produto_aliases (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    produto_id         INT UNSIGNED NOT NULL,
    estabelecimento_id INT UNSIGNED NULL,
    cod_interno        VARCHAR(60)  NOT NULL,
    descricao_original VARCHAR(255) NULL,
    criado_em          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alias_loja_cod (estabelecimento_id, cod_interno),
    KEY ix_alias_produto (produto_id),
    CONSTRAINT fk_alias_produto FOREIGN KEY (produto_id)
        REFERENCES produtos (id) ON DELETE CASCADE,
    CONSTRAINT fk_alias_estab FOREIGN KEY (estabelecimento_id)
        REFERENCES estabelecimentos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notas (
    id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    usuario_id           INT UNSIGNED  NOT NULL,
    estabelecimento_id   INT UNSIGNED  NULL,
    chave                CHAR(44)      NULL,
    modelo               VARCHAR(5)    NULL,
    serie                VARCHAR(10)   NULL,
    numero               VARCHAR(20)   NULL,
    emissao              DATETIME      NULL,
    valor_produtos       DECIMAL(14,2) NULL,
    desconto_total       DECIMAL(14,2) NULL,
    valor_total          DECIMAL(14,2) NULL,
    origem               ENUM('qrcode','manual') NOT NULL DEFAULT 'qrcode',
    status               ENUM('pendente','processando','ok','erro') NOT NULL DEFAULT 'pendente',
    erro_msg             VARCHAR(500)  NULL,
    qr_url               TEXT          NULL,
    url_consulta         TEXT          NULL,
    html_gz              MEDIUMBLOB    NULL,
    criado_em            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processado_em        DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notas_usuario_chave (usuario_id, chave),
    KEY ix_notas_usuario_status (usuario_id, status),
    KEY ix_notas_emissao (emissao),
    CONSTRAINT fk_notas_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_notas_estab FOREIGN KEY (estabelecimento_id)
        REFERENCES estabelecimentos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itens (
    id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nota_id            INT UNSIGNED  NOT NULL,
    produto_id         INT UNSIGNED  NULL,
    item_num           SMALLINT UNSIGNED NULL,
    descricao_original VARCHAR(255)  NOT NULL,
    cod_interno        VARCHAR(60)   NULL,
    ean_original       VARCHAR(20)   NULL,
    ncm                VARCHAR(10)   NULL,
    cest               VARCHAR(10)   NULL,
    cfop               VARCHAR(6)    NULL,
    quantidade         DECIMAL(14,4) NOT NULL DEFAULT 0,
    unidade            VARCHAR(10)   NULL,
    -- Valores "de tabela", como vem na nota
    valor_unitario     DECIMAL(14,4) NOT NULL DEFAULT 0,
    valor_total        DECIMAL(14,2) NOT NULL DEFAULT 0,
    desconto           DECIMAL(14,2) NOT NULL DEFAULT 0,
    -- Valores efetivamente pagos, ja descontados. E o que o historico usa:
    -- a pergunta do app e "quanto paguei", nao "quanto estava marcado".
    valor_total_liquido    DECIMAL(14,2) NOT NULL DEFAULT 0,
    valor_unitario_liquido DECIMAL(14,4) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_itens_nota (nota_id),
    KEY ix_itens_produto (produto_id),
    CONSTRAINT fk_itens_nota FOREIGN KEY (nota_id)
        REFERENCES notas (id) ON DELETE CASCADE,
    CONSTRAINT fk_itens_produto FOREIGN KEY (produto_id)
        REFERENCES produtos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Espelho do TouchPay: preco de venda e estoque atual por ponto de venda.
-- Preenchido pelo fluxo n8n "TouchPay -> Mercadinho" via /api/loja/callback.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS loja_pdvs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fonte         VARCHAR(20)  NOT NULL DEFAULT 'touchpay',
    externo_id    INT UNSIGNED NOT NULL,
    nome          VARCHAR(120) NOT NULL,
    tipo          VARCHAR(40)  NULL,
    -- Zero tira o ponto de venda do app inteiro: catalogo, bipe, relatorio e
    -- metas. Existe porque um PDV pode estar na mesma conta do TouchPay sem
    -- ser do dono do app — o sync continua trazendo, a tela e que ignora.
    ativo         TINYINT(1)   NOT NULL DEFAULT 1,
    atualizado_em DATETIME     NULL,
    criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pdv_fonte_externo (fonte, externo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loja_itens (
    id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    pdv_id             INT UNSIGNED  NOT NULL,
    -- Amarra com o catalogo do Mercadinho quando o EAN e conhecido.
    produto_id         INT UNSIGNED  NULL,
    externo_produto_id INT UNSIGNED  NULL,
    ean                VARCHAR(14)   NULL,
    codigo             VARCHAR(60)   NULL,
    descricao          VARCHAR(255)  NOT NULL,
    categoria          VARCHAR(120)  NULL,
    -- NULL quando o produto nao esta no planograma ativo (sem preco de venda).
    preco_venda        DECIMAL(14,2) NULL,
    estoque            DECIMAL(14,3) NOT NULL DEFAULT 0,
    reservado          DECIMAL(14,3) NOT NULL DEFAULT 0,
    custo_medio        DECIMAL(14,4) NULL,
    minimo             DECIMAL(14,3) NULL,
    capacidade         DECIMAL(14,3) NULL,
    unidade            VARCHAR(10)   NULL,
    imagem             VARCHAR(500)  NULL,
    atualizado_em      DATETIME      NULL,
    PRIMARY KEY (id),
    KEY ix_loja_itens_ean (ean),
    KEY ix_loja_itens_produto (produto_id),
    KEY ix_loja_itens_pdv (pdv_id),
    CONSTRAINT fk_loja_itens_pdv FOREIGN KEY (pdv_id)
        REFERENCES loja_pdvs (id) ON DELETE CASCADE,
    CONSTRAINT fk_loja_itens_produto FOREIGN KEY (produto_id)
        REFERENCES produtos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Vendas do TouchPay (GET /api/Transactions), uma linha por transacao e
-- uma por item vendido. Preenchido pelo fluxo n8n "TouchPay Vendas ->
-- Mercadinho" via /api/vendas/callback.
--
-- O par (fonte, externo_id) e unico: reimportar a mesma janela de datas
-- troca a venda no lugar em vez de duplicar.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vendas (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    fonte           VARCHAR(20)     NOT NULL DEFAULT 'touchpay',
    externo_id      BIGINT UNSIGNED NOT NULL,
    uuid            VARCHAR(60)     NULL,
    -- NULL so se o PDV sumir do espelho; o callback cria o que faltar.
    pdv_id          INT UNSIGNED    NULL,
    data_hora       DATETIME        NOT NULL,
    -- "Ok" e a venda que valeu; o resto fica gravado para poder filtrar.
    resultado       VARCHAR(30)     NULL,
    forma_pagamento VARCHAR(30)     NULL,
    bandeira        VARCHAR(30)     NULL,
    valor_total     DECIMAL(14,2)   NOT NULL DEFAULT 0,
    valor_pago      DECIMAL(14,2)   NOT NULL DEFAULT 0,
    codigo          VARCHAR(60)     NULL,
    atualizado_em   DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_venda_fonte_externo (fonte, externo_id),
    KEY ix_vendas_data (data_hora),
    KEY ix_vendas_pdv (pdv_id),
    CONSTRAINT fk_vendas_pdv FOREIGN KEY (pdv_id)
        REFERENCES loja_pdvs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS venda_itens (
    id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    venda_id           INT UNSIGNED  NOT NULL,
    -- Amarra com o catalogo do Mercadinho pelo EAN, igual ao espelho da loja.
    produto_id         INT UNSIGNED  NULL,
    externo_produto_id INT UNSIGNED  NULL,
    ean                VARCHAR(14)   NULL,
    codigo             VARCHAR(60)   NULL,
    descricao          VARCHAR(255)  NOT NULL,
    categoria          VARCHAR(120)  NULL,
    quantidade         DECIMAL(14,3) NOT NULL DEFAULT 1,
    -- O TouchPay manda o TOTAL da linha (item com quantidade 4 vem com o
    -- valor das 4). O unitario e conta nossa, guardada pronta para os
    -- relatorios nao terem que dividir toda vez.
    valor_total        DECIMAL(14,2) NOT NULL DEFAULT 0,
    valor_unitario     DECIMAL(14,4) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_venda_itens_venda (venda_id),
    KEY ix_venda_itens_produto (produto_id),
    KEY ix_venda_itens_ean (ean),
    CONSTRAINT fk_venda_itens_venda FOREIGN KEY (venda_id)
        REFERENCES vendas (id) ON DELETE CASCADE,
    CONSTRAINT fk_venda_itens_produto FOREIGN KEY (produto_id)
        REFERENCES produtos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Parametros de custo do relatorio de vendas. Uma linha por parametro,
-- editavel na propria tela — taxa de maquininha muda com o faturamento e
-- com o fim da promocao, e o CMV padrao e so o palpite de quem ainda nao
-- tem nota daquele produto.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS custos_parametros (
    chave         VARCHAR(40)   NOT NULL,
    valor         DECIMAL(14,4) NOT NULL DEFAULT 0,
    atualizado_em DATETIME      NOT NULL,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
