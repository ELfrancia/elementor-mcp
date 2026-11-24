<?php
/**
 * Muffin Builder (Betheme) data layer.
 *
 * Reads/writes the Betheme 28 page-builder tree stored in the `mfn-page-items`
 * post meta (native serialized array). Sections hold `wraps` (columns); wraps
 * hold `items` (content elements). Every node has jsclass/uid/title/icon and
 * its settings in `attr`:
 *
 *   [ section: { uid, jsclass, attr, wraps: [ wrap: { uid, size, attr, items: [
 *       item: { uid, type/jsclass, size, attr } ] } ] } ]
 *
 * Elements are addressed by an index path: [s] = section, [s,w] = wrap,
 * [s,w,i] = item. Legacy base64(serialize()) values are tolerated on read.
 * Saves also refresh `mfn-page-items-seo` and `mfn-page-object`. Pure helpers (decode/encode/insert/remove/…)
 * have no WordPress dependency except sanitization, so they are unit-testable.
 *
 * @package EMCP_Tools
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @since 3.3.0
 */
class EMCP_Tools_Muffin_Data {

	const META_ITEMS = 'mfn-page-items';
	const META_SEO   = 'mfn-page-items-seo';
	const META_OBJECT = 'mfn-page-object';
	const META_PREVIEW = 'mfn-builder-preview';

