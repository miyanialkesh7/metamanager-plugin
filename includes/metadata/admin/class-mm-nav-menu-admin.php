<?php
/**
 * MM_Nav_Menu_Admin — menu editor enhancements:
 *
 * LEFT SIDEBAR:  CPT Archives meta box (checkbox list + "Add to Menu" button
 *                that populates the menu editor via wpNavMenu.addCustomLink).
 *
 * RIGHT EDITOR:  "Use for navigation schema" checkbox below the menu items
 *                in the Menu Settings area. Only one menu can be designated.
 *                Nav schema outputs only when that menu is rendered on page load.
 */

defined( 'ABSPATH' ) || exit;

class MM_Nav_Menu_Admin {

	public function register_hooks(): void {
		add_action( 'admin_head-nav-menus.php', [ $this, 'add_meta_boxes' ], 5 );
		add_action( 'admin_footer-nav-menus.php', [ $this, 'inject_schema_checkbox' ] );
		add_action( 'wp_update_nav_menu', [ $this, 'save_schema_checkbox' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// LEFT SIDEBAR — CPT Archives meta box
	// -------------------------------------------------------------------------

	public function add_meta_boxes(): void {
		add_meta_box(
			'mm-cpt-archives',
			'CPT Archives',
			[ $this, 'render_cpt_archives_box' ],
			'nav_menu',
			'side',
			'default'
		);
	}

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
								data-url="<?php echo esc_attr( $archive_url ); ?>"
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
					// Use WordPress's built-in custom link add mechanism
					if (typeof wpNavMenu !== 'undefined' && wpNavMenu.addCustomLink) {
						$('#custom-menu-item-name').val(title);
						$('#custom-menu-item-url').val(url);
						wpNavMenu.addCustomLink();
					} else {
						// Fallback: fill the custom link form
						$('#custom-menu-item-name').val(title);
						$('#custom-menu-item-url').val(url);
						$('#submit-custom-links').trigger('click');
					}
					$(this).prop('checked', false);
				});
			});
		});
		</script>
		<?php
	}

	// -------------------------------------------------------------------------
	// RIGHT EDITOR — schema checkbox below menu items
	// -------------------------------------------------------------------------

	public function inject_schema_checkbox(): void {
		$current_menu = (int) ( isset( $_GET['menu'] ) ? sanitize_text_field( wp_unslash( $_GET['menu'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $current_menu ) {
			return;
		}
		$checked = get_term_meta( $current_menu, '_mm_nav_menu_primary', true );
		$nonce   = wp_create_nonce( 'mm_nav_menu_primary_' . $current_menu );
		?>
		<script>
		jQuery(function($) {
			var html = '<div class="menu-settings-group description-wide" style="margin:12px 0 0;padding:12px 0;border-top:1px solid #ccd0d4;">'
				+ '<label style="display:flex;align-items:center;gap:6px;">'
				+ '<input type="checkbox" name="_mm_nav_menu_primary" value="1" <?php echo $checked === '1' ? 'checked' : ''; ?>>'
				+ '<strong>Use for navigation schema</strong>'
				+ '</label>'
				+ '<input type="hidden" name="_mm_nav_menu_primary_nonce" value="<?php echo $nonce; ?>">'
				+ '<p class="description" style="margin:4px 0 0 22px;">When checked, this menu is emitted as <code>SiteNavigationElement</code> JSON-LD. Only one menu can be designated. Schema outputs only when this menu is rendered on the page.</p>'
				+ '</div>';
			$('.menu-settings').first().append(html);
		});
		</script>
		<?php
	}

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
			// Uncheck any other menu that was previously primary.
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
