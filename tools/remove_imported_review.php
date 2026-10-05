<?php
declare(strict_types=1);

// Target only the imported review identified by the customer, preserving edits
// and every other review. Safe to rerun: content is read from the live files.
$root = $argv[1] ?? __DIR__ . '/../site';
require $root . '/admin/bootstrap.php';
require $root . '/admin/editor.php';
$lock = catalog_write_lock();
$catalog = load_catalog();
$changed = array();
foreach ($catalog['products'] as $entry) {
    $source = (string)file_get_contents(site_path($entry['path']));
    if (!str_contains($source, 'Наша компания специализируется на остеклении')) continue;
    $product = product_read($entry);
    $reviews = array_values(array_filter($product['reviews'], static fn(array $review): bool => !(
        $review['author'] === 'Сергей'
        && str_starts_with(trim($review['text']), 'Наша компания специализируется на остеклении зданий')
    )));
    if (count($reviews) === count($product['reviews'])) continue;
    [$dom, $xpath] = load_dom_file($entry['path']);
    apply_product_reviews($dom, $xpath, $reviews, $product['name']);
    save_dom_file($entry['path'], $dom);
    $after = product_read($entry);
    unset($product['reviews'], $after['reviews']);
    if ($after !== $product) throw new RuntimeException('Изменились другие поля: ' . $entry['slug']);
    $changed[] = $entry['route'];
}
echo json_encode(array('removed_from_cards' => count($changed), 'routes' => $changed), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
