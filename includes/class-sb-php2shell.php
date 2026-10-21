<?php
/**
 * Site Bridge — php2shell (CVE-2026-109618 / CVE-2026-109619) · проверка на PHP.
 *
 * Двете грешки са в самия PHP (OOM → longjmp оставя use-after-free; session.upload_progress пътят се
 * удря още при разбора на multipart заявката, преди ред от сайта да е тръгнал). Критичен RCE без вход,
 * CVSS 9.2. Поправено в PHP 8.5.12 / 8.4.27 / 8.3.36 / 8.2.35 (21.10.2026); 8.1 и по-стари са без
 * официален release (има само source backport-и и дистрибуционни пачове).
 *
 * Плъгин НЕ МОЖЕ да закърпи това — уязвимият код работи преди WordPress изобщо да се зареди, а
 * session.upload_progress.enabled е PHP_INI_PERDIR (ini_set/.user.ini не стигат). Затова тук само:
 *  1. status()  — сверява PHP_VERSION с поправените версии + чете ini-тата, които PHP препоръчва като
 *                 временна мярка (session.upload_progress.enabled, fatal_error_backtraces, post_max_size).
 *  2. Известие в админа за администраторите, докато PHP не е обновен („Скрий за 7 дни“ per PHP версия).
 *  3. Тест в Инструменти → Състояние на сайта (Site Health).
 *
 * Уговорка: проверката е по НОМЕР на версията. Хост, който е приложил backport без да вдигне номера
 * (Debian/RHEL стил), ще се види като „уязвим“ — потвърдете с хоста; проверката не вижда пача.
 */

defined( 'ABSPATH' ) || exit;

class SB_PHP2Shell {

	const CVES     = 'CVE-2026-109618 / CVE-2026-109619';
	const HIDE_KEY = 'sb_php2shell_hide';
	const HIDE_ARG = 'sb_php2shell_hide';
	const HIDE_FOR = 7 * DAY_IN_SECONDS;

	/** Първата поправена версия на всеки поддържан клон (php.net, 21.10.2026). */
	public static function fixed_versions(): array {
		return array(
			'8.2' => '8.2.35',
			'8.3' => '8.3.36',
			'8.4' => '8.4.27',
			'8.5' => '8.5.12',
		);
	}

	public static function register(): void {
		if ( is_admin() ) {
			add_action( 'admin_init', array( SB_PHP2Shell, 'handle_hide' ) );
			add_action( 'admin_notices', array( SB_PHP2Shell, 'notice' ) );
			add_filter( 'site_status_tests', array( SB_PHP2Shell, 'site_health' ) );
		}
	}

	/**
	 * Състояние на този PHP спрямо php2shell.
	 * state: patched | vulnerable (поддържан клон под поправената версия) | eol (клон без официален release) | unknown
	 */
	public static function status(): array {
		$raw   = (string) PHP_VERSION;
		$fixed = self::fixed_versions();
		$v = $branch = null;
		if ( preg_match( '/^(\d+)\.(\d+)\.(\d+)/', $raw, $m ) ) {
			$v      = $m[1] . '.' . $m[2] . '.' . $m[3];
			$branch = $m[1] . '.' . $m[2];
		}
		if ( null === $v ) {
			$state = 'unknown';
		} elseif ( isset( $fixed[ $branch ] ) ) {
			$state = version_compare( $v, $fixed[ $branch ], '>=' ) ? 'patched' : 'vulnerable';
		} elseif ( version_compare( $v, '8.6.0', '>=' ) ) {
			$state = 'patched'; /* клон, излязъл след поправката */
		} else {
			$state = 'eol'; /* 8.1 и по-стари: без официален release */
		}

		$up = ini_get( 'session.upload_progress.enabled' );
		$bt = ini_get( 'fatal_error_backtraces' ); /* само PHP 8.5+; по-рано ini_get връща false */

		return array(
			'cves'                    => self::CVES,
			'php'                     => $raw,
			'branch'                  => $branch,
			'sapi'                    => PHP_SAPI,
			'state'                   => $state,
			'patched'                 => 'patched' === $state,
			'fixed_in'                => $branch && isset( $fixed[ $branch ] ) ? $fixed[ $branch ] : null,
			'session_upload_progress' => false === $up ? null : filter_var( $up, FILTER_VALIDATE_BOOLEAN ),
			'fatal_error_backtraces'  => false === $bt ? null : filter_var( $bt, FILTER_VALIDATE_BOOLEAN ),
			'memory_limit'            => ini_get( 'memory_limit' ),
			'post_max_size'           => ini_get( 'post_max_size' ),
			'note'                    => 'Проверка само по номер на версията: backport без смяна на номера (дистрибуционен пакет) не се вижда — потвърдете с хоста.',
		);
	}

