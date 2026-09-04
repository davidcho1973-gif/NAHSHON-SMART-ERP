<?php

namespace App\Console\Commands;

use RuntimeException;

/**
 * --dry-run 을 되돌리기 위한 신호. 오류가 아니다.
 *
 * 적재·수정 명령은 «해 보고 되돌리기» 를 트랜잭션 안에서 예외를 던져 구현한다. 그 신호를
 * 명령 파일마다 따로 선언해 두면, 두 명령이 한 요청에 함께 로드되는 순간(artisan list 처럼
 * 전 명령을 훑는 자리) 같은 이름을 두 번 선언해 치명적 오류가 난다 — 화면에는 «명령을 찾을 수
 * 없음» 처럼 엉뚱하게 보인다. 그래서 한 자리에 둔다.
 */
class DryRunRollback extends RuntimeException {}
