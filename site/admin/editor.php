<?php
declare(strict_types=1);

function text_of(?DOMElement $node): string {
    return $node ? trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '') : '';
}

function meta_content(DOMXPath $xpath, string $name): string {
    return first_node($xpath, '//meta[@name="' . $name . '"]')?->getAttribute('content') ?? '';
}

function product_content_path(array $entry): string {
    return ALYM_STORAGE_DIR . '/product-content/' . hash('sha256', (string)($entry['slug'] ?? $entry['path'])) . '.json';
}

function saved_product_content(array $entry): ?array {
    $path = product_content_path($entry);
    if (!is_file($path)) return null;
    $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data['product'] ?? null) || ($data['entry']['path'] ?? '') !== $entry['path']) {
        throw new RuntimeException('Сохранённые данные товара повреждены.');
    }
    return $data;
}

function save_product_content(array $entry, array $product): void {
    $data = array('saved_at' => date(DATE_ATOM), 'entry' => $entry, 'product' => $product);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $history = ALYM_STORAGE_DIR . '/product-history/' . hash('sha256', $entry['slug']) . '/' . date('Ymd_His') . '-' . bin2hex(random_bytes(4)) . '.json';
    atomic_write($history, $json, 0600);
    atomic_write(product_content_path($entry), $json, 0600);
}

function product_read(array $entry, bool $publishedOnly = false): array {
    if (!$publishedOnly && ($saved = saved_product_content($entry))) return $saved['product'];
    [$dom, $xpath] = load_dom_file($entry['path']);
    $title = text_of(first_node($xpath, '//title'));
    $h1 = text_of(first_node($xpath, '//h1'));
    $status = text_of(first_node($xpath, '//*[contains(concat(" ",normalize-space(@class)," ")," catalog-detail__status-nal ") or contains(concat(" ",normalize-space(@class)," ")," catalog-detail__status-zakaz ")]'));
    $price = text_of(first_node($xpath, class_query('pricespace')));
    $unit = text_of(first_node($xpath, class_query('catalog-detail__price-rub')));
    $article = preg_replace('/^Арт\.\s*/u', '', text_of(first_node($xpath, class_query('catalog-detail__article')))) ?? '';
    $summary = first_node($xpath, class_query('catalog-detail__preview'));
    $description = first_node($xpath, class_query('catalog-detail__text'));
    $videos = array();
    foreach (array_filter(array($summary, $description)) as $videoContainer) {
        foreach ($xpath->query('.//iframe[@src] | .//video[@src] | .//video/source[@src] | .//a[@href]', $videoContainer) ?: array() as $video) {
            if (!$video instanceof DOMElement) continue;
            $src = trim($video->tagName === 'a' ? $video->getAttribute('href') : $video->getAttribute('src'));
            if ($src !== '' && is_product_video_url($src)) $videos[] = $src;
        }
    }
    $images = array();
    $galleryImageQuery = '//div[contains(concat(" ",normalize-space(@class)," ")," catalog-detail__img-box ")]//img'
        . ' | //div[contains(concat(" ",normalize-space(@class)," ")," catalog-detail__img-view ")]//img';
    foreach ($xpath->query($galleryImageQuery) ?: array() as $image) {
        if (!$image instanceof DOMElement) continue;
        $src = trim($image->getAttribute('src'));
        for ($parent = $image->parentNode; $parent; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && $parent->tagName === 'a' && trim($parent->getAttribute('href')) !== '') {
                $src = trim($parent->getAttribute('href'));
                break;
            }
            if ($parent instanceof DOMElement && str_contains(' ' . $parent->getAttribute('class') . ' ', ' catalog-detail__img-box ')) break;
        }
        if ($src !== '' && !in_array($src, $images, true)) $images[] = $src;
    }
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
        'article' => trim($article),
        'status' => $status ?: 'В наличии',
        'summary' => $summary ? strip_product_video_html(inner_html($summary)) : '',
        'description' => $description ? strip_product_video_html(inner_html($description)) : '',
        'video_url' => $videos[0] ?? '',
        'videos' => array_values(array_unique($videos)),
        'reviews' => product_reviews_read($xpath),
        'attributes' => $attributes,
        'image' => $images[0] ?? '',
        'images' => $images,
        'seo_title' => $title,
        'seo_description' => meta_content($xpath, 'description'),
    );
}

function apply_saved_product(DOMDocument $dom, DOMXPath $xpath, array $entry, array $saved, array $catalog): void {
    $values = $saved['product'];
    $values['description'] = append_product_video_html($values['description'], (string)$values['video_url']);
    apply_product_values($dom, $xpath, $values);
    apply_product_gallery($dom, $xpath, $values['images'], $values['name']);
    apply_product_reviews($dom, $xpath, $values['reviews'], $values['name']);
    apply_product_category($xpath, $catalog['categories'][$entry['category'] ?? ''] ?? null);
}

