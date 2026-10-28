<?php
/**
 * Admin: settings page (WooCommerce → تطبيق دكان), broadcast form, orders list badge.
 *
 * @package Dokan_App_API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dokan_App_Admin {

	const SLUG = 'dokan-app';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_dokan_app_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_dokan_app_broadcast', array( __CLASS__, 'broadcast' ) );
		add_action( 'admin_post_dokan_app_test_push', array( __CLASS__, 'test_push' ) );

		// Orders list badge — legacy (posts) screen.
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_legacy' ), 10, 2 );
		// HPOS screen.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column_hpos' ), 10, 2 );
		add_action( 'admin_head', array( __CLASS__, 'column_css' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( DOKAN_APP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Plugin row link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">الإعدادات</a>' );
		return $links;
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			'تطبيق دكان',
			'تطبيق دكان',
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/* ------------------------------------------------------------------
	 * Orders column
	 * ------------------------------------------------------------------ */

	/**
	 * Add column after order number.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'order_number' === $k ) {
				$new['dokan_app'] = 'تطبيق';
			}
		}
		if ( ! isset( $new['dokan_app'] ) ) {
			$new['dokan_app'] = 'تطبيق';
		}
		return $new;
	}

	/**
	 * Legacy column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 */
	public static function render_column_legacy( $column, $post_id ) {
		if ( 'dokan_app' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		self::badge( $order );
	}

	/**
	 * HPOS column.
	 *
	 * @param string   $column Column.
	 * @param WC_Order $order  Order.
	 */
	public static function render_column_hpos( $column, $order ) {
		if ( 'dokan_app' !== $column ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		self::badge( $order );
	}

	/**
	 * Print badge.
	 *
	 * @param WC_Order|false $order Order.
	 */
	private static function badge( $order ) {
		if ( ! $order instanceof WC_Order || ! $order->get_meta( '_dokan_app_order' ) ) {
			echo '<span aria-hidden="true">–</span>';
			return;
		}
		$p     = (string) $order->get_meta( '_dokan_app_platform' );
		$title = 'ios' === $p ? 'iOS' : ( 'android' === $p ? 'Android' : '' );
		echo '<span class="dokan-app-badge" title="' . esc_attr( $title ) . '">تطبيق' . ( $title ? ' · ' . esc_html( $title ) : '' ) . '</span>';
	}

	/**
	 * Column CSS.
	 */
	public static function column_css() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		echo '<style>.column-dokan_app{width:84px}.dokan-app-badge{display:inline-block;padding:2px 8px;border-radius:10px;background:#e8f5e9;color:#1b5e20;font-size:11px;font-weight:600;white-space:nowrap}</style>';
	}

	/* ------------------------------------------------------------------
	 * Settings page
	 * ------------------------------------------------------------------ */

	/**
	 * Render page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'ليس لديك صلاحية.', 'dokan-app' ) );
		}
		$s       = wp_parse_args( (array) get_option( Dokan_App_Helpers::OPT_SETTINGS, array() ), Dokan_App_Helpers::defaults() );
		$sa_raw  = (string) get_option( Dokan_App_Helpers::OPT_FCM_SA, '' );
		$sa      = Dokan_App_Push::service_account();
		$banners = is_array( $s['banners'] ) ? $s['banners'] : array();
		$banners[] = array(
			'image' => '',
			'title' => '',
			'link'  => '',
		); // One empty row for adding.
		$notice = isset( $_GET['dokan_app_msg'] ) ? sanitize_key( wp_unslash( $_GET['dokan_app_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$count  = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$msgs   = array(
			'saved'      => array( 'success', 'تم حفظ الإعدادات.' ),
			'sa_invalid' => array( 'error', 'ملف حساب الخدمة (Service Account JSON) غير صالح. لم يتم حفظه.' ),
			'sent'       => array( 'success', sprintf( 'تم إرسال الإشعار إلى %d جهاز.', $count ) ),
			'push_off'   => array( 'error', 'الإشعارات غير مفعّلة: أضف ملف حساب الخدمة لـ Firebase أولاً.' ),
			'empty'      => array( 'error', 'يرجى كتابة عنوان ونص الإشعار.' ),
			'test_sent'  => array( 'success', sprintf( 'تم إرسال إشعار تجريبي إلى %d جهاز.', $count ) ),
			'no_user'    => array( 'error', 'لم يتم العثور على عميل بهذا الرقم أو لا توجد لديه أجهزة مسجلة.' ),
		);
		?>
		<div class="wrap" dir="rtl" style="max-width:960px">
			<h1>تطبيق دكان</h1>
			<?php if ( isset( $msgs[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msgs[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $msgs[ $notice ][1] ); ?></p></div>
			<?php endif; ?>

			<p>رابط واجهة التطبيق: <code><?php echo esc_html( rest_url( DOKAN_APP_NS ) ); ?></code></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dokan_app_save_settings">
				<?php wp_nonce_field( 'dokan_app_save_settings' ); ?>

				<h2>إرسال رمز التحقق (OTP)</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">طريقة الإرسال</th>
						<td>
							<select name="s[otp_sender]">
								<option value="none" <?php selected( $s['otp_sender'], 'none' ); ?>>غير مفعّل (أو عبر إضافة أخرى بفلتر dokan_app_send_otp)</option>
								<option value="whatsapp" <?php selected( $s['otp_sender'], 'whatsapp' ); ?>>واتساب (WhatsApp Cloud API)</option>
								<option value="sms" <?php selected( $s['otp_sender'], 'sms' ); ?>>بوابة SMS عامة (HTTP)</option>
							</select>
						</td>
					</tr>
					<tr><th colspan="2"><strong>واتساب</strong></th></tr>
					<tr>
						<th scope="row"><label for="wa_pnid">Phone Number ID</label></th>
						<td><input id="wa_pnid" class="regular-text" dir="ltr" name="s[wa_phone_number_id]" value="<?php echo esc_attr( $s['wa_phone_number_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wa_tok">Access Token</label></th>
						<td><input id="wa_tok" type="password" autocomplete="new-password" class="regular-text" dir="ltr" name="s[wa_access_token]" value="" placeholder="<?php echo $s['wa_access_token'] ? esc_attr( '•••••••• (محفوظ — اتركه فارغًا للإبقاء)' ) : ''; ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wa_tpl">اسم القالب (Authentication)</label></th>
						<td><input id="wa_tpl" class="regular-text" dir="ltr" name="s[wa_template_name]" value="<?php echo esc_attr( $s['wa_template_name'] ); ?>">
						<p class="description">قالب من فئة Authentication يحتوي على متغير الرمز وزر نسخ الرمز.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wa_lang">رمز اللغة</label></th>
						<td><input id="wa_lang" class="small-text" dir="ltr" name="s[wa_language]" value="<?php echo esc_attr( $s['wa_language'] ); ?>"></td>
					</tr>
					<tr><th colspan="2"><strong>بوابة SMS</strong></th></tr>
					<tr>
						<th scope="row"><label for="sms_url">رابط البوابة</label></th>
						<td><input id="sms_url" class="large-text" dir="ltr" name="s[sms_url]" value="<?php echo esc_attr( $s['sms_url'] ); ?>">
						<p class="description">استخدم <code>{phone}</code> و <code>{message}</code> داخل الرابط، مثال: <code dir="ltr">https://gateway.example/send?user=X&amp;pass=Y&amp;to={phone}&amp;msg={message}</code>. في وضع POST تُرسل متغيرات الرابط كحقول نموذج.</p></td>
					</tr>
					<tr>
						<th scope="row">الطريقة</th>
						<td><select name="s[sms_method]">
							<option value="GET" <?php selected( $s['sms_method'], 'GET' ); ?>>GET</option>
							<option value="POST" <?php selected( $s['sms_method'], 'POST' ); ?>>POST</option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="sms_msg">نص الرسالة</label></th>
						<td><input id="sms_msg" class="large-text" name="s[sms_message]" value="<?php echo esc_attr( $s['sms_message'] ); ?>">
						<p class="description">يجب أن يحتوي على <code>{code}</code>.</p></td>
					</tr>
				</table>

				<h2>الإشعارات (Firebase Cloud Messaging)</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="fcm_sa">Service Account JSON</label></th>
						<td>
							<?php if ( $sa ) : ?>
								<p>✅ محفوظ للمشروع: <code dir="ltr"><?php echo esc_html( $sa['project_id'] ); ?></code></p>
							<?php elseif ( '' !== $sa_raw ) : ?>
								<p>⚠️ المحفوظ غير صالح.</p>
							<?php endif; ?>
							<textarea id="fcm_sa" name="fcm_sa" rows="5" class="large-text code" dir="ltr" placeholder="<?php echo $sa ? esc_attr( 'اتركه فارغًا للإبقاء على الملف الحالي' ) : '{ &quot;type&quot;: &quot;service_account&quot;, ... }'; ?>"></textarea>
							<label><input type="checkbox" name="fcm_sa_clear" value="1"> حذف الملف المحفوظ</label>
							<?php if ( ! function_exists( 'openssl_sign' ) ) : ?>
								<p style="color:#b32d2e">امتداد OpenSSL غير متوفر على الخادم، الإشعارات لن تعمل.</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2>الدعم والتواصل</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cw_url">Chatwoot Base URL</label></th>
						<td><input id="cw_url" class="regular-text" dir="ltr" name="s[chatwoot_base_url]" value="<?php echo esc_attr( $s['chatwoot_base_url'] ); ?>" placeholder="https://support.dokansa.com"></td>
					</tr>
					<tr>
						<th scope="row"><label for="cw_tok">Chatwoot Website Token</label></th>
						<td><input id="cw_tok" class="regular-text" dir="ltr" name="s[chatwoot_token]" value="<?php echo esc_attr( $s['chatwoot_token'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wa_num">رقم واتساب</label></th>
						<td><input id="wa_num" class="regular-text" dir="ltr" name="s[whatsapp]" value="<?php echo esc_attr( $s['whatsapp'] ); ?>" placeholder="9665XXXXXXXX"></td>
					</tr>
				</table>

				<h2>التوصيل</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cutoff">آخر موعد للطلب (الرياض)</label></th>
						<td><input id="cutoff" type="time" name="s[riyadh_cutoff]" value="<?php echo esc_attr( $s['riyadh_cutoff'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="dn_r">ملاحظة التوصيل — الرياض</label></th>
						<td><textarea id="dn_r" class="large-text" rows="2" name="s[delivery_note_riyadh]"><?php echo esc_textarea( $s['delivery_note_riyadh'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="dn_k">ملاحظة التوصيل — باقي المملكة</label></th>
						<td><textarea id="dn_k" class="large-text" rows="2" name="s[delivery_note_ksa]"><?php echo esc_textarea( $s['delivery_note_ksa'] ); ?></textarea></td>
					</tr>
				</table>

				<h2>البنرات</h2>
				<p class="description">اترك رابط الصورة فارغًا لحذف الصف. الرابط يمكن أن يكون رابط منتج/تصنيف من الموقع.</p>
				<table class="widefat striped" id="dokan-app-banners" style="max-width:900px">
					<thead><tr><th>رابط الصورة</th><th>العنوان</th><th>الرابط</th></tr></thead>
					<tbody>
					<?php foreach ( $banners as $i => $b ) : ?>
						<tr>
							<td><input class="large-text" dir="ltr" name="banners[<?php echo (int) $i; ?>][image]" value="<?php echo esc_attr( isset( $b['image'] ) ? $b['image'] : '' ); ?>"></td>
							<td><input class="large-text" name="banners[<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr( isset( $b['title'] ) ? $b['title'] : '' ); ?>"></td>
							<td><input class="large-text" dir="ltr" name="banners[<?php echo (int) $i; ?>][link]" value="<?php echo esc_attr( isset( $b['link'] ) ? $b['link'] : '' ); ?>"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="dokan-app-add-banner">+ إضافة صف</button></p>

				<h2>أقل إصدار مسموح للتطبيق</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="min_ios">iOS</label></th>
						<td><input id="min_ios" class="small-text" dir="ltr" name="s[min_ios]" value="<?php echo esc_attr( $s['min_ios'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="min_and">Android</label></th>
						<td><input id="min_and" class="small-text" dir="ltr" name="s[min_android]" value="<?php echo esc_attr( $s['min_android'] ); ?>"></td>
					</tr>
				</table>

				<?php submit_button( 'حفظ الإعدادات' ); ?>
			</form>

			<hr>
			<h2>إرسال إشعار لجميع مستخدمي التطبيق</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('سيتم إرسال الإشعار لجميع المستخدمين. متابعة؟');">
				<input type="hidden" name="action" value="dokan_app_broadcast">
				<?php wp_nonce_field( 'dokan_app_broadcast' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="b_title">العنوان</label></th>
						<td><input id="b_title" class="regular-text" name="title" maxlength="80" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="b_body">النص</label></th>
						<td><textarea id="b_body" class="large-text" rows="3" name="body" maxlength="240" required></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="b_pid">رقم المنتج (اختياري)</label></th>
						<td><input id="b_pid" type="number" min="0" class="small-text" name="product_id">
						<p class="description">عند الضغط على الإشعار يفتح التطبيق صفحة هذا المنتج.</p></td>
					</tr>
				</table>
				<?php submit_button( 'إرسال الإشعار', 'primary', 'submit', true, Dokan_App_Push::is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>

			<h3>إشعار تجريبي لعميل</h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dokan_app_test_push">
				<?php wp_nonce_field( 'dokan_app_test_push' ); ?>
				<input name="phone" class="regular-text" dir="ltr" placeholder="05XXXXXXXX" required>
				<?php submit_button( 'إرسال تجريبي', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<script>
		(function(){
			var btn = document.getElementById('dokan-app-add-banner');
			if (!btn) { return; }
			btn.addEventListener('click', function(){
				var tbody = document.querySelector('#dokan-app-banners tbody');
				var i = tbody.rows.length + Date.now() % 1000;
				var tr = document.createElement('tr');
				tr.innerHTML = '<td><input class="large-text" dir="ltr" name="banners['+i+'][image]"></td>' +
					'<td><input class="large-text" name="banners['+i+'][title]"></td>' +
					'<td><input class="large-text" dir="ltr" name="banners['+i+'][link]"></td>';
				tbody.appendChild(tr);
			});
		})();
		</script>
		<?php
	}

	/**
	 * Redirect back with message.
	 *
	 * @param string $msg Msg key.
	 * @param int    $n   Count.
	 */
	private static function back( $msg, $n = 0 ) {
		$args = array(
			'page'          => self::SLUG,
			'dokan_app_msg' => $msg,
		);
		if ( $n ) {
			$args['n'] = $n;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save settings.
	 */
	public static function save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'ليس لديك صلاحية.', 'dokan-app' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'dokan_app_save_settings' );

		$old = wp_parse_args( (array) get_option( Dokan_App_Helpers::OPT_SETTINGS, array() ), Dokan_App_Helpers::defaults() );
		$in  = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$get = function ( $k ) use ( $in ) {
			return isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : '';
		};

		$new                       = $old;
		$sender                    = $get( 'otp_sender' );
		$new['otp_sender']         = in_array( $sender, array( 'none', 'whatsapp', 'sms' ), true ) ? $sender : 'none';
		$new['wa_phone_number_id'] = preg_replace( '/\D+/', '', $get( 'wa_phone_number_id' ) );
		$tok                       = trim( sanitize_text_field( $get( 'wa_access_token' ) ) );
		if ( '' !== $tok ) {
			$new['wa_access_token'] = $tok;
		}
		$new['wa_template_name']     = sanitize_key( $get( 'wa_template_name' ) );
		$lang                        = preg_replace( '/[^A-Za-z_]/', '', $get( 'wa_language' ) );
		$new['wa_language']          = '' !== $lang ? $lang : 'ar';
		$new['sms_url']              = esc_url_raw( trim( $get( 'sms_url' ) ), array( 'https', 'http' ) );
		$new['sms_method']           = 'POST' === strtoupper( $get( 'sms_method' ) ) ? 'POST' : 'GET';
		$new['sms_message']          = sanitize_text_field( $get( 'sms_message' ) );
		$new['chatwoot_base_url']    = esc_url_raw( trim( $get( 'chatwoot_base_url' ) ), array( 'https', 'http' ) );
		$new['chatwoot_token']       = preg_replace( '/[^A-Za-z0-9_\-]/', '', $get( 'chatwoot_token' ) );
		$new['whatsapp']             = preg_replace( '/[^\d+]/', '', $get( 'whatsapp' ) );
		$cut                         = $get( 'riyadh_cutoff' );
		$new['riyadh_cutoff']        = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $cut ) ? $cut : '15:00';
		$new['delivery_note_riyadh'] = sanitize_textarea_field( $get( 'delivery_note_riyadh' ) );
		$new['delivery_note_ksa']    = sanitize_textarea_field( $get( 'delivery_note_ksa' ) );
		$ver                         = function ( $v ) {
			return preg_match( '/^\d+(\.\d+){0,3}$/', $v ) ? $v : '1.0.0';
		};
		$new['min_ios']              = $ver( trim( $get( 'min_ios' ) ) );
		$new['min_android']          = $ver( trim( $get( 'min_android' ) ) );

		$banners = array();
		$raw_b   = isset( $_POST['banners'] ) && is_array( $_POST['banners'] ) ? wp_unslash( $_POST['banners'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $raw_b as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			$img = isset( $b['image'] ) && is_scalar( $b['image'] ) ? esc_url_raw( trim( (string) $b['image'] ), array( 'https', 'http' ) ) : '';
			if ( '' === $img ) {
				continue;
			}
			$banners[] = array(
				'image' => $img,
				'title' => isset( $b['title'] ) && is_scalar( $b['title'] ) ? sanitize_text_field( (string) $b['title'] ) : '',
				'link'  => isset( $b['link'] ) && is_scalar( $b['link'] ) ? esc_url_raw( trim( (string) $b['link'] ) ) : '',
			);
			if ( count( $banners ) >= 20 ) {
				break;
			}
		}
		$new['banners'] = $banners;

		update_option( Dokan_App_Helpers::OPT_SETTINGS, $new, true );
		delete_transient( 'dokan_app_config_cache' );

		$msg = 'saved';
		if ( ! empty( $_POST['fcm_sa_clear'] ) ) {
			delete_option( Dokan_App_Helpers::OPT_FCM_SA );
			delete_transient( Dokan_App_Push::TOKEN_CACHE );
		} else {
			$sa = isset( $_POST['fcm_sa'] ) ? trim( (string) wp_unslash( $_POST['fcm_sa'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON validated below.
			if ( '' !== $sa ) {
				$dec = json_decode( $sa, true );
				if ( is_array( $dec ) && ! empty( $dec['client_email'] ) && ! empty( $dec['private_key'] ) && ! empty( $dec['project_id'] ) && is_email( $dec['client_email'] ) ) {
					delete_option( Dokan_App_Helpers::OPT_FCM_SA );
					add_option( Dokan_App_Helpers::OPT_FCM_SA, wp_json_encode( $dec ), '', 'no' );
					delete_transient( Dokan_App_Push::TOKEN_CACHE );
				} else {
					$msg = 'sa_invalid';
				}
			}
		}
		self::back( $msg );
	}

	/**
	 * Broadcast.
	 */
	public static function broadcast() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'ليس لديك صلاحية.', 'dokan-app' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'dokan_app_broadcast' );
		if ( ! Dokan_App_Push::is_configured() ) {
			self::back( 'push_off' );
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$body  = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		$pid   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( '' === $title || '' === $body ) {
			self::back( 'empty' );
		}
		$data = array( 'type' => 'promo' );
		if ( $pid ) {
			$data['product_id'] = (string) $pid;
		}
		$r = Dokan_App_Push::broadcast( $title, $body, $data );
		self::back( 'sent', max( 0, (int) $r['sent'] ) );
	}

	/**
	 * Test push to one customer by phone.
	 */
	public static function test_push() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'ليس لديك صلاحية.', 'dokan-app' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'dokan_app_test_push' );
		if ( ! Dokan_App_Push::is_configured() ) {
			self::back( 'push_off' );
		}
		$phone = isset( $_POST['phone'] ) ? Dokan_App_Helpers::normalize_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) : '';
		$uid   = '' !== $phone ? Dokan_App_OTP::find_user_by_phone( $phone ) : 0;
		if ( ! $uid || empty( Dokan_App_Push::devices( $uid ) ) ) {
			self::back( 'no_user' );
		}
		$n = Dokan_App_Push::send_to_user( $uid, 'إشعار تجريبي', 'هذا إشعار تجريبي من متجر دكان 🐾', array( 'type' => 'promo' ) );
		self::back( 'test_sent', $n );
	}
}
