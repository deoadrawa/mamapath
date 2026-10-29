<?php
// Usage: php sql/backup.php /private/backup/dir [keep=14]
// Dumps the database named in .env to a timestamped, gzip-compressed file and prunes old backups.
// Schedule it (e.g. daily cron). Keep the destination outside the web root and outside the repository.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$directory = $argv[1] ?? '';
$keep = max(1, (int) ($argv[2] ?? 14));
if ($directory === '' || !is_dir($directory) || !is_writable($directory)) {
    fwrite(STDERR, "Usage: php sql/backup.php /existing/writable/dir [keep=14]\n");
    exit(1);
}
$directory = realpath($directory);
$root = realpath(dirname(__DIR__));
if ($directory === $root || str_starts_with($directory . '/', $root . '/')) {
    fwrite(STDERR, "Choose a backup directory outside the application folder.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config.php';

$options = tempnam(sys_get_temp_dir(), 'mcb');
chmod($options, 0600);
file_put_contents($options, "[client]\nuser=" . DB_USER . "\npassword=\"" . addcslashes(DB_PASS, "\"\\") . "\"\nhost=" . DB_HOST . "\n");

$target = $directory . '/mama-care-' . date('Ymd-His') . '.sql.gz';
$command = sprintf(
    'mysqldump --defaults-extra-file=%s --single-transaction --routines --triggers --no-tablespaces %s | gzip > %s',
    escapeshellarg($options),
    escapeshellarg(DB_NAME),
    escapeshellarg($target)
);
passthru('bash -c ' . escapeshellarg('set -o pipefail; ' . $command), $status);
unlink($options);

if ($status !== 0 || !is_file($target) || filesize($target) < 200) {
    @unlink($target);
    fwrite(STDERR, "Backup failed; no file was kept.\n");
    exit(1);
}
chmod($target, 0600);

$files = glob($directory . '/mama-care-*.sql.gz') ?: [];
rsort($files);
foreach (array_slice($files, $keep) as $old) {
    unlink($old);
}
fwrite(STDOUT, "Backup written: $target (keeping latest $keep)\n");
