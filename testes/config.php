<?php
declare(strict_types=1);

/*
 * O config.php nao pode morar numa variavel global.
 *
 * `$cfg` no escopo global de um script e o mesmo `$cfg` que guardava a
 * configuracao inteira. O cron.php tinha um `foreach ($fontes as $fonte =>
 * $cfg)`: a primeira volta apagava tudo, e a conexao seguinte ia ao MySQL com
 * usuario e senha vazios. Com display_errors em 0, em silencio — o cron nunca
 * funcionou e ninguem viu.
 *
 * Este teste pisa no global de proposito e confere que a configuracao continua
 * de pe. Nao toca o banco: bootstrap so define funcoes.
 */

// O bootstrap e quem define a constante APP; aqui basta o caminho.
$dir_app = dirname(__DIR__) . '/app';

// Na maquina de desenvolvimento nao existe config.php (ele mora so no
// servidor). Cria um de mentira e apaga no fim; se ja existir, nao encosta.
$inventado = !is_file($dir_app . '/config.php');
if ($inventado) {
    file_put_contents($dir_app . '/config.php', "<?php\nreturn ['db_user' => 'usuario-de-teste'];\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$ok = 0; $falhou = 0;
function checar(string $nome, $obtido, $esperado): void
{
    global $ok, $falhou;
    if ($obtido === $esperado) { $ok++; return; }
    $falhou++;
    printf("FALHOU  %s\n   esperado: %s\n   obtido:   %s\n",
        $nome, var_export($esperado, true), var_export($obtido, true));
}

$antes = cfg('db_user');
checar('o usuario do banco existe', $antes !== null && $antes !== '', true);

// Exatamente o que o cron fazia.
$cfg = ['minutos' => 5, 'nome' => 'vendas'];
checar('config sobrevive a um $cfg global', cfg('db_user'), $antes);

// E o mesmo com o nome do array antigo, para o caso de alguem restaurar.
$GLOBALS['cfg'] = 'lixo';
checar('config sobrevive ate a $GLOBALS[cfg]', cfg('db_user'), $antes);

// Chave que nao existe continua caindo no padrao.
checar('chave desconhecida usa o padrao', cfg('nao_existe', 'padrao'), 'padrao');

if ($inventado) {
    unlink($dir_app . '/config.php');
}

printf("\n%d passaram, %d falharam\n", $ok, $falhou);
exit($falhou > 0 ? 1 : 0);
