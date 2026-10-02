<?php
/**
 * Page-builder detection and best-effort code-block extraction (Oxygen classic,
 * Oxygen 6 / Breakdance). Raw meta is always available via /post/{id}, so when
 * the heuristics miss, the data can still be inspected directly.
 */

defined( 'ABSPATH' ) || exit;

class SB_Oxygen {

	/** Meta keys known to hold builder trees. */
	const TREE_META_KEYS = array(
		'ct_builder_json',
		'ct_builder_shortcodes',
		'ct_builder_json_lite',
		'_breakdance_data',
		'breakdance_data',
		'_oxygen_data',
		'oxygen_data',
	);

	/** Post types registered by builders. */
	const BUILDER_POST_TYPES = array(
		'ct_template',
		'oxy_user_library',
		'breakdance_template',
		'breakdance_header',
		'breakdance_footer',
		'breakdance_block',
		'breakdance_popup',
		'breakdance_form_res',
		'oxygen_template',
		'oxygen_header',
		'oxygen_footer',
		'oxygen_block',
		'oxygen_popup',
	);

	public static function detect(): array {
		global $wpdb;

		$constants = array();
		foreach ( array( 'CT_VERSION', 'OXYGEN_VERSION', '__BREAKDANCE_VERSION', 'BREAKDANCE_VERSION', 'ELEMENTOR_VERSION', 'BRICKS_VERSION', 'ET_BUILDER_VERSION', 'WPB_VC_VERSION' ) as $c ) {
			if ( defined( $c ) ) {
				$constants[ $c ] = constant( $c );
			}
		}

		$post_types = array();
		foreach ( self::BUILDER_POST_TYPES as $pt ) {
			if ( post_type_exists( $pt ) ) {
				$counts            = (array) wp_count_posts( $pt );
				$post_types[ $pt ] = array_filter( $counts );
			}
		}

		$like = array();
		foreach ( array( 'ct\_%', '%breakdance%', '%oxygen%', 'oxy\_%', '\_elementor%', '\_bricks%' ) as $pattern ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, COUNT(*) AS posts, SUM(LENGTH(meta_value)) AS bytes FROM {$wpdb->postmeta} WHERE meta_key LIKE %s GROUP BY meta_key ORDER BY posts DESC LIMIT 100", $pattern ), ARRAY_A ); // phpcs:ignore
			foreach ( (array) $rows as $r ) {
				$like[ $r['meta_key'] ] = array(
					'posts' => (int) $r['posts'],
					'bytes' => (int) $r['bytes'],
				);
			}
		}

		$options = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE option_name LIKE 'oxygen%' OR option_name LIKE 'ct\_%' OR option_name LIKE 'breakdance%' ORDER BY option_name LIMIT 200", ARRAY_A ); // phpcs:ignore

		return array(
			'constants'      => $constants,
			'post_types'     => $post_types,
			'meta_keys'      => $like,
			'option_names'   => array_column( (array) $options, 'bytes', 'option_name' ),
			'tree_meta_keys' => self::TREE_META_KEYS,
		);
	}

