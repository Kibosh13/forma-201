<footer class="site-footer"><div class="site-shell">
    <div class="footer-grid">
        <div><h2 class="footer-title"><?php echo esc_html(function_exists('alym_setting') ? alym_setting('company_name', 'АлюмПрофи') : 'АлюмПрофи'); ?></h2><p>Алюминиевый профиль и комплектующие для строительства, интерьера и ограждений.</p></div>
        <div><h2 class="footer-title">Разделы</h2><nav><?php wp_nav_menu(array('theme_location'=>'footer','container'=>false,'menu_class'=>'footer-list','fallback_cb'=>'alym_menu_fallback')); ?></nav></div>
        <div><h2 class="footer-title">Контакты</h2><ul class="footer-list"><li><?php echo esc_html(function_exists('alym_setting') ? alym_setting('phone', '') : ''); ?></li><li><?php echo esc_html(function_exists('alym_setting') ? alym_setting('email', '') : ''); ?></li><li><?php echo esc_html(function_exists('alym_setting') ? alym_setting('address', '') : ''); ?></li></ul></div>
    </div>
    <div class="footer-bottom">© <?php echo esc_html(date('Y')); ?> <?php bloginfo('name'); ?></div>
</div></footer>
<?php wp_footer(); ?></body></html>