function is_product_video_url(string $url): bool {
    if (is_direct_product_video($url)) return true;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    foreach (array('rutube.ru', 'youtube.com', 'youtube-nocookie.com', 'youtu.be', 'vimeo.com', 'vkvideo.ru', 'vk.com') as $allowed) {
        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) return true;
    }
    return false;
}

function is_direct_product_video(string $url): bool {
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (!preg_match('~\.(?:mp4|webm|ogv)$~i', $path)) return false;
    return (str_starts_with($url, '/upload/') && !str_contains($url, '..'))
        || in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), array('https', 'http'), true);
}

function strip_product_video_html(string $html): string {
    if (trim($html) === '') return '';

    $fragment = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $fragment->loadHTML('<?xml encoding="UTF-8"><div id="alym-video-fragment">' . $html . '</div>', LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors();
    $container = $fragment->getElementById('alym-video-fragment');
    if (!$container) return $html;

    $fragmentXpath = new DOMXPath($fragment);
    $remove = array();
    foreach ($fragmentXpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," product-video-embed ")] | .//iframe[@src] | .//video | .//a[@href]', $container) ?: array() as $node) {
        if (!$node instanceof DOMElement) continue;
        if ($node->tagName === 'video' || str_contains(' ' . $node->getAttribute('class') . ' ', ' product-video-embed ')) {
            $remove[] = $node;
            continue;
        }
        $url = $node->tagName === 'a' ? $node->getAttribute('href') : $node->getAttribute('src');
        if (is_product_video_url($url)) $remove[] = $node;
    }
    foreach ($remove as $node) $node->parentNode?->removeChild($node);

    return inner_html($container);
}

function normalize_product_video_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (is_direct_product_video($url)) return $url;
    if (!filter_var($url, FILTER_VALIDATE_URL) || !is_product_video_url($url)) {
        throw new RuntimeException('Укажите корректную ссылку на видео RuTube, YouTube, Vimeo или VK Видео.');
    }

    if (preg_match('~rutube\.ru/(?:play/embed|video)/([a-zA-Z0-9_-]+)~i', $url, $match)) {
        return 'https://rutube.ru/play/embed/' . $match[1] . '/';
    }
    if (preg_match('~youtu\.be/([a-zA-Z0-9_-]+)~i', $url, $match)
        || preg_match('~youtube(?:-nocookie)?\.com/(?:embed|shorts)/([a-zA-Z0-9_-]+)~i', $url, $match)) {
        return 'https://www.youtube-nocookie.com/embed/' . $match[1];
    }
    if (str_contains($url, 'youtube.com/watch')) {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        if (!empty($query['v']) && preg_match('/^[a-zA-Z0-9_-]+$/', (string)$query['v'])) {
            return 'https://www.youtube-nocookie.com/embed/' . $query['v'];
        }
    }
    if (preg_match('~vimeo\.com/(?:video/)?([0-9]+)~i', $url, $match)) {
        return 'https://player.vimeo.com/video/' . $match[1];
    }
    if (str_contains($url, 'video_ext.php')) return $url;

    throw new RuntimeException('Для этого видео нужна ссылка для встраивания.');
}

