<?php
/**
 * Standalone QR smoke test: php tests/test-qr.php [output.png]
 */

define( 'MONAPAY_TESTING', true );
require_once dirname( __DIR__ ) . '/includes/class-monapay-qr-code.php';

$payload = '00020101021238540010A000000727012400069704160110MONA000123520400005303704540725000005802VN5914CONG TY MONA6008HOCHIMINH62130509DH102346304ABCD';
$matrix  = MonaPay_QR_Code::matrix( $payload );

if ( count( $matrix ) < 21 || count( $matrix ) !== count( $matrix[0] ) ) {
	fwrite( STDERR, "FAIL: QR matrix không vuông hoặc quá nhỏ\n" );
	exit( 1 );
}

foreach ( $matrix as $row ) {
	foreach ( $row as $module ) {
		if ( ! is_bool( $module ) ) {
			fwrite( STDERR, "FAIL: QR matrix còn module chưa được gán\n" );
			exit( 1 );
		}
	}
}

$png = MonaPay_QR_Code::png( $payload );
if ( 0 !== strpos( $png, "\x89PNG\r\n\x1a\n" ) ) {
	fwrite( STDERR, "FAIL: output không có PNG signature\n" );
	exit( 1 );
}

if ( isset( $argv[1] ) && '' !== $argv[1] ) {
	file_put_contents( $argv[1], $png );
}

echo 'PASS: QR version ' . ( ( count( $matrix ) - 17 ) / 4 ) . ', ' . strlen( $png ) . " PNG bytes\n";

