<?php
/**
 * Template: mm_service Archive
 *
 * Theme-agnostic service listing. Uses standard WordPress template tags
 * with a self-contained responsive grid — no theme-specific dependencies.
 * Follows the Customizer blog layout setting when available (SiteOrigin
 * themes), falls back to a clean grid otherwise.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

get_header();

// Prefer the Customizer blog layout (SiteOrigin themes), fall back to grid.
$layout = function_exists( 'siteorigin_setting' ) ? ( siteorigin_setting( 'blog_archive_layout' ) ?: 'grid' ) : 'grid';
?>
<div id="primary" class="content-area">
	<main id="main" class="site-main">
		<header class="page-header">
			<h1 class="page-title"><?php
				$pt = get_post_type_object( 'mm_service' );
				echo esc_html( $pt->labels->name ?? 'Services' );
			?></h1>
		</header>

		<div class="mm-service-archive mm-service-archive--<?php echo esc_attr( $layout ); ?>">
		<?php if ( have_posts() ) : ?>
			<?php while ( have_posts() ) : the_post(); ?>
				<article id="post-<?php the_ID(); ?>" <?php post_class( 'mm-service-card' ); ?>>
					<?php if ( has_post_thumbnail() ) : ?>
						<a class="mm-service-card__image" href="<?php the_permalink(); ?>">
							<?php the_post_thumbnail( 'medium_large' ); ?>
						</a>
					<?php endif; ?>
					<div class="mm-service-card__body">
						<h2 class="mm-service-card__title">
							<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
						</h2>
						<div class="mm-service-card__excerpt"><?php the_excerpt(); ?></div>
						<a class="mm-service-card__link" href="<?php the_permalink(); ?>">Learn more &rarr;</a>
					</div>
				</article>
			<?php endwhile; ?>
		</div>

		<?php the_posts_pagination(); ?>

		<?php else : ?>
			<p>No services are currently listed.</p>
		<?php endif; ?>
	</main>
</div>

<style>
.mm-service-archive--grid,
.mm-service-archive--masonry {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 32px;
	margin: 24px 0;
}
.mm-service-archive--list {
	display: flex;
	flex-direction: column;
	gap: 32px;
	margin: 24px 0;
}
.mm-service-archive--offset .mm-service-card:nth-child(even) {
	margin-left: 8%;
}
.mm-service-card {
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
.mm-service-card__image img {
	width: 100%;
	height: auto;
	display: block;
	border-radius: 4px;
}
.mm-service-card__body {
	padding: 16px 0 0;
}
.mm-service-card__title {
	font-size: 20px;
	margin: 0 0 8px;
	line-height: 1.3;
}
.mm-service-card__title a {
	text-decoration: none;
}
.mm-service-card__title a:hover {
	opacity: 0.8;
}
.mm-service-card__excerpt {
	margin: 0 0 12px;
	line-height: 1.6;
}
.mm-service-card__link {
	font-weight: 600;
	text-decoration: none;
	display: inline-block;
	margin-top: 4px;
}
.mm-service-card__link:hover {
	opacity: 0.7;
}
@media (max-width: 600px) {
	.mm-service-archive--grid,
	.mm-service-archive--masonry {
		grid-template-columns: 1fr;
	}
	.mm-service-archive--offset .mm-service-card:nth-child(even) {
		margin-left: 0;
	}
}
</style>

<?php
get_footer();
