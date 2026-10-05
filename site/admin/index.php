<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/editor.php';
require __DIR__ . '/leads.php';

if (isset($_GET['logout'])) {
    $_SESSION = array();
    session_destroy();
    redirect('/admin/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    check_csrf();
    $validUser = hash_equals((string)$config['user'], trim((string)($_POST['user'] ?? '')));
    $validPassword = $config['password_hash'] !== '' && password_verify((string)($_POST['password'] ?? ''), (string)$config['password_hash']);
    if ($validUser && $validPassword) {
        session_regenerate_id(true);
        $_SESSION['alym_admin_logged_in'] = true;
        redirect('/admin/');
    }
    flash('Неверный логин или пароль.', 'error');
    redirect('/admin/?login=1');
}

if (!is_logged_in()) {
    $flash = take_flash();
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Вход — управление сайтом</title><link rel="stylesheet" href="/admin/admin.css?v=20261005-card-editor"></head><body>
    <form class="login" method="post">
        <h1>Управление сайтом</h1>
        <p class="muted">Самописная административная панель alymprofi.ru</p>
        <?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
        <input type="hidden" name="action" value="login"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="field"><label>Логин</label><input name="user" autocomplete="username" required></div>
        <div class="field" style="margin-top:14px"><label>Пароль</label><input type="password" name="password" autocomplete="current-password" required></div>
        <button style="width:100%;margin-top:20px">Войти</button>
    </form></body></html><?php
    exit;
}

require_login();
$catalog = load_catalog();

if (($_GET['section'] ?? '') === 'leads' && ($_GET['action'] ?? '') === 'file') {
    $lead = find_lead((string)($_GET['id'] ?? ''));
    $fileIndex = max(0, (int)($_GET['file'] ?? 0));
    $file = $lead['files'][$fileIndex] ?? null;
    if (!$file) {
        http_response_code(404);
        exit('Файл не найден.');
    }
    $relative = str_replace(array('..', '\\'), '', (string)$file['path']);
    $absolute = ALYM_STORAGE_DIR . '/' . ltrim($relative, '/');
    if (!is_file($absolute)) {
        http_response_code(404);
        exit('Файл не найден.');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($absolute));
    header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode((string)$file['name']));
    readfile($absolute);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_product') {
            $old = trim((string)($_POST['old_slug'] ?? '')) ?: null;
            $slug = product_save($catalog, $old);
            flash($old ? 'Товар сохранён и сразу опубликован на сайте.' : 'Товар добавлен и сразу опубликован на сайте.');
            redirect('/admin/?section=products&action=edit&slug=' . rawurlencode($slug));
        }
        if ($action === 'delete_product') {
            product_delete($catalog, (string)$_POST['slug']);
            flash('Товар удалён. Резервная копия сохранена.');
            redirect('/admin/?section=products');
        }
        if ($action === 'save_category') {
            $old = trim((string)($_POST['old_slug'] ?? '')) ?: null;
            $slug = category_save($catalog, $old);
            flash($old ? 'Категория сохранена и сразу опубликована на сайте.' : 'Категория добавлена и сразу опубликована на сайте.');
            redirect('/admin/?section=categories&action=edit&slug=' . rawurlencode($slug));
        }
        if ($action === 'delete_category') {
            category_delete($catalog, (string)$_POST['slug']);
            flash('Категория удалена. Резервная копия сохранена.');
            redirect('/admin/?section=categories');
        }
        if ($action === 'save_page') {
            page_save($catalog, (string)$_POST['path']);
            flash('Страница сохранена и сразу опубликована на сайте.');
            redirect('/admin/?section=pages&action=edit&path=' . rawurlencode((string)$_POST['path']));
        }
        if ($action === 'upload_media') {
            $uploaded = upload_image('media_upload', 'media');
            if (!$uploaded) throw new RuntimeException('Выберите изображение.');
            flash('Изображение загружено: ' . $uploaded);
            redirect('/admin/?section=media');
        }
        if ($action === 'save_settings') {
            $count = global_settings_save();
            flash('Настройки сохранены и сразу опубликованы на сайте. Обновлено файлов: ' . $count . '.');
            redirect('/admin/?section=settings');
        }
        if ($action === 'complete_lead') {
            set_lead_status((string)$_POST['id'], 'done');
            flash('Заявка отмечена как обработанная.');
            redirect('/admin/?section=leads&action=view&id=' . rawurlencode((string)$_POST['id']));
        }
        if ($action === 'reopen_lead') {
            set_lead_status((string)$_POST['id'], 'new');
            flash('Заявка возвращена в новые.');
            redirect('/admin/?section=leads&action=view&id=' . rawurlencode((string)$_POST['id']));
        }
        if ($action === 'delete_lead') {
            delete_lead((string)$_POST['id']);
            flash('Заявка удалена.');
            redirect('/admin/?section=leads');
        }
    } catch (Throwable $error) {
        flash($error->getMessage(), 'error');
        redirect($_SERVER['HTTP_REFERER'] ?? '/admin/');
    }
}

