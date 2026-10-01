<?php get_header();
$hero_id = function_exists('alym_setting') ? (int) alym_setting('hero_image_id', 0) : 0;
$hero_url = $hero_id ? wp_get_attachment_image_url($hero_id, 'full') : '';
?>
<section class="hero<?php echo $hero_url ? ' has-image' : ''; ?>"<?php echo $hero_url ? ' style="background-image:url(' . esc_url($hero_url) . ')"' : ''; ?>><div class="site-shell"><div class="hero-copy">
    <h1><?php echo esc_html(function_exists('alym_setting') ? alym_setting('hero_title', 'Алюминиевый профиль и комплектующие') : 'Алюминиевый профиль и комплектующие'); ?></h1>
    <p><?php echo esc_html(function_exists('alym_setting') ? alym_setting('hero_text', 'Профили, поручни, фурнитура и решения для строительства') : 'Профили, поручни, фурнитура и решения для строительства'); ?></p>
    <a class="button" href="<?php echo esc_url(get_post_type_archive_link('product')); ?>">Перейти в каталог</a>
</div></div></section>
<section class="section"><div class="site-shell"><div class="section-head"><h2 class="section-title">Категории</h2><a href="<?php echo esc_url(get_post_type_archive_link('product')); ?>">Весь каталог →</a></div><div class="category-grid">
<?php
$terms = get_terms(array('taxonomy'=>'product_cat','hide_empty'=>true,'parent'=>0,'number'=>9));
if (!is_wp_error($terms)) : foreach ($terms as $term) :
    $thumbnail_id = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
    $image = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'medium') : '';
    if (!$image) {
        $sample = get_posts(array('post_type'=>'product','posts_per_page'=>1,'fields'=>'ids','tax_query'=>array(array('taxonomy'=>'product_cat','field'=>'term_id','terms'=>$term->term_id))));
        if ($sample && function_exists('alym_product_image_url')) $image = alym_product_image_url($sample[0], 'medium');
    }
?>
    <a class="category-card" href="<?php echo esc_url(get_term_link($term)); ?>"><?php if ($image) : ?><img src="<?php echo esc_url($image); ?>" alt=""><?php endif; ?><h3><?php echo esc_html($term->name); ?></h3></a>
<?php endforeach; endif; ?>
</div></div></section>
<section class="section soft"><div class="site-shell"><div class="section-head"><h2 class="section-title">Популярные товары</h2></div><?php echo do_shortcode('[products limit="6" columns="3" orderby="date" order="DESC"]'); ?></div></section>
<?php if (have_posts()) : while (have_posts()) : the_post(); if (trim(get_the_content())) : ?><section class="section"><div class="site-shell entry-content"><?php the_content(); ?></div></section><?php endif; endwhile; endif; ?>
<?php get_footer(); ?>