function append_product_video_html(string $html, string $videoUrl): string {
    $html = trim(strip_product_video_html($html));
    if ($videoUrl === '') return $html;
    $src = htmlspecialchars($videoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (is_direct_product_video($videoUrl)) {
        return $html . '<div class="product-video-embed"><video src="' . $src . '" controls preload="metadata" title="Видео о товаре"></video></div>';
    }
    return $html . '<div class="product-video-embed"><iframe src="' . $src . '" title="Видео о товаре" loading="lazy" allow="clipboard-write; autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>';
}

function product_reviews_read(DOMXPath $xpath): array {
    // Imported pages may contain more than one old review block. Read and
    // replace all of them rather than leaving a second copy on the page.
    $items = $xpath->query(class_query('product-managed-review') . ' | ' . class_query('portfolio-detail'));
    $reviews = array();
    foreach ($items ?: array() as $item) {
        if (!$item instanceof DOMElement) continue;
        $isManaged = str_contains(' ' . $item->getAttribute('class') . ' ', ' product-managed-review ');
        $authorNode = $xpath->query($isManaged
            ? './/*[contains(concat(" ",normalize-space(@class)," ")," product-managed-review__author ")]'
            : './/*[contains(concat(" ",normalize-space(@class)," ")," client-text ")]', $item)?->item(0);
        $author = trim(preg_replace('/^Автор:\s*/u', '', $authorNode?->textContent ?? '') ?? '');
        $ratingNode = $xpath->query($isManaged
            ? './/*[contains(concat(" ",normalize-space(@class)," ")," product-managed-review__rating ")]'
            : './/*[contains(concat(" ",normalize-space(@class)," ")," rating ")]', $item)?->item(0);
        $rating = max(1, min(5, (int)($ratingNode instanceof DOMElement ? ($ratingNode->getAttribute('data-rating') ?: $ratingNode->getAttribute('value') ?: 5) : 5)));
        $textNode = $xpath->query($isManaged
            ? './/*[contains(concat(" ",normalize-space(@class)," ")," product-managed-review__text ")]'
            : './/*[contains(concat(" ",normalize-space(@class)," ")," review-tex-padding ")]', $item)?->item(0);
        $paragraphs = array();
        if ($textNode instanceof DOMElement) {
            $copy = $textNode->cloneNode(true);
            foreach (iterator_to_array($copy->getElementsByTagName('br')) as $break) $break->parentNode?->replaceChild($copy->ownerDocument->createTextNode("\n"), $break);
            foreach ($copy->getElementsByTagName('p') as $paragraph) {
                $paragraphText = trim($paragraph->textContent);
                if ($paragraphText !== '') $paragraphs[] = $paragraphText;
            }
        }
        $text = $paragraphs ? implode("\n\n", $paragraphs) : ($textNode instanceof DOMElement ? text_of($textNode) : '');
        $images = array();
        foreach ($xpath->query('.//img[contains(concat(" ",normalize-space(@class)," ")," portfolio-detail__photo-img ") or contains(concat(" ",normalize-space(@class)," ")," product-managed-review__image ")]', $item) ?: array() as $image) {
            if (!$image instanceof DOMElement) continue;
            $src = trim($image->getAttribute('src'));
            if ($src !== '' && !in_array($src, $images, true)) $images[] = $src;
        }
        if ($author !== '' || $text !== '') $reviews[] = array('author' => $author, 'rating' => $rating, 'text' => $text, 'images' => $images);
    }
    return $reviews;
}

function append_review_text(DOMDocument $dom, DOMElement $container, string $text): void {
    $blocks = preg_split('/(?:\r?\n){2,}/u', trim($text)) ?: array();
    foreach ($blocks as $block) {
        if (trim($block) === '') continue;
        $paragraph = $dom->createElement('p');
        $lines = preg_split('/\r?\n/u', trim($block)) ?: array();
        foreach ($lines as $index => $line) {
            if ($index > 0) $paragraph->appendChild($dom->createElement('br'));
            $paragraph->appendChild($dom->createTextNode($line));
        }
        $container->appendChild($paragraph);
    }
}

function apply_product_reviews(DOMDocument $dom, DOMXPath $xpath, array $reviews, string $productName): void {
    $existing = first_node($xpath, class_query('portfolio-list'));
    $existingParent = $existing?->parentNode;
    $nextSibling = $existing?->nextSibling;
    foreach (iterator_to_array($xpath->query(class_query('portfolio-list') . ' | ' . class_query('product-managed-review') . ' | ' . class_query('portfolio-detail')) ?: array()) as $oldReview) {
        $oldReview->parentNode?->removeChild($oldReview);
    }
    sync_product_review_schema($xpath, $reviews);
    if (!$reviews) return;

    $reviewForm = $xpath->query('//form[@id="review-add"]')?->item(0);
    $reviewBox = $reviewForm instanceof DOMElement ? find_card_ancestor($reviewForm, 'review') : null;
    $parent = $reviewBox?->parentNode ?: $existingParent;
    if (!$parent) {
        $parent = first_node($xpath, class_query('content-box'));
    }
    if (!$parent) throw new RuntimeException('Не найден блок карточки для сохранения отзывов.');

    $list = $dom->createElement('section');
    $list->setAttribute('class', 'portfolio-list product-managed-reviews');
    $heading = $dom->createElement('h2', 'Отзывы');
    $heading->setAttribute('class', 'h2-section');
    $list->appendChild($heading);
    $items = $dom->createElement('div');
    $items->setAttribute('class', 'product-managed-reviews__list');
    $list->appendChild($items);

    foreach ($reviews as $review) {
        $card = $dom->createElement('article');
        $card->setAttribute('class', 'product-managed-review');
        $header = $dom->createElement('div');
        $header->setAttribute('class', 'product-managed-review__header');
        $author = $dom->createElement('strong');
        $author->appendChild($dom->createTextNode(trim((string)($review['author'] ?? ''))));
        $author->setAttribute('class', 'product-managed-review__author');
        $ratingValue = max(1, min(5, (int)($review['rating'] ?? 5)));
        $rating = $dom->createElement('span', str_repeat('★', $ratingValue) . str_repeat('☆', 5 - $ratingValue));
        $rating->setAttribute('class', 'product-managed-review__rating');
        $rating->setAttribute('data-rating', (string)$ratingValue);
        $rating->setAttribute('aria-label', 'Оценка ' . $ratingValue . ' из 5');
        $header->append($author, $rating);
        $card->appendChild($header);

        $text = $dom->createElement('div');
        $text->setAttribute('class', 'product-managed-review__text');
        append_review_text($dom, $text, (string)($review['text'] ?? ''));
        $card->appendChild($text);

        $images = array_values(array_filter(array_map('strval', $review['images'] ?? array())));
        if ($images) {
            $gallery = $dom->createElement('div');
            $gallery->setAttribute('class', 'product-managed-review__images');
            foreach ($images as $src) {
                $anchor = $dom->createElement('a');
                $anchor->setAttribute('href', $src);
                $anchor->setAttribute('class', 'gallery');
                $anchor->setAttribute('data-fancybox', 'review_gallery');
                $image = $dom->createElement('img');
                $image->setAttribute('src', $src);
                $image->setAttribute('alt', 'Отзыв о ' . $productName);
                $image->setAttribute('class', 'product-managed-review__image');
                $anchor->appendChild($image);
                $gallery->appendChild($anchor);
            }
            $card->appendChild($gallery);
        }
        $items->appendChild($card);
    }

    if ($reviewBox && $reviewBox->parentNode === $parent) {
        $parent->insertBefore($list, $reviewBox);
    } elseif ($existingParent === $parent && $nextSibling?->parentNode === $parent) {
        $parent->insertBefore($list, $nextSibling);
    } else {
        $parent->appendChild($list);
    }
}

function sync_product_review_schema(DOMXPath $xpath, array $reviews): void {
    foreach ($xpath->query('//script[@type="application/ld+json"]') ?: array() as $script) {
        $json = json_decode($script->textContent, true);
        if (!is_array($json) || ($json['@type'] ?? '') !== 'Product') continue;
        unset($json['review'], $json['aggregateRating']);
        if ($reviews) {
            $total = 0;
            $json['review'] = array();
            foreach ($reviews as $review) {
                $rating = max(1, min(5, (int)($review['rating'] ?? 5)));
                $total += $rating;
                $json['review'][] = array(
                    '@type' => 'Review',
                    'author' => array('@type' => 'Person', 'name' => (string)$review['author']),
                    'reviewBody' => (string)$review['text'],
                    'reviewRating' => array('@type' => 'Rating', 'ratingValue' => $rating, 'bestRating' => 5, 'worstRating' => 1),
                );
            }
            $json['aggregateRating'] = array('@type' => 'AggregateRating', 'ratingValue' => round($total / count($reviews), 2), 'reviewCount' => count($reviews));
        }
        $script->textContent = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }
}

function product_revision(array $entry): string {
    return hash('sha256', (string)json_encode(product_read($entry), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function apply_product_gallery(DOMDocument $dom, DOMXPath $xpath, array $images, string $name): void {
    $box = first_node($xpath, class_query('catalog-detail__img-box'));
    if (!$box) return;

    foreach (iterator_to_array($xpath->query(class_query('catalog-detail__img-view')) ?: array()) as $legacyGallery) {
        if ($legacyGallery instanceof DOMElement) $legacyGallery->parentNode?->removeChild($legacyGallery);
    }

    while ($box->firstChild) $box->removeChild($box->firstChild);
    $classes = preg_split('/\s+/', trim($box->getAttribute('class'))) ?: array();
    $classes = array_values(array_filter($classes, static fn(string $class): bool => $class !== 'clamp-profile-gallery'));
    if (count($images) > 1) $classes[] = 'clamp-profile-gallery';
    $box->setAttribute('class', implode(' ', array_unique($classes)));

    foreach ($images as $src) {
        $src = trim((string)$src);
        if ($src === '') continue;
        $anchor = $dom->createElement('a');
        $anchor->setAttribute('href', $src);
        $anchor->setAttribute('class', count($images) > 1 ? 'gallery clamp-profile-gallery__item' : 'gallery');
        $anchor->setAttribute('data-fancybox', 'gallery_product');
        $image = $dom->createElement('img');
        $image->setAttribute('src', $src);
        $image->setAttribute('alt', $name);
        $image->setAttribute('title', $name);
        $image->setAttribute('class', 'catalog-detail__img-img');
        $anchor->appendChild($image);
        $box->appendChild($anchor);
    }
    foreach ($xpath->query('//script[@type="application/ld+json"]') ?: array() as $script) {
        $json = json_decode($script->textContent, true);
        if (!is_array($json) || ($json['@type'] ?? '') !== 'Product') continue;
        $json['image'] = array_values($images);
        $script->textContent = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }
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

    $status = first_node($xpath, '//*[contains(concat(" ",normalize-space(@class)," ")," catalog-detail__status-nal ") or contains(concat(" ",normalize-space(@class)," ")," catalog-detail__status-zakaz ")]');
    if ($status) {
        $status->textContent = trim($values['status']);
        $status->setAttribute('class', trim($values['status']) === 'Под заказ' ? 'catalog-detail__status-zakaz' : 'catalog-detail__status-nal');
    }
    $price = first_node($xpath, class_query('pricespace'));
    if ($price) $price->textContent = trim($values['price']);
    $unit = first_node($xpath, class_query('catalog-detail__price-rub'));
    if ($unit) $unit->textContent = trim($values['unit']);
    $article = first_node($xpath, class_query('catalog-detail__article'));
    if (!$article && trim($values['article']) !== '') {
        $statusBox = first_node($xpath, class_query('catalog-detail__status-box'));
        if ($statusBox?->parentNode) {
            $article = $dom->createElement('div');
            $article->setAttribute('class', 'catalog-detail__article');
            $statusBox->parentNode->insertBefore($article, $statusBox);
        }
    }
    if ($article) $article->textContent = trim($values['article']) === '' ? '' : 'Арт. ' . trim($values['article']);
    $summary = first_node($xpath, class_query('catalog-detail__preview'));
    if ($summary) set_inner_html($summary, $values['summary']);
    $description = first_node($xpath, class_query('catalog-detail__text'));
    if (!$description && trim($values['description']) !== '') {
        $tabs = first_node($xpath, class_query('catalog-detail__tabs') . '//*[contains(concat(" ",normalize-space(@class)," ")," tabs_block ")]');
        $tabList = first_node($xpath, class_query('catalog-detail__tabs-ul'));
        if ($tabs && $tabList) {
            $tabList->insertBefore($dom->createElement('li', 'Описание'), $tabList->firstChild);
            $block = $dom->createElement('div');
            $block->setAttribute('class', 'tabs_block1');
            $description = $dom->createElement('div');
            $description->setAttribute('class', 'catalog-detail__text');
            $block->appendChild($description);
            $tabs->insertBefore($block, $tabs->firstChild);
        }
    }
    if ($description) set_inner_html($description, $values['description']);

    foreach ($xpath->query('//*[@data-name]') ?: array() as $button) {
        if ($button instanceof DOMElement && !find_card_ancestor($button, 'catalog-section-tile__item')) $button->setAttribute('data-name', $name);
    }
    foreach ($xpath->query('//*[@data-price]') ?: array() as $button) {
        if ($button instanceof DOMElement && !find_card_ancestor($button, 'catalog-section-tile__item')) $button->setAttribute('data-price', trim($values['price']));
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
        $json['sku'] = trim($values['article']);
        if (isset($json['offers']) && is_array($json['offers'])) {
            $json['offers']['price'] = str_replace(array(' ', "\u{00a0}", ','), array('', '', '.'), trim($values['price']));
            $json['offers']['availability'] = trim($values['status']) === 'Под заказ' ? 'https://schema.org/PreOrder' : 'https://schema.org/InStock';
        }
        $script->textContent = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    }
}

function apply_product_category(DOMXPath $xpath, ?array $category): void {
    if (!$category) return;
    $links = $xpath->query('//ul[@itemscope and @itemtype="http://schema.org/BreadcrumbList"]/li/a');
    if (!$links || $links->length < 3) return;
    $link = $links->item($links->length - 1);
    if (!$link instanceof DOMElement) return;
    $link->setAttribute('href', (string)$category['route']);
    $link->setAttribute('title', (string)$category['name']);
    $label = $xpath->query('.//*[@itemprop="name"]', $link)?->item(0);
    if ($label) $label->textContent = (string)$category['name'];
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
    $unit = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," catalog-section-tile__price-rub ")]', $card)?->item(0);
    if ($unit) $unit->textContent = $entry['unit'] ?? 'р./шт.';
    $article = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," catalog-section-tile__article ")]', $card)?->item(0);
    if ($article) $article->textContent = empty($entry['article']) ? '' : 'Арт. ' . $entry['article'];
    $status = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," catalog-section-tile__status-nal ") or contains(concat(" ",normalize-space(@class)," ")," catalog-section-tile__status-zakaz ")]', $card)?->item(0);
    if ($status instanceof DOMElement) {
        $status->textContent = $entry['status'];
        $status->setAttribute('class', $entry['status'] === 'Под заказ' ? 'catalog-section-tile__status-zakaz' : 'catalog-section-tile__status-nal');
    }
    foreach ($xpath->query('.//*[@data-name]', $card) ?: array() as $button) if ($button instanceof DOMElement) $button->setAttribute('data-name', $entry['name']);
    foreach ($xpath->query('.//*[@data-price]', $card) ?: array() as $button) if ($button instanceof DOMElement) $button->setAttribute('data-price', $entry['price']);
}

