<?php
declare(strict_types=1);

const SEARCH_RESULT_LIMIT = 120;

function search_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function search_normalize(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = str_replace('ё', 'е', $value);
    return preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?: '';
}

function search_matches(string $haystack, array $terms): bool {
    foreach ($terms as $term) {
        if ($term !== '' && !str_contains($haystack, $term)) return false;
    }
    return true;
}

function search_score(string $name, string $query, array $terms): int {
    $score = 0;
    if ($name === $query) $score += 1000;
    if (str_starts_with($name, $query)) $score += 500;
    $position = mb_strpos($name, $query, 0, 'UTF-8');
    if ($position !== false) $score += max(100, 350 - (int)$position);
    foreach ($terms as $term) {
        if (str_starts_with($name, $term)) $score += 40;
    }
    return $score;
}

function search_price(string $raw): string {
    $normalized = str_replace(',', '.', trim($raw));
    if (!is_numeric($normalized)) return $raw;
    $value = (float)$normalized;
    $decimals = floor($value) === $value ? 0 : 2;
    return number_format($value, $decimals, ',', ' ');
}

function replace_content_box(string $html, string $content): string {
    $marker = '<div class="content-box">';
    $open = strpos($html, $marker);
    if ($open === false) return $html;
    $contentStart = $open + strlen($marker);
    $tail = substr($html, $contentStart);
    preg_match_all('~<div\b[^>]*>|</div\s*>~i', $tail, $matches, PREG_OFFSET_CAPTURE);
    $depth = 1;
    foreach ($matches[0] as [$tag, $offset]) {
        if (stripos($tag, '</div') === 0) {
            $depth--;
            if ($depth === 0) {
                $close = $contentStart + $offset;
                return substr($html, 0, $contentStart) . $content . substr($html, $close);
            }
        } else {
            $depth++;
        }
    }
    return $html;
}

$siteRoot = dirname(__DIR__);
$templatePath = $siteRoot . '/catalog/index.html';
$catalogPath = $siteRoot . '/admin/data/catalog.json';
$template = is_file($templatePath) ? (string)file_get_contents($templatePath) : '';
$catalog = is_file($catalogPath) ? json_decode((string)file_get_contents($catalogPath), true) : null;

$query = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($query, 'UTF-8') > 100) $query = mb_substr($query, 0, 100, 'UTF-8');
$normalizedQuery = search_normalize($query);
$terms = array_values(array_filter(explode(' ', $normalizedQuery), static fn(string $term): bool => $term !== ''));

$categoryResults = [];
$productResults = [];
if ($terms && is_array($catalog)) {
    foreach (($catalog['categories'] ?? []) as $category) {
        $name = search_normalize((string)($category['name'] ?? ''));
        $haystack = $name . ' ' . search_normalize((string)($category['slug'] ?? ''));
        if (!search_matches($haystack, $terms)) continue;
        $category['_score'] = search_score($name, $normalizedQuery, $terms);
        $categoryResults[] = $category;
    }
    usort($categoryResults, static fn(array $a, array $b): int => ($b['_score'] <=> $a['_score']) ?: strnatcasecmp((string)$a['name'], (string)$b['name']));

    $categories = $catalog['categories'] ?? [];
    foreach (($catalog['products'] ?? []) as $product) {
        $category = $categories[$product['category'] ?? ''] ?? [];
        $name = search_normalize((string)($product['name'] ?? ''));
        $haystack = implode(' ', [
            $name,
            search_normalize((string)($product['slug'] ?? '')),
            search_normalize((string)($category['name'] ?? '')),
        ]);
        if (!search_matches($haystack, $terms)) continue;
        $product['_score'] = search_score($name, $normalizedQuery, $terms);
        $product['_category_name'] = (string)($category['name'] ?? '');
        $product['_category_route'] = (string)($category['route'] ?? '');
        $productResults[] = $product;
    }
    usort($productResults, static fn(array $a, array $b): int => ($b['_score'] <=> $a['_score']) ?: strnatcasecmp((string)$a['name'], (string)$b['name']));
    $productResults = array_slice($productResults, 0, SEARCH_RESULT_LIMIT);
}

