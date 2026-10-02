<?php
/**
 * REST routes. Everything here is read-only.
 */

defined( 'ABSPATH' ) || exit;

class SB_REST {

	public static function register_routes(): void {
		$ns   = SITE_BRIDGE_NS;
		$auth = array( 'SB_Auth', 'check' );

		$get = function ( string $route, string $method, $permission = null ) use ( $ns, $auth ) {
			register_rest_route(
				$ns,
				$route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, $method ),
					'permission_callback' => $permission ?: $auth,
				)
			);
		};
		$post = function ( string $route, string $method, $permission = null ) use ( $ns, $auth ) {
			register_rest_route(
				$ns,
				$route,
				array(
					'methods'             => array( 'GET', 'POST' ),
					'callback'            => array( __CLASS__, $method ),
					'permission_callback' => $permission ?: $auth,
				)
			);
		};

		$get( '/', 'index' );
		$get( '/info', 'info' );
		$get( '/plugins', 'plugins' );
		$get( '/constants', 'constants' );
		$get( '/options', 'options' );
		$get( '/posts', 'posts' );
		$get( '/post/(?P<id>\d+)', 'post' );
		$get( '/meta/keys', 'meta_keys' );
		$get( '/meta', 'meta' );
		$get( '/oxygen', 'oxygen' );
		$get( '/oxygen/code-blocks', 'oxygen_code_blocks' );
		$get( '/users', 'users' );
		$get( '/cron', 'cron' );
		$get( '/hooks', 'hooks' );
		$get( '/errors', 'errors' );
		$get( '/health', 'health' );
		$get( '/log', 'log', SB_Auth::feature_check( 'files' ) );
		$get( '/file', 'file', SB_Auth::feature_check( 'files' ) );
		$get( '/ls', 'ls', SB_Auth::feature_check( 'files' ) );
		$post( '/sql', 'sql', SB_Auth::feature_check( 'sql' ) );
		$post( '/render', 'render', SB_Auth::feature_check( 'render' ) );

		register_rest_route(
			$ns,
			'/errors',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'errors_clear' ),
				'permission_callback' => $auth,
			)
		);
	}

	/* ------------------------------------------------------------------ helpers */

	private static function int_param( WP_REST_Request $r, string $k, int $default, int $min, int $max ): int {
		$v = $r->get_param( $k );
		if ( $v === null || $v === '' ) {
			return $default;
		}
		return max( $min, min( $max, (int) $v ) );
	}

	private static function respond( $data, array $extra = array() ) {
		return rest_ensure_response( array_merge( array( 'ok' => true ), $extra, array( 'data' => $data ) ) );
	}

	private static function fail( string $code, string $msg, int $status = 400 ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	/* ------------------------------------------------------------------ routes */

	public static function index( WP_REST_Request $r ) {
		$s = SB_Auth::settings();
		return self::respond(
			array(
				'plugin'   => 'site-bridge',
				'version'  => SITE_BRIDGE_VERSION,
				'site'     => home_url(),
				'features' => array(
					'files'  => (bool) $s['allow_files'],
					'sql'    => (bool) $s['allow_sql'],
					'render' => (bool) $s['allow_render'],
				),
				'routes'   => array(
					'GET  /info'                    => 'Versions, debug flags, paths, theme, active plugins, builders.',
					'GET  /plugins'                 => 'All plugins with active flag and pending updates.',
					'GET  /constants?search='       => 'User-defined constants (secrets redacted).',
					'GET  /options?name=a,b|search=&limit=' => 'Options, unserialized, secrets redacted.',
					'GET  /posts?type=&status=&search=&meta_key=&per_page=&page=&with_meta_keys=1' => 'List posts of any type.',
					'GET  /post/{id}?content=1&meta=1'      => 'One post with all meta (and extracted builder code blocks).',
					'GET  /meta/keys?like=ct_%'     => 'Distinct post meta keys with counts.',
					'GET  /meta?key=&like=&limit='  => 'Which posts carry a meta key.',
					'GET  /oxygen'                  => 'Builder detection: constants, post types, meta keys, options.',
					'GET  /oxygen/code-blocks?post=&limit=' => 'Extracted code blocks (PHP/CSS/JS) across builder posts.',
					'GET  /users?search='           => 'Users with roles.',
					'GET  /cron'                    => 'Scheduled events.',
					'GET  /hooks?hook=init'         => 'Callbacks attached to a hook, with source file.',
					'GET  /errors'                  => 'Captured fatal errors (DELETE to clear).',
					'GET  /health'                  => 'Site Health debug data.',
					'GET  /log?file=debug|php|<path>&lines=&grep=' => 'Tail of debug.log / PHP error log / any readable log.',
					'GET  /file?path=&from=&lines=' => 'Read a file inside the allowed scope.',
					'GET  /ls?path='                => 'List a directory.',
					'POST /sql {query, limit}'      => 'Run a single SELECT/SHOW/DESCRIBE/EXPLAIN. {prefix} is replaced.',
					'POST /render {path, as, format, max}' => 'Fetch a front-end or wp-admin page as admin, return text or HTML.',
				),
			)
		);
	}

	public static function info( WP_REST_Request $r ) {
		global $wpdb, $wp_version;
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		$debug = array();
		foreach ( array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'WP_CACHE', 'DISABLE_WP_CRON', 'ALTERNATE_WP_CRON', 'WP_AUTO_UPDATE_CORE', 'AUTOMATIC_UPDATER_DISABLED', 'FS_METHOD', 'WP_POST_REVISIONS', 'EMPTY_TRASH_DAYS', 'CONCATENATE_SCRIPTS', 'WP_HOME', 'WP_SITEURL' ) as $c ) {
			$debug[ $c ] = defined( $c ) ? constant( $c ) : null;
		}

		$counts = array();
		foreach ( get_post_types( array(), 'names' ) as $pt ) {
			if ( in_array( $pt, array( 'revision', 'oembed_cache', 'customize_changeset', 'user_request' ), true ) ) {
				continue;
			}
			$c = array_filter( (array) wp_count_posts( $pt ) );
			if ( $c ) {
				$counts[ $pt ] = $c;
			}
		}

		return self::respond(
			array(
				'site'        => array(
					'name'        => get_bloginfo( 'name' ),
					'url'         => site_url(),
					'home'        => home_url(),
					'admin'       => admin_url(),
					'rest'        => rest_url(),
					'locale'      => get_locale(),
					'timezone'    => wp_timezone_string(),
					'now'         => current_time( 'mysql' ),
					'now_utc'     => gmdate( 'c' ),
					'multisite'   => is_multisite(),
					'ssl'         => is_ssl(),
					'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : null,
					'permalinks'  => get_option( 'permalink_structure' ),
				),
				'versions'    => array(
					'wordpress'   => $wp_version,
					'php'         => PHP_VERSION,
					'mysql'       => $wpdb->db_version(),
					'db_server'   => method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : null,
					'site_bridge' => SITE_BRIDGE_VERSION,
				),
				'server'      => array(
					'software'           => $_SERVER['SERVER_SOFTWARE'] ?? '',
					'os'                 => PHP_OS,
					'sapi'               => PHP_SAPI,
					'memory_limit'       => ini_get( 'memory_limit' ),
					'max_execution_time' => ini_get( 'max_execution_time' ),
					'upload_max'         => ini_get( 'upload_max_filesize' ),
					'post_max'           => ini_get( 'post_max_size' ),
					'display_errors'     => ini_get( 'display_errors' ),
					'log_errors'         => ini_get( 'log_errors' ),
					'error_log'          => ini_get( 'error_log' ),
					'error_reporting'    => error_reporting(),
					'disabled_functions' => ini_get( 'disable_functions' ),
					'opcache'            => function_exists( 'opcache_get_status' ) && @opcache_get_status( false ) ? true : false,
					'extensions'         => get_loaded_extensions(),
				),
				'debug'       => $debug,
				'paths'       => array(
					'ABSPATH'        => ABSPATH,
					'WP_CONTENT_DIR' => WP_CONTENT_DIR,
					'WP_PLUGIN_DIR'  => WP_PLUGIN_DIR,
					'uploads'        => wp_upload_dir()['basedir'],
					'debug_log'      => SB_Paths::debug_log_path(),
					'debug_log_exists' => file_exists( SB_Paths::debug_log_path() ),
					'debug_log_size' => file_exists( SB_Paths::debug_log_path() ) ? filesize( SB_Paths::debug_log_path() ) : 0,
					'file_scope'     => SB_Auth::setting( 'file_scope' ),
				),
				'db'          => array(
					'prefix'  => $wpdb->prefix,
					'name'    => DB_NAME,
					'host'    => DB_HOST,
					'charset' => $wpdb->charset,
					'collate' => $wpdb->collate,
				),
				'theme'       => array(
					'name'       => $theme->get( 'Name' ),
					'version'    => $theme->get( 'Version' ),
					'stylesheet' => get_stylesheet(),
					'template'   => get_template(),
					'parent'     => $parent ? $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) : null,
					'dir'        => get_stylesheet_directory(),
				),
				'plugins'     => self::plugin_list( true ),
				'mu_plugins'  => array_keys( get_mu_plugins() ),
				'dropins'     => array_keys( get_dropins() ),
				'builders'    => SB_Oxygen::detect()['constants'],
				'post_types'  => $counts,
				'taxonomies'  => array_values( get_taxonomies( array( '_builtin' => false ) ) ),
				'users_total' => count_users()['total_users'],
				'cron_next'   => wp_next_scheduled( 'wp_version_check' ) ? gmdate( 'c', wp_next_scheduled( 'wp_version_check' ) ) : null,
				'errors_captured' => count( SB_Errors::all() ),
			)
		);
	}

	private static function plugin_list( bool $active_only ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all     = get_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$out     = array();
		foreach ( $all as $file => $p ) {
			$active = is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) );
			if ( $active_only && ! $active ) {
				continue;
			}
			$out[] = array(
				'file'     => $file,
				'slug'     => dirname( $file ) === '.' ? basename( $file, '.php' ) : dirname( $file ),
				'name'     => $p['Name'],
				'version'  => $p['Version'],
				'active'   => $active,
				'network'  => is_multisite() && is_plugin_active_for_network( $file ),
				'update'   => isset( $updates->response[ $file ] ) ? $updates->response[ $file ]->new_version : null,
				'requires' => array(
					'wp'  => $p['RequiresWP'] ?? '',
					'php' => $p['RequiresPHP'] ?? '',
				),
			);
		}
		return $out;
	}

	public static function plugins( WP_REST_Request $r ) {
		return self::respond( self::plugin_list( false ) );
	}

	public static function constants( WP_REST_Request $r ) {
		$search = (string) $r->get_param( 'search' );
		$user   = get_defined_constants( true )['user'] ?? array();
		$out    = array();
		foreach ( $user as $k => $v ) {
			if ( $search !== '' && stripos( $k, $search ) === false ) {
				continue;
			}
			$out[ $k ] = SB_Redact::value( $k, is_scalar( $v ) || $v === null ? $v : gettype( $v ) );
		}
		ksort( $out );
		return self::respond( $out, array( 'count' => count( $out ) ) );
	}

	public static function options( WP_REST_Request $r ) {
		global $wpdb;
		$names  = (string) $r->get_param( 'name' );
		$search = (string) $r->get_param( 'search' );
		$limit  = self::int_param( $r, 'limit', 100, 1, 500 );
		$raw    = (bool) $r->get_param( 'raw' );

		if ( $names === '' && $search === '' ) {
			return self::fail( 'sb_args', 'Pass ?name=opt1,opt2 or ?search=prefix' );
		}

		if ( $names !== '' ) {
			$list = array_filter( array_map( 'trim', explode( ',', $names ) ) );
			$in   = implode( ',', array_fill( 0, count( $list ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ($in)", $list ), ARRAY_A ); // phpcs:ignore
		} else {
			$like = strpos( $search, '%' ) === false ? $wpdb->esc_like( $search ) . '%' : $search;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT %d", $like, $limit ), ARRAY_A ); // phpcs:ignore
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$value = $raw ? $row['option_value'] : SB_Redact::normalize( $row['option_value'] );
			$out[] = array(
				'name'     => $row['option_name'],
				'autoload' => $row['autoload'],
				'bytes'    => strlen( $row['option_value'] ),
				'value'    => SB_Redact::value( $row['option_name'], $value ),
			);
		}
		return self::respond( $out, array( 'count' => count( $out ) ) );
	}

	public static function posts( WP_REST_Request $r ) {
		$type     = (string) $r->get_param( 'type' );
		$status   = (string) $r->get_param( 'status' );
		$search   = (string) $r->get_param( 'search' );
		$meta_key = (string) $r->get_param( 'meta_key' );
		$per_page = self::int_param( $r, 'per_page', 50, 1, 200 );
		$page     = self::int_param( $r, 'page', 1, 1, 100000 );
		$with_mk  = (bool) $r->get_param( 'with_meta_keys' );

		$types = $type !== '' ? array_map( 'trim', explode( ',', $type ) ) : array_values( array_diff( get_post_types( array(), 'names' ), array( 'revision' ) ) );

		$args = array(
			'post_type'        => $types,
			'post_status'      => $status !== '' ? array_map( 'trim', explode( ',', $status ) ) : 'any',
			'posts_per_page'   => $per_page,
			'paged'            => $page,
			'orderby'          => 'modified',
			'order'            => 'DESC',
			'suppress_filters' => true,
			'no_found_rows'    => false,
		);
		if ( $search !== '' ) {
			$args['s'] = $search;
		}
		if ( $meta_key !== '' ) {
			$args['meta_key'] = $meta_key; // phpcs:ignore
		}
		if ( $status === 'trash' || $status === 'any' ) {
			$args['post_status'] = $status === 'trash' ? 'trash' : array_keys( get_post_stati() );
		}

		$q   = new WP_Query( $args );
		$out = array();
		foreach ( $q->posts as $p ) {
			$item = array(
				'id'       => $p->ID,
				'title'    => $p->post_title,
				'type'     => $p->post_type,
				'status'   => $p->post_status,
				'slug'     => $p->post_name,
				'parent'   => $p->post_parent,
				'author'   => (int) $p->post_author,
				'date'     => $p->post_date,
				'modified' => $p->post_modified,
				'link'     => get_permalink( $p ),
				'content_bytes' => strlen( $p->post_content ),
			);
			if ( $with_mk ) {
				$meta = get_post_meta( $p->ID );
				$keys = array();
				foreach ( $meta as $k => $vals ) {
					$keys[ $k ] = array_sum( array_map( 'strlen', $vals ) );
				}
				$item['meta_keys'] = $keys;
			}
			$out[] = $item;
		}
		return self::respond(
			$out,
			array(
				'total' => (int) $q->found_posts,
				'pages' => (int) $q->max_num_pages,
				'page'  => $page,
			)
		);
	}

	public static function post( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$p  = get_post( $id );
		if ( ! $p ) {
			return self::fail( 'sb_not_found', 'No post with id ' . $id, 404 );
		}
		$with_content = $r->get_param( 'content' ) === null ? true : (bool) $r->get_param( 'content' );
		$with_meta    = $r->get_param( 'meta' ) === null ? true : (bool) $r->get_param( 'meta' );
		$raw          = (bool) $r->get_param( 'raw' );

		$data = array(
			'id'            => $p->ID,
			'title'         => $p->post_title,
			'type'          => $p->post_type,
			'status'        => $p->post_status,
			'slug'          => $p->post_name,
			'parent'        => $p->post_parent,
			'author'        => (int) $p->post_author,
			'date'          => $p->post_date,
			'modified'      => $p->post_modified,
			'link'          => get_permalink( $p ),
			'edit_link'     => admin_url( 'post.php?post=' . $p->ID . '&action=edit' ),
			'template'      => get_page_template_slug( $p ),
			'content_bytes' => strlen( $p->post_content ),
			'excerpt'       => $p->post_excerpt,
			'terms'         => array(),
		);
		foreach ( get_object_taxonomies( $p->post_type ) as $tax ) {
			$terms = get_the_terms( $p, $tax );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$data['terms'][ $tax ] = wp_list_pluck( $terms, 'name', 'term_id' );
			}
		}
		if ( $with_content ) {
			$data['content'] = $p->post_content;
		}
		if ( $with_meta ) {
			$meta = get_post_meta( $p->ID );
			$out  = array();
			foreach ( $meta as $k => $vals ) {
				$items = array();
				foreach ( $vals as $v ) {
					$items[] = array(
						'bytes' => strlen( $v ),
						'value' => SB_Redact::value( $k, $raw ? $v : SB_Redact::normalize( $v ) ),
					);
				}
				$out[ $k ] = count( $items ) === 1 ? $items[0] : $items;
			}
			$data['meta'] = $out;
			$blocks       = SB_Oxygen::code_blocks_for_post( $p->ID );
			if ( $blocks ) {
				$data['code_blocks'] = $blocks;
			}
		}
		return self::respond( $data );
	}

	public static function meta_keys( WP_REST_Request $r ) {
		global $wpdb;
		$like  = (string) $r->get_param( 'like' );
		$limit = self::int_param( $r, 'limit', 200, 1, 1000 );
		if ( $like === '' ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, COUNT(*) AS n, SUM(LENGTH(meta_value)) AS bytes FROM {$wpdb->postmeta} GROUP BY meta_key ORDER BY n DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, COUNT(*) AS n, SUM(LENGTH(meta_value)) AS bytes FROM {$wpdb->postmeta} WHERE meta_key LIKE %s GROUP BY meta_key ORDER BY n DESC LIMIT %d", $like, $limit ), ARRAY_A ); // phpcs:ignore
		}
		return self::respond( $rows, array( 'count' => count( (array) $rows ) ) );
	}

	public static function meta( WP_REST_Request $r ) {
		global $wpdb;
		$key   = (string) $r->get_param( 'key' );
		$like  = (string) $r->get_param( 'like' );
		$limit = self::int_param( $r, 'limit', 200, 1, 2000 );
		if ( $key === '' && $like === '' ) {
			return self::fail( 'sb_args', 'Pass ?key=meta_key or ?like=prefix%' );
		}
		$where = $key !== '' ? $wpdb->prepare( 'pm.meta_key = %s', $key ) : $wpdb->prepare( 'pm.meta_key LIKE %s', $like );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_key, LENGTH(pm.meta_value) AS bytes, p.post_type, p.post_status, p.post_title FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE $where ORDER BY pm.post_id LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore
		$by    = array();
		foreach ( (array) $rows as $row ) {
			$by[ $row['post_type'] ?? '(orphan)' ] = ( $by[ $row['post_type'] ?? '(orphan)' ] ?? 0 ) + 1;
		}
		return self::respond( $rows, array( 'count' => count( (array) $rows ), 'by_post_type' => $by ) );
	}

	public static function oxygen( WP_REST_Request $r ) {
		$d          = SB_Oxygen::detect();
		$d['posts'] = SB_Oxygen::posts_with_trees( self::int_param( $r, 'limit', 500, 1, 5000 ) );
		return self::respond( $d );
	}

	public static function oxygen_code_blocks( WP_REST_Request $r ) {
		$post  = (int) $r->get_param( 'post' );
		$limit = self::int_param( $r, 'limit', 300, 1, 5000 );
		$full  = (bool) $r->get_param( 'full' );
		$posts = $post ? array( array( 'id' => $post ) ) : SB_Oxygen::posts_with_trees( $limit );
		$out   = array();
		foreach ( $posts as $pinfo ) {
			$blocks = SB_Oxygen::code_blocks_for_post( (int) $pinfo['id'] );
			if ( ! $blocks ) {
				continue;
			}
			$p = get_post( (int) $pinfo['id'] );
			foreach ( $blocks as $b ) {
				$b['post_title']  = $p ? $p->post_title : null;
				$b['post_type']   = $p ? $p->post_type : null;
				$b['post_status'] = $p ? $p->post_status : null;
				$b['php_lines']   = $b['php'] === '' ? 0 : substr_count( $b['php'], "\n" ) + 1;
				if ( ! $full ) {
					foreach ( array( 'php', 'css', 'js' ) as $k ) {
						if ( strlen( $b[ $k ] ) > 4000 ) {
							$b[ $k ] = substr( $b[ $k ], 0, 4000 ) . "\n/* … truncated, use ?full=1 or /post/{id} */";
						}
					}
				}
				$out[] = $b;
			}
		}
		return self::respond( $out, array( 'count' => count( $out ), 'posts_scanned' => count( $posts ) ) );
	}

	public static function users( WP_REST_Request $r ) {
		$search = (string) $r->get_param( 'search' );
		$args   = array(
			'number'  => self::int_param( $r, 'limit', 100, 1, 1000 ),
			'orderby' => 'ID',
		);
		if ( $search !== '' ) {
			$args['search'] = '*' . $search . '*';
		}
		$out = array();
		foreach ( get_users( $args ) as $u ) {
			$out[] = array(
				'id'         => $u->ID,
				'login'      => $u->user_login,
				'display'    => $u->display_name,
				'email'      => $u->user_email,
				'roles'      => $u->roles,
				'registered' => $u->user_registered,
			);
		}
		return self::respond( $out, array( 'count' => count( $out ) ) );
	}

	public static function cron( WP_REST_Request $r ) {
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : get_option( 'cron' );
		$out  = array();
		foreach ( (array) $cron as $ts => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				foreach ( $events as $sig => $ev ) {
					$out[] = array(
						'hook'     => $hook,
						'next'     => gmdate( 'c', (int) $ts ),
						'in_sec'   => (int) $ts - time(),
						'schedule' => $ev['schedule'] ?? 'once',
						'interval' => $ev['interval'] ?? null,
						'args'     => SB_Redact::deep( $ev['args'] ?? array() ),
					);
				}
			}
		}
		return self::respond(
			$out,
			array(
				'count'            => count( $out ),
				'disable_wp_cron'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'doing_cron_lock'  => get_transient( 'doing_cron' ),
			)
		);
	}

	public static function hooks( WP_REST_Request $r ) {
		global $wp_filter;
		$hook = (string) $r->get_param( 'hook' );
		if ( $hook === '' ) {
			$search = (string) $r->get_param( 'search' );
			$names  = array_keys( $wp_filter );
			if ( $search !== '' ) {
				$names = array_values( array_filter( $names, function ( $n ) use ( $search ) { return stripos( $n, $search ) !== false; } ) );
			}
			sort( $names );
			return self::respond( array_slice( $names, 0, 2000 ), array( 'count' => count( $names ) ) );
		}
		if ( empty( $wp_filter[ $hook ] ) ) {
			return self::respond( array(), array( 'hook' => $hook, 'count' => 0 ) );
		}
		$out = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $cbs ) {
			foreach ( $cbs as $cb ) {
				$out[] = array_merge( array( 'priority' => $priority, 'accepted_args' => $cb['accepted_args'] ), self::describe_callable( $cb['function'] ) );
			}
		}
		return self::respond( $out, array( 'hook' => $hook, 'count' => count( $out ) ) );
	}

	private static function describe_callable( $fn ): array {
		try {
			if ( is_string( $fn ) ) {
				if ( strpos( $fn, '::' ) !== false ) {
					list( $c, $m ) = explode( '::', $fn, 2 );
					$ref = new ReflectionMethod( $c, $m );
				} else {
					$ref = new ReflectionFunction( $fn );
				}
				$name = $fn;
			} elseif ( is_array( $fn ) ) {
				$cls  = is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0];
				$ref  = new ReflectionMethod( $cls, $fn[1] );
				$name = $cls . ( is_object( $fn[0] ) ? '->' : '::' ) . $fn[1];
			} elseif ( $fn instanceof Closure ) {
				$ref  = new ReflectionFunction( $fn );
				$name = 'Closure';
			} elseif ( is_object( $fn ) ) {
				$ref  = new ReflectionMethod( $fn, '__invoke' );
				$name = get_class( $fn ) . '->__invoke';
			} else {
				return array( 'callable' => gettype( $fn ) );
			}
			$file = $ref->getFileName();
			return array(
				'callable' => $name,
				'file'     => $file ? SB_Paths::relative( $file ) : null,
				'line'     => $ref->getStartLine(),
				'source'   => $file ? SB_Paths::source_of( $file ) : null,
			);
		} catch ( \Throwable $t ) {
			return array( 'callable' => is_string( $fn ) ? $fn : gettype( $fn ), 'error' => $t->getMessage() );
		}
	}

	public static function errors( WP_REST_Request $r ) {
		return self::respond( SB_Errors::all(), array( 'capture_warnings' => (bool) SB_Auth::setting( 'capture_warnings' ) ) );
	}

	public static function errors_clear( WP_REST_Request $r ) {
		SB_Errors::clear();
		return self::respond( array( 'cleared' => true ) );
	}

	public static function health( WP_REST_Request $r ) {
		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}
		foreach ( array( 'plugin', 'update', 'misc', 'file', 'theme' ) as $inc ) {
			$f = ABSPATH . 'wp-admin/includes/' . $inc . '.php';
			if ( file_exists( $f ) ) {
				require_once $f;
			}
		}
		$section = (string) $r->get_param( 'section' );
		$data    = WP_Debug_Data::debug_data();
		$out     = array();
		foreach ( $data as $key => $sec ) {
			if ( $section !== '' && $key !== $section ) {
				continue;
			}
			$fields = array();
			foreach ( (array) ( $sec['fields'] ?? array() ) as $fk => $f ) {
				if ( ! empty( $f['private'] ) ) {
					continue;
				}
				$fields[ $fk ] = SB_Redact::value( $fk, $f['value'] ?? null );
			}
			$out[ $key ] = array(
				'label'  => $sec['label'] ?? $key,
				'fields' => $fields,
			);
		}
		return self::respond( $out );
	}

	public static function log( WP_REST_Request $r ) {
		$file  = (string) ( $r->get_param( 'file' ) ?: 'debug' );
		$lines = self::int_param( $r, 'lines', 200, 1, 5000 );
		$grep  = (string) $r->get_param( 'grep' );

		if ( $file === 'debug' ) {
			$path = SB_Paths::debug_log_path();
		} elseif ( $file === 'php' ) {
			$path = (string) ini_get( 'error_log' );
			if ( $path === '' || in_array( $path, array( 'syslog', 'stderr' ), true ) ) {
				return self::fail( 'sb_no_php_log', 'PHP error_log is not a file on this server (error_log=' . $path . ').', 404 );
			}
		} else {
			$path = SB_Paths::resolve( $file );
			if ( is_wp_error( $path ) ) {
				return $path;
			}
		}
		if ( ! file_exists( $path ) ) {
			return self::respond( array(), array( 'path' => $path, 'exists' => false ) );
		}
		if ( ! is_readable( $path ) ) {
			return self::fail( 'sb_unreadable', 'Log exists but is not readable: ' . $path, 403 );
		}
		$all = SB_Paths::tail( $path, $grep !== '' ? min( 20000, $lines * 20 ) : $lines );
		if ( $grep !== '' ) {
			$re  = @preg_match( '/' . str_replace( '/', '\/', $grep ) . '/i', '' ) !== false ? '/' . str_replace( '/', '\/', $grep ) . '/i' : '/' . preg_quote( $grep, '/' ) . '/i';
			$all = array_values( array_filter( $all, function ( $l ) use ( $re ) { return preg_match( $re, $l ); } ) );
			$all = array_slice( $all, -$lines );
		}
		return self::respond(
			$all,
			array(
				'path'  => $path,
				'exists' => true,
				'size'  => filesize( $path ),
				'mtime' => gmdate( 'c', filemtime( $path ) ),
				'lines' => count( $all ),
			)
		);
	}

	public static function file( WP_REST_Request $r ) {
		$path = SB_Paths::resolve( (string) $r->get_param( 'path' ) );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( is_dir( $path ) ) {
			return self::respond( SB_Paths::listing( $path ), array( 'path' => $path, 'type' => 'dir' ) );
		}
		if ( ! is_readable( $path ) ) {
			return self::fail( 'sb_unreadable', 'Not readable.', 403 );
		}
		$from  = self::int_param( $r, 'from', 1, 1, PHP_INT_MAX );
		$lines = self::int_param( $r, 'lines', 2000, 1, 20000 );
		$size  = filesize( $path );
		$meta  = array(
			'path'  => $path,
			'type'  => 'file',
			'size'  => $size,
			'mtime' => gmdate( 'c', filemtime( $path ) ),
			'md5'   => md5_file( $path ),
			'from'  => $from,
		);
		if ( $from === 1 && $size <= 1048576 && $r->get_param( 'lines' ) === null ) {
			$content = file_get_contents( $path );
			if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
				return self::respond( null, array_merge( $meta, array( 'binary' => true ) ) );
			}
			$meta['total_lines'] = substr_count( $content, "\n" ) + 1;
			return self::respond( $content, $meta );
		}
		$out = SB_Paths::read_lines( $path, $from, $lines );
		return self::respond( implode( "\n", $out ), array_merge( $meta, array( 'lines' => count( $out ), 'next_from' => $from + count( $out ) ) ) );
	}

	public static function ls( WP_REST_Request $r ) {
		$path = SB_Paths::resolve( (string) ( $r->get_param( 'path' ) ?: 'wp-content' ) );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! is_dir( $path ) ) {
			return self::fail( 'sb_not_dir', 'Not a directory.', 400 );
		}
		return self::respond( SB_Paths::listing( $path ), array( 'path' => $path ) );
	}

	public static function sql( WP_REST_Request $r ) {
		global $wpdb;
		$query = (string) $r->get_param( 'query' );
		$limit = self::int_param( $r, 'limit', 500, 1, 2000 );
		$query = trim( $query );
		$query = rtrim( $query, "; \t\n\r" );
		$query = str_replace( '{prefix}', $wpdb->prefix, $query );

		if ( $query === '' ) {
			return self::fail( 'sb_args', 'Pass {"query": "SELECT ..."} (POST) or ?query= (GET).' );
		}
		if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $query ) ) {
			return self::fail( 'sb_sql_readonly', 'Only SELECT / SHOW / DESCRIBE / EXPLAIN / WITH ... SELECT statements are allowed.', 403 );
		}
		if ( preg_match( '/;|--|\/\*|#|\binto\s+(outfile|dumpfile)\b|\bload_file\s*\(|\bsleep\s*\(|\bbenchmark\s*\(|\bfor\s+update\b|\block\s+in\s+share\s+mode\b/i', $query ) ) {
			return self::fail( 'sb_sql_denied', 'Query contains a disallowed construct (multiple statements, comments, file I/O, sleep, locking).', 403 );
		}
		if ( preg_match( '/^\s*(SELECT|WITH)\b/i', $query ) && ! preg_match( '/\blimit\s+\d+/i', $query ) ) {
			$query .= ' LIMIT ' . $limit;
		}

		$t0 = microtime( true );
		$wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore
		$err  = $wpdb->last_error;
		$wpdb->suppress_errors( false );
		$ms = round( ( microtime( true ) - $t0 ) * 1000, 1 );

		if ( $err ) {
			return new WP_Error( 'sb_sql_error', $err, array( 'status' => 400, 'query' => $query ) );
		}
		$rows = array_map( array( 'SB_Redact', 'row' ), (array) $rows );
		return self::respond(
			$rows,
			array(
				'count'   => count( $rows ),
				'ms'      => $ms,
				'query'   => $query,
				'columns' => $rows ? array_keys( $rows[0] ) : array(),
			)
		);
	}

	public static function render( WP_REST_Request $r ) {
		$path   = (string) $r->get_param( 'path' );
		$as     = (string) ( $r->get_param( 'as' ) ?: 'admin' );
		$format = (string) ( $r->get_param( 'format' ) ?: 'text' );
		$max    = self::int_param( $r, 'max', 200000, 1000, 2000000 );
		if ( $path === '' ) {
			return self::fail( 'sb_args', 'Pass {"path": "wp-admin/admin.php?page=..."} or a full URL on this site.' );
		}

		$home = home_url( '/' );
		if ( preg_match( '#^https?://#i', $path ) ) {
			$url = $path;
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( $home, PHP_URL_HOST ) ) {
				return self::fail( 'sb_render_host', 'Only URLs on this site can be rendered.', 403 );
			}
		} elseif ( strpos( $path, 'wp-admin' ) === 0 ) {
			$url = site_url( '/' . ltrim( $path, '/' ) );
		} else {
			$url = $home . ltrim( $path, '/' );
		}
		$url = add_query_arg( '_sb', time(), $url );

		$cookies = array();
		$user_id = 0;
		if ( $as !== 'guest' ) {
			$user_id = (int) SB_Auth::setting( 'render_user' );
			if ( ! $user_id || ! get_userdata( $user_id ) ) {
				$admins  = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
				$user_id = $admins ? (int) $admins[0]->ID : 0;
			}
			if ( ! $user_id ) {
				return self::fail( 'sb_render_user', 'No administrator user available to render as.', 500 );
			}
			$exp    = time() + 300;
			$secure = wp_parse_url( $url, PHP_URL_SCHEME ) === 'https';
			$auth   = wp_generate_auth_cookie( $user_id, $exp, $secure ? 'secure_auth' : 'auth' );
			$login  = wp_generate_auth_cookie( $user_id, $exp, 'logged_in' );
			$cookies[] = new WP_Http_Cookie( array( 'name' => $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE, 'value' => $auth ) );
			$cookies[] = new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => $login ) );
		}

		$t0  = microtime( true );
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 45,
				'redirection' => 3,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'cookies'     => $cookies,
				'headers'     => array(
					'Cache-Control' => 'no-cache',
					'X-Site-Bridge' => '1',
				),
				'user-agent'  => 'SiteBridge/' . SITE_BRIDGE_VERSION . '; ' . home_url(),
			)
		);
		$ms = round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'sb_render_http', 'Loopback request failed: ' . $res->get_error_message() . ' (host may block loopback requests).', array( 'status' => 502, 'url' => $url ) );
		}
		$status = wp_remote_retrieve_response_code( $res );
		$html   = (string) wp_remote_retrieve_body( $res );

		$title = '';
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			$title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
		}
		$notices = array();
		if ( preg_match_all( '#<div[^>]+class="[^"]*\bnotice\b[^"]*"[^>]*>(.*?)</div>#is', $html, $mm ) ) {
			foreach ( $mm[1] as $n ) {
				$t = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $n ) ) );
				if ( $t !== '' ) {
					$notices[] = $t;
				}
			}
		}
		$php_errors = array();
		if ( preg_match_all( '/(Fatal error|Parse error|Warning|Notice|Deprecated)\s*:\s*(.{0,400}?)(?:<br|\n)/u', $html, $pe, PREG_SET_ORDER ) ) {
			foreach ( $pe as $e ) {
				$php_errors[] = $e[1] . ': ' . trim( wp_strip_all_tags( $e[2] ) );
			}
		}

		$body      = $format === 'html' ? $html : self::html_to_text( $html );
		$truncated = false;
		if ( strlen( $body ) > $max ) {
			$body      = substr( $body, 0, $max );
			$truncated = true;
		}
		return self::respond(
			$body,
			array(
				'url'        => $url,
				'as_user'    => $user_id,
				'status'     => $status,
				'ms'         => $ms,
				'title'      => $title,
				'notices'    => array_slice( array_unique( $notices ), 0, 20 ),
				'php_errors' => array_slice( array_unique( $php_errors ), 0, 20 ),
				'format'     => $format,
				'length'     => strlen( $body ),
				'truncated'  => $truncated,
				'login_page' => (bool) preg_match( '/id="loginform"/', $html ),
			)
		);
	}

	public static function html_to_text( string $html ): string {
		// Keep only the main admin area when present, it is what the user sees.
		if ( preg_match( '#<div[^>]+id="wpbody-content"[^>]*>(.*)<div[^>]+id="wpfooter"#is', $html, $m ) ) {
			$html = $m[1];
		} elseif ( preg_match( '#<body[^>]*>(.*)</body>#is', $html, $m ) ) {
			$html = $m[1];
		}
		$html = preg_replace( '#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<!--.*?-->#s', ' ', $html );
		$html = preg_replace( '#<(select)\b[^>]*>.*?</\1>#is', '[select]', $html );
		$html = preg_replace( '#<input[^>]+type=["\']?hidden["\']?[^>]*>#i', '', $html );
		$html = preg_replace_callback(
			'#<input[^>]*>#i',
			function ( $mm ) {
				$tag = $mm[0];
				$val = preg_match( '/\bvalue=["\']([^"\']*)["\']/i', $tag, $v ) ? $v[1] : '';
				$ph  = preg_match( '/\bplaceholder=["\']([^"\']*)["\']/i', $tag, $p ) ? $p[1] : '';
				return '[' . ( $val !== '' ? $val : $ph ) . ']';
			},
			$html
		);
		$html = preg_replace( '#<(br|hr)\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(p|div|tr|li|h[1-6]|table|ul|ol|section|article|header|footer|form|fieldset|blockquote|pre)>#i', "\n", $html );
		$html = preg_replace( '#<(td|th)\b[^>]*>#i', "\t", $html );
		$html = preg_replace( '#<(h[1-6])\b[^>]*>#i', "\n## ", $html );
		$html = preg_replace( '#<li\b[^>]*>#i', '- ', $html );
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[ \x{00A0}]+/u', ' ', $text );
		$text = preg_replace( '/ ?\t[ \t]*/', "\t", $text );
		$text = preg_replace( '/^[ \t]+|[ \t]+$/m', '', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}
}