$section = (string)($_GET['section'] ?? 'dashboard');
$action = (string)($_GET['action'] ?? 'list');
$flash = take_flash();
$nav = array(
    'dashboard' => 'Обзор',
    'leads' => 'Заявки' . (new_leads_count() ? ' (' . new_leads_count() . ')' : ''),
    'products' => 'Товары',
    'categories' => 'Категории',
    'pages' => 'Страницы и тексты',
    'media' => 'Изображения',
    'settings' => 'Настройки сайта',
);

function admin_header(string $title, string $section, array $nav, ?array $flash): void {
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= h($title) ?> — управление сайтом</title><link rel="stylesheet" href="/admin/admin.css?v=20261005-card-editor"></head><body><div class="layout"><aside class="sidebar"><div class="brand">alymprofi.ru</div><nav><?php foreach ($nav as $key => $label): ?><a class="<?= $section === $key ? 'active' : '' ?>" href="/admin/?section=<?= h($key) ?>"><?= h($label) ?></a><?php endforeach; ?></nav><a class="logout" href="/admin/?logout=1">Выйти</a></aside><main class="main"><?php if ($flash): ?><div class="notice <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif;
}

function admin_footer(): void {
    ?></main></div><script src="/admin/admin.js?v=20261005-card-editor"></script></body></html><?php
}

function rich_editor(string $name, string $html): void {
    ?><div class="rich-editor" data-rich-editor>
        <div class="rich-toolbar" role="toolbar" aria-label="Панель форматирования">
            <select data-rich-block aria-label="Стиль текста"><option value="p">Обычный текст</option><option value="h2">Заголовок 2</option><option value="h3">Заголовок 3</option><option value="blockquote">Цитата</option></select>
            <button type="button" data-rich-command="bold" title="Жирный"><strong>Ж</strong></button>
            <button type="button" data-rich-command="italic" title="Курсив"><em>К</em></button>
            <button type="button" data-rich-command="underline" title="Подчёркнутый"><u>Ч</u></button>
            <button type="button" data-rich-command="insertUnorderedList" title="Маркированный список">• Список</button>
            <button type="button" data-rich-command="insertOrderedList" title="Нумерованный список">1. Список</button>
            <button type="button" data-rich-link title="Добавить ссылку">Ссылка</button>
            <button type="button" data-rich-command="unlink" title="Удалить ссылку">Убрать ссылку</button>
            <button type="button" data-rich-command="removeFormat" title="Очистить форматирование">Очистить</button>
            <button type="button" data-rich-command="undo" title="Отменить">↶</button>
            <button type="button" data-rich-command="redo" title="Повторить">↷</button>
        </div>
        <div class="rich-content" contenteditable="true" data-rich-content></div>
        <textarea name="<?= h($name) ?>" data-rich-source hidden><?= h($html) ?></textarea>
    </div><?php
}

