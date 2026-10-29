<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
    fwrite(STDERR, "An account already exists. This bootstrap only runs on an empty users table.\n");
    exit(1);
}

$username = promptLine('Admin username: ');
$fullName = promptLine('Admin full name: ');
$password = promptSecret('Password (minimum 12 characters): ');
$confirmation = promptSecret('Confirm password: ');

if ($username === '' || $fullName === '') {
    fwrite(STDERR, "Username and full name are required.\n");
    exit(1);
}

if (strlen($password) < 12) {
    fwrite(STDERR, "Use a password with at least 12 characters.\n");
    exit(1);
}

if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    "INSERT INTO users (username, password_hash, full_name, role)
     VALUES (:username, :password_hash, :full_name, 'super_admin')"
);
$stmt->execute([
    ':username' => $username,
    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ':full_name' => $fullName,
]);

fwrite(STDOUT, "Administrator account created.\n");

function promptLine(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Unable to read input from the terminal.');
    }

    return rtrim($value, "\r\n");
}

function promptSecret(string $prompt): string
{
    if (!function_exists('shell_exec') || !function_exists('exec')) {
        throw new RuntimeException('Hidden terminal input is unavailable; enable shell_exec and exec to continue.');
    }

    $terminalState = shell_exec('stty -g');
    if (!is_string($terminalState) || trim($terminalState) === '') {
        throw new RuntimeException('Run this command from an interactive terminal.');
    }

    fwrite(STDOUT, $prompt);
    exec('stty -echo', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException('Unable to disable terminal echo for password input.');
    }

    try {
        $value = fgets(STDIN);
    } finally {
        exec('stty ' . escapeshellarg(trim($terminalState)), $output, $restoreStatus);
        fwrite(STDOUT, PHP_EOL);
        if ($restoreStatus !== 0) {
            throw new RuntimeException('Unable to restore terminal settings.');
        }
    }

    if ($value === false) {
        throw new RuntimeException('Unable to read password input from the terminal.');
    }

    return rtrim($value, "\r\n");
}
