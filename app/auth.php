<?php
declare(strict_types=1);

function usuario_atual(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    iniciar_sessao();
    $id = $_SESSION['usuario_id'] ?? null;
    if (!$id) {
        return $cache = null;
    }
    $u = q1('SELECT id, nome, email, ativo FROM usuarios WHERE id = ? AND ativo = 1', [$id]);
    if (!$u) {
        unset($_SESSION['usuario_id']);
        return $cache = null;
    }
    return $cache = $u;
}

function exigir_login(): array
{
    $u = usuario_atual();
    if (!$u) {
        if (str_starts_with(rota_atual(), '/api/')) {
            json_resposta(['erro' => 'nao autenticado'], 401);
        }
        iniciar_sessao();
        $_SESSION['apos_login'] = rota_atual();
        redirecionar('/login');
    }
    return $u;
}

function tentar_login(string $email, string $senha): bool
{
    $u = q1('SELECT id, senha_hash, ativo FROM usuarios WHERE email = ?', [mb_strtolower(trim($email))]);
    if (!$u || !$u['ativo'] || !password_verify($senha, $u['senha_hash'])) {
        return false;
    }
    iniciar_sessao();
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int) $u['id'];
    return true;
}

function fazer_logout(): void
{
    iniciar_sessao();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function criar_usuario(string $nome, string $email, string $senha): int
{
    return inserir('usuarios', [
        'nome'       => trim($nome),
        'email'      => mb_strtolower(trim($email)),
        'senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
    ]);
}
