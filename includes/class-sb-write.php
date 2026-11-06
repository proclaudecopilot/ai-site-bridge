<?php
/**
 * Write layer. Everything here MUTATES the site and only runs when the
 * "allow_write" feature is enabled in settings (plus the usual token/IP/rate
 * checks). Every mutation snapshots the previous value into the write audit
 * log so it can be reversed through /write/undo/{id}.
 *
 * Routes (all POST unless noted):
 *   POST   /write/post            create a post
 *   POST   /write/post/{id}       update a post's fields
 *   DELETE /write/post/{id}       trash (or ?force=1 delete) a post
 *   POST   /write/meta            set / delete one post meta key
 *   POST   /write/option          set / delete one option
 *   POST   /write/code-block      replace php/css/js of one Oxygen code block
 *   POST   /write/file            write a file on disk (allow_write_files)
 *   POST   /write/undo/{id}       restore the snapshot of an earlier write
 */

defined( 'ABSPATH' ) || exit;

class SB_Write {

	/** Options the bridge refuses to overwrite through the API (its own state). */
	const PROTECTED_OPTIONS = array(
		'site_bridge_settings',
		'site_bridge_log',
		'site_bridge_write_log',
		'site_bridge_errors',
	);

	/* ------------------------------------------------------------------ helpers */

	private static function respond( $data, array $extra = array() ) {
		return rest_ensure_response( array_merge( array( 'ok' => true, 'wrote' => true ), $extra, array( 'data' => $data ) ) );
	}