function update_related_accessory(DOMElement $anchor, DOMXPath $xpath, array $entry): void {
    $anchor->setAttribute('href', $entry['route']);
    $image = $xpath->query('.//img', $anchor)?->item(0);
    if ($image instanceof DOMElement) {
        $image->setAttribute('src', $entry['image']);
        $image->setAttribute('alt', $entry['name']);
        $image->removeAttribute('srcset');
    }
    $name = $xpath->query('.//span', $anchor)?->item(0);
    if ($name) $name->textContent = $entry['name'];
    $price = $xpath->query('.//strong', $anchor)?->item(0);
    if ($price) $price->textContent = $entry['price'] . ' ₽';
}

function sync_popular_script(string $relative, array $entry, array $routes, bool $delete): void {
    $path = site_path($relative);
    $source = (string)file_get_contents($path);
    $updated = preg_replace_callback(
        '/(\$\([\'\"]\\.popular-section[\'\"]\)\\.html\([\'\"])(.*?)([\'\"]\);)/su',
        static function (array $match) use ($entry, $routes, $delete): string {
            $markup = $match[2];
            foreach ($routes as $route) {
                $pattern = '/<a href="' . preg_quote($route, '/') . '">[^<]*<\/a>/u';
                $replacement = $delete ? '' : '<a href="' . h($entry['route']) . '">' . h($entry['name']) . '</a>';
                $replacement = str_replace(array('\\', "\r", "\n"), array('\\\\', '\\r', '\\n'), $replacement);
                $markup = preg_replace_callback($pattern, static fn(array $anchor): string => $replacement, $markup) ?? $markup;
            }
            return $match[1] . $markup . $match[3];
        },
        $source
    ) ?? $source;
    if ($updated !== $source) {
        backup_file($relative);
        atomic_write($path, $updated);
    }
}

