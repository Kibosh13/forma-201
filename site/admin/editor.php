<?php
declare(strict_types=1);

function text_of(?DOMElement $node): string {
    return $node ? trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '') : '';
}

function meta_content(DOMXPath $xpath, string $name): string {
    return first_node($xpath, '//meta[@name="' . $name . '"]')?->getAttribute('content') ?? '';
}

function product_read(array $entry): array {
    [$dom, $xpath] = load_dom_file($entry['path']);
    $title = text_of(first_node($xpath, '//title'));
    $h1 = text_of(first_node($xpath, '//h1'));
    $status = text_of(first_node($xpath, '//*[contains(concat(" ",normalize-space(@class)," ")," catalog-detail__status-nal ") or contains(concat(" ",normalize-space(@class)," ")," catalog-detail__status-order ")]'));
    $price = text_of(first_node($xpath, class_query('pricespace')));
    $unit = text_of(first_node($xpath, class_query('catalog-detail__price-rub')));
    $summary = first_node($xpath, class_query('catalog-detail__preview'));
    $description = first_node($xpath, class_query('catalog-detail__text'));
    $image = first_node($xpath, '//img[contains(concat(" ",normalize-space(@class)," ")," catalog-detail__img-img ")]');
    $attributes = array();
    foreach ($xpath->query(class_query('catalog-detail__parameter-item')) ?: array() as $item) {
        if (!$item instanceof DOMElement) continue;
        $itemXpath = new DOMXPath($dom);
        $nameNode = $itemXpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," catalog-detail__parameter-name ")]', $item)?->item(0);
        $valueNode = $itemXpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," catalog-detail__parameter-size ")]', $item)?->item(0);
        $name = $nameNode ? trim($nameNode->textContent) : '';
        $value = '';
        if ($valueNode) {
            $parts = array();
            foreach ($valueNode->getElementsByTagName('span') as $part) {
                $partText = text_of($part);
                if ($partText !== '') $parts[] = $partText;
            }
            $value = $parts ? implode(', ', $parts) : text_of($valueNode);
        }
        if ($name !== '' || $value !== '') {
            $attributes[] = array('name' => $name, 'value' => $value);
        }
    }
    return array(
        'name' => $h1,
        'price' => $price,
        'unit' => $unit,
        'status' => $status ?: 'В наличии',
        'summary' => $summary ? inner_html($summary) : '',
        'description' => $description ? inner_html($description) : '',
        'attributes' => $attributes,
        'image' => $image?->getAttribute('src') ?? '',
        'seo_title' => $title,
        'seo_description' => meta_content($xpath, 'description'),
    );
}

