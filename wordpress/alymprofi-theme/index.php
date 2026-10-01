<?php get_header(); ?>
<main class="page-main"><div class="site-shell"><h1 class="page-title"><?php bloginfo('name'); ?></h1><?php if (have_posts()) : while (have_posts()) : the_post(); ?><article><h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2><?php the_excerpt(); ?></article><?php endwhile; else : ?><p>Материалы не найдены.</p><?php endif; ?></div></main>
<?php get_footer(); ?>

