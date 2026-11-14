<?php
/**
 * Muffin Builder (Betheme) data layer.
 *
 * Reads/writes the Betheme page-builder tree stored in the `mfn-page-items`
 * post meta. The tree is a list of sections; each section holds `wraps`
 * (columns) and each wrap holds `items` (content elements):
 *
 *   [ section: { uid, attr, wraps: [ wrap: { uid, size, attr, items: [
 *       item: { uid, type, size, fields } ] } ] } ]
 *
 * Elements are addressed by an index path: [s] = section, [s,w] = wrap,
 * [s,w,i] = item. Betheme stores the tree as base64(serialize(array)); a plain
 * array is also tolerated on read. Pure helpers (decode/encode/insert/remove/…)
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
	 * Encode a section list the way Betheme stores it.
	 *
	 * @param array $tree Section list.
	 * @return string
	 */
	public static function encode( array $tree ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( serialize( array_values( $tree ) ) );
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
	 * @return void
	 */
	public static function save( int $post_id, array $tree ): void {
		update_post_meta( $post_id, self::META_ITEMS, wp_slash( self::encode( $tree ) ) );
		update_post_meta( $post_id, self::META_SEO, wp_slash( self::seo_text( $tree ) ) );
		clean_post_cache( $post_id );
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
						$value = $item['fields'][ $key ] ?? '';
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
	 * Normalize a user-supplied path to non-negative ints (max depth 3).
	 *
	 * @param mixed $path Raw path.
	 * @return int[]|null Null when malformed.
	 */
	public static function clean_path( $path ): ?array {
		if ( ! is_array( $path ) || count( $path ) > 3 ) {
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
			return array( 'uid' => self::uid(), 'attr' => $attr, 'wraps' => $wraps );
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
			return array( 'uid' => self::uid(), 'size' => $size, 'attr' => $attr, 'items' => $items );
		}

		$type = isset( $in['type'] ) ? (string) $in['type'] : '';
		if ( ! preg_match( '/^[a-z0-9_]+$/', $type ) ) {
			return 'item.type is required (e.g. heading, column, button, image, divider). See list-muffin-items.';
		}
		$fields = isset( $in['fields'] ) && is_array( $in['fields'] ) ? self::clean_value( $in['fields'] ) : array();
		return array( 'uid' => self::uid(), 'type' => $type, 'size' => $size, 'fields' => $fields );
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
		if ( isset( $changes['fields'] ) && is_array( $changes['fields'] ) ) {
			if ( 2 !== $depth ) {
				return 'fields can only be updated on items (path of 3 indexes).';
			}
			$el['fields'] = array_merge( (array) ( $el['fields'] ?? array() ), self::clean_value( $changes['fields'] ) );
		}
		if ( isset( $changes['attr'] ) && is_array( $changes['attr'] ) ) {
			$el['attr'] = array_merge( (array) ( $el['attr'] ?? array() ), self::clean_value( $changes['attr'] ) );
		}
		if ( isset( $changes['size'] ) ) {
			if ( 0 === $depth ) {
				return 'sections have no size.';
			}
			$size = self::clean_size( $changes['size'] );
			if ( null === $size ) {
				return 'size must look like 1/1, 1/2, 1/3 …';
			}
			$el['size'] = $size;
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
				$items = array();
				foreach ( array_values( (array) ( $wrap['items'] ?? array() ) ) as $i => $item ) {
					$items[] = array(
						'path'   => array( $s, $w, $i ),
						'type'   => (string) ( $item['type'] ?? '' ),
						'size'   => (string) ( $item['size'] ?? '1/1' ),
						'fields' => $full ? (array) ( $item['fields'] ?? array() ) : self::truncate( (array) ( $item['fields'] ?? array() ) ),
					);
				}
				$wraps[] = array(
					'path'  => array( $s, $w ),
					'size'  => (string) ( $wrap['size'] ?? '1/1' ),
					'items' => $items,
				);
			}
			$out[] = array( 'path' => array( $s ), 'wraps' => $wraps );
		}
		return $out;
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
	 * Best-effort catalog of common Betheme item types. Field names are from
	 * Betheme's builder and may vary by theme version — the authoritative source
	 * is an existing item of that type (get-muffin-page with full:true).
	 *
	 * @return array<string,array{label:string,fields:string[]}>
	 */
	public static function item_catalog(): array {
		return array(
			'heading'      => array( 'label' => 'Heading', 'fields' => array( 'title', 'tag', 'align' ) ),
			'column'       => array( 'label' => 'Column (text)', 'fields' => array( 'title', 'content' ) ),
			'button'       => array( 'label' => 'Button', 'fields' => array( 'title', 'link', 'target', 'icon', 'align' ) ),
			'image'        => array( 'label' => 'Image', 'fields' => array( 'src', 'alt', 'link', 'target', 'align' ) ),
			'divider'      => array( 'label' => 'Divider', 'fields' => array( 'height', 'style' ) ),
			'code'         => array( 'label' => 'Code', 'fields' => array( 'content' ) ),
			'call_to_action' => array( 'label' => 'Call to action', 'fields' => array( 'title', 'content', 'button_title', 'link' ) ),
			'icon_box'     => array( 'label' => 'Icon box', 'fields' => array( 'title', 'content', 'icon', 'link' ) ),
			'feature_box'  => array( 'label' => 'Feature box', 'fields' => array( 'title', 'content', 'image', 'link' ) ),
			'counter'      => array( 'label' => 'Counter', 'fields' => array( 'number', 'label', 'icon' ) ),
			'quick_fact'   => array( 'label' => 'Quick fact', 'fields' => array( 'number', 'title', 'label' ) ),
			'tabs'         => array( 'label' => 'Tabs', 'fields' => array( 'tabs' ) ),
			'accordion'    => array( 'label' => 'Accordion', 'fields' => array( 'tabs' ) ),
			'testimonials' => array( 'label' => 'Testimonials', 'fields' => array( 'tabs' ) ),
			'map_basic'    => array( 'label' => 'Google Map (basic)', 'fields' => array( 'address', 'zoom' ) ),
			'contact_box'  => array( 'label' => 'Contact box', 'fields' => array( 'title', 'address', 'telephone', 'email' ) ),
		);
	}
}
