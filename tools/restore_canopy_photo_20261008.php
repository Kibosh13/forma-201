<?php
declare(strict_types=1);
// One-time, field-level repair using the customer's original photograph.
$root = $argv[1] ?? '';
$source = $argv[2] ?? '';
$apply = in_array('--apply', $argv, true);
if (!is_file($source) || getimagesize($source)[2] !== IMAGETYPE_PNG) {
    throw new RuntimeException('Expected the original PNG photograph.');
}
define('ALYM_PUBLIC_CONTENT', true);
require $root . '/admin/bootstrap.php';
require $root . '/admin/editor.php';
$lock = $apply ? catalog_write_lock() : null;
$catalog = load_catalog();
$category = 'zazhimnye-profili-i-furnitura-dlya-steklyannykh-kozyrkov';
$slugs = ['zazhimnoy-profil-kozyrka-dlya-stekla', 'zazhimnoy-profil-kozyrka-dlya-stekla-2-metra', 'zazhimnoy-profil-kozyrka-dlya-stekla-3-metra'];
$hash = hash_file('sha256', $source);
$image = '/upload/admin/2026/10/canopy-profile-silver-with-glass-' . substr($hash, 0, 10) . '.png';
$report = ['applied' => $apply, 'image' => $image, 'sha256' => $hash, 'products' => []];
if (!isset($catalog['categories'][$category])) throw new RuntimeException('Category missing.');
foreach ($slugs as $slug) {
    $entry = $catalog['products'][$slug] ?? null;
    if (!$entry || ($entry['category'] ?? '') !== $category) throw new RuntimeException('Unexpected product category.');
    $values = product_read($entry);
    $images = $values['images'];
    $oldMain = $values['image'];
    $images = array_values(array_filter($images, static fn($value) => $value !== $oldMain && $value !== $image));
    array_unshift($images, $image);
    $report['products'][$slug] = ['name' => $values['name'], 'price' => $values['price'], 'before' => $values['images'], 'after' => $images];
}
if ($apply) {
    if (!is_file(site_path(ltrim($image, '/')))) atomic_write(site_path(ltrim($image, '/')), file_get_contents($source));
    if (hash_file('sha256', site_path(ltrim($image, '/'))) !== $hash) throw new RuntimeException('Photograph checksum mismatch.');
    backup_file('admin/storage/catalog.json');
    foreach ($slugs as $slug) {
        $entry = $catalog['products'][$slug];
        $values = product_read($entry);
        if ($values['images'] === $report['products'][$slug]['after'] && $values['image'] === $image) continue;
        $before = $values;
        $values['image'] = $image;
        $values['images'] = $report['products'][$slug]['after'];
        $entry['image'] = $image;
        $catalog['products'][$slug] = $entry;
        backup_file('admin/storage/product-content/' . basename(product_content_path($entry)));
        save_product_content($entry, $values);
        [$dom, $xpath] = load_dom_file($entry['path']);
        apply_saved_product($dom, $xpath, $entry, ['product' => $values], $catalog);
        save_dom_file($entry['path'], $dom);
        sync_product_cards($catalog, $entry);
        sync_product_references($entry);
        $after = product_read($entry);
        foreach ($before as $field => $value) {
            if (!in_array($field, ['image', 'images'], true) && $value !== $after[$field]) throw new RuntimeException('Unexpected field change: ' . $field);
        }
    }
    $catalog['categories'][$category]['image'] = $image;
    sync_category_card($catalog['categories'][$category]);
    save_catalog($catalog);
}
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
