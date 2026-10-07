<?php
declare(strict_types=1);

// Keep the production sitemap current without overwriting customer content.
define('ALYM_PUBLIC_CONTENT', true);
require __DIR__ . '/admin/bootstrap.php';
header('Content-Type: application/xml; charset=UTF-8');

try {
    $catalog = load_catalog();
    $routes = array();
    $add = static function (string $route) use (&$routes, $catalog): void {
        if (!str_starts_with($route, '/') || str_starts_with($route, '//') || str_contains($route, '..')
            || str_contains($route, '?') || str_contains($route, '#')
            || preg_match('~^/(?:admin|_mirror|poisk)(?:/|$)~', $route)
            || str_ends_with($route, '.php') || str_ends_with($route, '.xml')
            || isset($catalog['deleted_products'][$route])) return;
        $relative = rawurldecode(ltrim($route, '/'));
        if ($relative === '' || str_ends_with($relative, '/')) $relative .= 'index.html';
        if (is_file(__DIR__ . '/' . $relative)) $routes[$route] = true;
    };
    $productRoutes = array();
    foreach ($catalog['products'] as $entry) {
        $productRoutes[$entry['route']] = true;
        $add($entry['route']);
    }
    foreach (array('categories', 'pages') as $group) {
        foreach ($catalog[$group] ?? array() as $entry) $add((string)($entry['route'] ?? ''));
    }
    $add('/');
    // The imported XML files contain static content and tag pages. Rewrite
    // their old host and project mount, and omit obsolete product URLs.
    foreach (glob(__DIR__ . '/*.xml') ?: array() as $file) {
        $legacy = new DOMDocument();
        if (!$legacy->load($file, LIBXML_NONET)) continue;
        if ($legacy->documentElement?->localName !== 'urlset') continue;
        foreach ($legacy->getElementsByTagName('loc') as $location) {
            $url = trim($location->textContent);
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            if (!in_array($host, array('alymprofi.ru', 'www.alymprofi.ru', 'dial-td.ru', 'kibosh13.github.io'), true)) continue;
            $route = (string)parse_url($url, PHP_URL_PATH);
            if ($host === 'kibosh13.github.io' && str_starts_with($route, '/forma-201/')) $route = substr($route, strlen('/forma-201'));
            if (str_ends_with($route, '.prod') && !isset($productRoutes[$route])) continue;
            $add($route);
        }
    }
    ksort($routes);
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;
    $namespace = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    $set = $xml->createElementNS($namespace, 'urlset');
    $xml->appendChild($set);
    foreach (array_keys($routes) as $route) {
        $url = $xml->createElementNS($namespace, 'url');
        $loc = $xml->createElementNS($namespace, 'loc');
        $loc->appendChild($xml->createTextNode('https://alymprofi.ru' . $route));
        $url->appendChild($loc);
        $set->appendChild($url);
    }
    echo $xml->saveXML();
} catch (Throwable $error) {
    error_log('AlyumProfi sitemap: ' . $error->getMessage());
    http_response_code(503);
    echo '<?xml version="1.0" encoding="UTF-8"?><error>Карта сайта временно недоступна.</error>';
}
