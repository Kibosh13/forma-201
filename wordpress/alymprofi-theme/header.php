<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?>
<div class="topline"><div class="site-shell"><span class="topline-address"><?php echo esc_html(function_exists('alym_setting') ? alym_setting('address', 'Москва') : 'Москва'); ?></span><span><?php echo esc_html(function_exists('alym_setting') ? alym_setting('work_hours', 'Пн–Пт, 9:00–18:00') : 'Пн–Пт, 9:00–18:00'); ?></span></div></div>
<header class="site-header"><div class="site-shell header-main">
    <a class="site-brand" href="<?php echo esc_url(home_url('/')); ?>">
        <?php $logo_id = function_exists('alym_setting') ? (int) alym_setting('logo_id', 0) : 0; if ($logo_id) { echo wp_get_attachment_image($logo_id, 'medium'); } else { echo esc_html(function_exists('alym_setting') ? alym_setting('company_name', 'АлюмПрофи') : 'АлюмПрофи'); } ?>
    </a>
    <button class="menu-toggle" aria-label="Открыть меню" aria-expanded="false">☰</button>
    <nav class="primary-nav" aria-label="Основное меню"><?php wp_nav_menu(array('theme_location'=>'primary','container'=>false,'fallback_cb'=>'alym_menu_fallback')); ?></nav>
    <?php $phone = function_exists('alym_setting') ? alym_setting('phone', '8 (495) 664-30-04') : '8 (495) 664-30-04'; $phone_link = function_exists('alym_setting') ? alym_setting('phone_link', '+74956643004') : '+74956643004'; ?>
    <a class="header-phone" href="tel:<?php echo esc_attr($phone_link); ?>"><?php echo esc_html($phone); ?></a>
</div></header>

