<?php get_header(); ?>
<main class="page-main"><div class="site-shell"><?php while (have_posts()) : the_post(); ?><article><h1 class="page-title"><?php the_title(); ?></h1><div class="entry-content"><?php the_content(); ?></div></article><?php endwhile; ?></div></main>
<?php get_footer(); ?>

