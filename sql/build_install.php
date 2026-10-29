<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$repositoryRoot = realpath(dirname(__DIR__));
if ($repositoryRoot === false) {
    fwrite(STDERR, "Unable to resolve the application directory.\n");
    exit(1);
}

$schemaPath = __DIR__ . '/schema.sql';
$seedPath = __DIR__ . '/seed.sql';
$sourceBundlePath = __DIR__ . '/mama-care-install.sql';

if (($argv[1] ?? '') === '--refresh-source') {
    $schema = file_get_contents($schemaPath);
    $seed = file_get_contents($seedPath);
    if ($schema === false || $seed === false) {
        fwrite(STDERR, "Unable to read schema.sql or seed.sql.\n");
        exit(1);
    }

    $sourceBundle = "-- Fresh-install schema and required lookup data only.\n"
        . "-- Import into a new, empty database, then create an administrator.\n"
        . "-- No credentials, patient records, or demo data are included.\n\n"
        . $schema . "\n\n" . $seed;
    $temporaryPath = $sourceBundlePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporaryPath, $sourceBundle, LOCK_EX) !== strlen($sourceBundle)) {
        @unlink($temporaryPath);
        fwrite(STDERR, "Unable to write the fresh-install SQL source.\n");
        exit(1);
    }
    if (!chmod($temporaryPath, 0644) || !rename($temporaryPath, $sourceBundlePath)) {
        @unlink($temporaryPath);
        fwrite(STDERR, "Unable to update the fresh-install SQL source.\n");
        exit(1);
    }
    fwrite(STDOUT, "Credential-free install source updated: " . $sourceBundlePath . "\n");
    exit(0);
}

$outputPath = $argv[1] ?? '';
$username = $argv[2] ?? 'admin@homebridgeplus.org';
$fullName = $argv[3] ?? 'System Administrator';

if ($outputPath === '') {
    fwrite(STDERR, "Usage: php sql/build_install.php /private/path/install.sql [admin-username] [admin-full-name]\n");
    fwrite(STDERR, "       php sql/build_install.php --refresh-source\n");
    exit(1);
}

if ($username === '' || strlen($username) > 50 || $fullName === '' || strlen($fullName) > 150) {
    fwrite(STDERR, "The administrator username and full name must fit the database fields.\n");
    exit(1);
}

$outputDirectory = realpath(dirname($outputPath));
if ($outputDirectory === false || !is_writable($outputDirectory)) {
    fwrite(STDERR, "The output directory must exist and be writable.\n");
    exit(1);
}

$canonicalOutputPath = $outputDirectory . DIRECTORY_SEPARATOR . basename($outputPath);
$repositoryPrefix = rtrim($repositoryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (file_exists($canonicalOutputPath) || is_link($canonicalOutputPath)) {
    fwrite(STDERR, "The output file already exists; choose a new path to avoid overwriting data.\n");
    exit(1);
}
if (
    str_starts_with($canonicalOutputPath, $repositoryPrefix)
) {
    fwrite(STDERR, "Write the generated installer outside the repository so credentials are not committed.\n");
    exit(1);
}

$sourceBundle = file_get_contents($sourceBundlePath);
if ($sourceBundle === false) {
    fwrite(STDERR, "Unable to read sql/mama-care-install.sql. Refresh it from schema.sql and seed.sql first.\n");
    exit(1);
}

$initialPassword = bin2hex(random_bytes(18));
$passwordHash = password_hash($initialPassword, PASSWORD_DEFAULT);
if (!password_verify($initialPassword, $passwordHash)) {
    fwrite(STDERR, "Unable to verify the generated administrator password hash.\n");
    exit(1);
}

$sqlValue = static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'";
$adminInsert = "\n\n-- Initial administrator; password is stored as a PHP password_hash() value.\n"
    . "INSERT INTO `users` (`username`, `password_hash`, `full_name`, `role`, `email`, `is_active`) VALUES\n"
    . '(' . $sqlValue($username) . ', ' . $sqlValue($passwordHash) . ', '
    . $sqlValue($fullName) . ", 'admin', " . $sqlValue($username) . ", 1);\n";

$bundle = "-- Generated fresh-install bundle. Import into a new, empty database only.\n"
    . "-- Contains the tracked schema/lookup source and one administrator account.\n"
    . "-- No patient or demo records are included.\n\n"
    . $sourceBundle . $adminInsert;

$installerFile = fopen($canonicalOutputPath, 'x');
if ($installerFile === false) {
    fwrite(STDERR, "Unable to create the SQL installer.\n");
    exit(1);
}
if (!chmod($canonicalOutputPath, 0600)) {
    fclose($installerFile);
    unlink($canonicalOutputPath);
    fwrite(STDERR, "Unable to restrict access to the SQL installer.\n");
    exit(1);
}
$bytesWritten = fwrite($installerFile, $bundle);
$closed = fclose($installerFile);
if ($bytesWritten !== strlen($bundle) || !$closed) {
    unlink($canonicalOutputPath);
    fwrite(STDERR, "Unable to finish writing the SQL installer.\n");
    exit(1);
}

fwrite(STDOUT, "SQL installer created: " . $canonicalOutputPath . "\n");
fwrite(STDOUT, "Administrator username: " . $username . "\n");
fwrite(STDOUT, "Administrator password (save it securely): " . $initialPassword . "\n");
