<?php
/**
 * Betheme site-level storage: BeBuilder global classes, theme options,
 * templates and per-post builder options.
 *
 * Every write mirrors what Betheme's own admin AJAX handlers do
 * (visual-builder/visual-builder.php: mfnvb_saveclasses,
 * mfnvb_savethemeoptions, mfnvb_savepostoption), so the result is exactly
 * what BeBuilder would have saved and stays editable there.
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
class EMCP_Tools_Betheme_Store {

	const OPT_CLASSES       = 'be_classes';
	const OPT_CLASSES_FONTS = 'be_classes_fonts';
	const OPT_THEME         = 'betheme';

	/** Theme option keys whose value is executable code (needs unfiltered_html). */
	const CODE_OPTIONS = array( 'custom-js', 'custom-css', 'google-analytics', 'facebook-pixel', 'custom-head', 'custom-footer' );

	/** Post option keys whose value is executable code (needs unfiltered_html). */
	const CODE_POST_OPTIONS = array( 'mfn-post-js', 'mfn-post-css' );

	/** Section variants Betheme renders (default, sticky header, mobile header). */
	const SECTION_VERSIONS = array( 'default', 'header-sticky', 'header-mobile' );

	// ---- global classes ----------------------------------------------------

	/**
	 * Decoded `be_classes` option: { builder: [class…], css: {…} }.
	 *
	 * @return array
	 */
	public static function load_classes(): array {
		$raw = get_option( self::OPT_CLASSES, '' );
		if ( is_string( $raw ) ) {
			$raw = '' === $raw ? array() : json_decode( $raw, true );
		}
		$raw = is_array( $raw ) ? $raw : array();
		if ( ! isset( $raw['builder'] ) || ! is_array( $raw['builder'] ) ) {
			$raw['builder'] = array();
		}
		$raw['builder'] = array_values( $raw['builder'] );
		return $raw;
	}

	/**
	 * Full detail of one class, or null.
	 *
	 * @param string $id Class uid (be_heading_xxxxxxxx).
	 * @return array|null
	 */
	public static function get_class( string $id ): ?array {
		foreach ( self::load_classes()['builder'] as $c ) {
			if ( isset( $c['uid'] ) && $c['uid'] === $id ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * New class uid the way BeBuilder makes it: be_{jsclass}_{8 chars}.
	 *
	 * @param string $element Element type (jsclass).
	 * @return string
	 */
	public static function new_class_id( string $element ): string {
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$tail  = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$tail .= $chars[ random_int( 0, 35 ) ];
		}
		return 'be_' . $element . '_' . $tail;
	}

	/**
	 * Rewrite an element selector template into the class selector, exactly as
	 * BeBuilder's mfn_classes.ajax() does.
	 *
	 * @param string $selector Selector with the mfnuidelement placeholder.
	 * @param string $uid      Class uid.
	 * @return string
	 */
	public static function class_selector( string $selector, string $uid ): string {
		$selector = str_replace( '.mcb-section .mcb-wrap .mcb-item-mfnuidelement', '.column.' . $uid, $selector );
		$selector = str_replace( '.mcb-section .mcb-wrap-mfnuidelement', '.' . $uid, $selector );
		$selector = str_replace( 'mcb-section-mfnuidelement', $uid, $selector );
		return $selector;
	}

	/**
	 * CSS value guard: values end up inside generated stylesheets, so reject
	 * anything that could close a rule or load code.
	 *
	 * @param mixed $value Value (scalar or nested responsive array).
	 * @return bool
	 */
	public static function is_safe_css_value( $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				if ( is_string( $k ) && ! preg_match( '/^[A-Za-z0-9_\-]+$/', $k ) ) {
					return false;
				}
				if ( ! self::is_safe_css_value( $v ) ) {
					return false;
				}
			}
			return true;
		}
		if ( ! is_scalar( $value ) && null !== $value ) {
			return false;
		}
		$s = (string) $value;
		if ( preg_match( '/[<>{};\\\\]/', $s ) ) {
			return false;
		}
		return ! preg_match( '/@import|expression\s*\(|javascript:|behavior\s*:/i', $s );
	}

	/**
	 * Selector guard for raw css_* attrs.
	 *
	 * @param string $selector Selector.
	 * @return bool
	 */
	public static function is_safe_selector( string $selector ): bool {
		return '' !== $selector && strlen( $selector ) < 2000 && ! preg_match( '/[<>{};\\\\]|\/\*/', $selector );
	}

	/**
	 * Build class attrs from a simple styles map and/or raw css_* attrs.
	 *
	 * @param string $element Element type.
	 * @param string $uid     Class uid.
	 * @param array  $styles  Simple map, e.g. {"color":"#fff"}.
	 * @param array  $attr    Raw attrs ({css_x:{val,selector,style}} or plain settings).
	 * @return array|string Attr map or error.
	 */
	public static function build_class_attr( string $element, string $uid, array $styles, array $attr ) {
		$out = array();
		if ( $styles ) {
			$resolved = EMCP_Tools_Muffin_Data::resolve_styles( $element, $styles );
			if ( is_string( $resolved ) ) {
				return $resolved;
			}
			$attr = array_merge( $resolved, $attr );
		}
		foreach ( $attr as $key => $opt ) {
			$key = (string) $key;
			if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $key ) ) {
				return 'Invalid attr key "' . $key . '".';
			}
			if ( null === $opt ) {
				$out[ $key ] = null; // Removal marker, handled by the caller.
				continue;
			}
			if ( 0 === strpos( $key, 'css_' ) ) {
				if ( ! is_array( $opt ) || ! isset( $opt['val'], $opt['selector'], $opt['style'] ) ) {
					return 'css_* attr "' . $key . '" must be {val, selector, style}.';
				}
				$selector = self::class_selector( (string) $opt['selector'], $uid );
				if ( ! self::is_safe_selector( $selector ) || ! preg_match( '/^[A-Za-z0-9_\-]+$/', (string) $opt['style'] ) ) {
					return 'css_* attr "' . $key . '" has an invalid selector or style.';
				}
				if ( ! self::is_safe_css_value( $opt['val'] ) ) {
					return 'css_* attr "' . $key . '" has an unsafe value (no < > { } ; \\ or code).';
				}
				$out[ $key ] = array(
					'val'      => $opt['val'],
					'selector' => $selector,
					'style'    => (string) $opt['style'],
				);
				continue;
			}
			if ( ! self::is_safe_css_value( $opt ) ) {
				return 'attr "' . $key . '" has an unsafe value.';
			}
			$out[ $key ] = $opt;
		}
		return $out;
	}

	/**
	 * Persist the class list and regenerate be_classes.css + class fonts,
	 * as mfnvb_saveclasses() does.
	 *
	 * @param array $builder Class list.
	 * @return bool Whether Betheme regenerated the CSS.
	 */
	public static function save_classes( array $builder ): bool {
		$all            = self::load_classes();
		$all['builder'] = array_values( $builder );
		$css_ok         = false;

		EMCP_Tools_Muffin_Data::load_betheme_classes();
		if ( class_exists( 'Mfn_Helper' ) && method_exists( 'Mfn_Helper', 'preparePostUpdate' ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			try {
				$styles = Mfn_Helper::preparePostUpdate( $all['builder'], 'be_classes', 'mfn-be-classes-style' );
				if ( is_array( $styles ) ) {
					$all['css'] = $styles;
					$css_ok     = true;
					if ( ! empty( $styles['fonts'] ) && is_array( $styles['fonts'] ) ) {
						$old   = json_decode( (string) get_option( self::OPT_CLASSES_FONTS, '[]' ), true );
						$fonts = array_values( array_unique( array_merge( $styles['fonts'], is_array( $old ) ? $old : array() ) ) );
						update_option( self::OPT_CLASSES_FONTS, wp_json_encode( $fonts ) );
					}
				}
			} catch ( \Throwable $e ) {
				$css_ok = false;
			}
		}

		update_option( self::OPT_CLASSES, wp_json_encode( $all ) );
		return $css_ok;
	}

	// ---- theme options ----------------------------------------------------

	/**
	 * @return array
	 */
	public static function theme_options(): array {
		$opts = get_option( self::OPT_THEME, array() );
		return is_array( $opts ) ? $opts : array();
	}

	/**
	 * Validate and apply theme option changes. Only existing keys may change and
	 * the value type (string vs list) must match, so a typo can never create
	 * junk keys or break Betheme's option reader.
	 *
	 * @param array $values  key => new value.
	 * @param bool  $can_code Whether the caller may write code options.
	 * @return array{previous:array,changed:string[]}|string
	 */
	public static function update_theme_options( array $values, bool $can_code ) {
		$opts     = self::theme_options();
		$previous = array();
		if ( ! $opts ) {
			return 'Betheme options are empty or unreadable.';
		}
		foreach ( $values as $key => $val ) {
			$key = (string) $key;
			if ( ! array_key_exists( $key, $opts ) ) {
				return 'Unknown theme option "' . $key . '". Use get-betheme-options to find the exact key.';
			}
			if ( in_array( $key, self::CODE_OPTIONS, true ) && ! $can_code ) {
				return 'Option "' . $key . '" holds code and needs the unfiltered_html capability.';
			}
			$old = $opts[ $key ];
			if ( is_array( $old ) !== is_array( $val ) ) {
				return 'Option "' . $key . '" must be ' . ( is_array( $old ) ? 'an object/list' : 'a string' ) . ', like its current value.';
			}
			if ( ! is_array( $val ) && ! is_scalar( $val ) ) {
				return 'Option "' . $key . '" has an invalid value.';
			}
			$val = is_array( $val ) ? self::clean_option_array( $val, $can_code ) : (string) $val;
			if ( ! in_array( $key, self::CODE_OPTIONS, true ) && is_string( $val ) && ! $can_code ) {
				$val = wp_kses_post( $val );
			}
			$previous[ $key ] = $old;
			$opts[ $key ]     = $val;
		}
		update_option( self::OPT_THEME, $opts );
		return array(
			'previous' => $previous,
			'changed'  => array_keys( $previous ),
		);
	}

	/**
	 * @param array $val      Nested option value.
	 * @param bool  $can_code unfiltered_html.
	 * @return array
	 */
	private static function clean_option_array( array $val, bool $can_code ): array {
		$out = array();
		foreach ( $val as $k => $v ) {
			if ( is_string( $k ) && ! preg_match( '/^[A-Za-z0-9_\-]+$/', $k ) ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$out[ $k ] = self::clean_option_array( $v, $can_code );
			} elseif ( is_scalar( $v ) ) {
				$out[ $k ] = $can_code ? (string) $v : wp_kses_post( (string) $v );
			}
		}
		return $out;
	}

	// ---- templates ---------------------------------------------------------

	/**
	 * Betheme templates (header, footer, sidemenu, single, archive…).
	 *
	 * @param string $type Optional mfn_template_type filter.
	 * @return array
	 */
	public static function list_templates( string $type = '' ): array {
		$args = array(
			'post_type'      => 'template',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 200,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);
		if ( '' !== $type ) {
			$args['meta_key']   = 'mfn_template_type'; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['meta_value'] = $type; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$rows = array();
		foreach ( get_posts( $args ) as $p ) {
			$conditions = json_decode( (string) get_post_meta( $p->ID, 'mfn_template_conditions', true ), true );
			$rows[]     = array(
				'id'         => (int) $p->ID,
				'title'      => $p->post_title,
				'status'     => $p->post_status,
				'type'       => (string) get_post_meta( $p->ID, 'mfn_template_type', true ),
				'conditions' => is_array( $conditions ) ? $conditions : array(),
				'options'    => self::post_options( (int) $p->ID ),
			);
		}
		return $rows;
	}

	// ---- per-post builder options -------------------------------------------

	/**
	 * Whether a meta key is a Betheme post option (and not builder content,
	 * revisions or WordPress internals).
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_post_option_key( string $key ): bool {
		if ( ! preg_match( '/^(mfn[-_][a-z0-9_\-]+|css_[a-z0-9_\-]+|header_[a-z0-9_]+|mobile_[a-z0-9_]+|sidemenu_[a-z0-9_]+)$/', $key ) ) {
			return false;
		}
		$blocked = array( 'mfn-page-items', 'mfn-page-items-seo', 'mfn-page-object', 'mfn-builder-preview', 'mfn-page-local-style', 'mfn-builder-preview-local-style', 'mfn-page-options-style', 'mfn-page-fonts', 'mfn-builder-preview-fonts', 'mfn_template_type', 'mfn_template_conditions' );
		if ( in_array( $key, $blocked, true ) ) {
			return false;
		}
		return 0 !== strpos( $key, 'mfn-builder-revision' );
	}

	/**
	 * Current Betheme options of a post (values truncated to 300 chars).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function post_options( int $post_id ): array {
		$out = array();
		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
			$key = (string) $key;
			if ( ! self::is_post_option_key( $key ) ) {
				continue;
			}
			$v = get_post_meta( $post_id, $key, true );
			if ( is_string( $v ) && 0 === strpos( $key, 'css_' ) ) {
				$d = json_decode( $v, true );
				$v = is_array( $d ) ? $d : $v;
			}
			if ( is_string( $v ) && strlen( $v ) > 300 ) {
				$v = substr( $v, 0, 300 ) . '…';
			}
			$out[ $key ] = $v;
		}
		return $out;
	}

	/**
	 * Apply one post option the way mfnvb_savepostoption() does: plain values go
	 * to post meta; css_* values ({val, selector, style}) are stored as JSON and
	 * folded into `mfn-page-options-style`, which Betheme prints per request.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $key      Option key.
	 * @param mixed  $value    Value; null or '' deletes it.
	 * @param bool   $can_code unfiltered_html.
	 * @return true|string
	 */
	public static function set_post_option( int $post_id, string $key, $value, bool $can_code ) {
		if ( ! self::is_post_option_key( $key ) ) {
			return 'Option "' . $key . '" is not a Betheme post option (builder content, template type/conditions and revisions have their own tools).';
		}
		if ( in_array( $key, self::CODE_POST_OPTIONS, true ) && ! $can_code ) {
			return 'Option "' . $key . '" holds code and needs the unfiltered_html capability.';
		}

		$is_css = 0 === strpos( $key, 'css_' );
		if ( $is_css && null !== $value && '' !== $value ) {
			if ( ! is_array( $value ) || ! isset( $value['val'], $value['selector'], $value['style'] ) ) {
				return 'css_* option "' . $key . '" must be {val, selector, style}.';
			}
			if ( ! self::is_safe_selector( (string) $value['selector'] ) || ! preg_match( '/^[A-Za-z0-9_\-]+$/', (string) $value['style'] ) || ! self::is_safe_css_value( $value['val'] ) ) {
				return 'css_* option "' . $key . '" has an unsafe selector, style or value.';
			}
		} elseif ( null !== $value && '' !== $value ) {
			if ( is_array( $value ) ) {
				return 'Option "' . $key . '" must be a string.';
			}
			$value = (string) $value;
			if ( ! in_array( $key, self::CODE_POST_OPTIONS, true ) ) {
				$value = $can_code ? $value : wp_kses_post( $value );
			}
		}

		$old_meta = get_post_meta( $post_id, $key, true );
		if ( null === $value || '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, wp_slash( $is_css ? wp_json_encode( $value ) : $value ) );
		}

		if ( $is_css ) {
			$styles = get_post_meta( $post_id, 'mfn-page-options-style', true );
			$styles = is_array( $styles ) ? $styles : array();

			// Drop the previous rule of this option (its selector may have changed).
			$old = is_string( $old_meta ) ? json_decode( $old_meta, true ) : null;
			if ( is_array( $old ) && isset( $old['selector'], $old['style'] ) ) {
				$old_path = str_replace( '|hover', ':hover', (string) $old['selector'] );
				unset( $styles[ $old_path ][ (string) $old['style'] ] );
				if ( empty( $styles[ $old_path ] ) ) {
					unset( $styles[ $old_path ] );
				}
			}
			if ( null !== $value && '' !== $value ) {
				$path                                    = str_replace( '|hover', ':hover', (string) $value['selector'] );
				$styles[ $path ][ (string) $value['style'] ] = $value['val'];
			}
			update_post_meta( $post_id, 'mfn-page-options-style', $styles );
		}
		clean_post_cache( $post_id );
		return true;
	}
}
