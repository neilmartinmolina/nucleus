<?php

$uriPath = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
$decodedPath = rawurldecode($uriPath);

if (preg_match('/(^|\/)\.env(?:\.|$)/i', $decodedPath)) {
    http_response_code(403);
    echo "Forbidden";
    return true;
}

$filePath = realpath(__DIR__ . "/.." . $decodedPath);
$rootPath = realpath(__DIR__ . "/..");

if ($filePath && $rootPath && str_starts_with($filePath, $rootPath) && is_file($filePath)) {
    return false;
}

require __DIR__ . "/../index.php";
