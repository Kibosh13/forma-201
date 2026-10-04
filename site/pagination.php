<?php
declare(strict_types=1);

/**
 * Serve archived pages addressed by Bitrix PAGEN_* query parameters.
 *
 * The public catalog is a static mirror, while Apache normally ignores the
 * query string when resolving index.html and .tag files. The rewrite rule in
 * .htaccess sends pagination requests here, and this dispatcher selects the
 * matching captured HTML document from an explicit allowlist.
 */

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = rawurldecode((string) parse_url($requestUri, PHP_URL_PATH));
$requestQuery = (string) (parse_url($requestUri, PHP_URL_QUERY) ?? '');

parse_str($requestQuery, $queryParams);
$paginationParams = [];
foreach ($queryParams as $name => $value) {
    if (!preg_match('/^(?:PAGEN_[0-9]+|page)$/', (string) $name)) {
        continue;
    }
    if (is_array($value) || !ctype_digit((string) $value)) {
        continue;
    }
    $paginationParams[(string) $name] = (string) $value;
}

if ($paginationParams === []) {
    if (PHP_SAPI === 'cli-server') {
        return false;
    }
    http_response_code(404);
    exit;
}

ksort($paginationParams);
$routeKey = $requestPath . '?' . http_build_query(
    $paginationParams,
    '',
    '&',
    PHP_QUERY_RFC3986
);

/** @var array<string, string> $routes */
$routes = require __DIR__ . '/pagination-routes.php';
$relativeFile = $routes[$routeKey] ?? null;
$queryDirectory = realpath(__DIR__ . '/_mirror/query');
$targetFile = is_string($relativeFile)
    ? realpath(__DIR__ . '/' . ltrim($relativeFile, '/'))
    : false;

if (
    $relativeFile === null
    || $queryDirectory === false
    || $targetFile === false
    || !str_starts_with($targetFile, $queryDirectory . DIRECTORY_SEPARATOR)
    || !is_file($targetFile)
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Страница каталога не найдена.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
readfile($targetFile);
