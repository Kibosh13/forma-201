<?php
declare(strict_types=1);

// Read-only: exercise every live product layout in memory, without saving files.
$root = $argv[1] ?? __DIR__ . '/../site';
require $root . '/admin/bootstrap.php';
require $root . '/admin/editor.php';
$catalog = load_catalog();
$checked = 0;
$errors = array();
$missing = array('description' => 0, 'article' => 0);
foreach ($catalog['products'] as $entry) {
    try {
        $values = product_read($entry);
        [$dom, $xpath] = load_dom_file($entry['path']);
        if (!first_node($xpath, class_query('catalog-detail__text'))) $missing['description']++;
        if (!first_node($xpath, class_query('catalog-detail__article'))) $missing['article']++;
        $values['name'] = 'Проверка редактирования';
        $values['article'] = 'QA-ARTICLE';
        $values['summary'] = '<p>Проверка краткого текста</p>';
        $values['description'] = '<p>Проверка описания</p>';
        $values['attributes'] = array(array('name' => 'Проверка', 'value' => '3000'));
        apply_product_values($dom, $xpath, $values);
        foreach (array('catalog-detail__article' => 'Арт. QA-ARTICLE', 'catalog-detail__preview' => 'Проверка краткого текста', 'catalog-detail__text' => 'Проверка описания', 'catalog-detail__parameter-size' => '3000') as $class => $expected) {
            if (text_of(first_node($xpath, class_query($class))) !== $expected) throw new RuntimeException('Не сохранено поле ' . $class);
        }
        apply_product_gallery($dom, $xpath, array('/upload/qa-first.png', '/upload/qa-second.png'), $values['name']);
        if ($xpath->query(class_query('catalog-detail__img-box') . '//img')->length !== 2) throw new RuntimeException('Не заменена галерея');
        $reviews = array(array('author' => 'Тест & проверка', 'rating' => 4, 'text' => "Строка 1\nСтрока 2\n\nАбзац", 'images' => array('/upload/qa-review.png')));
        apply_product_reviews($dom, $xpath, $reviews, $values['name']);
        if (product_reviews_read($xpath) !== $reviews) throw new RuntimeException('Не сохранён отзыв');
        apply_product_reviews($dom, $xpath, array(), $values['name']);
        if (product_reviews_read($xpath)) throw new RuntimeException('Не удалён отзыв');
        if (str_contains($dom->saveHTML(), 'qa-review.png')) throw new RuntimeException('Осталась фотография удалённого отзыва');
        $checked++;
    } catch (Throwable $error) {
        $errors[] = array('slug' => $entry['slug'], 'error' => $error->getMessage());
    }
}
echo json_encode(array('checked' => $checked, 'originally_missing_blocks' => $missing, 'errors' => $errors), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
exit($errors ? 1 : 0);
