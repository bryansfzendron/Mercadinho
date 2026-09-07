<?php
declare(strict_types=1);

/*
 * O retry de deadlock. Aconteceu em producao: os 25 lotes de uma carga
 * chegaram juntos (o node HTTP do n8n manda 50 por vez por padrao) e os
 * callbacks se atropelaram gravando na mesma tabela — "1213 Deadlock found
 * when trying to get lock". O fluxo agora manda um de cada vez, e isto aqui e
 * a rede de baixo, para o botao da tela e o cron nao se cruzarem.
 *
 * Nada aqui toca o MySQL: a excecao e fabricada.
 */

require dirname(__DIR__) . '/app/db.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

/** Uma PDOException como o MySQL entrega, com o codigo em errorInfo[1]. */
function erro_do_banco(int $codigo, string $msg = 'erro'): PDOException
{
    $e = new PDOException($msg);
    $e->errorInfo = ['40001', $codigo, $msg];
    return $e;
}

// Deu certo de primeira: nao repete.
$vezes = 0;
$r = tentar_de_novo_em_deadlock(function () use (&$vezes) { $vezes++; return 'gravado'; });
checar('sem erro roda uma vez', [$r, $vezes], ['gravado', 1]);

// Deadlock na primeira, sucesso na segunda.
$vezes = 0;
$r = tentar_de_novo_em_deadlock(function () use (&$vezes) {
    $vezes++;
    if ($vezes === 1) { throw erro_do_banco(1213, 'Deadlock found'); }
    return 'gravado na segunda';
});
checar('deadlock tenta de novo', [$r, $vezes], ['gravado na segunda', 2]);

// Lock timeout tambem e transitorio.
$vezes = 0;
tentar_de_novo_em_deadlock(function () use (&$vezes) {
    $vezes++;
    if ($vezes < 3) { throw erro_do_banco(1205, 'Lock wait timeout'); }
    return 'ok';
});
checar('lock timeout tambem repete', $vezes, 3);

// Insistindo alem do limite, o erro sobe — nao pode sumir com a falha.
$vezes = 0;
$subiu = null;
try {
    tentar_de_novo_em_deadlock(function () use (&$vezes) {
        $vezes++;
        throw erro_do_banco(1213, 'Deadlock found');
    });
} catch (PDOException $e) {
    $subiu = (int) $e->errorInfo[1];
}
checar('desiste depois de 3 tentativas', $vezes, 3);
checar('e deixa o erro subir', $subiu, 1213);

// Erro que NAO e travamento sobe na hora: repetir um INSERT duplicado tres
// vezes so multiplica o estrago.
$vezes = 0;
$subiu = null;
try {
    tentar_de_novo_em_deadlock(function () use (&$vezes) {
        $vezes++;
        throw erro_do_banco(1062, 'Duplicate entry');
    });
} catch (PDOException $e) {
    $subiu = (int) $e->errorInfo[1];
}
checar('erro comum nao repete', $vezes, 1);
checar('e sobe igual', $subiu, 1062);

// Excecao sem errorInfo (driver diferente) nao pode virar laco infinito.
$vezes = 0;
try {
    tentar_de_novo_em_deadlock(function () use (&$vezes) {
        $vezes++;
        throw new PDOException('sem errorInfo');
    });
} catch (PDOException $e) { /* esperado */ }
checar('excecao sem codigo nao repete', $vezes, 1);

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