function apply_product_values(DOMDocument $dom, DOMXPath $xpath, array $values): void {
    $name = trim($values['name']);
    $seoTitle = trim($values['seo_title']) ?: $name;
    $seoDescription = trim($values['seo_description']);
    set_title($xpath, $dom, $seoTitle);
    set_meta($xpath, $dom, 'description', $seoDescription);
    set_property_meta($xpath, $dom, 'og:description', $seoDescription);
    $h1 = first_node($xpath, '//h1');
    if ($h1) $h1->textContent = $name;

    $status = first_node($xpath, '//*[contains(concat(" ",normalize-space(@class)," ")," catalog-detail__status-nal ") or contains(concat(" ",normalize-space(@class)," ")," catalog-detail__status-order ")]');
    if ($status) {
        $status->textContent = trim($values['status']);
        $status->setAttribute('class', trim($values['status']) === 'Под заказ' ? 'catalog-detail__status-order' : 'catalog-detail__status-nal');
    }
    $price = first_node($xpath, class_query('pricespace'));
    if ($price) $price->textContent = trim($values['price']);
    $unit = first_node($xpath, class_query('catalog-detail__price-rub'));
    if ($unit) $unit->textContent = trim($values['unit']);
    $summary = first_node($xpath, class_query('catalog-detail__preview'));
    if ($summary) set_inner_html($summary, $values['summary']);
    $description = first_node($xpath, class_query('catalog-detail__text'));
    if ($description) set_inner_html($description, $values['description']);

    foreach ($xpath->query('//*[@data-name]') ?: array() as $button) {
        if ($button instanceof DOMElement) $button->setAttribute('data-name', $name);
    }
    foreach ($xpath->query('//*[@data-price]') ?: array() as $button) {
        if ($button instanceof DOMElement) $button->setAttribute('data-price', trim($values['price']));
    }

    $image = first_node($xpath, '//img[contains(concat(" ",normalize-space(@class)," ")," catalog-detail__img-img ")]');
    if ($image && !empty($values['image'])) {
        $image->setAttribute('src', $values['image']);
        $image->setAttribute('alt', $name);
        $image->setAttribute('title', $name);
        $image->removeAttribute('srcset');
        set_property_meta($xpath, $dom, 'og:image', $values['image']);
    }

    $parameter = first_node($xpath, class_query('catalog-detail__parameter'));
    if ($parameter) {
        $column = first_node($xpath, class_query('catalog-detail__parameter') . '//*[contains(concat(" ",normalize-space(@class)," ")," col-xl-9 ")]');
        if ($column) {
            while ($column->firstChild) $column->removeChild($column->firstChild);
            foreach ($values['attributes'] as $attribute) {
                $attributeName = trim((string)($attribute['name'] ?? ''));
                $attributeValue = trim((string)($attribute['value'] ?? ''));
                if ($attributeName === '' && $attributeValue === '') continue;
                $item = $dom->createElement('div');
                $item->setAttribute('class', 'catalog-detail__parameter-item');
                $nameNode = $dom->createElement('div');
                $nameNode->setAttribute('class', 'catalog-detail__parameter-name');
                $nameNode->textContent = $attributeName;
                $lineNode = $dom->createElement('div');
                $lineNode->setAttribute('class', 'catalog-detail__parameter-line');
                $valueNode = $dom->createElement('div');
                $valueNode->setAttribute('class', 'catalog-detail__parameter-size');
                $valueNode->textContent = $attributeValue;
                $item->append($nameNode, $lineNode, $valueNode);
                $column->appendChild($item);
            }
        }
    }

    foreach ($xpath->query('//script[@type="application/ld+json"]') ?: array() as $script) {
        $json = json_decode($script->textContent, true);
        if (!is_array($json) || ($json['@type'] ?? '') !== 'Product') continue;
        $json['name'] = $name;
        $json['description'] = $seoDescription;
        if (!empty($values['image'])) $json['image'] = $values['image'];
        if (isset($json['offers']) && is_array($json['offers'])) $json['offers']['price'] = trim($values['price']);
        $script->textContent = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

function find_card_ancestor(DOMNode $node, string $class): ?DOMElement {
    for ($current = $node; $current; $current = $current->parentNode) {
        if ($current instanceof DOMElement && str_contains(' ' . $current->getAttribute('class') . ' ', ' ' . $class . ' ')) return $current;
    }
    return null;
}

function update_product_card(DOMElement $card, DOMXPath $xpath, array $entry): void {
    foreach ($xpath->query('.//a[@href]', $card) ?: array() as $anchor) {
        if (!$anchor instanceof DOMElement) continue;
        $class = $anchor->getAttribute('class');
        if ($class === '' || str_contains($class, 'catalog-section-tile__title-link')) {
            $anchor->setAttribute('href', $entry['route']);
        }
        if (str_contains($class, 'catalog-section-tile__title-link')) $anchor->textContent = $entry['name'];
    }
    $img = $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," catalog-section-tile__img-img ")]', $card)?->item(0);
    if ($img instanceof DOMElement) {
        $img->setAttribute('src', $entry['image']);
        $img->setAttribute('alt', $entry['name']);
        $img->setAttribute('title', $entry['name']);
        $img->removeAttribute('srcset');
    }
    $title = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," catalog-section-tile__title-link ")]', $card)?->item(0);
    if ($title) $title->textContent = $entry['name'];
    $price = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," pricespace ")]', $card)?->item(0);
    if ($price) $price->textContent = $entry['price'];
    $status = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," catalog-section-tile__status-nal ") or contains(concat(" ",normalize-space(@class)," ")," catalog-section-tile__status-order ")]', $card)?->item(0);
    if ($status instanceof DOMElement) {
        $status->textContent = $entry['status'];
        $status->setAttribute('class', $entry['status'] === 'Под заказ' ? 'catalog-section-tile__status-order' : 'catalog-section-tile__status-nal');
    }
    foreach ($xpath->query('.//*[@data-name]', $card) ?: array() as $button) if ($button instanceof DOMElement) $button->setAttribute('data-name', $entry['name']);
    foreach ($xpath->query('.//*[@data-price]', $card) ?: array() as $button) if ($button instanceof DOMElement) $button->setAttribute('data-price', $entry['price']);
}