	/**
	 * Posts that carry builder data, with the meta key(s) found on each.
	 */
	public static function posts_with_trees( int $limit = 500 ): array {
		global $wpdb;
		$keys = self::TREE_META_KEYS;
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$sql  = $wpdb->prepare(
			"SELECT p.ID, p.post_type, p.post_status, p.post_title, pm.meta_key, LENGTH(pm.meta_value) AS bytes
			 FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key IN ($in) AND pm.meta_value <> ''
			 ORDER BY p.post_type, p.ID LIMIT %d",
			array_merge( $keys, array( $limit ) )
		); // phpcs:ignore
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$id = (int) $r['ID'];
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array(
					'id'     => $id,
					'type'   => $r['post_type'],
					'status' => $r['post_status'],
					'title'  => $r['post_title'],
					'meta'   => array(),
				);
			}
			$out[ $id ]['meta'][ $r['meta_key'] ] = (int) $r['bytes'];
		}
		return array_values( $out );
	}

	/** All code blocks found in a post's builder meta. */
	public static function code_blocks_for_post( int $post_id ): array {
		$blocks = array();
		foreach ( self::TREE_META_KEYS as $key ) {
			$raw = get_post_meta( $post_id, $key, true );
			if ( $raw === '' || $raw === null || $raw === false ) {
				continue;
			}
			foreach ( self::extract( $key, $raw ) as $b ) {
				$b['post_id']  = $post_id;
				$b['meta_key'] = $key;
				$blocks[]      = $b;
			}
		}
		return $blocks;
	}

	public static function extract( string $meta_key, $raw ): array {
		if ( $meta_key === 'ct_builder_shortcodes' && is_string( $raw ) ) {
			return self::from_shortcodes( $raw );
		}
		$tree = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $tree ) ) {
			$tree = maybe_unserialize( $raw );
		}
		if ( ! is_array( $tree ) ) {
			return array();
		}
		$blocks = self::from_oxygen_json( $tree );
		if ( ! $blocks ) {
			$blocks = self::deep_scan( $tree );
		}
		return $blocks;
	}

	/* ---- Oxygen classic: JSON tree, nodes {id, name, options:{original:{...}}, children:[]} ---- */

	private static function from_oxygen_json( array $tree ): array {
		$blocks = array();
		self::walk(
			$tree,
			function ( array $node ) use ( &$blocks ) {
				if ( ( $node['name'] ?? '' ) !== 'ct_code_block' ) {
					return;
				}
				$o        = $node['options']['original'] ?? ( $node['options'] ?? array() );
				$blocks[] = array(
					'format'     => 'oxygen_json',
					'element_id' => $node['id'] ?? null,
					'label'      => $node['options']['nicename'] ?? ( $o['nicename'] ?? null ),
					'php'        => self::maybe_b64( $o['code-php'] ?? '' ),
					'css'        => self::maybe_b64( $o['code-css'] ?? '' ),
					'js'         => self::maybe_b64( $o['code-js'] ?? '' ),
				);
			}
		);
		return $blocks;
	}

	/* ---- Oxygen classic: shortcodes string [ct_code_block id="x" ct_options='{"ct_id":..,"original":{"code-php":"<b64>"}}'] ---- */

	private static function from_shortcodes( string $raw ): array {
		$blocks = array();
		if ( ! preg_match_all( '/\[ct_code_block\s+([^\]]*)\]/s', $raw, $m ) ) {
			return $blocks;
		}
		foreach ( $m[1] as $attr_str ) {
			$atts = shortcode_parse_atts( $attr_str );
			$opts = array();
			if ( ! empty( $atts['ct_options'] ) ) {
				$opts = json_decode( html_entity_decode( $atts['ct_options'], ENT_QUOTES ), true );
				if ( ! is_array( $opts ) ) {
					$opts = json_decode( $atts['ct_options'], true );
				}
			}
			$o        = is_array( $opts ) ? ( $opts['original'] ?? $opts ) : array();
			$blocks[] = array(
				'format'     => 'oxygen_shortcodes',
				'element_id' => $atts['id'] ?? ( $opts['ct_id'] ?? null ),
				'label'      => $opts['nicename'] ?? null,
				'php'        => self::maybe_b64( $o['code-php'] ?? '' ),
				'css'        => self::maybe_b64( $o['code-css'] ?? '' ),
				'js'         => self::maybe_b64( $o['code-js'] ?? '' ),
			);
		}
		return $blocks;
	}

	/* ---- Fallback for Breakdance / Oxygen 6 or unknown formats: look for PHP-ish keys ---- */

	private static function deep_scan( array $tree ): array {
		$blocks = array();
		self::walk(
			$tree,
			function ( array $node, array $path, array $ancestors ) use ( &$blocks ) {
				$php = null;
				$css = null;
				$js  = null;
				foreach ( $node as $k => $v ) {
					if ( ! is_string( $v ) || ! is_string( $k ) ) {
						continue;
					}
					if ( preg_match( '/^(code[-_]?php|php[-_]?code|phpcode|php)$/i', $k ) ) {
						$php = self::maybe_b64( $v );
					} elseif ( preg_match( '/^(code[-_]?css|css[-_]?code|css)$/i', $k ) ) {
						$css = self::maybe_b64( $v );
					} elseif ( preg_match( '/^(code[-_]?js|js[-_]?code|javascript|js)$/i', $k ) ) {
						$js = self::maybe_b64( $v );
					}
				}
				if ( $php !== null && $php !== '' ) {
					$id    = $node['id'] ?? null;
					$label = $node['label'] ?? ( $node['nicename'] ?? ( $node['type'] ?? null ) );
					for ( $i = count( $ancestors ) - 1; $i >= 0 && ( $id === null || $label === null ); $i-- ) {
						$a = $ancestors[ $i ];
						if ( $id === null && isset( $a['id'] ) && is_scalar( $a['id'] ) ) {
							$id = $a['id'];
						}
						if ( $label === null ) {
							$label = $a['label'] ?? ( $a['nicename'] ?? ( $a['type'] ?? ( $a['data']['type'] ?? null ) ) );
						}
					}
					$blocks[] = array(
						'format'     => 'deep_scan',
						'element_id' => $id,
						'label'      => $label,
						'path'       => implode( '.', $path ),
						'php'        => $php,
						'css'        => $css ?? '',
						'js'         => $js ?? '',
					);
				}
			}
		);
		return $blocks;
	}

	/** Depth-first walk calling $fn($node, $path, $ancestors) for every associative array. */
	public static function walk( array $node, callable $fn, array $path = array(), int $depth = 0, array $ancestors = array() ): void {
		if ( $depth > 60 ) {
			return;
		}
		$is_assoc = array_keys( $node ) !== range( 0, count( $node ) - 1 );
		if ( $is_assoc ) {
			$fn( $node, $path, $ancestors );
			$ancestors[] = $node;
		}
		foreach ( $node as $k => $v ) {
			if ( is_array( $v ) ) {
				self::walk( $v, $fn, array_merge( $path, array( (string) $k ) ), $depth + 1, $ancestors );
			}
		}
	}

	/** Oxygen stores code base64-encoded inside shortcodes; decode when it clearly is base64. */
	public static function maybe_b64( $value ): string {
		if ( ! is_string( $value ) || $value === '' ) {
			return '';
		}
		if ( strpos( $value, '<' ) !== false || strpos( $value, ' ' ) !== false || strpos( $value, "\n" ) !== false ) {
			return $value;
		}
		if ( ! preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/', $value ) || strlen( $value ) % 4 !== 0 ) {
			return $value;
		}
		$dec = base64_decode( $value, true );
		if ( $dec === false || ! mb_check_encoding( $dec, 'UTF-8' ) ) {
			return $value;
		}
		return $dec;
	}
}