	private static function fail( string $code, string $msg, int $status = 400 ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	/** Pull a parameter whether it arrived as JSON body or query string. */
	private static function param( WP_REST_Request $r, string $key, $default = null ) {
		$v = $r->get_param( $key );
		return $v === null ? $default : $v;
	}

	private static function truthy( $v ): bool {
		return in_array( $v, array( true, 1, '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Turn an incoming value into what should be stored.
	 *  - raw=1   : keep the exact string.
	 *  - json=1  : json_decode a string value (arrays/objects/scalars).
	 *  - arrays coming straight from a JSON request body are used as-is.
	 */
	private static function decode_value( WP_REST_Request $r, $value ) {
		if ( self::truthy( self::param( $r, 'raw' ) ) ) {
			return is_string( $value ) ? $value : wp_json_encode( $value );
		}
		if ( self::truthy( self::param( $r, 'json' ) ) && is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				return $decoded;
			}
			return new WP_Error( 'sb_bad_json', 'json=1 was set but the value is not valid JSON: ' . json_last_error_msg(), array( 'status' => 400 ) );
		}
		return $value;
	}

	private static function clip( $s, int $len = 160 ): string {
		$s = is_scalar( $s ) ? (string) $s : wp_json_encode( $s );
		$s = preg_replace( '/\s+/', ' ', (string) $s );
		return mb_strlen( $s ) > $len ? mb_substr( $s, 0, $len ) . '…' : $s;
	}

	/* ------------------------------------------------------------------ posts */

	public static function create_post( WP_REST_Request $r ) {
		$fields = self::collect_post_fields( $r, true );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		if ( empty( $fields['post_type'] ) ) {
			$fields['post_type'] = 'post';
		}
		if ( ! post_type_exists( $fields['post_type'] ) ) {
			return self::fail( 'sb_bad_type', 'Unknown post type: ' . $fields['post_type'], 400 );
		}
		if ( empty( $fields['post_status'] ) ) {
			$fields['post_status'] = 'draft';
		}

		$meta = self::param( $r, 'meta' );
		$id   = wp_insert_post( wp_slash( $fields ), true );
		if ( is_wp_error( $id ) ) {
			return self::fail( 'sb_insert_failed', $id->get_error_message(), 400 );
		}
		if ( is_array( $meta ) ) {
			foreach ( $meta as $k => $v ) {
				update_post_meta( $id, $k, wp_slash( $v ) );
			}
		}

		$log_id = SB_Auth::write_log(
			array(
				'action'  => 'create_post',
				'target'  => 'post ' . $id,
				'summary' => sprintf( '%s "%s" created (%s)', $fields['post_type'], self::clip( $fields['post_title'] ?? '' ), $fields['post_status'] ),
				'before'  => array( 'post_id' => $id, 'existed' => false ),
			)
		);
		return self::respond(
			array(
				'id'        => $id,
				'edit_link' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
				'link'      => get_permalink( $id ),
			),
			array( 'undo_id' => $log_id )
		);
	}

	public static function update_post( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$p  = get_post( $id );
		if ( ! $p ) {
			return self::fail( 'sb_not_found', 'No post with id ' . $id, 404 );
		}
		$fields = self::collect_post_fields( $r, false );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		if ( ! $fields ) {
			return self::fail( 'sb_no_fields', 'Nothing to update. Send post_title, post_content, post_status, post_excerpt, post_name, post_parent or menu_order.', 400 );
		}

		// Snapshot only the fields being changed, so undo is precise.
		$before = array( 'post_id' => $id, 'existed' => true, 'fields' => array() );
		foreach ( array_keys( $fields ) as $key ) {
			if ( isset( $p->$key ) ) {
				$before['fields'][ $key ] = $p->$key;
			}
		}

		$fields['ID'] = $id;
		$res          = wp_update_post( wp_slash( $fields ), true );
		if ( is_wp_error( $res ) ) {
			return self::fail( 'sb_update_failed', $res->get_error_message(), 400 );
		}

		$log_id = SB_Auth::write_log(
			array(
				'action'  => 'update_post',
				'target'  => 'post ' . $id,
				'summary' => sprintf( 'Updated %s on post %d (%s)', implode( ', ', array_keys( $fields ) ), $id, self::clip( $p->post_title ) ),
				'before'  => $before,
			)
		);
		return self::respond( array( 'id' => $id, 'changed' => array_values( array_diff( array_keys( $fields ), array( 'ID' ) ) ) ), array( 'undo_id' => $log_id ) );
	}

	public static function delete_post( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$p  = get_post( $id );
		if ( ! $p ) {
			return self::fail( 'sb_not_found', 'No post with id ' . $id, 404 );
		}
		$force = self::truthy( self::param( $r, 'force' ) );

		if ( $force ) {
			$before = array( 'post_id' => $id, 'existed' => true, 'forced' => true ); // Not restorable.
			$res    = wp_delete_post( $id, true );
		} else {
			$before = array( 'post_id' => $id, 'existed' => true, 'prev_status' => $p->post_status );
			$res    = wp_trash_post( $id );
		}
		if ( ! $res ) {
			return self::fail( 'sb_delete_failed', 'Could not ' . ( $force ? 'delete' : 'trash' ) . ' post ' . $id, 400 );
		}

		$log_id = SB_Auth::write_log(
			array(
				'action'  => $force ? 'delete_post' : 'trash_post',
				'target'  => 'post ' . $id,
				'summary' => sprintf( '%s post %d "%s"', $force ? 'Permanently deleted' : 'Trashed', $id, self::clip( $p->post_title ) ),
				'before'  => $before,
			)
		);
		return self::respond( array( 'id' => $id, 'forced' => $force ), array( 'undo_id' => $log_id, 'restorable' => ! $force ) );
	}

	/** Collect & sanitize the subset of post fields the request carries. */
	private static function collect_post_fields( WP_REST_Request $r, bool $is_create ) {
		$out  = array();
		$map  = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order', 'post_date', 'comment_status', 'ping_status', 'page_template' );
		if ( $is_create ) {
			$map[] = 'post_type';
			$map[] = 'post_author';
		}
		// Convenience aliases.
		$aliases = array( 'title' => 'post_title', 'content' => 'post_content', 'status' => 'post_status', 'excerpt' => 'post_excerpt', 'slug' => 'post_name', 'type' => 'post_type', 'parent' => 'post_parent' );
		foreach ( $aliases as $from => $to ) {
			if ( self::param( $r, $from ) !== null && self::param( $r, $to ) === null && ( $to !== 'post_type' || $is_create ) ) {
				$r->set_param( $to, self::param( $r, $from ) );
			}
		}
		foreach ( $map as $field ) {
			$v = self::param( $r, $field );
			if ( $v === null ) {
				continue;
			}
			if ( in_array( $field, array( 'post_parent', 'menu_order', 'post_author' ), true ) ) {
				$out[ $field ] = (int) $v;
			} else {
				$out[ $field ] = (string) $v;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ meta */

	public static function meta( WP_REST_Request $r ) {
		$post_id = (int) self::param( $r, 'post_id' );
		$key     = (string) self::param( $r, 'key' );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return self::fail( 'sb_not_found', 'post_id is missing or does not exist.', 404 );
		}
		if ( $key === '' ) {
			return self::fail( 'sb_args', 'Pass "key" (the meta key).', 400 );
		}

		$before_vals = get_post_meta( $post_id, $key, false ); // All values for the key.
		$before      = array(
			'post_id' => $post_id,
			'key'     => $key,
			'existed' => ! empty( $before_vals ),
			'values'  => $before_vals,
		);
		$delete = self::truthy( self::param( $r, 'delete' ) );

		if ( $delete ) {
			delete_post_meta( $post_id, $key );
			$summary = sprintf( 'Deleted meta "%s" on post %d', $key, $post_id );
			$result  = array( 'post_id' => $post_id, 'key' => $key, 'deleted' => true );
		} else {
			$value = self::decode_value( $r, self::param( $r, 'value', '' ) );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			update_post_meta( $post_id, $key, wp_slash( $value ) );
			$summary = sprintf( 'Set meta "%s" on post %d → %s', $key, $post_id, self::clip( $value ) );
			$result  = array( 'post_id' => $post_id, 'key' => $key, 'value' => $value );
		}

		$log_id = SB_Auth::write_log(
			array(
				'action'  => $delete ? 'delete_meta' : 'set_meta',
				'target'  => 'post ' . $post_id . ' meta ' . $key,
				'summary' => $summary,
				'before'  => $before,
			)
		);
		return self::respond( $result, array( 'undo_id' => $log_id ) );
	}

	/* ------------------------------------------------------------------ options */

	public static function option( WP_REST_Request $r ) {
		$name = (string) self::param( $r, 'name' );
		if ( $name === '' ) {
			return self::fail( 'sb_args', 'Pass "name" (the option name).', 400 );
		}
		if ( in_array( $name, self::PROTECTED_OPTIONS, true ) ) {
			return self::fail( 'sb_protected', 'This option belongs to Site Bridge itself and cannot be written through the API.', 403 );
		}

		$sentinel = '__sb_absent__';
		$current  = get_option( $name, $sentinel );
		$before   = array(
			'name'     => $name,
			'existed'  => $current !== $sentinel,
			'value'    => $current === $sentinel ? null : $current,
		);
		$delete = self::truthy( self::param( $r, 'delete' ) );

		if ( $delete ) {
			delete_option( $name );
			$summary = sprintf( 'Deleted option "%s"', $name );
			$result  = array( 'name' => $name, 'deleted' => true );
		} else {
			$value = self::decode_value( $r, self::param( $r, 'value', '' ) );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$autoload = self::param( $r, 'autoload' );
			if ( $autoload === null ) {
				update_option( $name, $value );
			} else {
				update_option( $name, $value, self::truthy( $autoload ) ? 'yes' : 'no' );
			}
			$summary = sprintf( 'Set option "%s" → %s', $name, self::clip( $value ) );
			$result  = array( 'name' => $name, 'value' => $value );
		}

		$log_id = SB_Auth::write_log(
			array(
				'action'  => $delete ? 'delete_option' : 'set_option',
				'target'  => 'option ' . $name,
				'summary' => $summary,
				'before'  => $before,
			)
		);
		return self::respond( $result, array( 'undo_id' => $log_id ) );
	}

	/* ------------------------------------------------------------------ Oxygen code block */

	public static function code_block( WP_REST_Request $r ) {
		$post_id    = (int) self::param( $r, 'post_id' );
		$element_id = self::param( $r, 'element_id' );
		$meta_key   = (string) self::param( $r, 'meta_key' );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return self::fail( 'sb_not_found', 'post_id is missing or does not exist.', 404 );
		}
		if ( $element_id === null || $element_id === '' ) {
			return self::fail( 'sb_args', 'Pass "element_id" of the code block to change. Find it in /oxygen/code-blocks.', 400 );
		}

		// Which parts to set (only those present in the request).
		$parts = array();
		foreach ( array( 'php', 'css', 'js' ) as $k ) {
			$v = self::param( $r, $k );
			if ( $v !== null ) {
				$parts[ $k ] = (string) $v;
			}
		}
		if ( ! $parts ) {
			return self::fail( 'sb_args', 'Pass at least one of "php", "css", "js".', 400 );
		}

		// Decide which meta key holds the tree.
		$candidates = $meta_key !== '' ? array( $meta_key ) : array( 'ct_builder_json', 'ct_builder_shortcodes' );
		foreach ( $candidates as $key ) {
			$raw = get_post_meta( $post_id, $key, true );
			if ( $raw === '' || $raw === null || $raw === false ) {
				continue;
			}
			if ( $key === 'ct_builder_shortcodes' && is_string( $raw ) ) {
				$updated = self::replace_in_shortcodes( $raw, (string) $element_id, $parts );
			} else {
				$updated = self::replace_in_json( $raw, (string) $element_id, $parts );
			}
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			if ( $updated === null ) {
				continue; // Element not found in this meta key, try the next.
			}

			$before = array(
				'post_id' => $post_id,
				'key'     => $key,
				'existed' => true,
				'values'  => array( $raw ),
			);
			update_post_meta( $post_id, $key, wp_slash( $updated ) );

			$log_id = SB_Auth::write_log(
				array(
					'action'  => 'set_code_block',
					'target'  => 'post ' . $post_id . ' code block ' . $element_id,
					'summary' => sprintf( 'Updated %s of code block %s on post %d (%s)', implode( '/', array_keys( $parts ) ), $element_id, $post_id, $key ),
					'before'  => $before,
				)
			);
			return self::respond(
				array( 'post_id' => $post_id, 'meta_key' => $key, 'element_id' => $element_id, 'changed' => array_keys( $parts ) ),
				array( 'undo_id' => $log_id )
			);
		}

		return self::fail( 'sb_block_not_found', 'No code block with that element_id was found in ' . implode( ' or ', $candidates ) . '. Check /oxygen/code-blocks for the id and meta_key, or edit the raw tree via /write/meta.', 404 );
	}

	/** Replace code-php/css/js in an Oxygen JSON tree for the matching node. Returns new JSON string, null (not found) or WP_Error. */
	private static function replace_in_json( $raw, string $element_id, array $parts ) {
		$tree = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $tree ) ) {
			return null;
		}
		$found = false;
		$walk  = function ( &$node ) use ( &$walk, &$found, $element_id, $parts ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			$node_id = isset( $node['id'] ) ? (string) $node['id'] : null;
			if ( $node_id === $element_id && ( $node['name'] ?? '' ) !== '' ) {
				if ( ! isset( $node['options'] ) || ! is_array( $node['options'] ) ) {
					$node['options'] = array();
				}
				$target =& $node['options'];
				if ( isset( $node['options']['original'] ) && is_array( $node['options']['original'] ) ) {
					$target =& $node['options']['original'];
				}
				foreach ( $parts as $p => $code ) {
					$target[ 'code-' . $p ] = $code;
				}
				unset( $target );
				$found = true;
			}
			foreach ( $node as $k => &$v ) {
				if ( is_array( $v ) ) {
					$walk( $v );
				}
			}
			unset( $v );
		};
		$walk( $tree );
		if ( ! $found ) {
			return null;
		}
		$json = wp_json_encode( $tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( $json === false ) {
			return new WP_Error( 'sb_encode_failed', 'Could not re-encode the builder tree to JSON.', array( 'status' => 500 ) );
		}
		return $json;
	}

	/** Replace code in an Oxygen ct_builder_shortcodes string (base64 in ct_options). */
	private static function replace_in_shortcodes( string $raw, string $element_id, array $parts ) {
		$found   = false;
		$updated = preg_replace_callback(
			'/\[ct_code_block\s+([^\]]*)\]/s',
			function ( $m ) use ( &$found, $element_id, $parts ) {
				$attr_str = $m[1];
				$atts     = shortcode_parse_atts( $attr_str );
				$this_id  = $atts['id'] ?? null;
				if ( (string) $this_id !== $element_id && empty( $atts['ct_options'] ) ) {
					return $m[0];
				}
				if ( empty( $atts['ct_options'] ) ) {
					return $m[0];
				}
				$json = html_entity_decode( $atts['ct_options'], ENT_QUOTES );
				$opts = json_decode( $json, true );
				if ( ! is_array( $opts ) ) {
					$opts = json_decode( $atts['ct_options'], true );
				}
				if ( ! is_array( $opts ) ) {
					return $m[0];
				}
				$opt_id = (string) ( $opts['ct_id'] ?? ( $atts['id'] ?? '' ) );
				if ( $opt_id !== $element_id && (string) $this_id !== $element_id ) {
					return $m[0];
				}
				$ref =& $opts;
				if ( isset( $opts['original'] ) && is_array( $opts['original'] ) ) {
					$ref =& $opts['original'];
				}
				foreach ( $parts as $p => $code ) {
					$ref[ 'code-' . $p ] = base64_encode( $code ); // Oxygen keeps code base64-encoded here.
				}
				unset( $ref );
				$found    = true;
				$new_json = wp_json_encode( $opts, JSON_UNESCAPED_UNICODE );
				// Rebuild the attribute string, keeping any non-ct_options attrs.
				$rebuilt = '';
				foreach ( $atts as $ak => $av ) {
					if ( $ak === 'ct_options' ) {
						continue;
					}
					$rebuilt .= $ak . '="' . esc_attr( $av ) . '" ';
				}
				$rebuilt .= "ct_options='" . $new_json . "'";
				return '[ct_code_block ' . trim( $rebuilt ) . ']';
			},
			$raw
		);
		if ( ! $found || $updated === null ) {
			return null;
		}
		return $updated;
	}

	/* ------------------------------------------------------------------ files */

	public static function file( WP_REST_Request $r ) {
		if ( ! SB_Auth::setting( 'allow_write_files' ) ) {
			return self::fail( 'sb_write_files_off', 'Writing files is turned off. Enable "Писане на файлове" in Site Bridge settings.', 403 );
		}
		$path    = (string) self::param( $r, 'path' );
		$content = self::param( $r, 'content' );
		if ( $path === '' ) {
			return self::fail( 'sb_args', 'Pass "path" and "content".', 400 );
		}
		if ( $content === null ) {
			return self::fail( 'sb_args', 'Pass "content" (use an empty string to create an empty file).', 400 );
		}
		if ( ! is_string( $content ) ) {
			$content = wp_json_encode( $content );
		}
		if ( strlen( $content ) > 5 * 1024 * 1024 ) {
			return self::fail( 'sb_too_big', 'Refusing to write more than 5 MB through the API.', 413 );
		}

		$target = SB_Paths::resolve_writable( $path );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$existed      = file_exists( $target );
		if ( $existed && ! is_writable( $target ) ) {
			return self::fail( 'sb_not_writable', 'File exists but is not writable: ' . $target, 403 );
		}
		if ( ! $existed && ! is_writable( dirname( $target ) ) ) {
			return self::fail( 'sb_dir_not_writable', 'Directory is not writable: ' . dirname( $target ), 403 );
		}
		$before = array(
			'path'    => $target,
			'existed' => $existed,
			'content' => $existed ? @file_get_contents( $target ) : null,
		);

		$bytes = file_put_contents( $target, $content );
		if ( $bytes === false ) {
			return self::fail( 'sb_write_failed', 'file_put_contents failed for ' . $target, 500 );
		}

		$log_id = SB_Auth::write_log(
			array(
				'action'  => 'write_file',
				'target'  => SB_Paths::relative( $target ),
				'summary' => sprintf( '%s file %s (%d bytes)', $existed ? 'Overwrote' : 'Created', SB_Paths::relative( $target ), $bytes ),
				'before'  => $before,
			)
		);
		return self::respond(
			array(
				'path'    => $target,
				'relative' => SB_Paths::relative( $target ),
				'bytes'   => $bytes,
				'created' => ! $existed,
				'md5'     => md5_file( $target ),
			),
			array( 'undo_id' => $log_id )
		);
	}

	/* ------------------------------------------------------------------ undo */

	public static function undo( WP_REST_Request $r ) {
		$id    = (string) $r['id'];
		$entry = SB_Auth::write_entry( $id );
		if ( ! $entry ) {
			return self::fail( 'sb_not_found', 'No write with id ' . $id . ' in the audit log.', 404 );
		}
		if ( ! empty( $entry['undone'] ) ) {
			return self::fail( 'sb_already_undone', 'That write was already undone.', 409 );
		}
		if ( ! empty( $entry['before_omitted'] ) ) {
			return self::fail( 'sb_undo_omitted', 'The before-snapshot was larger than 1 MB and was not kept, so this change cannot be auto-undone.', 410 );
		}
		$before = $entry['before'] ?? array();
		$action = $entry['action'] ?? '';

		switch ( $action ) {
			case 'create_post':
				$pid = (int) ( $before['post_id'] ?? 0 );
				if ( $pid ) {
					wp_trash_post( $pid );
				}
				$msg = 'Created post ' . $pid . ' moved to trash.';
				break;

			case 'update_post':
				$pid = (int) ( $before['post_id'] ?? 0 );
				if ( ! $pid || ! get_post( $pid ) ) {
					return self::fail( 'sb_undo_gone', 'The post no longer exists.', 410 );
				}
				$restore = array( 'ID' => $pid );
				foreach ( (array) ( $before['fields'] ?? array() ) as $k => $v ) {
					$restore[ $k ] = $v;
				}
				wp_update_post( wp_slash( $restore ), true );
				$msg = 'Restored ' . implode( ', ', array_keys( $before['fields'] ?? array() ) ) . ' on post ' . $pid . '.';
				break;

			case 'trash_post':
				$pid = (int) ( $before['post_id'] ?? 0 );
				wp_untrash_post( $pid );
				if ( ! empty( $before['prev_status'] ) ) {
					wp_update_post( array( 'ID' => $pid, 'post_status' => $before['prev_status'] ) );
				}
				$msg = 'Post ' . $pid . ' restored from trash.';
				break;

			case 'delete_post':
				return self::fail( 'sb_undo_impossible', 'A permanent (force) delete cannot be undone.', 410 );

			case 'set_meta':
			case 'delete_meta':
			case 'set_code_block':
				$pid = (int) ( $before['post_id'] ?? 0 );
				$key = (string) ( $before['key'] ?? '' );
				delete_post_meta( $pid, $key );
				if ( ! empty( $before['existed'] ) ) {
					foreach ( (array) ( $before['values'] ?? array() ) as $v ) {
						add_post_meta( $pid, $key, wp_slash( maybe_unserialize( $v ) ) );
					}
				}
				$msg = 'Meta "' . $key . '" on post ' . $pid . ' restored.';
				break;

			case 'set_option':
			case 'delete_option':
				$name = (string) ( $before['name'] ?? '' );
				if ( empty( $before['existed'] ) ) {
					delete_option( $name );
					$msg = 'Option "' . $name . '" removed (it did not exist before).';
				} else {
					update_option( $name, $before['value'] );
					$msg = 'Option "' . $name . '" restored.';
				}
				break;

			case 'write_file':
				$path = (string) ( $before['path'] ?? '' );
				if ( $path === '' ) {
					return self::fail( 'sb_undo_gone', 'No path recorded.', 410 );
				}
				if ( empty( $before['existed'] ) ) {
					if ( file_exists( $path ) ) {
						@unlink( $path );
					}
					$msg = 'File ' . SB_Paths::relative( $path ) . ' removed (it did not exist before).';
				} else {
					$ok = file_put_contents( $path, (string) $before['content'] );
					if ( $ok === false ) {
						return self::fail( 'sb_undo_failed', 'Could not rewrite the previous file content.', 500 );
					}
					$msg = 'File ' . SB_Paths::relative( $path ) . ' restored to its previous content.';
				}
				break;

			default:
				return self::fail( 'sb_undo_unknown', 'Do not know how to undo action "' . $action . '".', 400 );
		}

		SB_Auth::mark_undone( $id );
		SB_Auth::write_log(
			array(
				'action'  => 'undo',
				'target'  => $entry['target'] ?? '',
				'summary' => 'Undo of ' . $id . ': ' . $msg,
				'undone'  => true, // The undo entry itself is terminal.
			)
		);
		return self::respond( array( 'undone' => $id, 'action' => $action, 'message' => $msg ) );
	}
}