$pageTitle = $query === '' ? 'Поиск по сайту' : 'Поиск: ' . $query;
ob_start();
?>
<style>
.search-page { padding-bottom: 40px; }
.search-page__form { display:flex; gap:12px; margin:24px 0 30px; }
.search-page__input { flex:1; min-width:0; height:50px; padding:0 18px; border:1px solid #cfd4d8; border-radius:4px; background:#fff; color:#292d31; font-size:17px; }
.search-page__button, .search-card__button { display:inline-flex; align-items:center; justify-content:center; border:0; border-radius:4px; background:#363c41; color:#fff !important; cursor:pointer; text-decoration:none; }
.search-page__button { min-width:130px; padding:0 24px; font-size:16px; }
.search-page__button:hover, .search-card__button:hover { background:#22272b; }
.search-page__summary { margin:0 0 20px; color:#687078; }
.search-page__categories { display:flex; flex-wrap:wrap; gap:10px; margin:0 0 28px; }
.search-page__category { padding:9px 14px; border:1px solid #d7dbde; border-radius:20px; background:#f5f6f7; color:#292d31; text-decoration:none; }
.search-page__category:hover { border-color:#869099; }
.search-results-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:20px; }
.search-card { display:flex; flex-direction:column; min-width:0; overflow:hidden; border:1px solid #e0e3e5; border-radius:5px; background:#fff; box-shadow:0 5px 18px rgba(32,39,44,.07); }
.search-card__image-link { display:flex; height:245px; align-items:center; justify-content:center; padding:16px; background:#fff; }
.search-card__image { display:block; width:100%; height:100%; object-fit:contain; }
.search-card__body { display:flex; flex:1; flex-direction:column; padding:18px; }
.search-card__status { margin-bottom:10px; color:#6f787f; font-size:14px; }
.search-card__status:before { content:'\f00c'; margin-right:6px; font-family:FontAwesome; }
.search-card__status--order { color:#777; }
.search-card__title { color:#292d31; font-size:18px; font-weight:600; line-height:1.3; text-decoration:none; }
.search-card__category { margin-top:10px; color:#777; font-size:13px; text-decoration:none; }
.search-card__footer { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:auto; padding-top:20px; }
.search-card__price { color:#292d31; font-size:21px; font-weight:700; white-space:nowrap; }
.search-card__button { min-height:40px; padding:0 15px; }
.search-page__empty { padding:28px; border:1px solid #e0e3e5; border-radius:5px; background:#f7f8f9; color:#4e565d; }
@media (max-width:991px) { .search-results-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:575px) { .search-page__form { flex-direction:column; } .search-page__button { min-height:48px; } .search-results-grid { grid-template-columns:1fr; } }
</style>
<div class="breadcrumb"><ul><li><a href="/">Главная</a></li><li><span>Поиск</span></li></ul></div>
<div class="search-page">
    <h1><?= search_h($pageTitle) ?></h1>
    <form class="search-page__form" action="/poisk/" method="get" role="search">
        <input class="search-page__input" type="search" name="q" value="<?= search_h($query) ?>" placeholder="Название товара или категории" maxlength="100" required autofocus>
        <button class="search-page__button" type="submit">Найти</button>
    </form>

<?php if ($query === ''): ?>
    <div class="search-page__empty">Введите название товара или категории.</div>
<?php elseif (!is_array($catalog)): ?>
    <div class="search-page__empty">Поиск временно недоступен. Пожалуйста, повторите попытку позднее.</div>
<?php else: ?>
    <p class="search-page__summary">Найдено товаров: <?= count($productResults) ?><?= count($productResults) === SEARCH_RESULT_LIMIT ? '+' : '' ?></p>
    <?php if ($categoryResults): ?>
        <div class="search-page__categories">
            <?php foreach ($categoryResults as $category): ?>
                <a class="search-page__category" href="<?= search_h((string)$category['route']) ?>"><?= search_h((string)$category['name']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($productResults): ?>
        <div class="search-results-grid">
            <?php foreach ($productResults as $product):
                $route = (string)($product['route'] ?? '#');
                $image = (string)($product['image'] ?? '');
                if ($image === '') $image = '/upload/main/logo-alymprofi-mark.svg?v=2';
                $status = (string)($product['status'] ?? '');
                $status = $status === 'Под заказ' ? 'Под заказ' : 'В наличии';
            ?>
                <article class="search-card">
                    <a class="search-card__image-link" href="<?= search_h($route) ?>">
                        <img class="search-card__image" src="<?= search_h($image) ?>" alt="<?= search_h((string)$product['name']) ?>" loading="lazy">
                    </a>
                    <div class="search-card__body">
                        <div class="search-card__status<?= $status === 'Под заказ' ? ' search-card__status--order' : '' ?>"><?= search_h($status) ?></div>
                        <a class="search-card__title" href="<?= search_h($route) ?>"><?= search_h((string)$product['name']) ?></a>
                        <?php if (($product['_category_name'] ?? '') !== ''): ?>
                            <a class="search-card__category" href="<?= search_h((string)$product['_category_route']) ?>"><?= search_h((string)$product['_category_name']) ?></a>
                        <?php endif; ?>
                        <div class="search-card__footer">
                            <span class="search-card__price"><?= search_h(search_price((string)($product['price'] ?? ''))) ?> р./шт.</span>
                            <a class="search-card__button" href="<?= search_h($route) ?>">Подробнее</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="search-page__empty">По запросу «<?= search_h($query) ?>» ничего не найдено. Попробуйте изменить формулировку.</div>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php
$content = (string)ob_get_clean();

if ($template === '') {
    http_response_code(500);
    echo 'Search template is unavailable.';
    exit;
}

$template = preg_replace('~<title>.*?</title>~s', '<title>' . search_h($pageTitle) . ' — АлюмПрофи</title>', $template, 1) ?? $template;
$template = preg_replace('~<meta name="description" content="[^"]*"\s*/?>~i', '<meta name="description" content="Поиск алюминиевого профиля и комплектующих в каталоге АлюмПрофи.">', $template, 1) ?? $template;
$template = replace_content_box($template, $content);
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
echo $template;
