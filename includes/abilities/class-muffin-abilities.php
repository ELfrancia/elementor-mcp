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
						'description' => __( 'Section: {attr?, ver?:"default|header-sticky|header-mobile", classes?, wraps?:[wrap]}. Wrap: {size?:"1/3", attr?, items?:[item]}. Item: {type:"heading", size?:"1/1", fields:{title:"Hi", header_tag:"h2"}, styles?:{"color":"#fff","font-size":"48px","text-align":"center","margin-top":"20px"}, classes?:["be_heading_xxx"]}. Use list-muffin-classes for site styles.', 'emcp-tools' ),
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
						'description' => __( 'BeBuilder global class ids (sections, wraps and items), from list-muffin-classes. Replaces the current list; [] clears it.', 'emcp-tools' ),
					),
					'ver'     => array(
						'type'        => 'string',
						'enum'        => EMCP_Tools_Betheme_Store::SECTION_VERSIONS,
						'description' => __( 'Sections only: default, header-sticky or header-mobile (header templates).', 'emcp-tools' ),
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

		$this->register_site_tools();
	}

	/**
	 * Site-level Betheme tools: global classes, theme options, templates and
	 * per-post builder options.
	 */
	private function register_site_tools(): void {
		$css_attr = __( 'Raw attrs: css_* entries as {val, selector, style} using the element selector template from list-muffin-items (the mfnuidelement placeholder is rewritten to the class automatically); val may be responsive {desktop, laptop, tablet, mobile}.', 'emcp-tools' );

		$this->reg(
			'emcp-tools/get-muffin-class',
			__( 'Get Muffin Class', 'emcp-tools' ),
			__( 'Betheme: full definition of one BeBuilder global class (its element type, title and every style attr with selector and value).', 'emcp-tools' ),
			'execute_get_class',
			'check_read_permission',
			array(
				'type'       => 'object',
				'properties' => array( 'id' => array( 'type' => 'string', 'description' => __( 'Class id, e.g. be_heading_22qv02rk.', 'emcp-tools' ) ) ),
				'required'   => array( 'id' ),
			),
			array( 'type' => 'object', 'properties' => array( 'class' => array( 'type' => 'object' ) ) ),
			true
		);

		$this->reg(
			'emcp-tools/create-muffin-class',
			__( 'Create Muffin Class', 'emcp-tools' ),
			__( 'Betheme: creates a BeBuilder global class (a reusable style, editable in BeBuilder) for one element type and regenerates be_classes.css. Give styles as a simple map and/or raw css_* attrs. Apply it with classes:[id] on add/update-muffin-element; one edit to the class then restyles every element using it.', 'emcp-tools' ),
			'execute_create_class',
			'check_theme_permission',
			array(
				'type'       => 'object',
				'properties' => array(
					'element' => array( 'type' => 'string', 'description' => __( 'Element type the class styles (heading, button, column, section, wrap…).', 'emcp-tools' ) ),
					'title'   => array( 'type' => 'string', 'description' => __( 'Name shown in BeBuilder (must be unique).', 'emcp-tools' ) ),
					'styles'  => array( 'type' => 'object', 'description' => __( 'Simple styles, e.g. {"color":"#E31B23","font-size":"18px","text-transform":"uppercase"}.', 'emcp-tools' ) ),
					'attr'    => array( 'type' => 'object', 'description' => $css_attr ),
				),
				'required'   => array( 'element', 'title' ),
			),
			array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ), 'css_regenerated' => array( 'type' => 'boolean' ) ) ),
			false
		);

		$this->reg(
			'emcp-tools/update-muffin-class',
			__( 'Update Muffin Class', 'emcp-tools' ),
			__( 'Betheme: changes a BeBuilder global class (rename and/or merge styles/attr; an attr set to null is removed), then regenerates be_classes.css so every element using the class updates at once. Returns the previous definition for rollback.', 'emcp-tools' ),
			'execute_update_class',
			'check_theme_permission',
			array(
				'type'       => 'object',
				'properties' => array(
					'id'     => array( 'type' => 'string' ),
					'title'  => array( 'type' => 'string' ),
					'styles' => array( 'type' => 'object', 'description' => __( 'Simple styles to merge.', 'emcp-tools' ) ),
					'attr'   => array( 'type' => 'object', 'description' => $css_attr . ' ' . __( 'null removes an attr.', 'emcp-tools' ) ),
				),
				'required'   => array( 'id' ),
			),
			array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ), 'previous' => array( 'type' => 'object' ) ) ),
			false
		);

		$this->reg(
			'emcp-tools/delete-muffin-class',
			__( 'Delete Muffin Class', 'emcp-tools' ),
			__( 'Betheme: deletes a BeBuilder global class (needs confirm:true). Elements that used it lose that style. Returns the deleted definition so it can be recreated.', 'emcp-tools' ),
			'execute_delete_class',
			'check_theme_permission',
			array(
				'type'       => 'object',
				'properties' => array(
					'id'      => array( 'type' => 'string' ),
					'confirm' => array( 'type' => 'boolean', 'description' => __( 'Must be true.', 'emcp-tools' ) ),
				),
				'required'   => array( 'id', 'confirm' ),
			),
			array( 'type' => 'object', 'properties' => array( 'deleted' => array( 'type' => 'object' ) ) ),
			false,
			true
		);

		$this->reg(
			'emcp-tools/get-betheme-options',
			__( 'Get Betheme Options', 'emcp-tools' ),
			__( 'Betheme: reads Theme Options (colors, fonts, header, performance, custom CSS/JS…). Pass keys for exact values, or search to find keys by name; with no filter only the key list is returned. Long values are truncated unless full:true.', 'emcp-tools' ),
			'execute_get_options',
			'check_theme_permission',
			array(
				'type'       => 'object',
				'properties' => array(
					'keys'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'search' => array( 'type' => 'string', 'description' => __( 'Substring of the option key, e.g. "font" or "color".', 'emcp-tools' ) ),
					'full'   => array( 'type' => 'boolean' ),
				),
			),
			array( 'type' => 'object', 'properties' => array( 'options' => array( 'type' => 'object' ), 'count' => array( 'type' => 'integer' ) ) ),
			true
		);

		$this->reg(
			'emcp-tools/update-betheme-options',
			__( 'Update Betheme Options', 'emcp-tools' ),
			__( 'Betheme: changes existing Theme Options, as the BeBuilder Theme Options panel does. Only existing keys, same value type as now; code options (custom-js, custom-css…) need unfiltered_html. Returns the previous values for rollback.', 'emcp-tools' ),
			'execute_update_options',
			'check_theme_permission',
			array(
				'type'       => 'object',
				'properties' => array(
					'values' => array( 'type' => 'object', 'description' => __( 'key => new value, keys from get-betheme-options.', 'emcp-tools' ) ),
				),
				'required'   => array( 'values' ),
			),
			array( 'type' => 'object', 'properties' => array( 'changed' => array( 'type' => 'array' ), 'previous' => array( 'type' => 'object' ) ) ),
			false
		);

		$this->reg(
			'emcp-tools/list-muffin-templates',
			__( 'List Muffin Templates', 'emcp-tools' ),
			__( 'Betheme: lists theme templates (header, footer, sidemenu, single, archive…) with their display conditions and template options (e.g. header_mobile, mobile_header_position, sidemenu css_*). Edit a template layout with the muffin element tools using its id as post_id.', 'emcp-tools' ),
			'execute_list_templates',
			'check_read_permission',
			array(
				'type'       => 'object',
				'properties' => array( 'type' => array( 'type' => 'string', 'description' => __( 'Optional filter: header, footer, sidemenu, single-post…', 'emcp-tools' ) ) ),
			),
			array( 'type' => 'object', 'properties' => array( 'templates' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ) ) ),
			true
		);

		$this->reg(
			'emcp-tools/update-muffin-post-options',
			__( 'Update Muffin Post Options', 'emcp-tools' ),
			__( 'Betheme: sets page/template options as the BeBuilder options panel does, e.g. header_mobile:"enabled", mobile_header_position:"fixed", sidemenu css_bg:{val,selector,style} (folded into mfn-page-options-style). null or "" removes an option. mfn-post-js/mfn-post-css need unfiltered_html. Returns previous values.', 'emcp-tools' ),
			'execute_update_post_options',
			'check_write_permission',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer', 'description' => __( 'Page or template ID.', 'emcp-tools' ) ),
					'options' => array( 'type' => 'object' ),
				),
				'required'   => array( 'post_id', 'options' ),
			),
			array( 'type' => 'object', 'properties' => array( 'changed' => array( 'type' => 'array' ), 'previous' => array( 'type' => 'object' ) ) ),
			false
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
	 * Site-wide styles and Theme Options.
	 *
	 * @param array|null $input Input.
	 * @return bool
	 */
	public function check_theme_permission( $input = null ): bool {
		unset( $input );
		return current_user_can( 'edit_theme_options' );
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
		$changes = array_intersect_key( (array) $input, array_flip( array( 'fields', 'attr', 'size', 'classes', 'styles', 'ver' ) ) );
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
	 * @return array|null Error result when Betheme is not active.
	 */
	private static function inactive(): ?array {
		return EMCP_Tools_Muffin_Data::is_active() ? null : array( 'error' => __( 'The Betheme theme is not active.', 'emcp-tools' ) );
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_get_class( $input ): array {
		$err = self::inactive();
		if ( $err ) {
			return $err;
		}
		$class = EMCP_Tools_Betheme_Store::get_class( (string) ( $input['id'] ?? '' ) );
		return $class ? array( 'class' => $class ) : array( 'error' => __( 'Class not found. Call list-muffin-classes.', 'emcp-tools' ) );
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_create_class( $input ): array {
		$err = self::inactive();
		if ( $err ) {
			return $err;
		}
		$element = strtolower( trim( (string) ( $input['element'] ?? '' ) ) );
		$title   = trim( sanitize_text_field( (string) ( $input['title'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-z0-9_]+$/', $element ) || '' === $title ) {
			return array( 'error' => __( 'element (e.g. heading) and title are required.', 'emcp-tools' ) );
		}
		if ( ! in_array( $element, array( 'section', 'wrap' ), true ) && ! EMCP_Tools_Muffin_Data::is_known_item_type( $element ) ) {
			return array( 'error' => __( 'Unknown element type. See list-muffin-items.', 'emcp-tools' ) );
		}
		$all = EMCP_Tools_Betheme_Store::load_classes();
		foreach ( $all['builder'] as $c ) {
			if ( isset( $c['title'] ) && 0 === strcasecmp( (string) $c['title'], $title ) ) {
				return array( 'error' => __( 'A class with this title already exists.', 'emcp-tools' ) );
			}
		}
		$uid  = EMCP_Tools_Betheme_Store::new_class_id( $element );
		$attr = EMCP_Tools_Betheme_Store::build_class_attr( $element, $uid, (array) ( $input['styles'] ?? array() ), (array) ( $input['attr'] ?? array() ) );
		if ( is_string( $attr ) ) {
			return array( 'error' => $attr );
		}
		$attr = array_filter(
			$attr,
			static function ( $v ) {
				return null !== $v;
			}
		);
		if ( ! $attr ) {
			return array( 'error' => __( 'Give at least one style or attr.', 'emcp-tools' ) );
		}
		$all['builder'][] = array(
			'jsclass' => $element,
			'type'    => 'be_class',
			'title'   => $title,
			'uid'     => $uid,
			'attr'    => $attr,
		);
		$css = EMCP_Tools_Betheme_Store::save_classes( $all['builder'] );
		return array( 'id' => $uid, 'css_regenerated' => $css );
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_update_class( $input ): array {
		$err = self::inactive();
		if ( $err ) {
			return $err;
		}
		$id  = (string) ( $input['id'] ?? '' );
		$all = EMCP_Tools_Betheme_Store::load_classes();
		foreach ( $all['builder'] as $i => $c ) {
			if ( ( $c['uid'] ?? '' ) !== $id ) {
				continue;
			}
			$previous = $c;
			if ( isset( $input['title'] ) ) {
				$title = trim( sanitize_text_field( (string) $input['title'] ) );
				if ( '' === $title ) {
					return array( 'error' => __( 'title cannot be empty.', 'emcp-tools' ) );
				}
				foreach ( $all['builder'] as $j => $other ) {
					if ( $j !== $i && isset( $other['title'] ) && 0 === strcasecmp( (string) $other['title'], $title ) ) {
						return array( 'error' => __( 'A class with this title already exists.', 'emcp-tools' ) );
					}
				}
				$c['title'] = $title;
			}
			$attr = EMCP_Tools_Betheme_Store::build_class_attr( (string) ( $c['jsclass'] ?? '' ), $id, (array) ( $input['styles'] ?? array() ), (array) ( $input['attr'] ?? array() ) );
			if ( is_string( $attr ) ) {
				return array( 'error' => $attr );
			}
			$merged = (array) ( $c['attr'] ?? array() );
			foreach ( $attr as $k => $v ) {
				if ( null === $v ) {
					unset( $merged[ $k ] );
				} else {
					$merged[ $k ] = $v;
				}
			}
			$c['attr']            = $merged;
			$all['builder'][ $i ] = $c;
			$css                  = EMCP_Tools_Betheme_Store::save_classes( $all['builder'] );
			return array( 'id' => $id, 'css_regenerated' => $css, 'previous' => $previous );
		}
		return array( 'error' => __( 'Class not found. Call list-muffin-classes.', 'emcp-tools' ) );
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_delete_class( $input ): array {
		$err = self::inactive();
		if ( $err ) {
			return $err;
		}
		if ( true !== ( $input['confirm'] ?? false ) ) {
			return array( 'error' => __( 'Pass confirm:true to delete a class.', 'emcp-tools' ) );
		}
		$id  = (string) ( $input['id'] ?? '' );
		$all = EMCP_Tools_Betheme_Store::load_classes();
		foreach ( $all['builder'] as $i => $c ) {
			if ( ( $c['uid'] ?? '' ) === $id ) {
				unset( $all['builder'][ $i ] );
				$css = EMCP_Tools_Betheme_Store::save_classes( $all['builder'] );
				return array( 'deleted' => $c, 'css_regenerated' => $css );
			}
		}
		return array( 'error' => __( 'Class not found. Call list-muffin-classes.', 'emcp-tools' ) );
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_get_options( $input = array() ): array {
		$err = self::inactive();
		if ( $err ) {
			return $err;
		}
		$opts   = EMCP_Tools_Betheme_Store::theme_options();
		$keys   = array_map( 'strval', (array) ( $input['keys'] ?? array() ) );
		$search = strtolower( trim( (string) ( $input['search'] ?? '' ) ) );
		$full   = ! empty( $input['full'] );
		if ( ! $keys && '' === $search ) {
			// No filter: the key list only, to keep the answer small.
			return array( 'keys' => array_map( 'strval', array_keys( $opts ) ), 'count' => count( $opts ) );
		}
		$out = array();
		foreach ( $opts as $k => $v ) {
			$k = (string) $k;
			if ( $keys && ! in_array( $k, $keys, true ) ) {
				continue;
			}
			if ( ! $keys && false === strpos( strtolower( $k ), $search ) ) {
				continue;
			}
			if ( ! $full && is_string( $v ) && strlen( $v ) > 200 ) {
				$v = substr( $v, 0, 200 ) . '… (' . strlen( $v ) . ' chars, pass full:true)';
			}
			$out[ $k ] = $v;
		}
		return array( 'options' => $out, 'count' => count( $out ) );
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_update_options( $input ): array {
		$err = self::inactive();
		if ( $err ) {
			return $err;
		}
		$values = isset( $input['values'] ) && is_array( $input['values'] ) ? $input['values'] : array();
		if ( ! $values ) {
			return array( 'error' => __( 'values must be a non-empty object.', 'emcp-tools' ) );
		}
		$res = EMCP_Tools_Betheme_Store::update_theme_options( $values, current_user_can( 'unfiltered_html' ) );
		return is_string( $res ) ? array( 'error' => $res ) : $res;
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_list_templates( $input = array() ): array {
		$err = self::inactive();
		if ( $err ) {
			return $err;
		}
		$type = sanitize_key( (string) ( $input['type'] ?? '' ) );
		return array( 'templates' => EMCP_Tools_Betheme_Store::list_templates( $type ) );
	}

	/**
	 * @param array $input Input.
	 * @return array
	 */
	public function execute_update_post_options( $input ): array {
		$post = $this->load_post( $input );
		if ( is_array( $post ) ) {
			return $post;
		}
		$options = isset( $input['options'] ) && is_array( $input['options'] ) ? $input['options'] : array();
		if ( ! $options ) {
			return array( 'error' => __( 'options must be a non-empty object.', 'emcp-tools' ) );
		}
		$can_code = current_user_can( 'unfiltered_html' );
		// Check every key first so a bad key never leaves a half-applied change.
		foreach ( array_keys( $options ) as $key ) {
			if ( ! EMCP_Tools_Betheme_Store::is_post_option_key( (string) $key ) ) {
				return array(
					'error' => sprintf(
						/* translators: %s: option key */
						__( 'Option "%s" is not a Betheme post option.', 'emcp-tools' ),
						(string) $key
					),
				);
			}
			if ( in_array( (string) $key, EMCP_Tools_Betheme_Store::CODE_POST_OPTIONS, true ) && ! $can_code ) {
				return array( 'error' => __( 'mfn-post-js / mfn-post-css need the unfiltered_html capability.', 'emcp-tools' ) );
			}
		}
		$previous = array();
		$changed  = array();
		foreach ( $options as $key => $value ) {
			$key = (string) $key;
			$old = metadata_exists( 'post', (int) $post->ID, $key ) ? get_post_meta( (int) $post->ID, $key, true ) : null;
			$res = EMCP_Tools_Betheme_Store::set_post_option( (int) $post->ID, $key, $value, $can_code );
			if ( true !== $res ) {
				return array( 'error' => $res, 'changed' => $changed, 'previous' => $previous );
			}
			$dec              = is_string( $old ) && 0 === strpos( $key, 'css_' ) ? json_decode( $old, true ) : null;
			$previous[ $key ] = is_array( $dec ) ? $dec : $old;
			$changed[]        = $key;
		}
		return array( 'post_id' => (int) $post->ID, 'changed' => $changed, 'previous' => $previous );
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