function review_editor_row(string $key, array $review): void {
    $rating = max(1, min(5, (int)($review['rating'] ?? 5)));
    ?><section class="review-editor-row" data-review-row>
        <div class="review-editor-row__head"><strong>Отзыв</strong><label class="review-delete"><input type="checkbox" name="review_delete[<?= h($key) ?>]" value="1"> Удалить отзыв</label></div>
        <div class="review-editor-grid">
            <div class="field"><label>Автор</label><input name="review_author[<?= h($key) ?>]" value="<?= h((string)($review['author'] ?? '')) ?>" placeholder="Имя автора"></div>
            <div class="field"><label>Оценка</label><select name="review_rating[<?= h($key) ?>]"><?php for ($star = 5; $star >= 1; $star--): ?><option value="<?= $star ?>" <?= $rating === $star ? 'selected' : '' ?>><?= $star ?> из 5</option><?php endfor; ?></select></div>
            <div class="field full"><label>Текст</label><textarea name="review_text[<?= h($key) ?>]" placeholder="Текст отзыва"><?= h((string)($review['text'] ?? '')) ?></textarea></div>
            <div class="field full"><label>Фотографии отзыва</label><?php if (!empty($review['images'])): ?><div class="review-image-list"><?php foreach ($review['images'] as $imagePath): ?><div class="review-image-card"><img src="<?= h((string)$imagePath) ?>" alt=""><input type="hidden" name="review_existing_image[<?= h($key) ?>][]" value="<?= h((string)$imagePath) ?>"><label><input type="checkbox" name="review_remove_image[<?= h($key) ?>][]" value="<?= h((string)$imagePath) ?>"> Удалить фото</label></div><?php endforeach; ?></div><?php endif; ?><input type="file" name="review_upload_<?= h($key) ?>[]" accept="image/*" multiple><span class="help">Можно добавить несколько фотографий.</span></div>
        </div>
    </section><?php
}

if ($section === 'dashboard') {
    admin_header('Обзор', $section, $nav, $flash);
    ?><div class="topline"><h1>Управление сайтом</h1><a class="button secondary" href="/?v=<?= time() ?>" target="_blank">Открыть сайт</a></div>
    <div class="grid">
        <div class="panel stat"><strong><?= new_leads_count() ?></strong><span>новых заявок</span></div>
        <div class="panel stat"><strong><?= count($catalog['products']) ?></strong><span>товаров</span></div>
        <div class="panel stat"><strong><?= count($catalog['categories']) ?></strong><span>категорий</span></div>
        <div class="panel stat"><strong><?= count($catalog['pages']) ?></strong><span>редактируемых страниц</span></div>
    </div>
    <div class="panel"><h2>Что можно изменить</h2><p>Название, цену, статус, характеристики, описание, SEO и изображение каждого товара; категории и их изображения; обычные страницы и все изображения на них; общие контакты, адрес и логотип.</p><p><strong>Все изменения публикуются сразу после нажатия кнопки «Сохранить».</strong> Для проверки используйте кнопку «Посмотреть изменения на сайте» в редакторе.</p><p class="muted">Перед каждой записью HTML-файла создаётся резервная копия в закрытом служебном каталоге.</p></div><?php
    admin_footer(); exit;
}

