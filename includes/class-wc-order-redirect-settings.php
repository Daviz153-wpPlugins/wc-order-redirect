<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Order_Redirect_Settings extends WC_Settings_Page {

	private const PER_PAGE_DEFAULT = 10;

	public function __construct() {
		$this->id    = 'wcor';
		$this->label = '리다이렉트';
		parent::__construct();
		add_action( 'woocommerce_admin_field_wcor_toggle', array( $this, 'render_toggle_field' ) );
		add_action( 'woocommerce_admin_field_wcor_url', array( $this, 'render_url_field' ) );
		add_action( 'woocommerce_admin_field_wcor_log_viewer', array( $this, 'render_log_viewer' ) );
		add_action( 'admin_post_wcor_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_wcor_delete_log_entry', array( $this, 'handle_delete_log_entry' ) );
		add_action( 'admin_head', array( $this, 'inject_styles' ) );
	}

	public function inject_styles(): void {
		?>
		<style>
			/* WC/WP admin 반응형 CSS가 테이블 셀을 block으로 바꾸는 것 차단 */
			.wcor-log table { display:table !important; width:100% !important; }
			.wcor-log table thead { display:table-header-group !important; }
			.wcor-log table tbody { display:table-row-group !important; }
			.wcor-log table tr { display:table-row !important; }
			.wcor-log table th,
			.wcor-log table td { display:table-cell !important; vertical-align:top; padding:8px 10px !important; }
			/* select 너비 — WC form-table이 100%로 늘리는 것 차단 */
			.wcor-log select { width:auto !important; min-width:90px; max-width:120px; }
			/* 툴바 버튼 높이 통일 */
			.wcor-log .button { height:30px !important; line-height:28px !important; box-sizing:border-box; }
			/* 페이지네이션 내비게이션 버튼 — a와 span 동일 박스 */
			.wcor-log .wcor-pg { display:inline-flex !important; align-items:center; justify-content:center; min-width:28px; height:28px !important; line-height:1 !important; padding:0 5px; font-size:13px; text-decoration:none; }
		</style>
		<?php
	}

	public function get_settings(): array {
		return apply_filters(
			'woocommerce_get_settings_' . $this->id,
			array(
				array(
					'title' => '기본 설정',
					'type'  => 'title',
					'id'    => 'wcor_general_title',
				),
				array(
					'title'   => '리다이렉트 기능',
					'type'    => 'wcor_toggle',
					'desc'    => '기능을 끄면 모든 리다이렉트가 중단됩니다.',
					'id'      => 'wcor_enabled',
					'default' => 'yes',
				),
				array(
					'title'   => '기본 리다이렉트 URL',
					'type'    => 'wcor_url',
					'desc'    => '상품별 리다이렉트가 꺼져 있을 때 이동할 URL. http:// 또는 https://로 시작하는 모든 URL(외부 사이트 포함) 사용 가능. 비워두면 리다이렉트하지 않습니다.',
					'id'      => 'wcor_default_url',
					'default' => '',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'wcor_general_end',
				),
				array(
					'title' => '리다이렉트 로그',
					'desc'  => '최근 50건의 리다이렉트 이력',
					'type'  => 'title',
					'id'    => 'wcor_log_title',
				),
				array(
					'type' => 'wcor_log_viewer',
					'id'   => 'wcor_log_viewer',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'wcor_log_end',
				),
			)
		);
	}

	public function save(): void {
		$enabled = ! empty( $_POST['wcor_enabled'] ) ? 'yes' : 'no';
		$raw_url = trim( wp_unslash( (string) ( $_POST['wcor_default_url'] ?? '' ) ) );

		if ( '' !== $raw_url ) {
			if (
				! filter_var( $raw_url, FILTER_VALIDATE_URL ) ||
				( ! str_starts_with( $raw_url, 'http://' ) && ! str_starts_with( $raw_url, 'https://' ) )
			) {
				WC_Admin_Settings::add_error(
					__( '기본 리다이렉트 URL이 유효하지 않습니다. http:// 또는 https://로 시작하는 전체 주소를 입력해주세요.', 'wc-order-redirect' )
				);
				update_option( 'wcor_enabled', $enabled );
				return;
			}

			if ( ! wcor_is_url_domain_allowed( $raw_url ) ) {
				WC_Admin_Settings::add_error(
					__( '허용되지 않은 도메인입니다. 자체 도메인 또는 허용된 외부 서비스(make.com, tally.so)만 사용 가능합니다.', 'wc-order-redirect' )
				);
				update_option( 'wcor_enabled', $enabled );
				return;
			}
		}

		update_option( 'wcor_enabled', $enabled );
		update_option( 'wcor_default_url', esc_url_raw( $raw_url ) );
	}

	// ── Custom field renderers ──────────────────────────────────────────

	public function render_toggle_field( array $value ): void {
		$enabled = get_option( $value['id'], $value['default'] ?? 'yes' ) === 'yes';
		$id      = esc_attr( $value['id'] );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo $id; ?>"><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp">
				<style>
					.wcor-toggle{position:relative;display:inline-block;width:44px;height:24px;vertical-align:middle}
					.wcor-toggle input{opacity:0;width:0;height:0}
					.wcor-toggle-slider{position:absolute;cursor:pointer;inset:0;background:#ccc;border-radius:24px;transition:.25s}
					.wcor-toggle-slider::before{content:"";position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s}
					.wcor-toggle input:checked+.wcor-toggle-slider{background:#2271b1}
					.wcor-toggle input:checked+.wcor-toggle-slider::before{transform:translateX(20px)}
					.wcor-toggle-label{margin-left:8px;vertical-align:middle;font-size:13px;color:#646970}
				</style>
				<label class="wcor-toggle">
					<input type="checkbox" id="<?php echo $id; ?>" name="<?php echo $id; ?>" value="yes"<?php checked( $enabled ); ?>>
					<span class="wcor-toggle-slider"></span>
				</label>
				<?php if ( ! empty( $value['desc'] ) ) : ?>
					<span class="wcor-toggle-label"><?php echo esc_html( $value['desc'] ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public function render_url_field( array $value ): void {
		$current = get_option( $value['id'], $value['default'] ?? '' );
		$id      = esc_attr( $value['id'] );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo $id; ?>"><?php echo esc_html( $value['title'] ); ?></label>
			</th>
			<td class="forminp">
				<div style="display:flex;align-items:center;gap:0;max-width:420px;">
					<input type="url" id="<?php echo $id; ?>" name="<?php echo $id; ?>"
							value="<?php echo esc_attr( $current ); ?>"
							style="flex:1;min-width:0;height:30px;padding:0 8px;box-sizing:border-box;border-radius:4px 0 0 4px;border-right:none;"
							placeholder="https://">
					<button type="button" class="button"
							style="flex-shrink:0;border-radius:0 4px 4px 0;height:30px;line-height:28px;"
							onclick="(function(){var v=document.getElementById('<?php echo esc_js( $id ); ?>').value.trim();if(!v){alert('URL을 먼저 입력해주세요.');return;}if(!/^https?:\/\//i.test(v)){alert('http:// 또는 https://로 시작하는 URL만 열 수 있습니다.');return;}window.open(v,'_blank','noopener,noreferrer');})()">확인</button>
				</div>
				<?php if ( ! empty( $value['desc'] ) ) : ?>
					<p class="description"><?php echo esc_html( $value['desc'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	// ── Log viewer ──────────────────────────────────────────────────────

	public function render_log_viewer(): void {
		$raw_log  = (array) get_option( 'wcor_redirect_log', array() );
		$cleared  = isset( $_GET['cleared'] );
		$search   = sanitize_text_field( wp_unslash( $_GET['log_search'] ?? '' ) );
		$sort_col = in_array( $_GET['sort_col'] ?? '', array( 'ts', 'order', 'url', 'src', 'customer' ), true )
					? sanitize_key( $_GET['sort_col'] ) : 'ts';
		$sort_dir = ( $_GET['sort_dir'] ?? 'desc' ) === 'asc' ? 'asc' : 'desc';
		$per_page = in_array( (int) ( $_GET['log_per_page'] ?? 0 ), array( 10, 20, 50 ), true )
					? (int) $_GET['log_per_page'] : self::PER_PAGE_DEFAULT;
		$base_url = admin_url( 'admin.php?page=wc-settings&tab=wcor' );

		// 저장된 엔트리에 customer/products 필드가 없으면 WC에서 채움 (검색 대상 확보)
		$log = $raw_log;
		foreach ( $log as &$entry ) {
			$oid = (int) ( $entry['order'] ?? 0 );
			if ( $oid && ( '' === ( $entry['customer'] ?? '' ) || '' === ( $entry['products'] ?? '' ) ) ) {
				$wc_order = wc_get_order( $oid );
				if ( $wc_order ) {
					if ( '' === ( $entry['products'] ?? '' ) ) {
						$entry['products'] = implode( ', ', array_map( fn( $it ) => $it->get_name(), $wc_order->get_items() ) );
					}
					if ( '' === ( $entry['customer'] ?? '' ) ) {
						$entry['customer'] = trim( $wc_order->get_billing_last_name() . ' ' . $wc_order->get_billing_first_name() );
						$entry['email']    = $wc_order->get_billing_email();
						$entry['phone']    = $wc_order->get_billing_phone();
					}
				}
			}
		}
		unset( $entry );

		// filter
		if ( '' !== $search ) {
			$s   = strtolower( $search );
			$log = array_values(
				array_filter(
					$log,
					function ( array $e ) use ( $s ): bool {
						return str_contains( strtolower( (string) ( $e['order'] ?? '' ) ), $s )
						|| str_contains( strtolower( $e['url'] ?? '' ), $s )
						|| str_contains( strtolower( $e['products'] ?? '' ), $s )
						|| str_contains( strtolower( $e['customer'] ?? '' ), $s )
						|| str_contains( strtolower( $e['email'] ?? '' ), $s )
						|| str_contains( strtolower( $e['phone'] ?? '' ), $s );
					}
				)
			);
		}

		// sort
		usort(
			$log,
			function ( array $a, array $b ) use ( $sort_col, $sort_dir ): int {
				$av  = $a[ $sort_col ] ?? '';
				$bv  = $b[ $sort_col ] ?? '';
				$cmp = ( is_numeric( $av ) && is_numeric( $bv ) ) ? ( $av <=> $bv ) : strcmp( (string) $av, (string) $bv );
				return 'asc' === $sort_dir ? $cmp : -$cmp;
			}
		);

		$total       = count( $log );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged       = max( 1, min( $total_pages, (int) ( $_GET['log_paged'] ?? 1 ) ) );
		$current_log = array_slice( $log, ( $paged - 1 ) * $per_page, $per_page );

		$sort_url         = function ( string $col ) use ( $sort_col, $sort_dir, $per_page, $search, $base_url ): string {
			$dir = ( $col === $sort_col && 'desc' === $sort_dir ) ? 'asc' : 'desc';
			return add_query_arg(
				array(
					'sort_col'     => $col,
					'sort_dir'     => $dir,
					'log_paged'    => 1,
					'log_per_page' => $per_page,
					'log_search'   => $search,
				),
				$base_url
			);
		};
		$arrow            = function ( string $col ) use ( $sort_col, $sort_dir ): string {
			if ( $sort_col !== $col ) {
				return '<span style="opacity:.3;margin-left:2px;font-size:10px;">↕</span>';
			}
			return 'asc' === $sort_dir
				? '<span style="margin-left:2px;font-size:10px;color:#2271b1;">↑</span>'
				: '<span style="margin-left:2px;font-size:10px;color:#2271b1;">↓</span>';
		};
		$page_url         = function ( int $p ) use ( $sort_col, $sort_dir, $per_page, $search, $base_url ): string {
			return add_query_arg(
				array(
					'log_paged'    => $p,
					'log_per_page' => $per_page,
					'sort_col'     => $sort_col,
					'sort_dir'     => $sort_dir,
					'log_search'   => $search,
				),
				$base_url
			);
		};
		$per_page_url_tpl = esc_js(
			add_query_arg(
				array(
					'log_per_page' => '__WCOR_PP__',
					'log_paged'    => 1,
					'sort_col'     => $sort_col,
					'sort_dir'     => $sort_dir,
					'log_search'   => $search,
				),
				$base_url
			)
		);
		$search_url_tpl   = esc_js(
			add_query_arg(
				array(
					'log_search'   => '__WCOR_S__',
					'log_paged'    => 1,
					'sort_col'     => $sort_col,
					'sort_dir'     => $sort_dir,
					'log_per_page' => $per_page,
				),
				$base_url
			)
		);
		$hl               = static function ( string $text ) use ( $search ): string {
			$escaped = esc_html( $text );
			if ( '' === $search || '' === $text ) {
				return $escaped;
			}
			return preg_replace(
				'/(' . preg_quote( esc_html( $search ), '/' ) . ')/iu',
				'<mark style="background:#fff176;padding:0 1px;border-radius:2px;">$1</mark>',
				$escaped
			) ?? $escaped;
		};
		?>
		<tr valign="top">
			<td colspan="2" style="padding:16px 0 0;">
				<div class="wcor-log wc-settings-prevent-change-event">

				<?php if ( $cleared ) : ?>
					<div style="background:#edfaef;border-left:4px solid #00a32a;padding:10px 14px;margin-bottom:14px;border-radius:2px;">
						로그가 비워졌습니다.
					</div>
				<?php endif; ?>

				<?php if ( empty( $raw_log ) ) : ?>
					<p style="color:#8c8f94;font-size:13px;">아직 리다이렉트 기록이 없습니다.</p>
				<?php else : ?>

					<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
						<div style="display:flex;align-items:center;gap:0;">
							<input type="text" id="wcor-search-input"
									value="<?php echo esc_attr( $search ); ?>"
									placeholder="주문번호, 주문자, URL 검색…"
									style="width:220px;height:30px;padding:0 8px;border:1px solid #8c8f94;border-right:none;border-radius:4px 0 0 4px;box-sizing:border-box;font-size:13px;background:#fff;outline:none;"
									onkeydown="if(event.key==='Enter'){window.location.href='<?php echo $search_url_tpl; ?>'.replace('__WCOR_S__',encodeURIComponent(this.value));}">
							<button type="button" class="button button-small"
									style="height:30px;line-height:28px;border-radius:0 4px 4px 0;flex-shrink:0;box-sizing:border-box;"
									onclick="var i=document.getElementById('wcor-search-input');if(i)window.location.href='<?php echo $search_url_tpl; ?>'.replace('__WCOR_S__',encodeURIComponent(i.value));">검색</button>
						</div>
						<?php if ( '' !== $search ) : ?>
							<a href="<?php echo esc_url( $base_url ); ?>" class="button button-small">✕ 초기화</a>
						<?php endif; ?>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcor_clear_log' ), 'wcor_clear_log' ) ); ?>"
							class="button button-small"
							style="margin-left:auto;"
							onclick="return confirm('로그를 모두 비울까요?')">로그 비우기</a>
					</div>

					<?php if ( 0 === $total ) : ?>
						<p style="color:#8c8f94;font-size:13px;">"<?php echo esc_html( $search ); ?>" 검색 결과가 없습니다.</p>
					<?php else : ?>

						<!-- 로그 테이블 -->
						<table class="widefat" style="border-collapse:collapse;width:100%;">
							<thead>
								<tr>
									<th style="width:130px;white-space:nowrap;">
										<a href="<?php echo esc_url( $sort_url( 'ts' ) ); ?>" style="color:inherit;text-decoration:none;">시간<?php echo $arrow( 'ts' ); ?></a>
									</th>
									<th style="width:70px;white-space:nowrap;">
										<a href="<?php echo esc_url( $sort_url( 'order' ) ); ?>" style="color:inherit;text-decoration:none;">주문번호<?php echo $arrow( 'order' ); ?></a>
									</th>
									<th>주문정보</th>
									<th style="width:160px;">
										<a href="<?php echo esc_url( $sort_url( 'customer' ) ); ?>" style="color:inherit;text-decoration:none;">주문자<?php echo $arrow( 'customer' ); ?></a>
									</th>
									<th>
										<a href="<?php echo esc_url( $sort_url( 'url' ) ); ?>" style="color:inherit;text-decoration:none;">이동 URL<?php echo $arrow( 'url' ); ?></a>
									</th>
									<th style="width:70px;text-align:center;white-space:nowrap;">
										<a href="<?php echo esc_url( $sort_url( 'src' ) ); ?>" style="color:inherit;text-decoration:none;">출처<?php echo $arrow( 'src' ); ?></a>
									</th>
									<th style="width:30px;"></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $current_log as $entry ) :
									$is_default = ( $entry['src'] ?? '' ) === 'default';
									$ts         = (int) ( $entry['ts'] ?? 0 );
									$order_id   = (int) ( $entry['order'] ?? 0 );
									$products   = (string) ( $entry['products'] ?? '' );
									$customer   = (string) ( $entry['customer'] ?? '' );
									$email      = (string) ( $entry['email'] ?? '' );
									$phone      = (string) ( $entry['phone'] ?? '' );
									$delete_url = wp_nonce_url(
										admin_url( 'admin-post.php?action=wcor_delete_log_entry&ts=' . $ts . '&order=' . $order_id ),
										'wcor_delete_log_entry'
									);
									?>
									<tr>
										<td style="font-size:12px;color:#646970;white-space:nowrap;">
											<?php echo esc_html( wp_date( 'Y-m-d H:i', $ts ) ); ?>
										</td>
										<td style="white-space:nowrap;">
											<?php if ( $order_id ) : ?>
												<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&id=' . $order_id ) ); ?>"
													style="font-weight:600;">#<?php echo $hl( (string) $order_id ); ?></a>
											<?php else : ?>
												<span style="color:#8c8f94;">—</span>
											<?php endif; ?>
										</td>
										<td style="font-size:12px;word-break:break-word;">
											<?php echo $products ? $hl( $products ) : '<span style="color:#8c8f94;">—</span>'; ?>
										</td>
										<td style="font-size:12px;">
											<?php if ( $customer || $email || $phone ) : ?>
												<?php
												if ( $customer ) :
													?>
													<span style="font-weight:500;"><?php echo $hl( $customer ); ?></span><?php endif; ?>
												<?php
												if ( $email ) :
													?>
													<br><span style="color:#646970;"><?php echo $hl( $email ); ?></span><?php endif; ?>
												<?php
												if ( $phone ) :
													?>
													<br><span style="color:#646970;"><?php echo $hl( $phone ); ?></span><?php endif; ?>
											<?php else : ?>
												<span style="color:#8c8f94;">—</span>
											<?php endif; ?>
										</td>
										<td style="font-size:12px;word-break:break-all;">
											<?php echo $hl( $entry['url'] ?? '' ); ?>
										</td>
										<td style="text-align:center;white-space:nowrap;">
											<span style="display:inline-block;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:600;
												background:<?php echo $is_default ? '#fff3cd' : '#d1e8ff'; ?>;
												color:<?php echo $is_default ? '#7a5c00' : '#0a4b78'; ?>;">
												<?php echo $is_default ? '기본값' : '상품'; ?>
											</span>
										</td>
										<td style="text-align:center;">
											<a href="<?php echo esc_url( $delete_url ); ?>"
												onclick="return confirm('이 항목을 삭제할까요?')"
												style="color:#d63638;text-decoration:none;font-size:16px;line-height:1;">×</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<!-- 페이지네이션: 왼쪽 [페이지X/Y] [N/page] [총계N] | 오른쪽 [«‹1›»] -->
						<div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;flex-wrap:wrap;gap:8px;">
							<div style="display:flex;align-items:center;gap:10px;font-size:13px;color:#50575e;">
								<span>페이지 <?php echo $paged; ?> / <?php echo $total_pages; ?></span>
								<select onchange="window.location.href='<?php echo $per_page_url_tpl; ?>'.replace('__WCOR_PP__',this.value)">
									<?php foreach ( array( 10, 20, 50 ) as $n ) : ?>
										<option value="<?php echo $n; ?>"<?php selected( $per_page, $n ); ?>><?php echo $n; ?> / page</option>
									<?php endforeach; ?>
								</select>
								<span>총계 <?php echo $total; ?></span>
							</div>
							<div style="display:flex;align-items:center;gap:2px;">
								<?php $dis = ' style="opacity:.35;pointer-events:none;"'; ?>
								<a href="<?php echo esc_url( $page_url( 1 ) ); ?>" class="button button-small wcor-pg"<?php echo $paged <= 1 ? $dis : ''; ?>>«</a>
								<a href="<?php echo esc_url( $page_url( $paged - 1 ) ); ?>" class="button button-small wcor-pg"<?php echo $paged <= 1 ? $dis : ''; ?>>‹</a>
								<?php
								for ( $p = max( 1, $paged - 2 ), $end = min( $total_pages, $paged + 2 ); $p <= $end; $p++ ) :
									if ( $p === $paged ) :
										?>
										<span class="button button-small wcor-pg" style="background:#2271b1;color:#fff;border-color:#2271b1;pointer-events:none;"><?php echo $p; ?></span>
									<?php else : ?>
										<a href="<?php echo esc_url( $page_url( $p ) ); ?>" class="button button-small wcor-pg"><?php echo $p; ?></a>
										<?php
									endif;
								endfor;
								?>
								<a href="<?php echo esc_url( $page_url( $paged + 1 ) ); ?>" class="button button-small wcor-pg"<?php echo $paged >= $total_pages ? $dis : ''; ?>>›</a>
								<a href="<?php echo esc_url( $page_url( $total_pages ) ); ?>" class="button button-small wcor-pg"<?php echo $paged >= $total_pages ? $dis : ''; ?>>»</a>
							</div>
						</div>

					<?php endif; ?>
				<?php endif; ?>
				</div><!-- /.wcor-log -->
			</td>
		</tr>
		<?php
	}

	// ── Action handlers ─────────────────────────────────────────────────

	public function handle_clear_log(): void {
		check_admin_referer( 'wcor_clear_log' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission denied.' );
		}
		delete_option( 'wcor_redirect_log' );
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=wcor&cleared=1' ) );
		exit();
	}

	public function handle_delete_log_entry(): void {
		check_admin_referer( 'wcor_delete_log_entry' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission denied.' );
		}

		$ts       = (int) ( $_GET['ts'] ?? 0 );
		$order_id = (int) ( $_GET['order'] ?? 0 );

		$log = (array) get_option( 'wcor_redirect_log', array() );
		$log = array_values(
			array_filter(
				$log,
				function ( array $entry ) use ( $ts, $order_id ): bool {
					return ! ( (int) ( $entry['ts'] ?? 0 ) === $ts && (int) ( $entry['order'] ?? 0 ) === $order_id );
				}
			)
		);
		update_option( 'wcor_redirect_log', $log, false );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=wcor' ) );
		exit();
	}
}
