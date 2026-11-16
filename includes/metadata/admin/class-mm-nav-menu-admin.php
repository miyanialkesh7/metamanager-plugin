<?php
/**
 * MM_Nav_Menu_Admin — nav-menus.php enhancements using WordPress codex
 * add_meta_box() API. No JavaScript injection hacks.
 *
 * LEFT SIDEBAR ("Add menu items"):
 *   - Archives meta box: dropdown (Blog, CPT archives, Taxonomies) + checkboxes + Add to Menu
 *
 * Nav schema is determined by WordPress's native Menu Settings "Primary Menu"
 * location assignment — no custom checkbox exists here.
 */

defined( 'ABSPATH' ) || exit;

class MM_Nav_Menu_Admin {

	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'register_nav_meta_boxes' ] );
	}

	/**
	 * Register meta boxes on the nav-menus screen (WordPress codex: add_meta_box).
	 */
	public function register_nav_meta_boxes(): void {
		add_meta_box(
			'mm-archives',
			__( 'Archives', 'metamanager' ),
			[ $this, 'render_archives_box' ],
			'nav-menus',
			'side',
			'default'
		);
	}

	// -------------------------------------------------------------------------
	// Archives meta box — all archive types in a dropdown
	// -------------------------------------------------------------------------

	public function render_archives_box(): void {
		// Build archive items grouped by type
		$groups = [];

		// Blog archive
		$blog_url = get_post_type_archive_link( 'post' );
		if ( $blog_url ) {
			$groups['blog'] = [
				'label' => __( 'Blog', 'metamanager' ),
				'items' => [ [ 'title' => __( 'Blog Archive', 'metamanager' ), 'url' => $blog_url ] ],
			];
		}

		// CPT archives
		if ( class_exists( 'MM_Schema_Post_Types' ) ) {
			$types = MM_Schema_Post_Types::TYPES;
			$cpt_items = [];
			foreach ( $types as $slug => $config ) {
				$label = $config[1];
				$url   = get_post_type_archive_link( $slug );
				if ( $url ) {
					$cpt_items[] = [ 'title' => $label, 'url' => $url ];
				}
			}
			if ( $cpt_items ) {
				$groups['cpt'] = [
					'label' => __( 'CPT Archives', 'metamanager' ),
					'items' => $cpt_items,
				];
			}
		}

		// Taxonomy archives
		$taxonomies = get_taxonomies( [ 'public' => true ], 'names' );
		$tax_items = [];
		foreach ( $taxonomies as $tax ) {
			$terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 50 ] );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$tax_obj = get_taxonomy( $tax );
			foreach ( $terms as $term ) {
				$url = get_term_link( $term );
				if ( ! is_wp_error( $url ) ) {
					$tax_items[] = [ 'title' => $term->name, 'url' => $url, 'group' => $tax_obj->labels->name ?? $tax ];
				}
			}
		}
		if ( $tax_items ) {
			$groups['tax'] = [
				'label' => __( 'Taxonomy Archives', 'metamanager' ),
				'items' => $tax_items,
			];
		}

		?>
		<div id="mm-archives-panel" class="posttypediv">
			<div class="tabs-panel tabs-panel-active">
				<p>
					<select id="mm-archives-filter" style="width:100%;margin-bottom:8px;">
						<option value=""><?php esc_html_e( 'All Archives', 'metamanager' ); ?></option>
						<?php foreach ( $groups as $key => $group ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $group['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<ul class="categorychecklist form-no-clear" id="mm-archives-list">
					<?php foreach ( $groups as $key => $group ) : ?>
					<?php foreach ( $group['items'] as $item ) : ?>
					<li data-group="<?php echo esc_attr( $key ); ?>">
						<label class="menu-item-title">
							<input type="checkbox" class="mm-archive-check"
								data-title="<?php echo esc_attr( $item['title'] ); ?>"
								data-url="<?php echo esc_url( $item['url'] ); ?>">
							<?php echo esc_html( $item['title'] ); ?>
						</label>
					</li>
					<?php endforeach; ?>
					<?php endforeach; ?>
				</ul>
			</div>
			<p class="button-controls">
				<button type="button" class="button" id="mm-add-archives-to-menu"><?php esc_html_e( 'Add to Menu', 'metamanager' ); ?></button>
			</p>
		</div>
		<script>
		jQuery(function($) {
			// Filter dropdown
			$('#mm-archives-filter').on('change', function() {
				var val = $(this).val();
				$('#mm-archives-list li').each(function() {
					$(this).toggle(!val || $(this).data('group') === val);
				});
			});
			// Add to Menu — use WordPress's native wpNavMenu.addCustomLink
			$('#mm-add-archives-to-menu').on('click', function() {
				var $checked = $('#mm-archives-list .mm-archive-check:checked');
				if (!$checked.length) return;
				// Process one at a time to avoid race conditions
				var items = [];
				$checked.each(function() {
					items.push({ title: $(this).data('title'), url: $(this).data('url') });
					$(this).prop('checked', false);
				});
				$.each(items, function(i, item) {
					$('#custom-menu-item-name').val(item.title);
					$('#custom-menu-item-url').val(item.url);
					if (typeof wpNavMenu !== 'undefined' && wpNavMenu.addCustomLink) {
						wpNavMenu.addCustomLink(wpNavMenu.addMenuItemToBottom);
					} else {
						$('#submit-customlinkdiv').trigger('click');
					}
				});
			});
		});
		</script>
		<?php
	}

}