	/**
	 * Whether the Betheme theme (parent or child) is active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		if ( defined( 'MFN_THEME_VERSION' ) ) {
			return true;
		}
		return function_exists( 'get_template' ) && 'betheme' === strtolower( (string) get_template() );
	}

	/**
	 * Decode the stored meta value into a section list.
	 *
	 * @param mixed $raw Meta value (base64+serialized string, or an array).
	 * @return array
	 */
	public static function decode( $raw ): array {
		if ( is_array( $raw ) ) {
			return array_values( $raw );
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $raw, true );
		$payload = ( false !== $decoded && '' !== $decoded ) ? $decoded : $raw;
		// Never instantiate objects from stored data.
		$data = @unserialize( $payload, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_array( $data ) ? array_values( $data ) : array();
	}

	/**
	 * Normalize a section list for storage. Betheme 28 stores `mfn-page-items`
	 * as a native post-meta array (WordPress serializes it), not base64.
	 *
	 * @param array $tree Section list.
	 * @return array
	 */
	public static function encode( array $tree ): array {
		return array_values( $tree );
	}

	/**
	 * Betheme 28 also keeps `mfn-page-object`: a JSON list of every node
	 * ({jsclass, uid, attr}) in post-order (children before their parent).
	 *
	 * @param array $tree Section list.
	 * @return string JSON.
	 */
	public static function page_object( array $tree ): string {
		return (string) wp_json_encode( self::page_object_data( $tree ) );
	}

	/**
	 * Betheme 28 flat array of every node ({jsclass, uid, attr}) in post-order
	 * (children before their parent). Used by Betheme's Mfn_Helper::preparePostUpdate
	 * to generate local page styles and post-{ID}.css.
	 *
	 * @param array $tree Section list.
	 * @return array
	 */
	public static function page_object_data( array $tree ): array {
		$flat = array();
		foreach ( $tree as $section ) {
			foreach ( (array) ( $section['wraps'] ?? array() ) as $wrap ) {
				self::flatten_items( (array) ( $wrap['items'] ?? array() ), $flat );
				$flat[] = self::flat_node( $wrap );
			}
			$flat[] = self::flat_node( $section );
		}
		return $flat;
	}

	/**
	 * Append items in post-order, descending into nested wraps (items that hold
	 * their own `items`) so every node at every depth is listed. The page CSS is
	 * generated from this list, so a skipped node loses all of its styles.
	 *
	 * @param array $items Items.
	 * @param array $flat  Output list (by reference).
	 * @return void
	 */
	private static function flatten_items( array $items, array &$flat ): void {
		foreach ( $items as $item ) {
			if ( ! empty( $item['items'] ) && is_array( $item['items'] ) ) {
				self::flatten_items( $item['items'], $flat );
			}
			$flat[] = self::flat_node( $item );
		}
	}

	/**
	 * @param array $node Tree node.
	 * @return array
	 */
	private static function flat_node( $node ): array {
		$node = (array) $node;
		return array(
			'jsclass' => (string) ( $node['jsclass'] ?? $node['type'] ?? '' ),
			'uid'     => (string) ( $node['uid'] ?? '' ),
			'attr'    => (array) ( $node['attr'] ?? array() ),
		);
	}

	/**
	 * @param int $post_id Post ID.
	 * @return array Section list (empty when the post has no builder data).
	 */
	public static function load( int $post_id ): array {
		return self::decode( get_post_meta( $post_id, self::META_ITEMS, true ) );
	}

	/**
	 * Persist the tree (+ the plain-text SEO copy Betheme keeps alongside it).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $tree    Section list.
	 * @return bool Whether the page CSS was regenerated (false when Betheme's generator is unavailable).
	 */
	public static function save( int $post_id, array $tree ): bool {
		update_post_meta( $post_id, self::META_ITEMS, wp_slash( self::encode( $tree ) ) );
		// Betheme renders ?preview=true from this copy (class-mfn-builder-front.php); keep it in sync.
		update_post_meta( $post_id, self::META_PREVIEW, wp_slash( self::encode( $tree ) ) );
		update_post_meta( $post_id, self::META_SEO, wp_slash( self::seo_text( $tree ) ) );
		update_post_meta( $post_id, self::META_OBJECT, wp_slash( self::page_object( $tree ) ) );
		$css = self::regenerate_styles( $post_id, $tree );
		clean_post_cache( $post_id );
		return $css;
	}

	/**
	 * Regenerate the page's local CSS and fonts with Betheme's own generator,
	 * exactly as BeBuilder does on save (visual-builder.php): it rebuilds
	 * `mfn-page-local-style` + `uploads/betheme/css/post-{ID}.css`, the preview
	 * copy (`mfn-builder-preview-local-style` / `post-{ID}-preview.css`) and
	 * `mfn-page-fonts` from the flat page object. Without this, `css_*` attrs
	 * written via MCP are stored but never reach the front-end, because the
	 * builder's own Update keeps its client-side style registry.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $tree    Section list.
	 * @return bool
	 */
	public static function regenerate_styles( int $post_id, array $tree ): bool {
		self::load_betheme_classes();

		if ( ! class_exists( 'Mfn_Helper' ) || ! method_exists( 'Mfn_Helper', 'preparePostUpdate' ) ) {
			return false;
		}
		if ( ! function_exists( 'WP_Filesystem' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$flat = self::page_object_data( $tree );
		try {
			$styles = Mfn_Helper::preparePostUpdate( $flat, $post_id, 'mfn-page-local-style' );
			Mfn_Helper::preparePostUpdate( $flat, $post_id, 'mfn-builder-preview-local-style' );

			if ( is_array( $styles ) && ! empty( $styles['fonts'] ) && is_array( $styles['fonts'] ) ) {
				$fonts_json = wp_json_encode( array_values( array_unique( $styles['fonts'] ) ) );
				update_post_meta( $post_id, 'mfn-page-fonts', $fonts_json );
				update_post_meta( $post_id, 'mfn-builder-preview-fonts', $fonts_json );
			} else {
				delete_post_meta( $post_id, 'mfn-page-fonts' );
				delete_post_meta( $post_id, 'mfn-builder-preview-fonts' );
			}

			if ( class_exists( 'Mfn_Builder_Ajax' ) ) {
				$ajax = new Mfn_Builder_Ajax();
				if ( method_exists( $ajax, 'set_revision' ) ) {
					$ajax->set_revision( $post_id, 'update', self::encode( $tree ) );
				}
			}

			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			if ( function_exists( 'rocket_clean_minify' ) ) {
				rocket_clean_minify();
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return true;
	}

	/**
	 * Plain text of every string content/title field, for `mfn-page-items-seo`.
	 *
	 * @param array $tree Section list.
	 * @return string
	 */
	public static function seo_text( array $tree ): string {
		$parts = array();
		foreach ( $tree as $section ) {
			foreach ( (array) ( $section['wraps'] ?? array() ) as $wrap ) {
				foreach ( (array) ( $wrap['items'] ?? array() ) as $item ) {
					foreach ( array( 'title', 'content' ) as $key ) {
						$value = $item['attr'][ $key ] ?? '';
						if ( is_string( $value ) && '' !== trim( $value ) ) {
							$parts[] = trim( wp_strip_all_tags( $value ) );
						}
					}
				}
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * 8-char hex element ID.
	 *
	 * @return string
	 */
	public static function uid(): string {
		return substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
	}

	/**
	 * Normalize a user-supplied path to non-negative ints (max depth 8: Betheme
	 * allows wraps nested inside wraps, which appear as items with `items`).
	 *
	 * @param mixed $path Raw path.
	 * @return int[]|null Null when malformed.
	 */
	public static function clean_path( $path ): ?array {
		if ( ! is_array( $path ) || count( $path ) > 8 ) {
			return null;
		}
		$out = array();
		foreach ( $path as $i ) {
			if ( ! is_numeric( $i ) || (int) $i < 0 ) {
				return null;
			}
			$out[] = (int) $i;
		}
		return $out;
	}

	/**
	 * Build a normalized node for the level it will be inserted at.
	 *
	 * @param int   $depth Level: 0 section, 1 wrap, 2 item.
	 * @param array $in    Raw node.
	 * @return array|string Node, or an error message.
	 */
	public static function build_node( int $depth, array $in ) {
		$attr = isset( $in['attr'] ) && is_array( $in['attr'] ) ? self::clean_value( $in['attr'] ) : array();

		if ( 0 === $depth ) {
			$wraps = array();
			foreach ( (array) ( $in['wraps'] ?? array() ) as $w ) {
				$node = is_array( $w ) ? self::build_node( 1, $w ) : 'wrap must be an object';
				if ( is_string( $node ) ) {
					return $node;
				}
				$wraps[] = $node;
			}
			return array(
				'icon'    => 'section',
				'uid'     => self::uid(),
				'jsclass' => 'section',
				'title'   => 'Section',
				'attr'    => $attr,
				'ver'     => 'default',
				'wraps'   => $wraps,
			);
		}

		$size = self::clean_size( $in['size'] ?? '1/1' );
		if ( null === $size ) {
			return 'size must look like 1/1, 1/2, 1/3, 2/3, 1/4, 3/4 …';
		}

		if ( 1 === $depth ) {
			$items = array();
			foreach ( (array) ( $in['items'] ?? array() ) as $it ) {
				$node = is_array( $it ) ? self::build_node( 2, $it ) : 'item must be an object';
				if ( is_string( $node ) ) {
					return $node;
				}
				$items[] = $node;
			}
			return array(
				'icon'           => 'wrap',
				'uid'            => self::uid(),
				'jsclass'        => 'wrap',
				'title'          => 'Wrap',
				'attr'           => $attr,
				'tablet_resized' => '0',
				'items'          => $items,
			) + self::size_keys( $size, true );
		}

		$type = isset( $in['type'] ) ? (string) $in['type'] : '';
		if ( ! preg_match( '/^[a-z0-9_]+$/', $type ) ) {
			return 'item.type is required (e.g. heading, column, button, image, divider). See list-muffin-items.';
		}
		// Only reject types when the authoritative Betheme catalog is loaded; the
		// small fallback catalog must never block valid Betheme items.
		if ( ! self::is_known_item_type( $type ) && self::$catalog_is_betheme ) {
			return 'Unknown item type "' . $type . '". See list-muffin-items for available types.';
		}
		// Betheme 28 keeps an item's settings in `attr`; `fields` is accepted as an alias.
		$fields = isset( $in['fields'] ) && is_array( $in['fields'] ) ? self::clean_value( $in['fields'] ) : array();

		self::note_unknown_fields( $type, array_merge( array_keys( $fields ), array_keys( self::content_attrs( $attr ) ) ) );

		// Apply Betheme defaults for settings only — never placeholder copy
		// ("This is the heading", Lorem ipsum) for text the caller did not give.
		$defaults = array_diff_key( self::item_defaults( $type ), array_flip( array( 'title', 'content', 'subtitle', 'desc', 'text', 'label' ) ) );
		$merged_attr = array_merge( $defaults, $attr, $fields );

		// Resolve styles if provided.
		if ( isset( $in['styles'] ) && is_array( $in['styles'] ) ) {
			$resolved_styles = self::resolve_styles( $type, $in['styles'] );
			if ( is_string( $resolved_styles ) ) {
				return $resolved_styles;
			}
			$merged_attr = array_merge( $merged_attr, $resolved_styles );
		}

		$meta   = self::type_meta( $type );
		$node   = array(
			'type'           => $type,
			'jsclass'        => $type,
			'title'          => $meta['title'],
			'icon'           => $meta['icon'],
			'attr'           => $merged_attr,
			'uid'            => self::uid(),
			'tablet_resized' => '0',
		) + self::size_keys( $size, false );
		$classes = self::clean_classes( $in['classes'] ?? array() );
		if ( $classes ) {
			$node['be_classes'] = $classes;
		}
		return $node;
	}

	/**
	 * Validate BeBuilder global class IDs (e.g. be_heading_22qv02rk).
	 *
	 * @param mixed $raw List of class IDs.
	 * @return string[]
	 */
	public static function clean_classes( $raw ): array {
		$out = array();
		foreach ( (array) $raw as $c ) {
			if ( is_string( $c ) && preg_match( '/^be_[a-z0-9_]+$/', $c ) ) {
				$out[] = $c;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The site's BeBuilder global classes (option `be_classes`): the styles an
	 * admin defined once and reuses across pages. Their CSS is global, so an
	 * item only needs the class ID in `be_classes` to pick the style up.
	 *
	 * @return array<int,array{id:string,element:string,title:string,styles:string[]}>
	 */
	public static function global_classes(): array {
		$raw = get_option( 'be_classes', array() );
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$rows = array();
		foreach ( (array) ( $raw['builder'] ?? array() ) as $c ) {
			if ( empty( $c['uid'] ) ) {
				continue;
			}
			$rows[] = array(
				'id'      => (string) $c['uid'],
				'element' => (string) ( $c['jsclass'] ?? '' ),
				'title'   => (string) ( $c['title'] ?? '' ),
				'styles'  => array_keys( (array) ( $c['attr'] ?? array() ) ),
			);
		}
		return $rows;
	}

	/**
	 * Responsive size keys Betheme stores for wraps/items. Mobile is always
	 * full width, matching what the builder writes.
	 *
	 * @param string $size Desktop size, e.g. 1/2.
	 * @return array
	 */
	private static function size_keys( string $size, bool $is_wrap ): array {
		unset( $is_wrap );
		return array(
			'size'        => $size,
			'tablet_size' => $size,
			'laptop_size' => $size,
			'mobile_size' => '1/1',
		);
	}

	/**
	 * Builder label + icon for an item type (from the catalog, else derived).
	 *
	 * @param string $type Item type / jsclass.
	 * @return array{title:string,icon:string}
	 */
	public static function type_meta( string $type ): array {
		$catalog = self::item_catalog();
		return array(
			'title' => $catalog[ $type ]['title'] ?? ucwords( str_replace( '_', ' ', $type ) ),
			'icon'  => $catalog[ $type ]['icon'] ?? str_replace( '_', '-', $type ),
		);
	}

	/**
	 * @param mixed $size Raw size.
	 * @return string|null
	 */
	public static function clean_size( $size ): ?string {
		$size = (string) $size;
		return preg_match( '#^\d{1,2}/\d{1,2}$#', $size ) ? $size : null;
	}

	/**
	 * Recursively sanitize field values: safe keys; HTML filtered through
	 * wp_kses_post unless the user holds `unfiltered_html`.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function clean_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				if ( is_string( $k ) && ! preg_match( '/^[A-Za-z0-9_\-]+$/', $k ) ) {
					continue;
				}
				$out[ $k ] = self::clean_value( $v );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value );
		}
		return is_scalar( $value ) ? $value : '';
	}

	/**
	 * Reference to the child list under a parent path ([] = sections).
	 *
	 * @param array $tree        Section list.
	 * @param int[] $parent_path Parent path (max 2 levels).
	 * @return array|null
	 */
	private static function &list_ref( array &$tree, array $parent_path ) {
		$list =& $tree;
		foreach ( $parent_path as $depth => $i ) {
			if ( ! isset( $list[ $i ] ) || ! is_array( $list[ $i ] ) ) {
				$none = null;
				return $none;
			}
			$key = 0 === $depth ? 'wraps' : 'items';
			if ( ! isset( $list[ $i ][ $key ] ) || ! is_array( $list[ $i ][ $key ] ) ) {
				$list[ $i ][ $key ] = array();
			}
			$list =& $list[ $i ][ $key ];
		}
		return $list;
	}

	/**
	 * @param array $tree Section list.
	 * @param int[] $path Element path.
	 * @return array|null The element, or null when the path does not resolve.
	 */
	public static function get( array $tree, array $path ): ?array {
		if ( ! $path ) {
			return null;
		}
		$last   = array_pop( $path );
		$list   = self::list_ref( $tree, $path );
		return ( null !== $list && isset( $list[ $last ] ) && is_array( $list[ $last ] ) ) ? $list[ $last ] : null;
	}

	/**
	 * Insert a node under a parent (null index = append).
	 *
	 * @param array    $tree        Section list.
	 * @param int[]    $parent_path Parent path ([] for a new section).
	 * @param array    $node        Normalized node.
	 * @param int|null $index       Position among siblings.
	 * @return array|null New tree, or null when the parent does not resolve.
	 */
	public static function insert( array $tree, array $parent_path, array $node, ?int $index = null ): ?array {
		$list = self::list_ref( $tree, $parent_path );
		if ( null === $list ) {
			return null;
		}
		$list  = array_values( $list );
		$index = null === $index ? count( $list ) : max( 0, min( $index, count( $list ) ) );
		array_splice( $list, $index, 0, array( $node ) );
		return self::replace_list( $tree, $parent_path, $list );
	}

	/**
	 * Remove the element at a path.
	 *
	 * @param array $tree Section list.
	 * @param int[] $path Element path.
	 * @return array|null New tree, or null when the path does not resolve.
	 */
	public static function remove( array $tree, array $path ): ?array {
		if ( ! $path ) {
			return null;
		}
		$last = array_pop( $path );
		$list = self::list_ref( $tree, $path );
		if ( null === $list || ! isset( $list[ $last ] ) ) {
			return null;
		}
		$list = array_values( $list );
		array_splice( $list, $last, 1 );
		return self::replace_list( $tree, $path, $list );
	}

	/**
	 * Shallow-merge changes into the element at a path.
	 *
	 * @param array $tree    Section list.
	 * @param int[] $path    Element path.
	 * @param array $changes Keys: fields (merged), attr (merged), size (replaced).
	 * @return array|string New tree, or an error message.
	 */
	public static function update( array $tree, array $path, array $changes ) {
		$last = $path ? array_pop( $path ) : null;
		$list = null === $last ? null : self::list_ref( $tree, $path );
		if ( null === $list || ! isset( $list[ $last ] ) || ! is_array( $list[ $last ] ) ) {
			return 'path does not resolve to an element. Call get-muffin-page first.';
		}
		$el    = $list[ $last ];
		$depth = count( $path );

		if ( $depth >= 2 ) {
			// Existing elements are never blocked by the catalog: they are already on the page.
			$type = (string) ( $el['jsclass'] ?? $el['type'] ?? '' );

			if ( isset( $changes['fields'] ) && is_array( $changes['fields'] ) ) {
				$clean_fields = self::clean_value( $changes['fields'] );
				self::note_unknown_fields( $type, array_keys( $clean_fields ) );
				// Betheme 28: item settings live in `attr`.
				$el['attr'] = array_merge( (array) ( $el['attr'] ?? array() ), $clean_fields );
			}

			if ( isset( $changes['attr'] ) && is_array( $changes['attr'] ) ) {
				$clean_attr = self::clean_value( $changes['attr'] );
				self::note_unknown_fields( $type, array_keys( self::content_attrs( $clean_attr ) ) );
				$el['attr'] = array_merge( (array) ( $el['attr'] ?? array() ), $clean_attr );
			}

			if ( isset( $changes['styles'] ) && is_array( $changes['styles'] ) ) {
				$resolved_styles = self::resolve_styles( $type, $changes['styles'] );
				if ( is_string( $resolved_styles ) ) {
					return $resolved_styles;
				}
				$el['attr'] = array_merge( (array) ( $el['attr'] ?? array() ), $resolved_styles );
			}

			if ( isset( $changes['classes'] ) && is_array( $changes['classes'] ) ) {
				$classes = self::clean_classes( $changes['classes'] );
				if ( $classes ) {
					$el['be_classes'] = $classes;
				} else {
					unset( $el['be_classes'] );
				}
			}
		} else {
			if ( isset( $changes['fields'] ) ) {
				return 'fields can only be updated on items (path of 3+ indexes).';
			}
			if ( isset( $changes['styles'] ) ) {
				return 'styles can only be set on items.';
			}
			if ( isset( $changes['classes'] ) ) {
				return 'classes can only be set on items.';
			}
			if ( isset( $changes['attr'] ) && is_array( $changes['attr'] ) ) {
				$el['attr'] = array_merge( (array) ( $el['attr'] ?? array() ), self::clean_value( $changes['attr'] ) );
			}
		}

		if ( isset( $changes['size'] ) ) {
			if ( 0 === $depth ) {
				return 'sections have no size.';
			}
			$size = self::clean_size( $changes['size'] );
			if ( null === $size ) {
				return 'size must look like 1/1, 1/2, 1/3 …';
			}
			$el = array_merge( $el, self::size_keys( $size, 1 === $depth ) );
		}
		$list[ $last ] = $el;
		return self::replace_list( $tree, $path, $list );
	}

	/**
	 * @param array $tree        Section list.
	 * @param int[] $parent_path Parent path.
	 * @param array $list        New child list.
	 * @return array
	 */
	private static function replace_list( array $tree, array $parent_path, array $list ): array {
		if ( ! $parent_path ) {
			return array_values( $list );
		}
		$ref =& self::list_ref( $tree, array_slice( $parent_path, 0, -1 ) );
		$i   = end( $parent_path );
		$key = 1 === count( $parent_path ) ? 'wraps' : 'items';
		$ref[ $i ][ $key ] = array_values( $list );
		return $tree;
	}

	/**
	 * Compact view of the tree with a path per element.
	 *
	 * @param array $tree Section list.
	 * @param bool  $full Include every field value (default: truncated strings).
	 * @return array
	 */
	public static function summarize( array $tree, bool $full = false ): array {
		$out = array();
		foreach ( array_values( $tree ) as $s => $section ) {
			$wraps = array();
			foreach ( array_values( (array) ( $section['wraps'] ?? array() ) ) as $w => $wrap ) {
				$wraps[] = array(
					'path'  => array( $s, $w ),
					'size'  => (string) ( $wrap['size'] ?? '1/1' ),
					'items' => self::summarize_items( (array) ( $wrap['items'] ?? array() ), array( $s, $w ), $full ),
				);
			}
			$out[] = array( 'path' => array( $s ), 'wraps' => $wraps );
		}
		return $out;
	}

	/**
	 * Summarize a list of items, recursing into items that hold their own
	 * `items` (Betheme nests wraps inside wraps).
	 *
	 * @param array $items     Items.
	 * @param int[] $base_path Path of the parent.
	 * @param bool  $full      Untruncated values.
	 * @return array
	 */
	private static function summarize_items( array $items, array $base_path, bool $full ): array {
		$out = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$path = array_merge( $base_path, array( $i ) );
			$attr = (array) ( $item['attr'] ?? array() );
			$row  = array(
				'path'   => $path,
				'type'   => (string) ( $item['jsclass'] ?? $item['type'] ?? '' ),
				'uid'    => (string) ( $item['uid'] ?? '' ),
				'size'   => (string) ( $item['size'] ?? '1/1' ),
				'fields' => $full ? $attr : self::truncate( self::content_attrs( $attr ) ),
			);
			if ( ! empty( $item['be_classes'] ) ) {
				$row['classes'] = array_values( (array) $item['be_classes'] );
			}
			if ( ! empty( $item['items'] ) && is_array( $item['items'] ) ) {
				$row['items'] = self::summarize_items( $item['items'], $path, $full );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Drop the generated `css_*` style blobs (selector/val arrays) so the
	 * compact view shows only content-like settings.
	 *
	 * @param array $attr Item attr.
	 * @return array
	 */
	private static function content_attrs( array $attr ): array {
		foreach ( array_keys( $attr ) as $k ) {
			if ( is_string( $k ) && 0 === strpos( $k, 'css_' ) ) {
				unset( $attr[ $k ] );
			}
		}
		return $attr;
	}

	/**
	 * @param array $fields Fields.
	 * @return array
	 */
	private static function truncate( array $fields ): array {
		foreach ( $fields as $k => $v ) {
			if ( is_string( $v ) && strlen( $v ) > 120 ) {
				$fields[ $k ] = substr( $v, 0, 120 ) . '…';
			} elseif ( is_array( $v ) ) {
				$fields[ $k ] = self::truncate( $v );
			}
		}
		return $fields;
	}

	/**
	 * In-memory cache of the parsed item catalog.
	 *
	 * @var array<string,array>|null
	 */
	private static $catalog_cache = null;

	/**
	 * Clear the cached item catalog (e.g. for testing).
	 *
	 * @return void
	 */
	public static function reset_catalog_cache(): void {
		self::$catalog_cache = null;
	}

	/**
	 * Item catalog: loaded dynamically from Betheme's Mfn_Builder_Fields when
	 * available (all 160+ items with real field definitions, defaults and style
	 * selectors), with a rich fallback catalog when Betheme is not running.
	 *
	 * @return array<string,array{label:string,title:string,icon:string,content_fields:array,style_fields:array,fields:string[],defaults:array}>
	 */
	public static function item_catalog(): array {
		if ( null !== self::$catalog_cache ) {
			return self::$catalog_cache;
		}

		self::load_betheme_classes();

		if ( class_exists( 'Mfn_Builder_Fields' ) ) {
			$catalog = self::build_catalog_from_betheme();
			if ( ! empty( $catalog ) ) {
				self::$catalog_cache       = $catalog;
				self::$catalog_is_betheme  = true;
				return self::$catalog_cache;
			}
		}

		self::$catalog_cache      = self::fallback_item_catalog();
		self::$catalog_is_betheme = false;
		return self::$catalog_cache;
	}

	/**
	 * Settings valid on every item (BeBuilder "Advanced" tab and legacy keys),
	 * filled from Betheme's own definitions when available.
	 *
	 * @var string[]
	 */
	private static $advanced_ids = array( 'animate', 'visibility', 'custom_id', 'classes', 'custom_css', 'conditions', 'width_switcher', 'height_switcher', 'bg_position', 'used_fonts', 'background_switcher', 'background_switcher_hover', 'custom-responsive', 'scroll-visibility' );

	/** @var bool Whether the cached catalog came from Betheme itself (vs. the small fallback). */
	private static $catalog_is_betheme = false;

	/** @var string[] Non-blocking notices collected during the last build/update (e.g. unknown field ids). */
	private static $warnings = array();

	/**
	 * Return and clear the notices collected during the last operation.
	 *
	 * @return string[]
	 */
	public static function take_warnings(): array {
		$w              = array_values( array_unique( self::$warnings ) );
		self::$warnings = array();
		return $w;
	}

	/**
	 * Record a notice for field ids the catalog does not list. Betheme stores
	 * many valid keys outside the per-item content fields (advanced tab:
	 * `animate`, `width_switcher`, `bg_position`, `visibility`, `custom_id`…),
	 * so unknown ids are reported, never rejected.
	 *
	 * @param string   $type Item type.
	 * @param string[] $keys Supplied keys.
	 * @return void
	 */
	private static function note_unknown_fields( string $type, array $keys ): void {
		if ( ! self::$catalog_is_betheme || ! self::is_known_item_type( $type ) ) {
			return;
		}
		$unknown = self::find_unknown_content_field( $type, $keys );
		if ( null !== $unknown ) {
			self::$warnings[] = 'Field "' . $unknown . '" is not a content field of "' . $type . '" in the Betheme catalog; it was saved as-is (check the spelling if unintended).';
		}
	}

	/**
	 * Whether an item type is recognized by the catalog.
	 *
	 * @param string $type Item type / jsclass.
	 * @return bool
	 */
	public static function is_known_item_type( string $type ): bool {
		$catalog = self::item_catalog();
		return isset( $catalog[ $type ] );
	}

	/**
	 * @param string $type Item type.
	 * @return array|null
	 */
	public static function get_item_catalog_entry( string $type ): ?array {
		$catalog = self::item_catalog();
		return $catalog[ $type ] ?? null;
	}

	/**
	 * List of known content field IDs for an item type.
	 *
	 * @param string $type Item type.
	 * @return string[]
	 */
	public static function get_item_field_ids( string $type ): array {
		$entry = self::get_item_catalog_entry( $type );
		return $entry ? (array) ( $entry['fields'] ?? array() ) : array();
	}

	/**
	 * Default content field values for an item type from the Betheme catalog.
	 *
	 * @param string $type Item type.
	 * @return array<string,mixed>
	 */
	public static function item_defaults( string $type ): array {
		$entry = self::get_item_catalog_entry( $type );
		return $entry ? (array) ( $entry['defaults'] ?? array() ) : array();
	}

	/**
	 * Check whether any supplied field key is unknown for an item type.
	 *
	 * @param string   $type       Item type.
	 * @param string[] $field_keys Keys supplied in fields/attr.
	 * @return string|null First unknown field key, or null when all are valid.
	 */
	public static function find_unknown_content_field( string $type, array $field_keys ): ?string {
		$known  = self::get_item_field_ids( $type );
		$ignore = array_merge( array( 'type', 'jsclass', 'size', 'tablet_size', 'laptop_size', 'mobile_size', 'tablet_resized', 'uid', 'classes', 'be_classes', 'ver' ), self::$advanced_ids );
		foreach ( $field_keys as $k ) {
			$k = (string) $k;
			if ( '' === $k || 0 === strpos( $k, 'css_' ) || in_array( $k, $ignore, true ) ) {
				continue;
			}
			if ( ! in_array( $k, $known, true ) ) {
				return $k;
			}
		}
		return null;
	}

	/**
	 * Resolve a simple styles map (e.g. {"color":"#fff","font-size":"48px","text-align":"center","margin-top":"20px"})
	 * to Betheme's native `css_*` array attributes with exact selectors derived from
	 * Betheme's field definitions for that item type.
	 *
	 * @param string $type   Item type.
	 * @param array  $styles Property-value map.
	 * @return array|string Array of css_* attributes or error message.
	 */
	public static function resolve_styles( string $type, array $styles ) {
		$catalog = self::get_item_catalog_entry( $type );
		if ( ! $catalog ) {
			return 'Unknown item type "' . $type . '".';
		}

		$known_props = array(
			// Typography
			'font-size', 'font-family', 'font-weight', 'line-height', 'letter-spacing', 'text-transform', 'font-style', 'text-decoration',
			// Text
			'text-align', 'text-shadow', 'color',
			// Spacing
			'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
			'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
			// Dimensions
			'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'flex',
			// Backgrounds
			'background', 'background-color', 'background-image', 'background-repeat', 'background-position', 'background-size', 'background-attachment',
			// Borders
			'border', 'border-width', 'border-style', 'border-color', 'border-radius',
			'border-top', 'border-bottom', 'border-left', 'border-right',
			// Effects & Display
			'box-shadow', 'opacity', 'display', 'visibility', 'overflow', 'z-index', 'position', 'top', 'bottom', 'left', 'right',
		);

		$item_styles = array();
		foreach ( (array) ( $catalog['style_fields'] ?? array() ) as $sf ) {
			if ( ! empty( $sf['style'] ) ) {
				$item_styles[] = strtolower( (string) $sf['style'] );
			}
		}

		$resolved = array();
		foreach ( $styles as $prop => $val ) {
			$prop_clean = strtolower( trim( (string) $prop ) );
			if ( ! in_array( $prop_clean, $known_props, true ) && ! in_array( $prop_clean, $item_styles, true ) ) {
				return 'Unknown style property "' . $prop . '" for item type "' . $type . '".';
			}

			$val_clean = is_scalar( $val ) ? trim( (string) $val ) : '';
			$selector  = self::find_selector_for_property( $catalog, $prop_clean );

			// Check if item defines an exact style field ID for this property.
			$attr_key = null;
			foreach ( (array) ( $catalog['style_fields'] ?? array() ) as $sf ) {
				if ( ! empty( $sf['id'] ) && strtolower( (string) ( $sf['style'] ?? '' ) ) === $prop_clean ) {
					$attr_key = (string) $sf['id'];
					break;
				}
			}
			if ( ! $attr_key ) {
				$attr_key = 'css_' . str_replace( '-', '_', $prop_clean );
			}

			$resolved[ $attr_key ] = array(
				'val'      => $val_clean,
				'selector' => $selector,
				'style'    => $prop_clean,
			);
		}

		return $resolved;
	}

	/**
	 * Derive the CSS selector for a given property from Betheme's field definitions.
	 *
	 * @param array  $catalog Catalog entry for the item type.
	 * @param string $prop    Clean property name (e.g. font-size, margin-top).
	 * @return string
	 */
	private static function find_selector_for_property( array $catalog, string $prop ): string {
		$style_fields = (array) ( $catalog['style_fields'] ?? array() );

		// 1. Margin & spacing (element level: BeBuilder uses css_advanced_margin on .mcb-column-inner)
		if ( 0 === strpos( $prop, 'margin' ) ) {
			foreach ( $style_fields as $sf ) {
				if ( 'css_advanced_margin' === ( $sf['id'] ?? '' ) && ! empty( $sf['selector'] ) ) {
					return (string) $sf['selector'];
				}
			}
			return '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner';
		}

		// 2. Padding (if item has specific element padding like css_button_padding, use it, else css_advanced_padding)
		if ( 0 === strpos( $prop, 'padding' ) ) {
			foreach ( $style_fields as $sf ) {
				if ( 'css_advanced_padding' !== ( $sf['id'] ?? '' ) && false !== strpos( (string) ( $sf['id'] ?? '' ), 'padding' ) && ! empty( $sf['selector'] ) && false === strpos( (string) ( $sf['selector'] ?? '' ), 'variations' ) ) {
					return (string) $sf['selector'];
				}
			}
			foreach ( $style_fields as $sf ) {
				if ( 'css_advanced_padding' === ( $sf['id'] ?? '' ) && ! empty( $sf['selector'] ) ) {
					return (string) $sf['selector'];
				}
			}
			return '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner';
		}

		// 3. Direct match on style (ignoring sub-variation loops)
		foreach ( $style_fields as $sf ) {
			if ( strtolower( (string) ( $sf['style'] ?? '' ) ) === $prop && ! empty( $sf['selector'] ) && false === strpos( (string) ( $sf['selector'] ?? '' ), 'variations' ) ) {
				return (string) $sf['selector'];
			}
		}

		// 4. Typography properties
		$typo_props = array( 'font-size', 'font-family', 'font-weight', 'line-height', 'letter-spacing', 'text-transform', 'font-style', 'text-decoration' );
		if ( in_array( $prop, $typo_props, true ) ) {
			foreach ( $style_fields as $sf ) {
				if ( 'typography' === strtolower( (string) ( $sf['style'] ?? '' ) ) && ! empty( $sf['selector'] ) && false === strpos( (string) ( $sf['selector'] ?? '' ), 'variations' ) ) {
					return (string) $sf['selector'];
				}
			}
			foreach ( $style_fields as $sf ) {
				if ( in_array( strtolower( (string) ( $sf['style'] ?? '' ) ), array( 'color', 'text-align' ), true ) && ! empty( $sf['selector'] ) && false === strpos( (string) ( $sf['selector'] ?? '' ), 'variations' ) ) {
					return (string) $sf['selector'];
				}
			}
		}

		// 5. Width / flex
		if ( in_array( $prop, array( 'width', 'min-width', 'max-width', 'flex' ), true ) ) {
			foreach ( $style_fields as $sf ) {
				if ( 'width' === strtolower( (string) ( $sf['style'] ?? '' ) ) && ! empty( $sf['selector'] ) ) {
					return (string) $sf['selector'];
				}
			}
			return '.mcb-section .mcb-wrap .mcb-item-mfnuidelement';
		}

		// 6. Height
		if ( in_array( $prop, array( 'height', 'min-height', 'max-height' ), true ) ) {
			foreach ( $style_fields as $sf ) {
				if ( 'height' === strtolower( (string) ( $sf['style'] ?? '' ) ) && ! empty( $sf['selector'] ) ) {
					return (string) $sf['selector'];
				}
			}
			return '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner';
		}

		// 7. Background & Border
		if ( 0 === strpos( $prop, 'background' ) || 0 === strpos( $prop, 'border' ) ) {
			foreach ( $style_fields as $sf ) {
				if ( 0 === strpos( strtolower( (string) ( $sf['style'] ?? '' ) ), 'background' ) && ! empty( $sf['selector'] ) ) {
					return (string) $sf['selector'];
				}
			}
			return '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner';
		}

		// Default element container selector
		return '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner';
	}

	/**
	 * Attempt to load Betheme's class files when available.
	 *
	 * @return void
	 */
	public static function load_betheme_classes(): void {
		if ( class_exists( 'Mfn_Builder_Fields' ) && class_exists( 'Mfn_Helper' ) ) {
			return;
		}

		$dirs = array();
		if ( function_exists( 'get_template_directory' ) ) {
			$dirs[] = get_template_directory();
		}
		if ( defined( 'ABSPATH' ) ) {
			$dirs[] = ABSPATH . 'wp-content/themes/betheme';
		}
		if ( defined( 'BETHEME_SRC_DIR' ) ) {
			$dirs[] = BETHEME_SRC_DIR;
		}

		foreach ( $dirs as $dir ) {
			if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
				continue;
			}
			$b_helper = $dir . '/functions/builder/class-mfn-builder-helper.php';
			$helper   = $dir . '/functions/admin/class-mfn-helper.php';
			$fields   = $dir . '/functions/builder/class-mfn-builder-fields.php';
			$ajax     = $dir . '/functions/builder/class-mfn-builder-ajax.php';

			if ( file_exists( $b_helper ) && ! class_exists( 'Mfn_Builder_Helper' ) ) {
				require_once $b_helper;
			}
			if ( file_exists( $helper ) && ! class_exists( 'Mfn_Helper' ) ) {
				require_once $helper;
			}
			if ( file_exists( $fields ) && ! class_exists( 'Mfn_Builder_Fields' ) ) {
				require_once $fields;
			}
			if ( file_exists( $ajax ) && ! class_exists( 'Mfn_Builder_Ajax' ) ) {
				require_once $ajax;
			}
			if ( class_exists( 'Mfn_Builder_Fields' ) ) {
				break;
			}
		}
	}

	/**
	 * Build catalog dynamically from Betheme's Mfn_Builder_Fields.
	 *
	 * @return array<string,array>
	 */
	private static function build_catalog_from_betheme(): array {
		if ( ! class_exists( 'Mfn_Builder_Fields' ) ) {
			return array();
		}

		try {
			$bf = new Mfn_Builder_Fields( false, 'items' );
			if ( method_exists( $bf, 'set_advanced' ) ) {
				$bf->set_advanced();
			}
			$raw_items = method_exists( $bf, 'get_items' ) ? $bf->get_items() : array();
			$raw_adv   = method_exists( $bf, 'get_advanced' ) ? $bf->get_advanced() : array();

			if ( ! is_array( $raw_items ) || empty( $raw_items ) ) {
				return array();
			}

			// Parse advanced style fields shared by elements.
			$adv_styles = array();
			if ( is_array( $raw_adv ) ) {
				foreach ( $raw_adv as $adv ) {
					// Non-style advanced settings (animate, visibility, custom_id…) are valid on every item.
					if ( ! empty( $adv['id'] ) && 0 !== strpos( (string) $adv['id'], 'css_' ) ) {
						self::$advanced_ids[] = (string) $adv['id'];
					}
					if ( ! empty( $adv['id'] ) && ( 0 === strpos( $adv['id'], 'css_' ) || ! empty( $adv['selector'] ) ) ) {
						$adv_styles[] = array(
							'id'       => (string) $adv['id'],
							'style'    => (string) ( $adv['style'] ?? '' ),
							'selector' => (string) ( $adv['selector'] ?? '' ),
						);
					}
				}
			}

			$catalog = array();
			foreach ( $raw_items as $type => $item_data ) {
				if ( ! is_array( $item_data ) ) {
					continue;
				}

				$content_fields = array();
				$style_fields   = array();
				$field_ids      = array();
				$defaults       = array();

				foreach ( (array) ( $item_data['attr'] ?? array() ) as $field ) {
					if ( empty( $field['id'] ) || ! is_string( $field['id'] ) ) {
						continue;
					}
					$ftype = $field['type'] ?? '';
					if ( in_array( $ftype, array( 'html', 'header' ), true ) ) {
						continue;
					}
					if ( ! empty( $field['class'] ) && is_string( $field['class'] ) && false !== strpos( $field['class'], 'mfn-deprecated' ) ) {
						continue;
					}

					$fid = $field['id'];
					if ( 0 === strpos( $fid, 'css_' ) || ( ! empty( $field['selector'] ) && ! empty( $field['style'] ) ) ) {
						$style_fields[] = array(
							'id'       => $fid,
							'style'    => (string) ( $field['style'] ?? '' ),
							'selector' => (string) ( $field['selector'] ?? '' ),
						);
					} else {
						$default_val = $field['std'] ?? $field['vbstd'] ?? '';
						if ( is_array( $default_val ) ) {
							$default_val = '';
						}
						$content_fields[] = array(
							'id'      => $fid,
							'type'    => (string) $ftype,
							'default' => (string) $default_val,
						);
						$field_ids[]      = $fid;
						$defaults[ $fid ] = (string) $default_val;
					}
				}

				// Merge advanced style fields into each item's style fields.
				$merged_style_fields = array_merge( $style_fields, $adv_styles );

				$catalog[ (string) $type ] = array(
					'label'          => (string) ( $item_data['title'] ?? ucwords( str_replace( '_', ' ', $type ) ) ),
					'title'          => (string) ( $item_data['title'] ?? ucwords( str_replace( '_', ' ', $type ) ) ),
					'icon'           => (string) ( $item_data['icon'] ?? str_replace( '_', '-', $type ) ),
					'content_fields' => $content_fields,
					'style_fields'   => $merged_style_fields,
					'fields'         => $field_ids,
					'defaults'       => $defaults,
				);
			}

			return $catalog;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Hand-written fallback catalog of common Betheme item types when
	 * Betheme's Mfn_Builder_Fields class is unavailable.
	 *
	 * @return array<string,array>
	 */
	private static function fallback_item_catalog(): array {
		$adv_styles = array(
			array( 'id' => 'css_advanced_margin', 'style' => 'margin', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner' ),
			array( 'id' => 'css_advanced_padding', 'style' => 'padding', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner' ),
			array( 'id' => 'css_advanced_height', 'style' => 'height', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner' ),
			array( 'id' => 'css_advanced_flex', 'style' => 'width', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement' ),
			array( 'id' => 'css_advanced_background_color', 'style' => 'background-color', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner' ),
			array( 'id' => 'css_advanced_border_style', 'style' => 'border-style', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner' ),
			array( 'id' => 'css_advanced_border_color', 'style' => 'border-color', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner' ),
			array( 'id' => 'css_advanced_border_width', 'style' => 'border-width', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner' ),
			array( 'id' => 'css_advanced_border_radius', 'style' => 'border-radius', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner' ),
			array( 'id' => 'css_advanced_box_shadow', 'style' => 'box-shadow', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .mcb-column-inner' ),
		);

		return array(
			'heading' => array(
				'label'          => 'Heading',
				'title'          => 'Heading',
				'icon'           => 'heading',
				'content_fields' => array(
					array( 'id' => 'title', 'type' => 'textarea', 'default' => 'This is the heading' ),
					array( 'id' => 'header_tag', 'type' => 'switch', 'default' => 'h2' ),
					array( 'id' => 'link_type', 'type' => 'select', 'default' => '' ),
					array( 'id' => 'sidebar_type', 'type' => 'select', 'default' => '' ),
					array( 'id' => 'link', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'target', 'type' => 'select', 'default' => '0' ),
					array( 'id' => 'link_title', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'onclick', 'type' => 'text', 'default' => '' ),
				),
				'style_fields'   => array_merge(
					array(
						array( 'id' => 'css_txt_align', 'style' => 'text-align', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .title' ),
						array( 'id' => 'css_color', 'style' => 'color', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .title,.mcb-section .mcb-wrap .mcb-item-mfnuidelement .title a' ),
						array( 'id' => 'css_color_hover', 'style' => 'color', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .title:hover,.mcb-section .mcb-wrap .mcb-item-mfnuidelement .title a:hover' ),
						array( 'id' => 'css_text_shadow', 'style' => 'text-shadow', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .title' ),
						array( 'id' => 'css_typography', 'style' => 'typography', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .title' ),
					),
					$adv_styles
				),
				'fields'         => array( 'title', 'header_tag', 'link_type', 'sidebar_type', 'link', 'target', 'link_title', 'onclick' ),
				'defaults'       => array(
					'title'      => 'This is the heading',
					'header_tag' => 'h2',
					'link'       => '',
					'target'     => '0',
				),
			),
			'column' => array(
				'label'          => 'Column Text',
				'title'          => 'Column Text',
				'icon'           => 'column',
				'content_fields' => array(
					array( 'id' => 'content', 'type' => 'textarea', 'default' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>' ),
					array( 'id' => 'title', 'type' => 'text', 'default' => '' ),
				),
				'style_fields'   => array_merge(
					array(
						array( 'id' => 'css_column_attr_text_align', 'style' => 'text-align', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .column_attr' ),
						array( 'id' => 'css_column_attr_color', 'style' => 'color', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .column_attr' ),
						array( 'id' => 'css_column_attra_color', 'style' => 'color', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .column_attr a' ),
						array( 'id' => 'css_column_attr_typography', 'style' => 'typography', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .column_attr' ),
						array( 'id' => 'css_column_attr_text_shadow', 'style' => 'text-shadow', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .column_attr' ),
					),
					$adv_styles
				),
				'fields'         => array( 'content', 'title' ),
				'defaults'       => array(
					'content' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>',
					'title'   => '',
				),
			),
			'plain_text' => array(
				'label'          => 'Plain Text',
				'title'          => 'Plain Text',
				'icon'           => 'plain-text',
				'content_fields' => array(
					array( 'id' => 'content', 'type' => 'textarea', 'default' => 'Lorem ipsum dolor sit amet' ),
				),
				'style_fields'   => array_merge(
					array(
						array( 'id' => 'css_plain_text_typography', 'style' => 'typography', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .desc' ),
						array( 'id' => 'css_plain_text_color', 'style' => 'color', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .desc' ),
					),
					$adv_styles
				),
				'fields'         => array( 'content' ),
				'defaults'       => array( 'content' => 'Lorem ipsum dolor sit amet' ),
			),
			'image' => array(
				'label'          => 'Image',
				'title'          => 'Image',
				'icon'           => 'image',
				'content_fields' => array(
					array( 'id' => 'src', 'type' => 'upload', 'default' => '' ),
					array( 'id' => 'size', 'type' => 'select', 'default' => '' ),
					array( 'id' => 'align', 'type' => 'select', 'default' => '' ),
					array( 'id' => 'link', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'target', 'type' => 'select', 'default' => '0' ),
					array( 'id' => 'alt', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'caption', 'type' => 'text', 'default' => '' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'src', 'size', 'align', 'link', 'target', 'alt', 'caption' ),
				'defaults'       => array( 'src' => '', 'size' => '', 'target' => '0' ),
			),
			'spacer' => array(
				'label'          => 'Spacer',
				'title'          => 'Spacer',
				'icon'           => 'spacer',
				'content_fields' => array(),
				'style_fields'   => $adv_styles,
				'fields'         => array(),
				'defaults'       => array(),
			),
			'divider_2' => array(
				'label'          => 'Divider',
				'title'          => '&bull; Divider',
				'icon'           => 'divider-2',
				'content_fields' => array(
					array( 'id' => 'type', 'type' => 'select', 'default' => 'dots' ),
					array( 'id' => 'height', 'type' => 'text', 'default' => '' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'type', 'height' ),
				'defaults'       => array( 'type' => 'dots' ),
			),
			'button' => array(
				'label'          => 'Button',
				'title'          => 'Button',
				'icon'           => 'button',
				'content_fields' => array(
					array( 'id' => 'title', 'type' => 'text', 'default' => 'Click here' ),
					array( 'id' => 'link', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'target', 'type' => 'select', 'default' => '0' ),
					array( 'id' => 'link_title', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'icon', 'type' => 'icon', 'default' => '' ),
					array( 'id' => 'align', 'type' => 'switch', 'default' => '' ),
				),
				'style_fields'   => array_merge(
					array(
						array( 'id' => 'css__text_align', 'style' => 'text-align', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement' ),
						array( 'id' => 'css_button_padding', 'style' => 'padding', 'selector' => '.mcb-section .mcb-wrap .mcb-item-mfnuidelement .button' ),
					),
					$adv_styles
				),
				'fields'         => array( 'title', 'link', 'target', 'link_title', 'icon', 'align' ),
				'defaults'       => array( 'title' => 'Click here', 'target' => '0' ),
			),
			'code' => array(
				'label'          => 'Code',
				'title'          => 'Code',
				'icon'           => 'code',
				'content_fields' => array(
					array( 'id' => 'content', 'type' => 'textarea', 'default' => '' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'content' ),
				'defaults'       => array( 'content' => '' ),
			),
			'call_to_action' => array(
				'label'          => 'Call to action',
				'title'          => 'Call to action',
				'icon'           => 'call-to-action',
				'content_fields' => array(
					array( 'id' => 'title', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'content', 'type' => 'textarea', 'default' => '' ),
					array( 'id' => 'button_title', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'link', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'target', 'type' => 'select', 'default' => '0' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'title', 'content', 'button_title', 'link', 'target' ),
				'defaults'       => array( 'target' => '0' ),
			),
			'icon_box' => array(
				'label'          => 'Icon box',
				'title'          => 'Icon box',
				'icon'           => 'icon-box',
				'content_fields' => array(
					array( 'id' => 'title', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'content', 'type' => 'textarea', 'default' => '' ),
					array( 'id' => 'icon', 'type' => 'icon', 'default' => '' ),
					array( 'id' => 'link', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'target', 'type' => 'select', 'default' => '0' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'title', 'content', 'icon', 'link', 'target' ),
				'defaults'       => array( 'target' => '0' ),
			),
			'feature_box' => array(
				'label'          => 'Feature box',
				'title'          => 'Feature box',
				'icon'           => 'feature-box',
				'content_fields' => array(
					array( 'id' => 'title', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'content', 'type' => 'textarea', 'default' => '' ),
					array( 'id' => 'image', 'type' => 'upload', 'default' => '' ),
					array( 'id' => 'link', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'target', 'type' => 'select', 'default' => '0' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'title', 'content', 'image', 'link', 'target' ),
				'defaults'       => array( 'target' => '0' ),
			),
			'counter' => array(
				'label'          => 'Counter',
				'title'          => 'Counter',
				'icon'           => 'counter',
				'content_fields' => array(
					array( 'id' => 'number', 'type' => 'text', 'default' => '0' ),
					array( 'id' => 'label', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'icon', 'type' => 'icon', 'default' => '' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'number', 'label', 'icon' ),
				'defaults'       => array( 'number' => '0' ),
			),
			'quick_fact' => array(
				'label'          => 'Quick fact',
				'title'          => 'Quick fact',
				'icon'           => 'quick-fact',
				'content_fields' => array(
					array( 'id' => 'number', 'type' => 'text', 'default' => '0' ),
					array( 'id' => 'title', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'label', 'type' => 'text', 'default' => '' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'number', 'title', 'label' ),
				'defaults'       => array( 'number' => '0' ),
			),
			'tabs' => array(
				'label'          => 'Tabs',
				'title'          => 'Tabs',
				'icon'           => 'tabs',
				'content_fields' => array(
					array( 'id' => 'tabs', 'type' => 'tabs', 'default' => '' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'tabs' ),
				'defaults'       => array(),
			),
			'accordion' => array(
				'label'          => 'Accordion',
				'title'          => 'Accordion',
				'icon'           => 'accordion',
				'content_fields' => array(
					array( 'id' => 'tabs', 'type' => 'tabs', 'default' => '' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'tabs' ),
				'defaults'       => array(),
			),
			'testimonials' => array(
				'label'          => 'Testimonials',
				'title'          => 'Testimonials',
				'icon'           => 'testimonials',
				'content_fields' => array(
					array( 'id' => 'tabs', 'type' => 'tabs', 'default' => '' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'tabs' ),
				'defaults'       => array(),
			),
			'map_basic' => array(
				'label'          => 'Google Map (basic)',
				'title'          => 'Google Map (basic)',
				'icon'           => 'map-basic',
				'content_fields' => array(
					array( 'id' => 'address', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'zoom', 'type' => 'text', 'default' => '13' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'address', 'zoom' ),
				'defaults'       => array( 'zoom' => '13' ),
			),
			'contact_box' => array(
				'label'          => 'Contact box',
				'title'          => 'Contact box',
				'icon'           => 'contact-box',
				'content_fields' => array(
					array( 'id' => 'title', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'address', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'telephone', 'type' => 'text', 'default' => '' ),
					array( 'id' => 'email', 'type' => 'text', 'default' => '' ),
				),
				'style_fields'   => $adv_styles,
				'fields'         => array( 'title', 'address', 'telephone', 'email' ),
				'defaults'       => array(),
			),
		);
	}
}