function sync_product_cards(array $catalog, array $entry, string $oldRoute = '', bool $delete = false): void {
    $routes = array_values(array_unique(array_filter(array($oldRoute, $entry['route']))));
    foreach ($catalog['categories'] as $slug => $category) {
        $relative = $category['path'];
        if (!is_file(site_path($relative))) continue;
        [$dom, $xpath] = load_dom_file($relative);
        $cards = array();
        foreach ($routes as $route) {
            foreach ($xpath->query('//a[@href="' . $route . '"]') ?: array() as $anchor) {
                $card = find_card_ancestor($anchor, 'col-xl-4');
                if ($card) $cards[spl_object_id($card)] = $card;
            }
        }
        $changed = false;
        $target = !$delete && $slug === ($entry['category'] ?? '');
        if (!$target) {
            foreach ($cards as $card) {
                $card->parentNode?->removeChild($card);
                $changed = true;
            }
        } elseif ($cards) {
            foreach ($cards as $card) update_product_card($card, $xpath, $entry);
            $changed = true;
        } else {
            $sample = first_node($xpath, '//div[contains(concat(" ",normalize-space(@class)," ")," col-xl-4 ")][.//*[contains(concat(" ",normalize-space(@class)," ")," catalog-section-tile__item ")]]');
            $row = $sample?->parentNode;
            if ($sample && $row) {
                $clone = $sample->cloneNode(true);
                if ($clone instanceof DOMElement) {
                    $clone->setAttribute('id', 'alym_' . substr(hash('sha256', $entry['slug']), 0, 12));
                    update_product_card($clone, $xpath, $entry);
                    $row->appendChild($clone);
                    $changed = true;
                }
            }
        }
        if ($changed) save_dom_file($relative, $dom);
    }
}

function product_save(array &$catalog, ?string $oldSlug): string {
    $creating = $oldSlug === null;
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Укажите название товара.');
    if ($creating) {
        $slug = unique_slug((string)($_POST['slug'] ?? $name), $catalog['products']);
        $template = site_path('catalog/alyuminievaya-dvernaya-korobka-l.prod');
        $relative = 'catalog/' . $slug . '.prod';
        copy($template, site_path($relative));
        chmod(site_path($relative), 0640);
        $entry = array('slug' => $slug, 'path' => $relative, 'route' => '/catalog/' . $slug . '.prod');
        $oldRoute = '';
    } else {
        if (!isset($catalog['products'][$oldSlug])) throw new RuntimeException('Товар не найден.');
        $entry = $catalog['products'][$oldSlug];
        $slug = $oldSlug;
        $oldRoute = $entry['route'];
    }
    $current = product_read($entry);
    $uploaded = upload_image('image_upload', $slug);
    $attributeNames = is_array($_POST['attribute_name'] ?? null) ? $_POST['attribute_name'] : array();
    $attributeValues = is_array($_POST['attribute_value'] ?? null) ? $_POST['attribute_value'] : array();
    $attributes = array();
    $attributeCount = max(count($attributeNames), count($attributeValues));
    for ($index = 0; $index < $attributeCount; $index++) {
        $attributeName = trim((string)($attributeNames[$index] ?? ''));
        $attributeValue = trim((string)($attributeValues[$index] ?? ''));
        if ($attributeName === '' && $attributeValue === '') continue;
        $attributes[] = array('name' => $attributeName, 'value' => $attributeValue);
    }
    $values = array(
        'name' => $name,
        'price' => trim((string)($_POST['price'] ?? '0')),
        'unit' => trim((string)($_POST['unit'] ?? 'р./шт.')),
        'status' => (string)($_POST['status'] ?? 'В наличии'),
        'summary' => (string)($_POST['summary'] ?? ''),
        'description' => (string)($_POST['description'] ?? ''),
        'attributes' => $attributes,
        'image' => $uploaded ?: $current['image'],
        'seo_title' => trim((string)($_POST['seo_title'] ?? '')),
        'seo_description' => trim((string)($_POST['seo_description'] ?? '')),
    );
    [$dom, $xpath] = load_dom_file($entry['path']);
    apply_product_values($dom, $xpath, $values);
    save_dom_file($entry['path'], $dom);
    $entry += array('category' => '');
    $entry['name'] = $values['name'];
    $entry['price'] = $values['price'];
    $entry['status'] = $values['status'];
    $entry['image'] = $values['image'];
    $category = (string)($_POST['category'] ?? '');
    $entry['category'] = isset($catalog['categories'][$category]) ? $category : '';
    $catalog['products'][$slug] = $entry;
    sync_product_cards($catalog, $entry, $oldRoute);
    save_catalog($catalog);
    return $slug;
}

