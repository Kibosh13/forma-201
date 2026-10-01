<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo');
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
    register_nav_menus(array(
        'primary' => 'Главное меню',
        'footer' => 'Меню в подвале',
    ));
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('alymprofi-style', get_stylesheet_uri(), array(), wp_get_theme()->get('Version'));
    wp_enqueue_script('alymprofi-site', get_template_directory_uri() . '/site.js', array(), wp_get_theme()->get('Version'), true);
});

add_filter('loop_shop_columns', function () { return 3; });
add_filter('loop_shop_per_page', function () { return 24; });

add_action('woocommerce_single_product_summary', function () {
    global $product;
    if (!$product) {
        return;
    }
    $status = get_post_meta($product->get_id(), '_alym_order_status', true);
    if ($status) {
        echo '<div class="product-status">' . esc_html($status) . '</div>';
    }
}, 6);

function alym_menu_fallback() {
    echo '<ul><li><a href="' . esc_url(home_url('/catalog/')) . '">Каталог</a></li><li><a href="' . esc_url(home_url('/kompaniya/')) . '">О компании</a></li><li><a href="' . esc_url(home_url('/delivery/')) . '">Доставка</a></li><li><a href="' . esc_url(home_url('/kontakty/')) . '">Контакты</a></li></ul>';
}

add_filter('woocommerce_product_tabs', function ($tabs) {
    if (isset($tabs['description'])) {
        $tabs['description']['title'] = 'Описание';
    }
    if (isset($tabs['additional_information'])) {
        $tabs['additional_information']['title'] = 'Характеристики';
    }
    return $tabs;
});

