<?php
/**
 * Plugin Name: AlymProfi — управление каталогом
 * Description: Настройки сайта, дополнительные поля товаров и импорт архива в WooCommerce.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ALYM_CORE_VERSION', '1.0.0');

add_action('after_setup_theme', function () {
    add_theme_support('woocommerce');
});

add_action('init', function () {
    add_rewrite_rule('^catalog/([^/]+)\\.prod/?$', 'index.php?post_type=product&name=$matches[1]', 'top');
});

register_activation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

function alym_product_image_url($product_id, $size = 'woocommerce_thumbnail') {
    $image_id = get_post_thumbnail_id($product_id);
    if ($image_id) {
        $value = wp_get_attachment_image_url($image_id, $size);
        if ($value) {
            return $value;
        }
    }
    return (string) get_post_meta($product_id, '_alym_source_image', true);
}

function alym_setting($key, $fallback = '') {
    $settings = get_option('alym_site_settings', array());
    return isset($settings[$key]) && $settings[$key] !== '' ? $settings[$key] : $fallback;
}

add_action('admin_menu', function () {
    add_menu_page(
        'Настройки сайта',
        'Настройки сайта',
        'manage_options',
        'alym-site-settings',
        'alym_render_settings_page',
        'dashicons-admin-customizer',
        59
    );
});

add_action('admin_init', function () {
    register_setting('alym_site_settings', 'alym_site_settings', array(
        'type' => 'array',
        'sanitize_callback' => function ($input) {
            $clean = array();
            foreach (array('company_name', 'phone', 'phone_link', 'email', 'address', 'work_hours', 'hero_title', 'hero_text') as $key) {
                $clean[$key] = isset($input[$key]) ? sanitize_text_field($input[$key]) : '';
            }
            foreach (array('logo_id', 'hero_image_id') as $key) {
                $clean[$key] = isset($input[$key]) ? absint($input[$key]) : 0;
            }
            return $clean;
        },
    ));
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_alym-site-settings') {
        return;
    }
    wp_enqueue_media();
    $script = <<<'JS'
jQuery(function($){
  $('.alym-media').on('click',function(e){
    e.preventDefault();
    var row=$(this).closest('.alym-media-row');
    var frame=wp.media({title:'Выберите изображение',button:{text:'Использовать'},multiple:false});
    frame.on('select',function(){
      var item=frame.state().get('selection').first().toJSON();
      row.find('input').val(item.id);
      row.find('img').attr('src',item.url).show();
    });
    frame.open();
  });
});
JS;
    wp_add_inline_script('jquery-core', $script);
});

function alym_render_settings_page() {
    $s = get_option('alym_site_settings', array());
    $fields = array(
        'company_name' => 'Название компании',
        'phone' => 'Телефон',
        'phone_link' => 'Телефон для ссылки (например +74950000000)',
        'email' => 'E-mail',
        'address' => 'Адрес',
        'work_hours' => 'Режим работы',
        'hero_title' => 'Заголовок на главной',
        'hero_text' => 'Подзаголовок на главной',
    );
    ?>
    <div class="wrap"><h1>Настройки сайта</h1>
        <p>Здесь редактируются общие тексты и изображения. Страницы находятся в разделе «Страницы», товары и категории — в разделе «Товары».</p>
        <form method="post" action="options.php">
            <?php settings_fields('alym_site_settings'); ?>
            <table class="form-table"><tbody>
            <?php foreach ($fields as $key => $label) : ?>
                <tr><th><label for="alym-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                    <td><input class="regular-text" id="alym-<?php echo esc_attr($key); ?>" name="alym_site_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr(isset($s[$key]) ? $s[$key] : ''); ?>"></td></tr>
            <?php endforeach; ?>
            <?php foreach (array('logo_id' => 'Логотип', 'hero_image_id' => 'Фоновое изображение главной') as $key => $label) :
                $id = isset($s[$key]) ? absint($s[$key]) : 0;
                $src = $id ? wp_get_attachment_image_url($id, 'medium') : '';
            ?>
                <tr><th><?php echo esc_html($label); ?></th><td class="alym-media-row">
                    <input type="hidden" name="alym_site_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($id); ?>">
                    <img src="<?php echo esc_url($src); ?>" style="display:<?php echo $src ? 'block' : 'none'; ?>;max-width:240px;max-height:120px;margin-bottom:10px">
                    <button class="button alym-media">Загрузить или выбрать</button>
                </td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php submit_button('Сохранить настройки'); ?>
        </form>
    </div>
    <?php
}

add_action('woocommerce_product_options_general_product_data', function () {
    woocommerce_wp_text_input(array(
        'id' => '_alym_unit',
        'label' => 'Единица цены',
        'description' => 'Например: р./шт. или р./пог.м',
        'desc_tip' => true,
    ));
    woocommerce_wp_select(array(
        'id' => '_alym_order_status',
        'label' => 'Статус на сайте',
        'options' => array('В наличии' => 'В наличии', 'Под заказ' => 'Под заказ'),
    ));
});

add_action('woocommerce_process_product_meta', function ($post_id) {
    if (isset($_POST['_alym_unit'])) {
        update_post_meta($post_id, '_alym_unit', sanitize_text_field(wp_unslash($_POST['_alym_unit'])));
    }
    if (isset($_POST['_alym_order_status'])) {
        update_post_meta($post_id, '_alym_order_status', sanitize_text_field(wp_unslash($_POST['_alym_order_status'])));
    }
});

add_filter('woocommerce_get_price_html', function ($html, $product) {
    $unit = get_post_meta($product->get_id(), '_alym_unit', true);
    return $html . ($unit ? '<small class="alym-price-unit"> ' . esc_html($unit) . '</small>' : '');
}, 10, 2);

if (defined('WP_CLI') && WP_CLI) {
    class Alym_Import_Command {
        private $term_ids = array();

        public function __invoke($args, $assoc_args) {
            if (empty($args[0]) || !is_file($args[0])) {
                WP_CLI::error('Укажите JSON-файл экспорта.');
            }
            if (!class_exists('WooCommerce')) {
                WP_CLI::error('WooCommerce не активирован.');
            }
            $data = json_decode(file_get_contents($args[0]), true);
            if (!is_array($data) || empty($data['products'])) {
                WP_CLI::error('Некорректный файл импорта.');
            }
            $with_images = !empty($assoc_args['images']);
            $offset = isset($assoc_args['offset']) ? max(0, (int) $assoc_args['offset']) : 0;
            $limit = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : count($data['products']);

            $this->import_settings();
            $this->import_categories($data['categories']);
            $this->import_pages(isset($data['pages']) ? $data['pages'] : array());
            $products = array_slice($data['products'], $offset, $limit);
            $progress = \WP_CLI\Utils\make_progress_bar('Импорт товаров', count($products));
            foreach ($products as $record) {
                $this->import_product($record, $with_images);
                $progress->tick();
            }
            $progress->finish();
            $this->connect_related($products);
            update_option('woocommerce_shop_page_display', 'subcategories');
            update_option('woocommerce_category_archive_display', 'subcategories');
            update_option('woocommerce_currency', 'RUB');
            update_option('woocommerce_price_num_decimals', 0);
            $shop = get_page_by_path('catalog', OBJECT, 'page');
            if (!$shop) {
                $shop_id = wp_insert_post(array(
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'post_name' => 'catalog',
                    'post_title' => 'Каталог',
                    'post_content' => '',
                ));
            } else {
                $shop_id = $shop->ID;
            }
            if ($shop_id && !is_wp_error($shop_id)) {
                update_option('woocommerce_shop_page_id', (int) $shop_id);
            }
            update_option('permalink_structure', '/%postname%/');
            update_option('woocommerce_permalinks', array(
                'category_base' => 'catalog-category',
                'tag_base' => 'catalog-tag',
                'attribute_base' => '',
                'product_base' => '/catalog',
            ));
            flush_rewrite_rules();
            WP_CLI::success(sprintf('Импортировано %d товаров (offset %d).', count($products), $offset));
        }

        private function import_settings() {
            update_option('blogname', 'АлюмПрофи');
            update_option('blogdescription', 'Алюминиевый профиль и комплектующие');
            $settings = get_option('alym_site_settings', array());
            $defaults = array(
                'company_name' => 'АлюмПрофи',
                'phone' => '8 (495) 664-30-04',
                'phone_link' => '+74956643004',
                'email' => '',
                'address' => 'Москва',
                'work_hours' => 'Пн–Пт, 9:00–18:00',
                'hero_title' => 'Алюминиевый профиль и комплектующие',
                'hero_text' => 'Профили, поручни, фурнитура и решения для строительства',
            );
            update_option('alym_site_settings', wp_parse_args($settings, $defaults));
        }

        private function import_categories($categories) {
            foreach ($categories as $category) {
                $this->ensure_category($category, $categories);
            }
        }

        private function ensure_category($category, $all) {
            $slug = sanitize_title($category['slug']);
            if (isset($this->term_ids[$slug])) {
                return $this->term_ids[$slug];
            }
            $existing = get_term_by('slug', $slug, 'product_cat');
            $parent_id = 0;
            if (!empty($category['parent'])) {
                foreach ($all as $candidate) {
                    if ($candidate['slug'] === $category['parent']) {
                        $parent_id = $this->ensure_category($candidate, $all);
                        break;
                    }
                }
            }
            if ($existing) {
                $term_id = (int) $existing->term_id;
                wp_update_term($term_id, 'product_cat', array('name' => $category['name'], 'parent' => $parent_id));
            } else {
                $created = wp_insert_term($category['name'], 'product_cat', array('slug' => $slug, 'parent' => $parent_id));
                if (is_wp_error($created)) {
                    WP_CLI::warning($created->get_error_message());
                    return 0;
                }
                $term_id = (int) $created['term_id'];
            }
            $this->term_ids[$slug] = $term_id;
            return $term_id;
        }

        private function import_pages($pages) {
            foreach ($pages as $page) {
                $existing = get_page_by_path($page['slug'], OBJECT, 'page');
                $post = array(
                    'ID' => $existing ? $existing->ID : 0,
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'post_name' => sanitize_title($page['slug']),
                    'post_title' => $page['name'],
                    'post_content' => wp_kses_post($page['content']),
                );
                $post_id = wp_insert_post($post, true);
                if (!is_wp_error($post_id)) {
                    update_post_meta($post_id, '_yoast_wpseo_title', $page['seo_title']);
                    update_post_meta($post_id, '_yoast_wpseo_metadesc', $page['seo_description']);
                    update_post_meta($post_id, '_alym_old_route', $page['route']);
                    if ($page['slug'] === 'home') {
                        update_option('show_on_front', 'page');
                        update_option('page_on_front', $post_id);
                    }
                }
            }
        }

        private function import_product($record, $with_images) {
            $existing = get_page_by_path($record['slug'], OBJECT, 'product');
            $post_id = wp_insert_post(array(
                'ID' => $existing ? $existing->ID : 0,
                'post_type' => 'product',
                'post_status' => 'publish',
                'post_name' => sanitize_title($record['slug']),
                'post_title' => $record['name'],
                'post_content' => wp_kses_post($record['description']),
                'post_excerpt' => sanitize_textarea_field($record['short_description']),
            ), true);
            if (is_wp_error($post_id)) {
                WP_CLI::warning($record['slug'] . ': ' . $post_id->get_error_message());
                return;
            }
            $product = new WC_Product_Simple($post_id);
            $product->set_regular_price((string) $record['price']);
            $product->set_price((string) $record['price']);
            $product->set_catalog_visibility('visible');
            $product->set_stock_status('instock');
            $attributes = array();
            foreach ($record['attributes'] as $index => $item) {
                $attribute = new WC_Product_Attribute();
                $attribute->set_id(0);
                $attribute->set_name($item['name']);
                $attribute->set_options(array($item['value']));
                $attribute->set_position($index);
                $attribute->set_visible(true);
                $attribute->set_variation(false);
                $attributes[] = $attribute;
            }
            $product->set_attributes($attributes);
            $product->save();
            $term_ids = array();
            foreach ($record['categories'] as $category) {
                $term = get_term_by('slug', sanitize_title($category['slug']), 'product_cat');
                if ($term) {
                    $term_ids[] = (int) $term->term_id;
                }
            }
            if ($term_ids) {
                wp_set_object_terms($post_id, $term_ids, 'product_cat');
            }
            update_post_meta($post_id, '_alym_unit', $record['unit']);
            update_post_meta($post_id, '_alym_order_status', $record['order_status']);
            update_post_meta($post_id, '_alym_old_path', $record['old_path']);
            update_post_meta($post_id, '_alym_source_image', $record['image']);
            update_post_meta($post_id, '_alym_gallery_sources', $record['gallery']);
            update_post_meta($post_id, '_alym_related_slugs', $record['related_slugs']);
            update_post_meta($post_id, '_yoast_wpseo_title', $record['seo_title']);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $record['seo_description']);
            if ($with_images && $record['image']) {
                $image_id = $this->sideload($record['image'], $post_id, $record['name']);
                if ($image_id) {
                    set_post_thumbnail($post_id, $image_id);
                }
            }
            if ($with_images && !empty($record['gallery'])) {
                $gallery_ids = array();
                foreach ($record['gallery'] as $index => $gallery_url) {
                    $gallery_id = $this->sideload($gallery_url, $post_id, $record['name'] . ' — фото ' . ($index + 2));
                    if ($gallery_id && $gallery_id !== get_post_thumbnail_id($post_id)) {
                        $gallery_ids[] = $gallery_id;
                    }
                }
                if ($gallery_ids) {
                    $product->set_gallery_image_ids(array_values(array_unique($gallery_ids)));
                    $product->save();
                }
            }
        }

        private function sideload($url, $parent_id, $title) {
            $existing = get_posts(array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'meta_key' => '_alym_source_url',
                'meta_value' => $url,
                'fields' => 'ids',
                'posts_per_page' => 1,
            ));
            if ($existing) {
                return (int) $existing[0];
            }
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $id = media_sideload_image($url, $parent_id, $title, 'id');
            if (is_wp_error($id)) {
                WP_CLI::warning('Изображение: ' . $id->get_error_message() . ' — ' . $url);
                return 0;
            }
            update_post_meta($id, '_alym_source_url', $url);
            return (int) $id;
        }

        private function connect_related($records) {
            foreach ($records as $record) {
                $post = get_page_by_path($record['slug'], OBJECT, 'product');
                if (!$post || empty($record['related_slugs'])) {
                    continue;
                }
                $ids = array();
                foreach ($record['related_slugs'] as $slug) {
                    $related = get_page_by_path($slug, OBJECT, 'product');
                    if ($related) {
                        $ids[] = (int) $related->ID;
                    }
                }
                update_post_meta($post->ID, '_crosssell_ids', array_values(array_unique($ids)));
            }
        }
    }
    WP_CLI::add_command('alym import', 'Alym_Import_Command');
}
