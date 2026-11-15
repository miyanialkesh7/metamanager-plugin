<?php
/**
 * Template: Single Organization
 *
 * Plugin-provided template for mm_organization CPT. No theme dependency.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) {
	the_post();
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
		<header class="entry-header">
			<h1 class="entry-title"><?php the_title(); ?></h1>
		</header>

		<div class="entry-content">
			<?php
			if ( has_excerpt() ) {
				the_excerpt();
			}
			the_content();
			MM_Schema_Post_Types::render_details_table( get_the_ID() );
			?>
		</div>
	</article>
	<?php
}

get_footer();