function sync_product_references(array $entry, string $oldRoute = '', bool $delete = false): void {
    $routes = array_values(array_unique(array_filter(array($oldRoute, $entry['route']))));
    $files = array('index.html');
    foreach (glob(ALYM_SITE_ROOT . '/catalog/*.prod') ?: array() as $path) {
        $files[] = 'catalog/' . basename($path);
    }
    foreach (glob(ALYM_SITE_ROOT . '/catalog/*.tag') ?: array() as $path) $files[] = 'catalog/' . basename($path);
    foreach (glob(ALYM_SITE_ROOT . '/_mirror/query/*.html') ?: array() as $path) $files[] = '_mirror/query/' . basename($path);
    foreach ($files as $relative) {
        $source = (string)file_get_contents(site_path($relative));
        $hasReference = false;
        foreach ($routes as $route) {
            if (str_contains($source, $route)) {
                $hasReference = true;
                break;
            }
        }
        if (!$hasReference) continue;

        [$dom, $xpath] = load_dom_file($relative);
        $anchors = array();
        foreach ($routes as $route) {
            foreach ($xpath->query('//a[@href="' . $route . '"]') ?: array() as $anchor) {
                if ($anchor instanceof DOMElement) $anchors[spl_object_id($anchor)] = $anchor;
            }
        }
        $changed = false;
        foreach ($anchors as $anchor) {
            $relatedClass = ' ' . $anchor->getAttribute('class') . ' ';
            $card = find_card_ancestor($anchor, 'catalog-section-tile__item');
            if ($delete) {
                if ($card) {
                    $column = find_card_ancestor($card, 'col-xl-3') ?: find_card_ancestor($card, 'col-xl-4') ?: $card;
                    $column->parentNode?->removeChild($column);
                } elseif (str_contains($relatedClass, ' related-accessories__item ')) {
                    $anchor->parentNode?->removeChild($anchor);
                }
                $changed = true;
            } elseif ($card) {
                update_product_card($card, $xpath, $entry);
                $changed = true;
            } elseif (str_contains($relatedClass, ' related-accessories__item ')) {
                update_related_accessory($anchor, $xpath, $entry);
                $changed = true;
            }
        }
        if ($changed) save_dom_file($relative, $dom);
        sync_popular_script($relative, $entry, $routes, $delete);
    }
}