function product_delete(array &$catalog, string $slug): void {
    if (!isset($catalog['products'][$slug])) throw new RuntimeException('Товар не найден.');
    $entry = $catalog['products'][$slug];
    sync_product_cards($catalog, $entry, $entry['route'], true);
    $source = site_path($entry['path']);
    if (is_file($source)) {
        $trash = ALYM_STORAGE_DIR . '/trash/' . date('Y-m-d_His') . '/' . basename($source);
        if (!is_dir(dirname($trash))) mkdir(dirname($trash), 0770, true);
        rename($source, $trash);
    }
    unset($catalog['products'][$slug]);
    save_catalog($catalog);
}

function category_read(array $entry): array {
    [$dom, $xpath] = load_dom_file($entry['path']);
    $h1 = text_of(first_node($xpath, '//h1'));
    return array(
        'name' => $h1 ?: $entry['name'],
        'seo_title' => text_of(first_node($xpath, '//title')),
        'seo_description' => meta_content($xpath, 'description'),
        'image' => $entry['image'] ?? '',
    );
}

function update_category_card(DOMElement $card, DOMXPath $xpath, array $entry): void {
    foreach ($xpath->query('.//a[@href]', $card) ?: array() as $anchor) {
        if ($anchor instanceof DOMElement) $anchor->setAttribute('href', $entry['route']);
    }
    $title = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," catalog-section-list__link-title ")]', $card)?->item(0);
    if ($title) $title->textContent = $entry['name'];
    $img = $xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," catalog-section-list__img-img ")]', $card)?->item(0);
    if ($img instanceof DOMElement) {
        $img->setAttribute('src', $entry['image']);
        $img->setAttribute('alt', $entry['name']);
        $img->setAttribute('title', $entry['name']);
        $img->removeAttribute('srcset');
    }
}

function sync_category_card(array $entry, string $oldRoute = '', bool $delete = false): void {
    $relative = 'catalog/index.html';
    [$dom, $xpath] = load_dom_file($relative);
    $routes = array_values(array_unique(array_filter(array($oldRoute, $entry['route']))));
    $cards = array();
    foreach ($routes as $route) {
        foreach ($xpath->query('//a[@href="' . $route . '"]') ?: array() as $anchor) {
            $card = find_card_ancestor($anchor, 'catalog-section-list__col');
            if ($card) $cards[spl_object_id($card)] = $card;
        }
    }
    if ($delete) {
        foreach ($cards as $card) $card->parentNode?->removeChild($card);
    } elseif ($cards) {
        foreach ($cards as $card) update_category_card($card, $xpath, $entry);
    } else {
        $sample = first_node($xpath, '//div[contains(concat(" ",normalize-space(@class)," ")," catalog-section-list__col ")]');
        if ($sample && $sample->parentNode) {
            $clone = $sample->cloneNode(true);
            if ($clone instanceof DOMElement) {
                $clone->setAttribute('id', 'alym_cat_' . substr(hash('sha256', $entry['slug']), 0, 10));
                update_category_card($clone, $xpath, $entry);
                $sample->parentNode->appendChild($clone);
            }
        }
    }
    save_dom_file($relative, $dom);
}

