<?php
/**
 * Template: HowTo Archive
 *
 * Theme-agnostic howto listing. Uses standard WordPress template
 * tags with a self-contained responsive layout — no theme-specific
 * dependencies. Heading reads the CPT's plural label.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

get_header();

$layout = function_exists( 'siteorigin_setting' ) ? ( siteorigin_setting( 'blog_archive_layout' ) ?: 'grid' ) : 'grid';
?>
<div id="primary" class="content-area">
	<main id="main" class="site-main">
		<header class="page-header">
			<h1 class="page-title"><?php
				$pt = get_post_type_object( 'mm_how_to' );
				echo esc_html( $pt->labels->name ?? 'HowTo' );
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
						<a class="mm-service-card__link" href="<?php the_permalink(); ?>">Learn more</a>
					</div>
				</article>
			<?php endwhile; ?>
		</div>

		<?php the_posts_pagination(); ?>

		<?php else : ?>
			<p>No howto are currently listed.</p>
		<?php endif; ?>
	</main>
</div>

<style>
.mm-service-archive {
	margin: 24px 0;
}
.mm-service-archive--grid,
.mm-service-archive--masonry {
	display: flex;
	flex-direction: column;
	gap: 40px;
}
.mm-service-archive--list {
	display: flex;
	flex-direction: column;
	gap: 40px;
}
.mm-service-archive--offset .mm-service-card:nth-child(even) {
	margin-left: 8%;
}
.mm-service-card {
	display: flex;
	flex-direction: row;
	align-items: center;
	gap: 0;
}
.mm-service-card__image {
	flex: 0 0 36%;
	max-width: 36%;
}
.mm-service-card__image img {
	width: 100%;
	height: auto;
	display: block;
	border-radius: 4px;
}
.mm-service-card__body {
	flex: 1;
	padding: 0 0 0 32px;
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
article.mm-service-card .mm-service-card__link{display:inline-block;padding:10px 16px;background:#7a0000;color:#ffffff !important;border-radius:4px;font-size:14px;font-weight:600;text-decoration:none;margin-top:8px}.mm-service-card__link:hover{opacity:.85}
@media (max-width: 768px) {
	.mm-service-card__image {
		flex: 0 0 24%;
		max-width: 24%;
	}
	.mm-service-card__body {
		padding: 0 0 0 20px;
	}
}
@media (max-width: 600px) {
	.mm-service-card {
		flex-direction: column;
	}
	.mm-service-card__image {
		flex: 0 0 100%;
		max-width: 100%;
	}
	.mm-service-card__body {
		padding: 16px 0 0;
	}
	.mm-service-archive--offset .mm-service-card:nth-child(even) {
		margin-left: 0;
	}
}
</style>

<?php
get_footer();
