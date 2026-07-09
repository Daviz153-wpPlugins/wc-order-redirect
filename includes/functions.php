<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 리다이렉트 대상 URL의 도메인이 허용 목록에 속하는지 검사합니다.
 * 서브도메인(hook.eu1.make.com 등)도 매칭합니다.
 * 추가 도메인은 wcor_allowed_redirect_domains 필터로 확장하세요.
 */
function wcor_is_url_domain_allowed( string $url ): bool {
	$allowed = apply_filters(
		'wcor_allowed_redirect_domains',
		array(
			'money153.com',
			'crmbiz.kr',
			'make.com',
			'tally.so',
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
		)
	);

	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host ) {
		return false;
	}

	foreach ( $allowed as $domain ) {
		$domain = strtolower( (string) $domain );
		if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
			return true;
		}
	}

	return false;
}