function category_save(array &$catalog, ?string $oldSlug): string {
    $creating = $oldSlug === null;
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Укажите название категории.');
    if ($creating) {
        $slug = unique_slug((string)($_POST['slug'] ?? $name), $catalog['categories']);
        $relative = 'catalog/' . $slug . '/index.html';
        $directory = dirname(site_path($relative));
        if (!is_dir($directory)) mkdir($directory, 0770, true);
        copy(site_path('catalog/dvernye-korobki/index.html'), site_path($relative));
        chmod(site_path($relative), 0640);
        $entry = array('slug' => $slug, 'path' => $relative, 'route' => '/catalog/' . $slug . '/', 'image' => '');
        [$cleanDom, $cleanXpath] = load_dom_file($relative);
        foreach (iterator_to_array($cleanXpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," col-xl-4 ")][.//*[contains(concat(" ",normalize-space(@class)," ")," catalog-section-tile__item ")]]') ?: array()) as $card) {
            $card->parentNode?->removeChild($card);
        }
        save_dom_file($relative, $cleanDom);
        $oldRoute = '';
    } else {
        if (!isset($catalog['categories'][$oldSlug])) throw new RuntimeException('Категория не найдена.');
        $entry = $catalog['categories'][$oldSlug];
        $slug = $oldSlug;
        $oldRoute = $entry['route'];
    }
    $current = category_read($entry);
    $uploaded = upload_image('image_upload', 'category-' . str_replace('/', '-', $slug));
    [$dom, $xpath] = load_dom_file($entry['path']);
    $seoTitle = trim((string)($_POST['seo_title'] ?? '')) ?: $name;
    $seoDescription = trim((string)($_POST['seo_description'] ?? ''));
    set_title($xpath, $dom, $seoTitle);
    set_meta($xpath, $dom, 'description', $seoDescription);
    set_property_meta($xpath, $dom, 'og:description', $seoDescription);
    $h1 = first_node($xpath, '//h1');
    if ($h1) $h1->textContent = $name;
    save_dom_file($entry['path'], $dom);
    $entry['name'] = $name;
    $entry['image'] = $uploaded ?: ($current['image'] ?: '/images/logo.png');
    $catalog['categories'][$slug] = $entry;
    sync_category_card($entry, $oldRoute);
    save_catalog($catalog);
    return $slug;
}

function category_delete(array &$catalog, string $slug): void {
    if (!isset($catalog['categories'][$slug])) throw new RuntimeException('Категория не найдена.');
    $entry = $catalog['categories'][$slug];
    sync_category_card($entry, $entry['route'], true);
    $directory = dirname(site_path($entry['path']));
    if (is_dir($directory)) {
        $trash = ALYM_STORAGE_DIR . '/trash/' . date('Y-m-d_His') . '/category-' . str_replace('/', '-', $slug);
        if (!is_dir(dirname($trash))) mkdir(dirname($trash), 0770, true);
        rename($directory, $trash);
    }
    unset($catalog['categories'][$slug]);
    foreach ($catalog['products'] as &$product) if (($product['category'] ?? '') === $slug) $product['category'] = '';
    unset($product);
    save_catalog($catalog);
}

function page_read(array $entry): array {
    [$dom, $xpath] = load_dom_file($entry['path']);
    $content = first_node($xpath, class_query('content-box')) ?: first_node($xpath, '//main') ?: first_node($xpath, '//body');
    $images = array();
    foreach ($xpath->query('//img[@src]') ?: array() as $i => $image) {
        if ($image instanceof DOMElement) $images[] = array('index' => $i, 'src' => $image->getAttribute('src'), 'alt' => $image->getAttribute('alt'));
    }
    return array(
        'name' => text_of(first_node($xpath, '//h1')) ?: $entry['name'],
        'seo_title' => text_of(first_node($xpath, '//title')),
        'seo_description' => meta_content($xpath, 'description'),
        'content' => $content ? inner_html($content) : '',
        'content_mode' => $content?->tagName ?? 'body',
        'images' => $images,
    );
}