function sync_product_cards(array $catalog, array $entry, string $oldRoute = '', bool $delete = false): void {
    $routes = array_values(array_unique(array_filter(array($oldRoute, $entry['route']))));
    $files = array();
    $pagination = is_file(ALYM_SITE_ROOT . '/pagination-routes.php') ? require ALYM_SITE_ROOT . '/pagination-routes.php' : array();
    foreach ($catalog['categories'] as $slug => $category) {
        $files[$category['path']] = $slug;
        foreach ($pagination as $route => $relative) {
            if (parse_url($route, PHP_URL_PATH) === parse_url($category['route'], PHP_URL_PATH)) $files[$relative] = $slug;
        }
    }
    $alreadyListed = false;
    foreach ($files as $relative => $slug) {
        if ($slug !== ($entry['category'] ?? '') || !is_file(site_path($relative))) continue;
        [$listingDom, $listingXpath] = load_dom_file($relative);
        foreach ($routes as $route) {
            foreach ($listingXpath->query('//a[@href="' . $route . '"]') ?: array() as $anchor) {
                if (find_card_ancestor($anchor, 'catalog-section-tile__item')) $alreadyListed = true;
            }
        }
    }
    foreach ($files as $relative => $slug) {
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
        } elseif (!$alreadyListed && $relative === $catalog['categories'][$slug]['path']) {
            $sample = first_node($xpath, '//div[contains(concat(" ",normalize-space(@class)," ")," col-xl-4 ")][.//*[contains(concat(" ",normalize-space(@class)," ")," catalog-section-tile__item ")]]');
            $row = $sample?->parentNode;
            if ($sample && $row) {
                $clone = $sample->cloneNode(true);
                if ($clone instanceof DOMElement) {
                    $clone->setAttribute('id', 'alym_' . substr(hash('sha256', $entry['slug']), 0, 12));
                    update_product_card($clone, $xpath, $entry);
                    $row->appendChild($clone);
                    $changed = true;
                    $alreadyListed = true;
                }
            }
        }
        if ($changed) save_dom_file($relative, $dom);
    }
}

