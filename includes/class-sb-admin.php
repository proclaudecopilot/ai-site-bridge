<?php
/**
 * Settings screen (Настройки → Site Bridge).
 */

defined( 'ABSPATH' ) || exit;

class SB_Admin {

	const SLUG = 'site-bridge';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SITE_BRIDGE_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu(): void {
		add_options_page( 'Site Bridge', 'Site Bridge', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">Настройки</a>' );
		return $links;
	}

	public static function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && $screen->id === 'settings_page_' . self::SLUG ) {
			return;
		}
		if ( SB_Auth::is_enabled() && ! SB_Auth::has_token() ) {
			echo '<div class="notice notice-warning"><p><strong>Site Bridge</strong> е включен, но няма генериран токен. <a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">Генерирайте токен</a>.</p></div>';
		}
	}

	public static function handle_post(): void {
		if ( empty( $_POST['sb_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'sb_settings' );
		$action   = sanitize_key( wp_unslash( $_POST['sb_action'] ) );
		$redirect = admin_url( 'options-general.php?page=' . self::SLUG );

		switch ( $action ) {
			case 'save':
				SB_Auth::update(
					array(
						'enabled'          => ! empty( $_POST['enabled'] ),
						'ip_allowlist'     => sanitize_textarea_field( wp_unslash( $_POST['ip_allowlist'] ?? '' ) ),
						'rate_limit'       => max( 0, (int) ( $_POST['rate_limit'] ?? 120 ) ),
						'allow_files'      => ! empty( $_POST['allow_files'] ),
						'allow_sql'        => ! empty( $_POST['allow_sql'] ),
						'allow_render'     => ! empty( $_POST['allow_render'] ),
						'capture_warnings' => ! empty( $_POST['capture_warnings'] ),
						'log_requests'     => ! empty( $_POST['log_requests'] ),
						'render_user'      => (int) ( $_POST['render_user'] ?? 0 ),
						'file_scope'       => ( $_POST['file_scope'] ?? 'content' ) === 'abspath' ? 'abspath' : 'content',
					)
				);
				$redirect = add_query_arg( 'sb_msg', 'saved', $redirect );
				break;

			case 'generate':
				$token = SB_Auth::generate_token();
				SB_Auth::update( array( 'enabled' => true ) );
				set_transient( 'sb_new_token_' . get_current_user_id(), $token, 300 );
				$redirect = add_query_arg( 'sb_msg', 'token', $redirect );
				break;

			case 'revoke':
				SB_Auth::revoke_token();
				$redirect = add_query_arg( 'sb_msg', 'revoked', $redirect );
				break;

			case 'clear_log':
				SB_Auth::clear_log();
				$redirect = add_query_arg( 'sb_msg', 'log', $redirect );
				break;

			case 'clear_errors':
				SB_Errors::clear();
				$redirect = add_query_arg( 'sb_msg', 'errors', $redirect );
				break;

			case 'check_update':
				SB_Updater::check_now();
				$redirect = add_query_arg( 'sb_msg', 'update', $redirect );
				break;
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s         = SB_Auth::settings();
		$new_token = get_transient( 'sb_new_token_' . get_current_user_id() );
		if ( $new_token ) {
			delete_transient( 'sb_new_token_' . get_current_user_id() );
		}
		$msg       = isset( $_GET['sb_msg'] ) ? sanitize_key( $_GET['sb_msg'] ) : '';
		$base_url  = rest_url( SITE_BRIDGE_NS );
		$const_tok = defined( 'SITE_BRIDGE_TOKEN' ) && SITE_BRIDGE_TOKEN !== '';
		$admins    = get_users( array( 'role' => 'administrator', 'orderby' => 'ID' ) );
		$errors    = SB_Errors::all();
		$log       = SB_Auth::get_log();
		?>
		<div class="wrap">
			<h1>Site Bridge <small style="font-weight:normal;color:#666">v<?php echo esc_html( SITE_BRIDGE_VERSION ); ?></small></h1>
			<p class="description">Read-only REST API, през което AI асистент може да чете състоянието на сайта (информация, опции, постове и meta, Oxygen данни, логове, файлове, SELECT заявки, рендерирани админ страници). Нищо не се записва през него.</p>

			<?php if ( $msg === 'saved' ) : ?>
				<div class="notice notice-success is-dismissible"><p>Настройките са запазени.</p></div>
			<?php elseif ( $msg === 'revoked' ) : ?>
				<div class="notice notice-success is-dismissible"><p>Токенът е анулиран.</p></div>
			<?php elseif ( $msg === 'log' || $msg === 'errors' ) : ?>
				<div class="notice notice-success is-dismissible"><p>Изчистено.</p></div>
			<?php elseif ( $msg === 'update' ) : ?>
				<div class="notice notice-info is-dismissible"><p>Проверката за обновления е изпълнена.</p></div>
			<?php endif; ?>

			<?php if ( $new_token ) : ?>
				<div class="notice notice-success" style="padding:12px 16px">
					<p><strong>Нов токен (показва се само веднъж, запазете го сега):</strong></p>
					<p><input type="text" readonly onclick="this.select()" value="<?php echo esc_attr( $new_token ); ?>" style="width:100%;max-width:640px;font-family:monospace;font-size:14px"></p>
					<p>Пример:</p>
					<pre style="background:#f6f7f7;padding:10px;overflow:auto">curl -s -H "X-Site-Bridge-Token: <?php echo esc_html( $new_token ); ?>" "<?php echo esc_html( $base_url ); ?>/info"</pre>
					<p>За Claude Code сесия: сложете го като environment secret, напр. <code>SB_<?php echo esc_html( strtoupper( preg_replace( '/[^a-z0-9]+/i', '_', wp_parse_url( home_url(), PHP_URL_HOST ) ) ) ); ?>_TOKEN</code>, и URL в <code>SB_..._URL=<?php echo esc_html( home_url() ); ?></code>.</p>
				</div>
			<?php endif; ?>

			<?php if ( defined( 'SITE_BRIDGE_DISABLED' ) && SITE_BRIDGE_DISABLED ) : ?>
				<div class="notice notice-error"><p><code>SITE_BRIDGE_DISABLED</code> е дефинирано в wp-config.php. API-то е изключено независимо от настройките.</p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'sb_settings' ); ?>
				<input type="hidden" name="sb_action" value="save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Състояние</th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> Включено</label>
							<p class="description">Базов URL: <code><?php echo esc_html( $base_url ); ?></code></p>
						</td>
					</tr>
					<tr>
						<th scope="row">Токен</th>
						<td>
							<?php if ( $s['token_hash'] ) : ?>
								<p>Активен токен <code><?php echo esc_html( $s['token_hint'] ); ?>…</code>, създаден <?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $s['token_created'] ) ); ?>,
								последно използван: <?php echo $s['token_last_used'] ? esc_html( wp_date( 'd.m.Y H:i', (int) $s['token_last_used'] ) ) : 'никога'; ?>.</p>
							<?php else : ?>
								<p>Няма генериран токен.</p>
							<?php endif; ?>
							<?php if ( $const_tok ) : ?>
								<p class="description">Освен това е дефиниран <code>SITE_BRIDGE_TOKEN</code> в wp-config.php и той също е валиден.</p>
							<?php endif; ?>
							<p class="description">Токенът се пази само като SHA-256 хеш. Изпраща се в header <code>X-Site-Bridge-Token</code> (или <code>Authorization: Bearer</code>).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Позволени IP адреси</th>
						<td>
							<textarea name="ip_allowlist" rows="3" class="large-text code" placeholder="празно = всички&#10;203.0.113.5&#10;203.0.113.0/24&#10;198.51.100.*"><?php echo esc_textarea( $s['ip_allowlist'] ); ?></textarea>
							<p class="description">По един на ред. Поддържа точен IP, CIDR (IPv4) и <code>*</code>. Текущият ви IP: <code><?php echo esc_html( SB_Auth::client_ip() ); ?></code>. Облачните сесии на Claude излизат от променливи IP адреси, затова по подразбиране е празно.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Лимит заявки / минута</th>
						<td><input type="number" name="rate_limit" min="0" value="<?php echo esc_attr( $s['rate_limit'] ); ?>" class="small-text"> <span class="description">0 = без лимит</span></td>
					</tr>
					<tr>
						<th scope="row">Функции</th>
						<td>
							<label><input type="checkbox" name="allow_files" value="1" <?php checked( $s['allow_files'] ); ?>> Файлове и логове (<code>/file</code>, <code>/ls</code>, <code>/log</code>)</label><br>
							<label style="margin-left:24px">Обхват:
								<select name="file_scope">
									<option value="content" <?php selected( $s['file_scope'], 'content' ); ?>>само wp-content</option>
									<option value="abspath" <?php selected( $s['file_scope'], 'abspath' ); ?>>цялата инсталация (без wp-config.php)</option>
								</select>
							</label><br>
							<label><input type="checkbox" name="allow_sql" value="1" <?php checked( $s['allow_sql'] ); ?>> SQL само за четене (<code>/sql</code>: SELECT / SHOW / DESCRIBE / EXPLAIN, един statement, автоматичен LIMIT)</label><br>
							<label><input type="checkbox" name="allow_render" value="1" <?php checked( $s['allow_render'] ); ?>> Рендериране на страници като администратор (<code>/render</code>)</label><br>
							<label style="margin-left:24px">Рендерирай като:
								<select name="render_user">
									<option value="0">първия администратор</option>
									<?php foreach ( $admins as $u ) : ?>
										<option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $s['render_user'], $u->ID ); ?>><?php echo esc_html( $u->user_login ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<p class="description">При <code>/render</code> плъгинът прави вътрешна HTTP заявка към собствения сайт с временна (5 мин.) auth бисквитка на избрания администратор и връща страницата като текст. Това показва на асистента точно това, което виждате вие в админа.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Грешки</th>
						<td>
							<label><input type="checkbox" name="capture_warnings" value="1" <?php checked( $s['capture_warnings'] ); ?>> Записвай и warnings/notices (не само fatal) в <code>/errors</code></label>
							<p class="description">Fatal грешки се записват винаги (последните 50). Warnings се пазят до 20 на заявка и минават и през стандартния PHP handler, така че debug.log не се променя.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Лог на заявките</th>
						<td><label><input type="checkbox" name="log_requests" value="1" <?php checked( $s['log_requests'] ); ?>> Пази последните <?php echo (int) SB_Auth::LOG_MAX; ?> заявки към API-то</label></td>
					</tr>
				</table>
				<?php submit_button( 'Запази' ); ?>
			</form>

			<hr>
			<h2>Токен</h2>
			<form method="post" style="display:inline-block;margin-right:8px" onsubmit="return confirm('Старият токен ще спре да работи. Продължавате?')">
				<?php wp_nonce_field( 'sb_settings' ); ?>
				<input type="hidden" name="sb_action" value="generate">
				<?php submit_button( $s['token_hash'] ? 'Генерирай нов токен' : 'Генерирай токен', 'primary', 'submit', false ); ?>
			</form>
			<?php if ( $s['token_hash'] ) : ?>
			<form method="post" style="display:inline-block" onsubmit="return confirm('Анулиране на токена?')">
				<?php wp_nonce_field( 'sb_settings' ); ?>
				<input type="hidden" name="sb_action" value="revoke">
				<?php submit_button( 'Анулирай токена', 'delete', 'submit', false ); ?>
			</form>
			<?php endif; ?>

			<hr>
			<h2>Обновления</h2>
			<?php $remote = SB_Updater::remote_version(); ?>
			<p>Инсталирана версия: <strong><?php echo esc_html( SITE_BRIDGE_VERSION ); ?></strong>.
			В GitHub (<code><?php echo esc_html( SB_Updater::REPO ); ?></code>, клон <code><?php echo esc_html( SB_Updater::BRANCH ); ?></code>):
			<?php if ( ! empty( $remote['version'] ) ) : ?>
				<strong><?php echo esc_html( $remote['version'] ); ?></strong>
				<?php if ( version_compare( $remote['version'], SITE_BRIDGE_VERSION, '>' ) ) : ?>
					— <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">има обновление, инсталирайте го от Плъгини</a>.
				<?php else : ?>
					— актуално.
				<?php endif; ?>
			<?php else : ?>
				<span style="color:#b32d2e">неизвестна (<?php echo esc_html( $remote['error'] ?? '' ); ?>)</span>
			<?php endif; ?>
			<?php if ( ! empty( $remote['checked'] ) ) : ?>
				<span class="description">Проверено <?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $remote['checked'] ) ); ?>.</span>
			<?php endif; ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'sb_settings' ); ?>
				<input type="hidden" name="sb_action" value="check_update">
				<?php submit_button( 'Провери сега', 'secondary', 'submit', false ); ?>
			</form>

			<hr>
			<h2>Уловени грешки <small style="font-weight:normal">(<?php echo count( $errors ); ?>)</small></h2>
			<?php if ( $errors ) : ?>
				<table class="widefat striped">
					<thead><tr><th>Кога</th><th>Тип</th><th>Съобщение</th><th>Файл</th><th>Източник</th><th>URL</th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $errors, 0, 20 ) as $e ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'd.m H:i:s', (int) $e['time'] ) ); ?></td>
							<td><?php echo esc_html( $e['type'] ); ?><?php echo ! empty( $e['fatal'] ) ? ' <span style="color:#b32d2e">fatal</span>' : ''; ?></td>
							<td style="max-width:420px;word-break:break-word"><?php echo esc_html( $e['message'] ); ?></td>
							<td><code><?php echo esc_html( $e['file'] . ':' . $e['line'] ); ?></code></td>
							<td><?php echo esc_html( $e['source'] ); ?></td>
							<td><code><?php echo esc_html( $e['url'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" style="margin-top:8px">
					<?php wp_nonce_field( 'sb_settings' ); ?>
					<input type="hidden" name="sb_action" value="clear_errors">
					<?php submit_button( 'Изчисти грешките', 'secondary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p>Няма записани грешки.</p>
			<?php endif; ?>

			<hr>
			<h2>Последни заявки <small style="font-weight:normal">(<?php echo count( $log ); ?>)</small></h2>
			<?php if ( $log ) : ?>
				<table class="widefat striped">
					<thead><tr><th>Кога</th><th>IP</th><th>Метод</th><th>Маршрут</th><th>Параметри</th><th>Статус</th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $log, 0, 50 ) as $l ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'd.m H:i:s', (int) $l['time'] ) ); ?></td>
							<td><?php echo esc_html( $l['ip'] ); ?></td>
							<td><?php echo esc_html( $l['method'] ); ?></td>
							<td><code><?php echo esc_html( $l['route'] ); ?></code></td>
							<td style="max-width:360px;word-break:break-all"><code><?php echo esc_html( $l['params'] ); ?></code></td>
							<td><?php echo (int) $l['status']; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" style="margin-top:8px">
					<?php wp_nonce_field( 'sb_settings' ); ?>
					<input type="hidden" name="sb_action" value="clear_log">
					<?php submit_button( 'Изчисти лога', 'secondary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p>Още няма заявки.</p>
			<?php endif; ?>

			<hr>
			<h2>Бърз тест</h2>
			<pre style="background:#f6f7f7;padding:10px;overflow:auto">curl -s -H "X-Site-Bridge-Token: &lt;TOKEN&gt;" "<?php echo esc_html( $base_url ); ?>/"            # списък с маршрути
curl -s -H "X-Site-Bridge-Token: &lt;TOKEN&gt;" "<?php echo esc_html( $base_url ); ?>/info"
curl -s -H "X-Site-Bridge-Token: &lt;TOKEN&gt;" "<?php echo esc_html( $base_url ); ?>/oxygen"
curl -s -H "X-Site-Bridge-Token: &lt;TOKEN&gt;" "<?php echo esc_html( $base_url ); ?>/log?lines=100"
curl -s -H "X-Site-Bridge-Token: &lt;TOKEN&gt;" -X POST -H "Content-Type: application/json" -d '{"query":"SELECT ID, post_title FROM {prefix}posts WHERE post_type=\'ct_template\'"}' "<?php echo esc_html( $base_url ); ?>/sql"
curl -s -H "X-Site-Bridge-Token: &lt;TOKEN&gt;" -X POST -H "Content-Type: application/json" -d '{"path":"wp-admin/admin.php?page=proof-shrine"}' "<?php echo esc_html( $base_url ); ?>/render"</pre>
		</div>
		<?php
	}
}
