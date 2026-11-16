<?php
/**
 * MM_Nav_Menu_Admin — WordPress nav-menus.php enhancements using
 * the standard add_meta_box() API (WordPress codex pattern).
 *
 * LEFT SIDEBAR ("Add menu items"):
 *   - CPT Archives meta box (checkbox list + Add to Menu)
 *
 * MENU SETTINGS (below menu items):
 *   - "Use for navigation schema" checkbox
 */

defined( 'ABSPATH' ) || exit;

class MM_Nav_Menu_Admin {

	public function register_hooks(): void {
		add_action( 'admin_init', [ $this, 'add_meta_boxes' ] );
		add_action( 'wp_update_nav_menu', [ $this, 'save_schema_checkbox' ], 10, 2 );
	}

	/**
	 * Register meta boxes on the nav-menus screen (WordPress codex: add_meta_box).
	 */
	public function add_meta_boxes(): void {
		add_meta_box(
			'mm-cpt-archives',
			__( 'CPT Archives', 'metamanager' ),
			[ $this, 'render_cpt_archives_box' ],
			'nav_menu',
			'side',
			'default'
		);
		add_meta_box(
			'mm-schema-nav',
			__( 'Navigation Schema', 'metamanager' ),
			[ $this, 'render_schema_box' ],
			'nav_menu',
			'side',
			'low'
		);
	}

	// -------------------------------------------------------------------------
	// LEFT SIDEBAR — CPT Archives
	// -------------------------------------------------------------------------

	public function render_cpt_archives_box(): void {
		if ( ! class_exists( 'MM_Schema_Post_Types' ) ) {
			return;
		}
		$types = MM_Schema_Post_Types::TYPES;
		?>
		<div id="mm-cpt-archives-panel" class="posttypediv">
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<?php foreach ( $types as $slug => $config ) :
						$label       = $config[1];
						$archive_url = get_post_type_archive_link( $slug );
						if ( ! $archive_url ) {
							continue;
						}
					?>
					<li>
						<label class="menu-item-title">
							<input type="checkbox" class="mm-cpt-archive-check"
								data-title="<?php echo esc_attr( $label ); ?>"
								data-url="<?php echo esc_url( $archive_url ); ?>"
								value="<?php echo esc_attr( $slug ); ?>">
							<?php echo esc_html( $label ); ?>
						</label>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<p class="button-controls">
				<button type="button" class="button" id="mm-add-cpt-archives-to-menu">Add to Menu</button>
			</p>
		</div>
		<script>
		jQuery(function($) {
			$('#mm-add-cpt-archives-to-menu').on('click', function() {
				$('#mm-cpt-archives-panel .mm-cpt-archive-check:checked').each(function() {
					var title = $(this).data('title');
					var url   = $(this).data('url');
					if (typeof wpNavMenu !== 'undefined' && wpNavMenu.addCustomLink) {
						$('#custom-menu-item-name').val(title);
						$('#custom-menu-item-url').val(url);
						wpNavMenu.addCustomLink();
					}
					$(this).prop('checked', false);
				});
			});
		});
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// MENU SETTINGS — schema checkbox (WordPress codex: add_meta_box)
	// -------------------------------------------------------------------------

	public function render_schema_box(): void {
		$current_menu = 0;
		if ( isset( $_GET['menu'] ) ) {
			$current_menu = (int) sanitize_text_field( wp_unslash( $_GET['menu'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$checked = $current_menu ? get_term_meta( $current_menu, '_mm_nav_menu_primary', true ) : '';
		?>
		<div class="menu-settings-group">
			<p class="description" style="margin:0 0 8px;">
				<?php esc_html_e( 'When checked, this menu is emitted as SiteNavigationElement JSON-LD. Only one menu can be designated. Schema outputs only when this menu is rendered on the page.', 'metamanager' ); ?>
			</p>
			<label>
				<input type="checkbox" name="_mm_nav_menu_primary" value="1" <?php checked( $checked, '1' ); ?>>
				<strong><?php esc_html_e( 'Use for navigation schema', 'metamanager' ); ?></strong>
			</label>
			<?php if ( $current_menu ) : ?>
			<input type="hidden" name="_mm_nav_menu_primary_nonce" value="<?php echo esc_attr( wp_create_nonce( 'mm_nav_menu_primary_' . $current_menu ) ); ?>">
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save the schema checkbox when a menu is updated.
	 */
	public function save_schema_checkbox( $menu_id, $data ): void {
		if ( ! isset( $_POST['_mm_nav_menu_primary_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_mm_nav_menu_primary_nonce'] ) ), 'mm_nav_menu_primary_' . $menu_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$is_primary = isset( $_POST['_mm_nav_menu_primary'] ) && '1' === $_POST['_mm_nav_menu_primary'];

		if ( $is_primary ) {
			$old = get_terms( [
				'taxonomy'   => 'nav_menu',
				'meta_query' => [ [ 'key' => '_mm_nav_menu_primary', 'value' => '1' ] ],
				'number'     => 0,
				'hide_empty' => false,
			] );
			if ( ! is_wp_error( $old ) ) {
				foreach ( $old as $term ) {
					if ( (int) $term->term_id !== (int) $menu_id ) {
						delete_term_meta( $term->term_id, '_mm_nav_menu_primary' );
					}
				}
			}
			update_term_meta( $menu_id, '_mm_nav_menu_primary', '1' );
		} else {
			delete_term_meta( $menu_id, '_mm_nav_menu_primary' );
		}
	}
}