function valid_review_image_path(string $path): bool {
    return str_starts_with($path, '/')
        && !str_contains($path, '..')
        && (bool)preg_match('~\.(?:jpe?g|png|webp|gif)(?:\?.*)?$~i', $path);
}

function product_reviews_from_post(): array {
    $authors = is_array($_POST['review_author'] ?? null) ? $_POST['review_author'] : array();
    $ratings = is_array($_POST['review_rating'] ?? null) ? $_POST['review_rating'] : array();
    $texts = is_array($_POST['review_text'] ?? null) ? $_POST['review_text'] : array();
    $existingImages = is_array($_POST['review_existing_image'] ?? null) ? $_POST['review_existing_image'] : array();
    $removedImages = is_array($_POST['review_remove_image'] ?? null) ? $_POST['review_remove_image'] : array();
    $deleted = is_array($_POST['review_delete'] ?? null) ? $_POST['review_delete'] : array();
    $keys = array_values(array_unique(array_merge(array_keys($authors), array_keys($texts))));
    $reviews = array();

    foreach ($keys as $key) {
        $key = (string)$key;
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $key) || !empty($deleted[$key])) continue;
        $author = trim((string)($authors[$key] ?? ''));
        $text = trim((string)($texts[$key] ?? ''));
        if ($author === '' && $text === '') continue;
        if ($author === '') throw new RuntimeException('Укажите автора отзыва.');
        if ($text === '') throw new RuntimeException('Укажите текст отзыва.');

        $remove = array_map('strval', is_array($removedImages[$key] ?? null) ? $removedImages[$key] : array());
        $images = array();
        foreach (is_array($existingImages[$key] ?? null) ? $existingImages[$key] : array() as $path) {
            $path = trim((string)$path);
            if (valid_review_image_path($path) && !in_array($path, $remove, true)) $images[] = $path;
        }
        $images = array_values(array_unique(array_merge($images, upload_images('review_upload_' . $key, 'review-' . $key))));
        $reviews[] = array(
            'author' => $author,
            'rating' => max(1, min(5, (int)($ratings[$key] ?? 5))),
            'text' => $text,
            'images' => $images,
        );
    }
    return $reviews;
}