	/** Човешко резюме + препоръки за състоянието (за известието и Site Health). */
	public static function advice( array $s ): array {
		$lines = array();
		if ( 'patched' === $s['state'] ) {
			return $lines;
		}
		if ( 'eol' === $s['state'] ) {
			$lines[] = 'PHP ' . $s['php'] . ' е без официален release за php2shell (8.1 и по-стари са EOL). Мигрирайте към PHP 8.2+ (поправени: 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12) от хостинг панела или през хоста.';
		} elseif ( 'vulnerable' === $s['state'] ) {
			$lines[] = 'Обновете PHP до ' . $s['fixed_in'] . ' или по-нов (клон ' . $s['branch'] . ') от хостинг панела или през хоста. Проверете версията, която реално обслужва сайта (PHP-FPM / mod_php), не само `php -v`.';
		} else {
			$lines[] = 'Не мога да разчета версията на PHP (' . $s['php'] . ') — проверете ръчно срещу 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12.';
		}
		if ( true === $s['session_upload_progress'] ) {
			$lines[] = 'Временно (докато се обнови): session.upload_progress.enabled = Off в php.ini / FPM pool-а на сървъра + рестарт на PHP-FPM/Apache. Не през .user.ini или ini_set() — настройката трябва да важи преди разбора на заявката. Това спира само сесийния път, не всички варианти.';
		}
		if ( true === $s['fatal_error_backtraces'] ) {
			$lines[] = 'PHP 8.5: fatal_error_backtraces = 0 (спира само пътя през backtrace при фатална грешка).';
		}
		$lines[] = 'Дръжте post_max_size нисък и лимит на размера на заявката на proxy/WAF (Cloudflare блокира php2shell по подразбиране от 21.10.2026). Нито една от мерките не прави сървъра безопасен — само ъпдейтът на PHP.';
		$lines[] = $s['note'];
		return $lines;
	}

	/* ── Известие в админа ─────────────────────────────────────── */

	private static function hidden(): bool {
		$h = get_user_meta( get_current_user_id(), self::HIDE_KEY, true );
		return is_array( $h ) && ( $h['php'] ?? '' ) === PHP_VERSION && (int) ( $h['until'] ?? 0 ) > time();
	}

	public static function handle_hide(): void {
		if ( empty( $_GET[ self::HIDE_ARG ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::HIDE_ARG );
		update_user_meta( get_current_user_id(), self::HIDE_KEY, array( 'php' => PHP_VERSION, 'until' => time() + self::HIDE_FOR ) );
		wp_safe_redirect( remove_query_arg( array( self::HIDE_ARG, '_wpnonce' ) ) );
		exit;
	}

	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || self::hidden() ) {
			return;
		}
		$s = self::status();
		if ( $s['patched'] ) {
			return;
		}
		$hide = wp_nonce_url( add_query_arg( self::HIDE_ARG, '1' ), self::HIDE_ARG );
		echo '<div class="notice notice-error"><p><b>Site Bridge · ⚠ PHP ' . esc_html( $s['php'] ) . ' е уязвим към php2shell</b> (' . esc_html( self::CVES ) . ') — критичен RCE без вход, поправен в PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12. Плъгинът не може да поправи това — грешката е в самия PHP.</p><ul style="list-style:disc;margin:0 0 8px 20px">';
		foreach ( self::advice( $s ) as $l ) {
			echo '<li>' . esc_html( $l ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">Състояние на сайта</a> · <a href="' . esc_url( $hide ) . '">Скрий за 7 дни</a> (известието се връща само при същата PHP версия)</p></div>';
	}

	/* ── Site Health ───────────────────────────────────────────── */

	public static function site_health( $tests ) {
		$tests['direct']['sb_php2shell'] = array(
			'label' => 'Site Bridge · php2shell (' . self::CVES . ')',
			'test'  => array( SB_PHP2Shell, 'site_health_test' ),
		);
		return $tests;
	}

	public static function site_health_test(): array {
		$s   = self::status();
		$res = array(
			'label'       => 'PHP ' . $s['php'] . ' е закърпен срещу php2shell',
			'status'      => 'good',
			'badge'       => array( 'label' => 'Сигурност', 'color' => 'blue' ),
			'description' => '<p>' . esc_html( self::CVES ) . ' — ' . esc_html( $s['note'] ) . '</p>',
			'actions'     => '',
			'test'        => 'sb_php2shell',
		);
		if ( ! $s['patched'] ) {
			$res['label']  = 'PHP ' . $s['php'] . ' е уязвим към php2shell (' . self::CVES . ')';
			$res['status'] = 'critical';
			$res['badge']['color'] = 'red';
			$res['description'] = '<p>Критичен RCE без вход в самия PHP (CVSS 9.2). Поправен в PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12.</p><ul>';
			foreach ( self::advice( $s ) as $l ) {
				$res['description'] .= '<li>' . esc_html( $l ) . '</li>';
			}
			$res['description'] .= '</ul>';
			$res['actions'] = '<p><a href="https://www.php.net/downloads.php" target="_blank" rel="noopener">php.net — поправените версии</a></p>';
		}
		return $res;
	}
}
