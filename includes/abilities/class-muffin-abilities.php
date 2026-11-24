<?php
/**
 * Muffin Builder (Betheme) MCP abilities.
 *
 * Five tools to read and build pages made with Betheme's native builder
 * (sections > wraps > items, stored in the `mfn-page-items` post meta). Only
 * registered when the Betheme theme is active; no Elementor dependency.
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
class EMCP_Tools_Muffin_Abilities {

	/** @var string[] */
	private $ability_names = array();

	/** @return string[] */
	public function get_ability_names(): array {
		return $this->ability_names;
	}

	/**
	 * Register the five abilities.
	 */
	public function register(): void {
		$path_schema = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
		$post_id     = array( 'type' => 'integer', 'description' => __( 'Post/page ID.', 'emcp-tools' ) );
		$out_ok      = array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ) ) );

		$this->reg(
			'emcp-tools/list-muffin-items',
			__( 'List Muffin Items', 'emcp-tools' ),
			__( 'Betheme Muffin Builder: lists item types from Betheme\'s catalog with their field names and style selectors. Use type to inspect a specific item, or search to filter by keyword. Verify field names against an existing item with get-muffin-page full:true.', 'emcp-tools' ),
			'execute_list_items',
			'check_read_permission',
			array(
				'type'       => 'object',
				'properties' => array(
					'type'   => array(
						'type'        => 'string',
						'description' => __( 'Item type (e.g. heading, column, button). When set, returns full content fields and style selectors for this item.', 'emcp-tools' ),
					),
					'search' => array(
						'type'        => 'string',
						'description' => __( 'Search keyword to filter item types by name or title.', 'emcp-tools' ),
					),
				),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'items' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
					'item'  => array( 'type' => 'object' ),
					'count' => array( 'type' => 'integer' ),
				),
			),
			true
		);

		$this->reg(
			'emcp-tools/list-muffin-classes',
			__( 'List Muffin Classes', 'emcp-tools' ),
			__( 'Betheme Muffin Builder: lists the site\'s BeBuilder global classes (reusable styles the admin defined, e.g. "Hero section style" for headings). Each has an id (be_heading_xxx), the element type it styles and a title. Pass ids in `classes` on add/update-muffin-element so new elements match the site design. Prefer these over raw css_* attrs.', 'emcp-tools' ),
			'execute_list_classes',
			'check_read_permission',
			array( 'type' => 'object', 'properties' => new stdClass() ),
			array( 'type' => 'object', 'properties' => array( 'classes' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
			true
		);

		$this->reg(
			'emcp-tools/get-muffin-page',
			__( 'Get Muffin Page', 'emcp-tools' ),
			__( 'Betheme Muffin Builder: returns the page tree (sections > wraps > items) with an index PATH per element ([s], [s,w], [s,w,i]). Call this right before update/remove to get current paths. full:true returns complete field values.', 'emcp-tools' ),
			'execute_get_page',
			'check_read_permission',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => $post_id,
					'full'    => array( 'type' => 'boolean', 'description' => __( 'Return untruncated field values.', 'emcp-tools' ) ),
				),
				'required'   => array( 'post_id' ),
			),
			array( 'type' => 'object', 'properties' => array( 'sections' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
			true
		);

		$this->reg(
			'emcp-tools/add-muffin-element',
			__( 'Add Muffin Element', 'emcp-tools' ),
			__( 'Betheme Muffin Builder: adds a section, wrap or item. parent_path [] => new section (element may nest wraps[] with items[]); [s] => new wrap in section s (may nest items[]); [s,w] => new item in that wrap ({type, size, fields}). index = position among siblings (default: append). One call can build a whole section declaratively.', 'emcp-tools' ),
			'execute_add_element',
			'check_write_permission',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'     => $post_id,
					'parent_path' => $path_schema + array( 'description' => __( '[] section | [s] wrap | [s,w] item.', 'emcp-tools' ) ),
					'index'       => array( 'type' => 'integer', 'description' => __( 'Insert position among siblings; omit to append.', 'emcp-tools' ) ),
					'element'     => array(
						'type'        => 'object',
						'description' => __( 'Section: {attr?, wraps?:[wrap]}. Wrap: {size?:"1/3", attr?, items?:[item]}. Item: {type:"heading", size?:"1/1", fields:{title:"Hi", header_tag:"h2"}, styles?:{"color":"#fff","font-size":"48px","text-align":"center","margin-top":"20px"}, classes?:["be_heading_xxx"]}. Use list-muffin-classes for site styles.', 'emcp-tools' ),
					),
				),
				'required'   => array( 'post_id', 'element' ),
			),
			array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'path' => array( 'type' => 'array' ) ) ),
			false
		);

		$this->reg(
			'emcp-tools/update-muffin-element',
			__( 'Update Muffin Element', 'emcp-tools' ),
			__( 'Betheme Muffin Builder: partial-merge update of the element at path — fields (items only), styles (items only), attr, size, classes. Unspecified keys are kept.', 'emcp-tools' ),
			'execute_update_element',
			'check_write_permission',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => $post_id,
					'path'    => $path_schema + array( 'description' => __( 'Element path from get-muffin-page.', 'emcp-tools' ) ),
					'fields'  => array( 'type' => 'object', 'description' => __( 'Item field values to merge.', 'emcp-tools' ) ),
					'styles'  => array( 'type' => 'object', 'description' => __( 'Item styles to merge, e.g. {"color":"#fff","font-size":"48px","text-align":"center","margin-top":"20px"}. Translated to css_* attrs with Betheme selectors.', 'emcp-tools' ) ),
					'attr'    => array( 'type' => 'object', 'description' => __( 'Style/attribute values to merge.', 'emcp-tools' ) ),
					'size'    => array( 'type' => 'string', 'description' => __( 'Wrap/item width, e.g. 1/2.', 'emcp-tools' ) ),
					'classes' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'BeBuilder global class ids (items only), from list-muffin-classes. Replaces the current list; [] clears it.', 'emcp-tools' ),
					),
				),
				'required'   => array( 'post_id', 'path' ),
			),
			$out_ok,
			false
		);

		$this->reg(
			'emcp-tools/remove-muffin-element',
			__( 'Remove Muffin Element', 'emcp-tools' ),
			__( 'Betheme Muffin Builder: deletes the section, wrap or item at path, with all its children. Destructive.', 'emcp-tools' ),
			'execute_remove_element',
			'check_write_permission',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => $post_id,
					'path'    => $path_schema + array( 'description' => __( 'Element path from get-muffin-page.', 'emcp-tools' ) ),
				),
				'required'   => array( 'post_id', 'path' ),
			),
			$out_ok,
			false,
			true
		);
	}

	/**
	 * Thin wrapper over emcp_tools_register_ability().
	 */
	private function reg( string $name, string $label, string $desc, string $cb, string $perm, array $in, array $out, bool $readonly, bool $destructive = false ): void {
		$this->ability_names[] = $name;
		emcp_tools_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $desc,
				'category'            => 'emcp-tools',
				'execute_callback'    => array( $this, $cb ),
				'permission_callback' => array( $this, $perm ),
				'input_schema'        => $in,
				'output_schema'       => $out,
				'meta'                => array(
					'annotations'  => array( 'readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $readonly ),
					'show_in_rest' => true,
				),
			)
		);
	}

	// ---- permissions -------------------------------------------------------

	/**
	 * @param array|null $input Input.
	 * @return bool
	 */
	public function check_read_permission( $input = null ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$post_id = absint( $input['post_id'] ?? 0 );
		return ! $post_id || current_user_can( 'edit_post', $post_id );
	}

	/**
	 * @param array|null $input Input.
	 * @return bool
	 */
	public function check_write_permission( $input = null ): bool {
		$post_id = absint( $input['post_id'] ?? 0 );
		return $post_id && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Non-blocking notices from the data layer (e.g. field ids not in the catalog).
	 *
	 * @return array
	 */
	private static function warnings(): array {
		$w = EMCP_Tools_Muffin_Data::take_warnings();
		return $w ? array( 'warnings' => $w ) : array();
	}

	// ---- executors ---------------------------------------------------------

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_list_items( $input = array() ): array {
		$catalog = EMCP_Tools_Muffin_Data::item_catalog();

		// Single item detail lookup
		if ( ! empty( $input['type'] ) ) {
			$type = strtolower( trim( (string) $input['type'] ) );
			if ( ! isset( $catalog[ $type ] ) ) {
				return array(
					'error' => sprintf(
						/* translators: %s: item type */
						__( 'Unknown item type "%s". Call list-muffin-items without parameters to view all available types.', 'emcp-tools' ),
						$type
					),
				);
			}
			$def = $catalog[ $type ];
			return array(
				'item' => array(
					'type'           => $type,
					'label'          => $def['label'] ?? $def['title'] ?? $type,
					'title'          => $def['title'] ?? $def['label'] ?? $type,
					'icon'           => $def['icon'] ?? '',
					'content_fields' => $def['content_fields'] ?? array(),
					'style_fields'   => $def['style_fields'] ?? array(),
				),
			);
		}

		// Filter by search keyword
		$search = ! empty( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';

		$rows = array();
		foreach ( $catalog as $type => $def ) {
			$label = (string) ( $def['label'] ?? $def['title'] ?? $type );
			if ( '' !== $search ) {
				if ( false === strpos( $type, $search ) && false === strpos( strtolower( $label ), $search ) ) {
					continue;
				}
			}
			$rows[] = array(
				'type'   => $type,
				'label'  => $label,
				'fields' => $def['fields'] ?? array(),
			);
		}

		return array(
			'items' => $rows,
			'count' => count( $rows ),
		);
	}

	/**
	 * @return array
	 */
	public function execute_list_classes(): array {
		return array( 'classes' => EMCP_Tools_Muffin_Data::global_classes() );
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_get_page( $input ): array {
		$post = $this->load_post( $input );
		if ( is_array( $post ) ) {
			return $post;
		}
		$tree = EMCP_Tools_Muffin_Data::load( (int) $post->ID );
		return array(
			'post_id'  => (int) $post->ID,
			'has_data' => ! empty( $tree ),
			'sections' => EMCP_Tools_Muffin_Data::summarize( $tree, ! empty( $input['full'] ) ),
		);
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_add_element( $input ): array {
		$post = $this->load_post( $input );
		if ( is_array( $post ) ) {
			return $post;
		}
		$parent = EMCP_Tools_Muffin_Data::clean_path( $input['parent_path'] ?? array() );
		if ( null === $parent || count( $parent ) > 7 ) {
			return array( 'error' => __( 'parent_path must be [], [section], [section, wrap] or a deeper item path (nested wraps).', 'emcp-tools' ) );
		}
		$element = isset( $input['element'] ) && is_array( $input['element'] ) ? $input['element'] : null;
		if ( null === $element ) {
			return array( 'error' => __( 'element must be an object.', 'emcp-tools' ) );
		}
		$node = EMCP_Tools_Muffin_Data::build_node( count( $parent ), $element );
		if ( is_string( $node ) ) {
			return array( 'error' => $node );
		}
		$index = isset( $input['index'] ) ? (int) $input['index'] : null;
		$tree  = EMCP_Tools_Muffin_Data::insert( EMCP_Tools_Muffin_Data::load( (int) $post->ID ), $parent, $node, $index );
		if ( null === $tree ) {
			return array( 'error' => __( 'parent_path does not resolve. Call get-muffin-page first.', 'emcp-tools' ) );
		}
		$css = EMCP_Tools_Muffin_Data::save( (int) $post->ID, $tree );
		return array( 'post_id' => (int) $post->ID, 'uid' => $node['uid'], 'parent_path' => $parent, 'css_regenerated' => $css ) + self::warnings();
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_update_element( $input ): array {
		$post = $this->load_post( $input );
		if ( is_array( $post ) ) {
			return $post;
		}
		$path = EMCP_Tools_Muffin_Data::clean_path( $input['path'] ?? array() );
		if ( ! $path ) {
			return array( 'error' => __( 'path is required.', 'emcp-tools' ) );
		}
		$changes = array_intersect_key( (array) $input, array_flip( array( 'fields', 'attr', 'size', 'classes', 'styles' ) ) );
		$tree    = EMCP_Tools_Muffin_Data::update( EMCP_Tools_Muffin_Data::load( (int) $post->ID ), $path, $changes );
		if ( is_string( $tree ) ) {
			return array( 'error' => $tree );
		}
		$css = EMCP_Tools_Muffin_Data::save( (int) $post->ID, $tree );
		return array( 'post_id' => (int) $post->ID, 'path' => $path, 'css_regenerated' => $css ) + self::warnings();
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_remove_element( $input ): array {
		$post = $this->load_post( $input );
		if ( is_array( $post ) ) {
			return $post;
		}
		$path = EMCP_Tools_Muffin_Data::clean_path( $input['path'] ?? array() );
		$tree = $path ? EMCP_Tools_Muffin_Data::remove( EMCP_Tools_Muffin_Data::load( (int) $post->ID ), $path ) : null;
		if ( null === $tree ) {
			return array( 'error' => __( 'path does not resolve to an element. Call get-muffin-page first.', 'emcp-tools' ) );
		}
		$css = EMCP_Tools_Muffin_Data::save( (int) $post->ID, $tree );
		return array( 'post_id' => (int) $post->ID, 'path' => $path, 'css_regenerated' => $css ) + self::warnings();
	}

	/**
	 * @param array $input Input.
	 * @return WP_Post|array The post, or an [error] result.
	 */
	private function load_post( $input ) {
		if ( ! EMCP_Tools_Muffin_Data::is_active() ) {
			return array( 'error' => __( 'The Betheme theme is not active.', 'emcp-tools' ) );
		}
		$post = get_post( absint( $input['post_id'] ?? 0 ) );
		return $post ? $post : array( 'error' => __( 'Post not found.', 'emcp-tools' ) );
	}
}