function product_save(array &$catalog, ?string $oldSlug): string {
    $creating = $oldSlug === null;
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Укажите название товара.');
    if ($creating) {
        $slug = unique_slug((string)($_POST['slug'] ?? $name), $catalog['products']);
        $relative = 'catalog/' . $slug . '.prod';
        $entry = array('slug' => $slug, 'path' => $relative, 'route' => '/catalog/' . $slug . '.prod');
        $oldRoute = '';
    } else {
        if (!isset($catalog['products'][$oldSlug])) throw new RuntimeException('Товар не найден.');
        $entry = $catalog['products'][$oldSlug];
        $revision = (string)($_POST['product_revision'] ?? '');
        if ($revision === '' || !hash_equals(product_revision($entry), $revision)) {
            throw new RuntimeException('Карточка изменилась после открытия формы. Откройте её заново, чтобы не перезаписать новые правки и не вернуть удалённые фото, видео или отзывы.');
        }
        $slug = $oldSlug;
        $oldRoute = $entry['route'];
    }
    if (!array_key_exists('reviews_present', $_POST) || !array_key_exists('video_url', $_POST)) {
        throw new RuntimeException('Форма получена не полностью. Откройте карточку заново и повторите сохранение.');
    }
    $sourceEntry = $creating ? array('path' => 'catalog/alyuminievaya-dvernaya-korobka-l.prod') : $entry;
    $current = product_read($sourceEntry);
    if ($creating) {
        $current['image'] = '';
        $current['images'] = array();
    }
    $currentImages = array_values(array_filter(array_map('strval', $current['images'] ?? array($current['image'] ?? ''))));
    $removeImages = array_map('intval', is_array($_POST['remove_image'] ?? null) ? $_POST['remove_image'] : array());
    $selectedMain = max(0, (int)($_POST['main_image'] ?? 0));
    $selectedMainPath = $currentImages[$selectedMain] ?? '';
    $gallery = array();
    foreach ($currentImages as $index => $imagePath) {
        if (!in_array($index, $removeImages, true)) $gallery[] = $imagePath;
    }
    $uploadedImages = upload_images('gallery_upload', $slug);
    $legacyUpload = upload_image('image_upload', $slug);
    if ($legacyUpload) $uploadedImages[] = $legacyUpload;
    $gallery = array_values(array_unique(array_merge($gallery, $uploadedImages)));
    if (!$gallery) throw new RuntimeException('Добавьте хотя бы одно изображение товара.');

    $mainImage = '';
    if (!empty($_POST['make_new_main']) && $uploadedImages) {
        $mainImage = $uploadedImages[0];
    } elseif ($selectedMainPath !== '' && in_array($selectedMainPath, $gallery, true)) {
        $mainImage = $selectedMainPath;
    } elseif ($gallery) {
        $mainImage = $gallery[0];
    }
    if ($mainImage !== '') {
        $gallery = array_values(array_filter($gallery, static fn(string $path): bool => $path !== $mainImage));
        array_unshift($gallery, $mainImage);
    }
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
    $summary = strip_product_video_html((string)($_POST['summary'] ?? ''));
    $description = strip_product_video_html((string)($_POST['description'] ?? ''));
    $videoSource = array_key_exists('video_url', $_POST) ? (string)$_POST['video_url'] : (string)($current['video_url'] ?? '');
    if (!empty($_POST['remove_videos'])) $videoSource = '';
    $videoUrl = normalize_product_video_url($videoSource);
    $description = append_product_video_html($description, $videoUrl);
    $reviews = array_key_exists('reviews_present', $_POST) ? product_reviews_from_post() : ($current['reviews'] ?? array());
    $category = (string)($_POST['category'] ?? '');
    $categoryEntry = isset($catalog['categories'][$category]) ? $catalog['categories'][$category] : null;
    $values = array(
        'name' => $name,
        'price' => trim((string)($_POST['price'] ?? '0')),
        'unit' => trim((string)($_POST['unit'] ?? 'р./шт.')),
        'article' => trim((string)($_POST['article'] ?? '')),
        'status' => (string)($_POST['status'] ?? 'В наличии'),
        'summary' => $summary,
        'description' => $description,
        'attributes' => $attributes,
        'image' => $mainImage,
        'seo_title' => trim((string)($_POST['seo_title'] ?? '')),
        'seo_description' => trim((string)($_POST['seo_description'] ?? '')),
    );
    [$dom, $xpath] = load_dom_file($sourceEntry['path']);
    apply_product_values($dom, $xpath, $values);
    apply_product_category($xpath, $categoryEntry);
    apply_product_gallery($dom, $xpath, $gallery, $name);
    apply_product_reviews($dom, $xpath, $reviews, $name);
    $entry += array('category' => '');
    $entry['name'] = $values['name'];
    $entry['price'] = $values['price'];
    $entry['unit'] = $values['unit'];
    $entry['article'] = $values['article'];
    $entry['status'] = $values['status'];
    $entry['image'] = $values['image'];
    $entry['category'] = isset($catalog['categories'][$category]) ? $category : '';
    $content = $values + array('images' => $gallery, 'reviews' => $reviews, 'video_url' => $videoUrl, 'videos' => $videoUrl === '' ? array() : array($videoUrl));
    $content['description'] = strip_product_video_html($description);
    // Persist every editable field separately from deployed templates. Public
    // rendering and subsequent admin forms use this data even after a code update.
    save_product_content($entry, $content);
    save_dom_file($entry['path'], $dom);
    $catalog['products'][$slug] = $entry;
    unset($catalog['deleted_products'][$entry['route']]);
    save_catalog($catalog);
    sync_product_cards($catalog, $entry, $oldRoute);
    sync_product_references($entry, $oldRoute);
    return $slug;
}

function product_delete(array &$catalog, string $slug): void {
    if (!isset($catalog['products'][$slug])) throw new RuntimeException('Товар не найден.');
    $entry = $catalog['products'][$slug];
    sync_product_cards($catalog, $entry, $entry['route'], true);
    sync_product_references($entry, $entry['route'], true);
    $source = site_path($entry['path']);
    if (is_file($source)) {
        $trash = ALYM_STORAGE_DIR . '/trash/' . date('Y-m-d_His') . '/' . basename($source);
        if (!is_dir(dirname($trash))) mkdir(dirname($trash), 0770, true);
        rename($source, $trash);
    }
    unset($catalog['products'][$slug]);
    $catalog['deleted_products'][$entry['route']] = date(DATE_ATOM);
    save_catalog($catalog);
    $contentPath = product_content_path($entry);
    if (is_file($contentPath) && !unlink($contentPath)) throw new RuntimeException('Не удалось удалить сохранённые данные товара.');
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
        'email' => 'info@alymprofi.ru',
        'lead_email' => 'info@alymprofi.ru',
        'address' => "140015, Московская область<br>\nЛюберецкий городской округ,<br>г. Люберцы, ул. Преображенская, д. 13",
        'logo' => '/upload/main/logo-alymprofi-dark.svg?v=3',
    );
    $path = ALYM_STORAGE_DIR . '/settings.json';
    if (is_file($path)) {
        $data = json_decode((string)file_get_contents($path), true);
        if (is_array($data)) {
            $settings = $data + $defaults;
            if (trim((string)($settings['email'] ?? '')) === '') {
                $settings['email'] = $defaults['email'];
            }
            return $settings;
        }
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