if ($section === 'products' && $action === 'edit') {
    $slug = (string)($_GET['slug'] ?? '');
    $creating = $slug === '' || !isset($catalog['products'][$slug]);
    $entry = $creating ? array('slug'=>'','category'=>'','path'=>'','route'=>'','name'=>'','price'=>'','status'=>'В наличии','image'=>'') : $catalog['products'][$slug];
    $product = $creating ? array('name'=>'','price'=>'','unit'=>'р./шт.','article'=>'','status'=>'В наличии','summary'=>'','description'=>'','video_url'=>'','videos'=>array(),'reviews'=>array(),'attributes'=>array(),'image'=>'','images'=>array(),'seo_title'=>'','seo_description'=>'') : product_read($entry);
    admin_header($creating ? 'Новый товар' : $product['name'], $section, $nav, $flash);
    $previewUrl = !$creating ? $entry['route'] . '?v=' . (string)filemtime(site_path($entry['path'])) : '';
    ?><div class="topline"><h1><?= $creating ? 'Добавить товар' : h($product['name']) ?></h1><?php if (!$creating): ?><a class="button secondary" href="<?= h($previewUrl) ?>" target="_blank">Посмотреть изменения на сайте</a><?php endif; ?></div>
    <form class="panel" method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_product"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="old_slug" value="<?= h($creating ? '' : $slug) ?>">
    <div class="form-grid">
        <div class="field full"><label>Название</label><input name="name" value="<?= h($product['name']) ?>" required></div>
        <?php if ($creating): ?><div class="field full"><label>Адрес карточки</label><input name="slug" placeholder="Заполнится автоматически из названия"><span class="help">Только при создании товара.</span></div><?php endif; ?>
        <div class="field"><label>Цена</label><input name="price" value="<?= h($product['price']) ?>" required></div>
        <div class="field"><label>Единица цены</label><input name="unit" value="<?= h($product['unit']) ?>" placeholder="р./шт."></div>
        <div class="field"><label>Артикул</label><input name="article" value="<?= h($product['article']) ?>" placeholder="Например: 0105"></div>
        <div class="field"><label>Статус</label><select name="status"><option <?= $product['status']==='В наличии'?'selected':'' ?>>В наличии</option><option <?= $product['status']==='Под заказ'?'selected':'' ?>>Под заказ</option></select></div>
        <div class="field"><label>Категория</label><select name="category"><option value="">Без категории</option><?php foreach ($catalog['categories'] as $catSlug=>$cat): ?><option value="<?= h($catSlug) ?>" <?= ($entry['category']??'')===$catSlug?'selected':'' ?>><?= h($cat['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field full"><label>Краткий текст</label><textarea name="summary"><?= h($product['summary']) ?></textarea></div>
        <div class="field full"><label>Описание</label><?php rich_editor('description', $product['description']); ?><span class="help">Редактируйте текст визуально: выделяйте слова и выбирайте нужное форматирование.</span></div>
        <div class="field full"><label>Видео</label><input type="url" name="video_url" value="<?= h((string)$product['video_url']) ?>" placeholder="https://rutube.ru/video/…"><span class="help">Вставьте ссылку RuTube, YouTube, Vimeo или ссылку для встраивания VK Видео. Чтобы удалить видео, очистите поле и сохраните товар.</span></div>
        <div class="field full"><label>Характеристики</label><div class="attributes-editor" data-attributes-editor><div class="attribute-labels"><span>Название характеристики</span><span>Значение</span><span></span></div><div class="attribute-rows" data-attribute-rows><?php foreach ($product['attributes'] ?: array(array('name'=>'','value'=>'')) as $attribute): ?><div class="attribute-row"><input name="attribute_name[]" value="<?= h((string)$attribute['name']) ?>" placeholder="Например: Длина"><input name="attribute_value[]" value="<?= h((string)$attribute['value']) ?>" placeholder="Например: 3000 мм"><button class="attribute-remove" type="button" data-remove-attribute aria-label="Удалить характеристику">Удалить</button></div><?php endforeach; ?></div><button class="button secondary attribute-add" type="button" data-add-attribute>+ Добавить характеристику</button></div><span class="help">Каждая характеристика заполняется отдельно. Пустые строки не сохраняются.</span></div>
        <div class="field"><label>SEO-заголовок</label><input name="seo_title" value="<?= h($product['seo_title']) ?>"></div>
        <div class="field"><label>SEO-описание</label><textarea name="seo_description"><?= h($product['seo_description']) ?></textarea></div>
        <div class="field full"><label>Фотографии товара</label>
            <?php if (!empty($product['images'])): ?><div class="product-gallery-editor"><?php foreach ($product['images'] as $imageIndex => $imagePath): ?><div class="product-image-card"><img src="<?= h($imagePath) ?>" alt=""><label class="image-choice"><input type="radio" name="main_image" value="<?= $imageIndex ?>" <?= $imageIndex === 0 ? 'checked' : '' ?>> Главное фото</label><label class="image-remove"><input type="checkbox" name="remove_image[]" value="<?= $imageIndex ?>"> Удалить</label></div><?php endforeach; ?></div><?php endif; ?>
            <input type="file" name="gallery_upload[]" accept="image/*" multiple <?= $creating ? 'required' : '' ?>>
            <label class="image-new-main"><input type="checkbox" name="make_new_main" value="1"> Сделать первое из загружаемых фото главным</label>
            <span class="help">Можно выбрать несколько файлов. Главное фото используется в каталоге и на главной странице, а в карточке товара показывается первым. Новые фотографии добавляются в конец и не меняют главное без установленной галочки.</span>
        </div>
        <div class="field full"><label>Отзывы о товаре</label><input type="hidden" name="reviews_present" value="1"><div class="reviews-editor" data-reviews-editor><div class="review-editor-rows" data-review-rows><?php foreach ($product['reviews'] as $reviewIndex => $review): review_editor_row('existing' . $reviewIndex, $review); endforeach; ?></div><button class="button secondary review-add" type="button" data-add-review>+ Добавить отзыв</button><span class="help">Отзывы можно создавать, редактировать и удалять. Фотографии загружаются с компьютера.</span></div></div>
    </div><div class="actions"><button>Сохранить</button><a class="button secondary" href="/admin/?section=products">Назад</a></div></form>
    <?php if (!$creating): ?><form method="post" onsubmit="return confirm('Удалить товар?')"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="slug" value="<?= h($slug) ?>"><button class="danger">Удалить товар</button></form><?php endif;
    admin_footer(); exit;
}

if ($section === 'products') {
    $search = mb_strtolower(trim((string)($_GET['q'] ?? '')));
    $items = array_values($catalog['products']);
    usort($items, fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
    if ($search !== '') $items = array_values(array_filter($items, fn($item)=>str_contains(mb_strtolower($item['name'].' '.$item['slug']),$search)));
    $page = max(1,(int)($_GET['page']??1)); $perPage=40; $pages=max(1,(int)ceil(count($items)/$perPage)); $page=min($page,$pages); $visible=array_slice($items,($page-1)*$perPage,$perPage);
    admin_header('Товары', $section, $nav, $flash);
    ?><div class="topline"><h1>Товары</h1><a class="button" href="/admin/?section=products&action=edit">Добавить товар</a></div><div class="panel"><form class="toolbar"><input type="hidden" name="section" value="products"><input type="search" name="q" value="<?= h($search) ?>" placeholder="Поиск по названию"><button>Найти</button></form></div><div class="panel table-wrap"><table class="table"><thead><tr><th>Фото</th><th>Название</th><th>Категория</th><th>Цена</th><th>Статус</th><th></th></tr></thead><tbody><?php foreach($visible as $item): ?><tr><td><?php if($item['image']): ?><img class="thumb" src="<?= h($item['image']) ?>"><?php endif; ?></td><td><?= h($item['name']) ?></td><td><?= h($catalog['categories'][$item['category']]['name']??'Без категории') ?></td><td><?= h($item['price']) ?></td><td><?= h($item['status']) ?></td><td><a class="button secondary" href="/admin/?section=products&action=edit&slug=<?= rawurlencode($item['slug']) ?>">Изменить</a></td></tr><?php endforeach; ?></tbody></table><div class="pagination"><?php for($i=1;$i<=$pages;$i++): if($i>5&&abs($i-$page)>2&&$i<$pages)continue; ?><a class="<?= $i===$page?'active':'' ?>" href="/admin/?section=products&q=<?= rawurlencode($search) ?>&page=<?= $i ?>"><?= $i ?></a><?php endfor; ?></div></div><?php
    admin_footer(); exit;
}

if ($section === 'leads' && $action === 'view') {
    $lead = find_lead((string)($_GET['id'] ?? ''));
    if (!$lead) redirect('/admin/?section=leads');
    $isNew = ($lead['status'] ?? 'new') === 'new';
    admin_header('Заявка ' . $lead['id'], $section, $nav, $flash);
    ?><div class="topline"><h1>Заявка <?= h($lead['id']) ?></h1><a class="button secondary" href="/admin/?section=leads">Все заявки</a></div>
    <div class="panel lead-card">
        <div class="lead-meta"><span><?= h(date('d.m.Y H:i', strtotime((string)$lead['created_at']))) ?></span><span><?= $isNew ? '<span class="lead-new">Новая</span>' : '<span class="lead-done">Обработана</span>' ?></span><span>Форма: <?= h((string)($lead['form'] ?? '')) ?></span></div>
        <?php if (!empty($lead['page'])): ?><div><strong>Страница:</strong> <?php if (filter_var($lead['page'], FILTER_VALIDATE_URL)): ?><a href="<?= h($lead['page']) ?>" target="_blank" rel="noopener"><?= h($lead['page']) ?></a><?php else: ?><?= h($lead['page']) ?><?php endif; ?></div><?php endif; ?>
        <dl class="lead-fields"><?php foreach (($lead['fields'] ?? array()) as $key => $value): if ($value === '') continue; ?><dt><?= h(lead_field_label((string)$key)) ?></dt><dd><?= nl2br(h((string)$value)) ?></dd><?php endforeach; ?></dl>
        <?php if (!empty($lead['files'])): ?><div><strong>Файлы:</strong><div class="actions"><?php foreach ($lead['files'] as $index => $file): ?><a class="button secondary" href="/admin/?section=leads&action=file&id=<?= rawurlencode($lead['id']) ?>&file=<?= $index ?>"><?= h((string)$file['name']) ?></a><?php endforeach; ?></div></div><?php endif; ?>
        <div class="help">Email: <?= !empty($lead['mail_sent']) ? 'отправлен на ' . h((string)$lead['mail_to']) : 'заявка сохранена, почтовый сервер не подтвердил отправку на ' . h((string)$lead['mail_to']) ?></div>
        <div class="actions"><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= h($lead['id']) ?>"><input type="hidden" name="action" value="<?= $isNew ? 'complete_lead' : 'reopen_lead' ?>"><button><?= $isNew ? 'Отметить обработанной' : 'Вернуть в новые' ?></button></form><form method="post" onsubmit="return confirm('Удалить заявку?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= h($lead['id']) ?>"><input type="hidden" name="action" value="delete_lead"><button class="danger">Удалить</button></form></div>
    </div><?php admin_footer(); exit;
}

if ($section === 'leads') {
    $leads = load_leads();
    admin_header('Заявки', $section, $nav, $flash);
    ?><div class="topline"><h1>Заявки с сайта</h1><span class="muted">Новых: <?= new_leads_count() ?></span></div><div class="panel table-wrap"><table class="table"><thead><tr><th>Дата</th><th>Имя</th><th>Телефон</th><th>Форма</th><th>Статус</th><th></th></tr></thead><tbody><?php if (!$leads): ?><tr><td colspan="6" class="muted">Заявок пока нет.</td></tr><?php endif; ?><?php foreach ($leads as $lead): $fields=$lead['fields']??array();$isNew=($lead['status']??'new')==='new'; ?><tr><td><?=h(date('d.m.Y H:i',strtotime((string)$lead['created_at'])))?></td><td><?=h((string)($fields['form_name']??''))?></td><td><?=h((string)($fields['form_phone']??''))?></td><td><?=h((string)($lead['form']??''))?></td><td><?=$isNew?'<span class="lead-new">Новая</span>':'<span class="lead-done">Обработана</span>'?></td><td><a class="button secondary" href="/admin/?section=leads&action=view&id=<?=rawurlencode((string)$lead['id'])?>">Открыть</a></td></tr><?php endforeach; ?></tbody></table></div><?php admin_footer(); exit;
}

if ($section === 'categories' && $action === 'edit') {
    $slug=(string)($_GET['slug']??''); $creating=$slug===''||!isset($catalog['categories'][$slug]); $entry=$creating?array('name'=>'','image'=>''): $catalog['categories'][$slug]; $category=$creating?array('name'=>'','seo_title'=>'','seo_description'=>'','image'=>''):category_read($entry);
    admin_header($creating?'Новая категория':$category['name'],$section,$nav,$flash);
    $categoryPreviewUrl = !$creating ? $entry['route'] . '?v=' . (string)filemtime(site_path($entry['path'])) : '';
    ?><div class="topline"><h1><?= $creating?'Добавить категорию':h($category['name']) ?></h1><?php if(!$creating):?><a class="button secondary" href="<?= h($categoryPreviewUrl) ?>" target="_blank">Посмотреть изменения на сайте</a><?php endif;?></div><form class="panel" method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_category"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="old_slug" value="<?=h($creating?'':$slug)?>"><div class="form-grid"><div class="field full"><label>Название</label><input name="name" value="<?=h($category['name'])?>" required></div><?php if($creating):?><div class="field full"><label>Адрес категории</label><input name="slug" placeholder="Заполнится автоматически"></div><?php endif;?><div class="field"><label>SEO-заголовок</label><input name="seo_title" value="<?=h($category['seo_title'])?>"></div><div class="field"><label>SEO-описание</label><textarea name="seo_description"><?=h($category['seo_description'])?></textarea></div><div class="field full"><label>Изображение категории</label><?php if($category['image']):?><img class="image-preview" src="<?=h($category['image'])?>"><?php endif;?><input type="file" name="image_upload" accept="image/*"><span class="help">Файл загружается с компьютера.</span></div></div><div class="actions"><button>Сохранить</button><a class="button secondary" href="/admin/?section=categories">Назад</a></div></form><?php if(!$creating):?><form method="post" onsubmit="return confirm('Удалить категорию? Товары останутся без категории.')"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="slug" value="<?=h($slug)?>"><button class="danger">Удалить категорию</button></form><?php endif;
    admin_footer();exit;
}

if ($section === 'categories') {
    $items=$catalog['categories']; uasort($items,fn($a,$b)=>strnatcasecmp($a['name'],$b['name']));
    admin_header('Категории',$section,$nav,$flash);
    ?><div class="topline"><h1>Категории</h1><a class="button" href="/admin/?section=categories&action=edit">Добавить категорию</a></div><div class="panel table-wrap"><table class="table"><thead><tr><th>Фото</th><th>Название</th><th>Адрес</th><th>Товаров</th><th></th></tr></thead><tbody><?php foreach($items as $slug=>$item):$count=count(array_filter($catalog['products'],fn($p)=>($p['category']??'')===$slug));?><tr><td><?php if($item['image']):?><img class="thumb" src="<?=h($item['image'])?>"><?php endif;?></td><td><?=h($item['name'])?></td><td><?=h($item['route'])?></td><td><?=$count?></td><td><a class="button secondary" href="/admin/?section=categories&action=edit&slug=<?=rawurlencode($slug)?>">Изменить</a></td></tr><?php endforeach;?></tbody></table></div><?php admin_footer();exit;
}

if ($section === 'pages' && $action === 'edit') {
    $path=(string)($_GET['path']??''); if(!isset($catalog['pages'][$path]))redirect('/admin/?section=pages'); $entry=$catalog['pages'][$path];$pageData=page_read($entry);
    admin_header($pageData['name'],$section,$nav,$flash);
    $pagePreviewUrl = $entry['route'] . '?v=' . (string)filemtime(site_path($entry['path']));
    ?><div class="topline"><h1><?=h($pageData['name'])?></h1><a class="button secondary" href="<?=h($pagePreviewUrl)?>" target="_blank">Посмотреть изменения на сайте</a></div><form class="panel" method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_page"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="path" value="<?=h($path)?>"><div class="form-grid"><div class="field full"><label>Заголовок страницы</label><input name="name" value="<?=h($pageData['name'])?>"></div><div class="field"><label>SEO-заголовок</label><input name="seo_title" value="<?=h($pageData['seo_title'])?>"></div><div class="field"><label>SEO-описание</label><textarea name="seo_description"><?=h($pageData['seo_description'])?></textarea></div><div class="field full"><label>Содержимое страницы</label><?php rich_editor('content', $pageData['content']); ?><span class="help">Основной текст страницы редактируется визуально.</span></div><div class="field full"><label>Изображения этой страницы</label><div class="image-list"><?php foreach($pageData['images'] as $image):?><div class="image-card"><img src="<?=h($image['src'])?>"><div class="help"><?=h($image['alt']?:$image['src'])?></div><input type="file" name="replace_image_<?=$image['index']?>" accept="image/*"></div><?php endforeach;?></div></div></div><div class="actions"><button>Сохранить страницу</button><a class="button secondary" href="/admin/?section=pages">Назад</a></div></form><?php admin_footer();exit;
}

if ($section === 'pages') {
    $items=$catalog['pages']; uasort($items,fn($a,$b)=>strnatcasecmp($a['name'],$b['name'])); admin_header('Страницы и тексты',$section,$nav,$flash);
    ?><div class="topline"><h1>Страницы и тексты</h1></div><div class="panel table-wrap"><table class="table"><thead><tr><th>Страница</th><th>Адрес</th><th></th></tr></thead><tbody><?php foreach($items as $path=>$item):?><tr><td><?=h($item['name'])?></td><td><?=h($item['route'])?></td><td><a class="button secondary" href="/admin/?section=pages&action=edit&path=<?=rawurlencode($path)?>">Изменить</a></td></tr><?php endforeach;?></tbody></table></div><?php admin_footer();exit;
}

if ($section === 'media') {
    $media=media_files(); admin_header('Изображения',$section,$nav,$flash);
    ?><div class="topline"><h1>Изображения</h1></div><form class="panel toolbar" method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="upload_media"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="file" name="media_upload" accept="image/*" required><button>Загрузить</button></form><div class="image-list"><?php foreach($media as $file):?><div class="image-card"><img src="<?=h($file['path'])?>"><div class="help"><?=h($file['path'])?></div></div><?php endforeach;?></div><?php admin_footer();exit;
}

if ($section === 'settings') {
    $settings=load_settings(); admin_header('Настройки сайта',$section,$nav,$flash);
    ?><div class="topline"><h1>Настройки сайта</h1></div><form class="panel" method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="save_settings"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><div class="form-grid"><div class="field full"><label>Название компании</label><input name="company_name" value="<?=h($settings['company_name'])?>"></div><div class="field"><label>Телефон на сайте</label><input name="phone" value="<?=h($settings['phone'])?>"></div><div class="field"><label>Телефон для ссылки</label><input name="phone_link" value="<?=h($settings['phone_link'])?>" placeholder="+74956643004"></div><div class="field"><label>Email на сайте</label><input type="email" name="email" value="<?=h($settings['email'])?>"></div><div class="field"><label>Email для заявок</label><input type="email" name="lead_email" value="<?=h($settings['lead_email'])?>" placeholder="info@alymprofi.ru"><span class="help">На этот адрес приходят новые заявки; одновременно они сохраняются в разделе «Заявки».</span></div><div class="field full"><label>Адрес</label><textarea name="address"><?=h($settings['address'])?></textarea></div><div class="field full"><label>Логотип</label><img class="image-preview" src="<?=h($settings['logo'])?>"><input type="file" name="logo_upload" accept="image/*"></div></div><div class="actions"><button>Сохранить настройки</button></div></form><?php admin_footer();exit;
}

redirect('/admin/');
