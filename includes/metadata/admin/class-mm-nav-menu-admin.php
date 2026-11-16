<?php
/**
 * MM_Nav_Menu_Admin — adds a "primary navigation" checkbox to the
 * Appearance → Menus edit screen so the user can designate which menu
 * the Schema module should emit as SiteNavigationElement.
 */

defined( 'ABSPATH' ) || exit;

class MM_Nav_Menu_Admin {
	public function register_hooks(): void {
		add_action( 'admin_head-nav-menus.php', [ $this, 'add_meta_box' ], 5 );
		add_action( 'admin_head-nav-menus.php', [ $this, 'add_cpt_archives_box' ], 5 );
		add_action( 'wp_update_nav_menu_item', [ $this, 'save_checkbox' ], 10, 3 );
	}

	/**
	 * Add a meta box to every nav-menu edit screen.
	 */
	public function add_meta_box(): void {
		add_meta_box(
			'mm-nav-menu-primary',
			'Metamanager — Schema Navigation',
			[ $this, 'render_meta_box' ],
			'nav_menu',
			'side',
			'high'
		);
	}

	/**
	 * Add a CPT Archives meta box so schema CPTs appear as top-level menu items.
	 *
	 * WordPress menu editor shows individual CPT posts but not the archive
	 * listing page. This box adds archive links (e.g. "Services" → /services/)
	 * as addable top-level items.
	 */
	public function add_cpt_archives_box(): void {
		add_meta_box(
			'mm-nav-menu-cpt-archives',
			'Metamanager — CPT Archives',
			[ $this, 'render_cpt_archives_box' ],
			'nav_menu',
			'side',
			'default'
		);
	}

	/**
	 * Render the CPT Archives meta box with addable archive links.
	 */
	public function render_cpt_archives_box(): void {
		if ( ! class_exists( 'MM_Schema_Post_Types' ) ) {
			return;
		}
		$types = MM_Schema_Post_Types::TYPES;
		?>
		<div class="posttypediv mm-cpt-archives" id="mm-cpt-archives">
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<?php foreach ( $types as $slug => $config ) :
						$label = $config[1];
						$archive_url = get_post_type_archive_link( $slug );
						if ( ! $archive_url ) {
							continue;
						}
						$archive_url = esc_url( $archive_url );
					?>
					<li>
						<label class="menu-item-title">
							<input type="checkbox"
								class="menu-item-checkbox mm-cpt-archive-check"
								data-title="<?php echo esc_attr( $label ); ?>"
								data-url="<?php echo esc_attr( $archive_url ); ?>"
								value="<?php echo esc_attr( $slug ); ?>">
							<?php echo esc_html( $label ); ?>
							<span class="description" style="color:#999;font-size:11px;margin-left:6px;"><?php echo esc_html( str_replace( home_url(), '', $archive_url ) ); ?></span>
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
				$('#mm-cpt-archives .mm-cpt-archive-check:checked').each(function() {
					var title = $(this).data('title');
					var url = $(this).data('url');
					// Use WordPress's built-in menu item add mechanism
					var $form = $('#menu-to-edit');
					if (!$form.length) return;
					// Add as custom link menu item via the hidden form fields
					var $customLinks = $('#add-custom-links');
					if ($customLinks.length) {
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

	/**
	 * Render the checkbox inside the meta box.
	 *
	 * @param object $menu The nav_menu term object passed by WordPress.
	 */
	public function render_meta_box( $menu = null ): void {
		// On nav-menus screen, $menu is null. Get current menu from URL.
		$term_id = is_object( $menu ) ? ( $menu->term_id ?? $menu->ID ?? 0 ) : (int) ( isset( $_GET['menu'] ) ? sanitize_text_field( wp_unslash( $_GET['menu'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$checked = get_term_meta( $term_id, '_mm_nav_menu_primary', true );
		wp_nonce_field( 'mm_nav_menu_primary_' . $term_id, '_mm_nav_menu_primary_nonce' );
		?>
		<p>
			<label>
				<input type="checkbox" name="_mm_nav_menu_primary" value="1"
					<?php checked( $checked, '1' ); ?>>
				Use as primary navigation for schema
			</label>
		</p>
		<p class="description">
			When checked, only this menu is emitted as <code>SiteNavigationElement</code> JSON-LD.
			If no menu is checked, no navigation schema is emitted.
		</p>
		<?php
	}

	/**
	 * Save the checkbox value when a menu is updated.
	 *
	 * @param int      $menu_id  The menu term ID.
	 * @param int|null $menu_id_from_db Unused.
	 * @param array    $menu_data Parsed menu data from the form.
	 */
	public function save_checkbox( int $menu_id, ?int $menu_id_from_db, array $menu_data ): void {
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
			$old_menus = get_terms( [
				'taxonomy'   => 'nav_menu',
				'meta_query' => [
					[
						'key'   => '_mm_nav_menu_primary',
						'value' => '1',
					],
				],
				'number'     => 0,
				'hide_empty' => false,
			] );
			if ( ! is_wp_error( $old_menus ) ) {
				foreach ( $old_menus as $old ) {
					if ( (int) $old->term_id !== $menu_id ) {
						delete_term_meta( $old->term_id, '_mm_nav_menu_primary' );
					}
				}
			}

			update_term_meta( $menu_id, '_mm_nav_menu_primary', '1' );
		} else {
			delete_term_meta( $menu_id, '_mm_nav_menu_primary' );
		}
	}
}