function page_save(array &$catalog, string $path): void {
    if (!isset($catalog['pages'][$path])) throw new RuntimeException('Страница не найдена.');
    $entry = $catalog['pages'][$path];
    [$dom, $xpath] = load_dom_file($entry['path']);
    $name = trim((string)($_POST['name'] ?? ''));
    $seoTitle = trim((string)($_POST['seo_title'] ?? '')) ?: $name;
    $seoDescription = trim((string)($_POST['seo_description'] ?? ''));
    set_title($xpath, $dom, $seoTitle);
    set_meta($xpath, $dom, 'description', $seoDescription);
    set_property_meta($xpath, $dom, 'og:title', $seoTitle);
    set_property_meta($xpath, $dom, 'og:description', $seoDescription);
    $h1 = first_node($xpath, '//h1');
    if ($h1 && $name !== '') $h1->textContent = $name;
    $content = first_node($xpath, class_query('content-box')) ?: first_node($xpath, '//main') ?: first_node($xpath, '//body');
    if ($content && isset($_POST['content'])) set_inner_html($content, (string)$_POST['content']);
    $imageNodes = $xpath->query('//img[@src]');
    if ($imageNodes) {
        foreach (iterator_to_array($imageNodes) as $i => $image) {
            if (!$image instanceof DOMElement) continue;
            $uploaded = upload_image('replace_image_' . $i, 'page-' . str_replace('/', '-', dirname($path)) . '-' . $i);
            if ($uploaded) {
                $image->setAttribute('src', $uploaded);
                $image->removeAttribute('srcset');
                $image->removeAttribute('data-src');
            }
        }
    }
    save_dom_file($entry['path'], $dom);
    if ($name !== '') $catalog['pages'][$path]['name'] = $name;
    save_catalog($catalog);
}

function media_files(): array {
    $directory = site_path('upload/admin');
    if (!is_dir($directory)) return array();
    $files = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $relative = '/' . str_replace('\\', '/', substr($file->getPathname(), strlen(ALYM_SITE_ROOT) + 1));
        $files[] = array('path' => $relative, 'size' => $file->getSize(), 'mtime' => $file->getMTime());
    }
    usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $files;
}

function load_settings(): array {
    $defaults = array(
        'company_name' => 'Производитель алюминиевого профиля',
        'phone' => '8 (495) 664-30-04',
        'phone_link' => '+74956643004',
        'email' => '',
        'lead_email' => 'info@alymprofi.ru',
        'address' => "140015, Московская область<br>\nЛюберецкий городской округ,<br>г. Люберцы, ул. Преображенская, д. 13",
        'logo' => '/upload/main/16a/64wrireoocf77w5r5e36i88mmgt5strp.svg',
    );
    $path = ALYM_STORAGE_DIR . '/settings.json';
    if (is_file($path)) {
        $data = json_decode((string)file_get_contents($path), true);
        if (is_array($data)) return $data + $defaults;
    }
    return $defaults;
}

function save_settings_file(array $settings): void {
    file_put_contents(ALYM_STORAGE_DIR . '/settings.json', json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function replace_sitewide(array $replacements): int {
    $changed = 0;
    $extensions = array('html', 'prod', 'tag');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ALYM_SITE_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) continue;
        if (str_contains($file->getPathname(), '/admin/')) continue;
        $source = (string)file_get_contents($file->getPathname());
        $updated = str_replace(array_keys($replacements), array_values($replacements), $source);
        if ($updated === $source) continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(ALYM_SITE_ROOT) + 1));
        backup_file($relative);
        file_put_contents($file->getPathname(), $updated, LOCK_EX);
        $changed++;
    }
    return $changed;
}

function global_settings_save(): int {
    $old = load_settings();
    $new = array(
        'company_name' => trim((string)($_POST['company_name'] ?? $old['company_name'])),
        'phone' => trim((string)($_POST['phone'] ?? $old['phone'])),
        'phone_link' => trim((string)($_POST['phone_link'] ?? $old['phone_link'])),
        'email' => trim((string)($_POST['email'] ?? $old['email'])),
        'lead_email' => trim((string)($_POST['lead_email'] ?? $old['lead_email'])),
        'address' => trim((string)($_POST['address'] ?? $old['address'])),
        'logo' => $old['logo'],
    );
    $uploaded = upload_image('logo_upload', 'logo');
    if ($uploaded) $new['logo'] = $uploaded;
    $replacements = array();
    foreach (array('company_name', 'phone', 'phone_link', 'email', 'address', 'logo') as $key) {
        if (($old[$key] ?? '') !== '' && ($old[$key] ?? '') !== ($new[$key] ?? '')) $replacements[$old[$key]] = $new[$key];
    }
    $count = $replacements ? replace_sitewide($replacements) : 0;
    save_settings_file($new);
    return $count;
}
