<?php
declare(strict_types=1);

/** Render saved admin data over the existing design, without writing on GET. */
function public_content_html(string $relative, array $catalog): string {
    [$dom, $xpath, $source] = load_dom_file($relative);
    $changed = false;
    $ownSlug = pathinfo($relative, PATHINFO_FILENAME);
    $own = $catalog['products'][$ownSlug] ?? null;
    if ($own && $own['path'] === $relative && ($saved = saved_product_content($own))) {
        apply_saved_product($dom, $xpath, $own, $saved, $catalog);
        $changed = true;
    }
    $byRoute = array();
    foreach ($catalog['products'] as $entry) $byRoute[$entry['route']] = $entry;
    $categoriesByRoute = array();
    foreach ($catalog['categories'] as $entry) $categoriesByRoute[$entry['route']] = $entry;
    $cards = array();
    $categoryCards = array();
    foreach ($xpath->query('//a[@href]') ?: array() as $anchor) {
        if (!$anchor instanceof DOMElement) continue;
        $href = $anchor->getAttribute('href');
        if (isset($categoriesByRoute[$href])) {
            $categoryCard = find_card_ancestor($anchor, 'catalog-section-list__box');
            if ($categoryCard && !isset($categoryCards[spl_object_id($categoryCard)])) {
                // The home page also contains category cards. Use the saved
                // category image there, even if an imported template is old.
                update_category_card($categoryCard, $xpath, $categoriesByRoute[$href]);
                $categoryCards[spl_object_id($categoryCard)] = $categoryCard;
                $changed = true;
            }
        }
        if (isset($catalog['deleted_products'][$href])) {
            $card = find_card_ancestor($anchor, 'catalog-section-tile__item');
            if ($card) {
                $column = find_card_ancestor($card, 'col-xl-3') ?: find_card_ancestor($card, 'col-xl-4') ?: $card;
                $column->parentNode?->removeChild($column);
            } elseif (str_contains(' ' . $anchor->getAttribute('class') . ' ', ' related-accessories__item ')) {
                $anchor->parentNode?->removeChild($anchor);
            }
            $changed = true;
            continue;
        }
        $entry = $byRoute[$href] ?? null;
        if (!$entry) continue;
        $card = find_card_ancestor($anchor, 'catalog-section-tile__item');
        if ($card && !isset($cards[spl_object_id($card)])) {
            update_product_card($card, $xpath, $entry);
            // Retain DOM wrappers: PHP may otherwise reuse their object IDs
            // for later cards and incorrectly skip those products.
            $cards[spl_object_id($card)] = $card;
            $changed = true;
        } elseif (str_contains(' ' . $anchor->getAttribute('class') . ' ', ' related-accessories__item ')) {
            update_related_accessory($anchor, $xpath, $entry);
            $changed = true;
        }
    }
    // Some imported popular-product links are stored in JavaScript strings.
    foreach ($xpath->query('//script[not(@src)]') ?: array() as $script) {
        if (!str_contains($script->textContent, '.popular-section')) continue;
        $original = $script->textContent;
        $updated = preg_replace_callback('~<a href="([^"<>]+)">[^<]*</a>~u', static function (array $match) use ($byRoute, $catalog): string {
            if (isset($catalog['deleted_products'][$match[1]])) return '';
            $entry = $byRoute[$match[1]] ?? null;
            return $entry ? '<a href="' . h($entry['route']) . '">' . h($entry['name']) . '</a>' : $match[0];
        }, $original) ?? $original;
        if ($updated !== $original) { $script->textContent = $updated; $changed = true; }
    }
    if (!$changed) return $source;
    return preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/', '', $dom->saveHTML()) ?? $source;
}
