<?php
declare(strict_types=1);
// Recover only evidenced saved fields; never copy an old whole-page backup.
$root = $argv[1] ?? __DIR__ . '/../site';
$apply = in_array('--apply', $argv, true);
require $root . '/admin/bootstrap.php';
require $root . '/admin/editor.php';
$lock = $apply ? catalog_write_lock() : null;
$catalog = load_catalog();
$seed = json_decode((string)file_get_contents($root . '/admin/data/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$report = array('products' => count($catalog['products']), 'changed' => array(), 'initialized' => 0, 'already_protected' => 0, 'applied' => $apply);
if ($apply) backup_file('admin/storage/catalog.json');
foreach ($catalog['products'] as $slug => $entry) {
    // This is a one-time migration. Never replay old uploads/backups over
    // complete data already protected by the new admin storage.
    if (saved_product_content($entry)) {
        $report['already_protected']++;
        continue;
    }
    $current = product_read($entry);
    $values = $current;
    // The live index survived the earlier template uploads. Use only fields
    // changed from the original index, and reject its legacy bogus statuses.
    foreach (array('name','price','unit','article','status') as $field) {
        if (!isset($entry[$field]) || $entry[$field] === ($seed['products'][$slug][$field] ?? null)) continue;
        if ($field === 'status' && !in_array($entry[$field], array('В наличии','Под заказ'), true)) continue;
        $values[$field] = $entry[$field];
    }
    // A later pre-save backup can retain characteristics from an earlier save.
    $backups = glob($root . '/admin/storage/backups/2026-10-05_*/' . $entry['path']) ?: array();
    $backups = array_values(array_filter($backups, static fn(string $p): bool => preg_match('~2026-10-05_1[78][0-9]{4}/~', $p) === 1));
    sort($backups);
    if (count($backups) > 1) {
        $first = product_read(array('path' => substr($backups[0], strlen($root) + 1)), true);
        $last = product_read(array('path' => substr(end($backups), strlen($root) + 1)), true);
        if ($last['attributes'] !== $first['attributes']) $values['attributes'] = $last['attributes'];
    }
    // Keep the current main image and restore the last uploaded diagram for
    // each affected kit. Older photos explicitly removed by the customer are
    // not reintroduced from an arbitrary historical gallery.
    $uploads = glob($root . '/upload/admin/2026/10/' . $slug . '-*') ?: array();
    usort($uploads, static fn(string $a, string $b): int => filemtime($a) <=> filemtime($b));
    if ($uploads) {
        $latest = end($uploads);
        $latestRelative = '/' . substr($latest, strlen($root) + 1);
        $hash = hash_file('sha256', $latest);
        $duplicate = false;
        foreach ($values['images'] as $image) {
            $path = $root . parse_url($image, PHP_URL_PATH);
            if ($image === $latestRelative || (is_file($path) && hash_file('sha256', $path) === $hash)) $duplicate = true;
        }
        if (!$duplicate) $values['images'][] = $latestRelative;
    }
    $diff = array();
    foreach ($values as $key => $value) if ($value !== $current[$key]) $diff[$key] = array('before' => $current[$key], 'restored' => $value);
    if ($diff) $report['changed'][$slug] = $diff;
    foreach (array('name','price','unit','article','status','image') as $field) $entry[$field] = $values[$field];
    $catalog['products'][$slug] = $entry;
    if ($apply) {
        if (!saved_product_content($entry) || $diff) {
            save_product_content($entry, $values);
            $report['initialized']++;
        }
        if ($diff) {
            [$dom,$xpath] = load_dom_file($entry['path']);
            apply_saved_product($dom, $xpath, $entry, array('product'=>$values), $catalog);
            save_dom_file($entry['path'], $dom);
        }
    }
}
if ($apply) save_catalog($catalog);
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
