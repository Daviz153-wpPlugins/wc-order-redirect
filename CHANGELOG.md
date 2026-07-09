# Changelog

## [1.1.4] - 2026-07-09

### 보안
- **오픈 리다이렉트 차단**: 리다이렉트 대상 URL을 도메인 화이트리스트로 검증 (`wcor_is_url_domain_allowed()`).
  허용 도메인: 자체 사이트 도메인, money153.com, crmbiz.kr, make.com, tally.so (서브도메인 포함).
  추가 도메인은 `wcor_allowed_redirect_domains` 필터로 확장 가능.
- **결제 실패 상태에서 리다이렉트 차단**: `failed`, `cancelled`, `refunded`, `checkout-draft` 주문 상태 denylist 추가.
  허용 상태 목록은 `wcor_skip_redirect_statuses` 필터로 조정 가능.
- **설정 저장 도메인 검증**: 관리자 설정 탭에서 기본 URL 저장 시 도메인 화이트리스트 적용 — 허용되지 않은 도메인 입력 시 오류 메시지 표시 후 저장 중단.
- **URL 미리보기 버튼 XSS 차단**: 설정 탭 URL 미리보기 버튼(`window.open`)에 http/https 스킴 검사 및 `noopener,noreferrer` 추가.

### 테스트
- PHPUnit: 도메인 비허용 URL 차단 테스트 2건 추가, denylist 상태 dataProvider 테스트 추가
- PHPUnit: bootstrap.php에 `apply_filters`, `wp_parse_url`, `home_url`, `WC_Order::has_status()` 스텁 추가
- E2E: `settings-ui.spec.js` 기본 URL을 tally.so 도메인으로 변경 (도메인 검증 통과)
- E2E: `default-redirect.spec.js` URL 상수를 `WP_BASE_URL` 환경변수 기반으로 동적화 (CI 환경 호환)

---

## [1.1.3] - 2026-06-14

### 테스트
- E2E: WC 설정 탭 UI 저장 플로우 검증 추가 (settings-ui.spec.js)
  - 기본 URL 입력 → 저장 → 필드·DB 반영 확인
  - UI 저장 후 실제 리다이렉트 발동 확인
  - 유효하지 않은 URL(ftp://) → 오류 메시지, DB 미저장 확인
- E2E: 메타박스 UI 저장 플로우 검증 추가 (meta-box-save.spec.js)
  - 토글 ON + URL 입력 → 저장 → DB enabled/url 반영 확인
  - UI 저장 URL → 결제 완료 후 실제 리다이렉트 발동 확인
  - 토글 OFF 저장 → enabled=no 확인
- PHPUnit: 디폴트 URL 유효성·동일 가격 처리 케이스 추가 (35 → 38)
  - 디폴트 URL이 ftp:// 등 비http 스킴이면 '' 반환
  - 상품·디폴트 URL 모두 유효하지 않으면 '' 반환
  - 동일 가격 상품은 어느 쪽이든 유효한 URL 반환

---

## [1.1.2] - 2026-06-14

### 테스트
- E2E 테스트를 test.crmbiz.kr 원격 서버에 연결 (SSH ControlMaster 방식)
- global-setup에서 COD 결제 수단 자동 활성화 — 수동 서버 설정 불필요
- SSH ControlPersist 300s로 연장, retries: 1 상시 적용으로 간헐적 네트워크 오류 흡수

---

## [1.1.1] - 2026-06-09

### 버그픽스
- 플러그인 삭제(uninstall) 시 HPOS 주문 메타(`_wcor_redirected`) 누락 삭제 수정

---

## [1.1.0] - 2026-06-08

### 추가
- WooCommerce 설정 탭(wcor) — 전역 토글, 디폴트 URL, 리다이렉트 로그 뷰어
- 디폴트 URL 폴백 (`wcor_default_url`) — 상품 URL 없을 때 글로벌 URL로 이동
- 중복 로그 방지 — `_wcor_redirected` 주문 메타로 재방문 시 로그 스킵
- GDPR 지원 — 개인정보 내보내기/삭제 등록 (`wp_privacy_personal_data_exporters/erasers`)
- URL 검증 피드백 — 잘못된 URL 저장 시 WooCommerce 관리자 오류 메시지 표시
- Variable Product 안내 문구 추가

---

## [1.0.2] - 2026-06-06

### 개선
- 리다이렉트 설정을 WooCommerce 상품 데이터 탭으로 이동 (기존 별도 메타박스 → 탭 통합)
- 토글 UI 개선 — 비활성 시 URL 입력 필드 자동 숨김, 활성 시 자동 표시

### 테스트
- PHPUnit 오류 시나리오 테스트 추가 (잘못된 key, 존재하지 않는 주문, javascript:/data: URL 차단 등)
- E2E 오류 시나리오 테스트 추가 (브라우저 레벨 리다이렉트 미발동 경로 검증)
- E2E 플러그인 공존 테스트 추가 (wc-order-webhook과 상호 간섭 없음 확인)

---

## [1.0.1] - 2026-06-05

### 추가
- 상품별 리다이렉트 활성/비활성 토글

### 보안
- order key 유효성 검증 추가 — 잘못된 key로 접근 시 리다이렉트 차단

### 개선
- 인라인 CSS를 `wp_enqueue_style`로 분리
- `usort` → `uasort` 변경으로 배열 키 보존

---

## [1.0.0] - 2026-06-04

### 최초 릴리즈
- 결제 완료 후 상품별 URL로 리다이렉트
- 다중 상품 주문 시 최고가 상품 URL 우선 적용
- WooCommerce HPOS 호환
- GitHub 릴리즈 기반 자동 업데이트
- GitHub Actions CI (PHPUnit + Playwright E2E)
