<?php
/**
 * Compact, dependency-free QR encoder for MONA Pay EMVCo payloads.
 *
 * This encoder is implemented inside the plugin, supports QR versions 1-40 in
 * byte mode with error correction level M, and is distributed under the same
 * GPL-2.0-or-later license as the plugin. It never sends payment data to a
 * third-party QR rendering service.
 *
 * @package MonaPay_WooCommerce
 */

defined( 'ABSPATH' ) || defined( 'MONAPAY_TESTING' ) || exit;

class MonaPay_QR_Code {
	/** Error-correction block layout for QR level M: count, total, data. */
	private const RS_BLOCKS = array(
		1  => array( array( 1, 26, 16 ) ),
		2  => array( array( 1, 44, 28 ) ),
		3  => array( array( 1, 70, 44 ) ),
		4  => array( array( 2, 50, 32 ) ),
		5  => array( array( 2, 67, 43 ) ),
		6  => array( array( 4, 43, 27 ) ),
		7  => array( array( 4, 49, 31 ) ),
		8  => array( array( 2, 60, 38 ), array( 2, 61, 39 ) ),
		9  => array( array( 3, 58, 36 ), array( 2, 59, 37 ) ),
		10 => array( array( 4, 69, 43 ), array( 1, 70, 44 ) ),
		11 => array( array( 1, 80, 50 ), array( 4, 81, 51 ) ),
		12 => array( array( 6, 58, 36 ), array( 2, 59, 37 ) ),
		13 => array( array( 8, 59, 37 ), array( 1, 60, 38 ) ),
		14 => array( array( 4, 64, 40 ), array( 5, 65, 41 ) ),
		15 => array( array( 5, 65, 41 ), array( 5, 66, 42 ) ),
		16 => array( array( 7, 73, 45 ), array( 3, 74, 46 ) ),
		17 => array( array( 10, 74, 46 ), array( 1, 75, 47 ) ),
		18 => array( array( 9, 69, 43 ), array( 4, 70, 44 ) ),
		19 => array( array( 3, 70, 44 ), array( 11, 71, 45 ) ),
		20 => array( array( 3, 67, 41 ), array( 13, 68, 42 ) ),
		21 => array( array( 17, 68, 42 ) ),
		22 => array( array( 17, 74, 46 ) ),
		23 => array( array( 4, 75, 47 ), array( 14, 76, 48 ) ),
		24 => array( array( 6, 73, 45 ), array( 14, 74, 46 ) ),
		25 => array( array( 8, 75, 47 ), array( 13, 76, 48 ) ),
		26 => array( array( 19, 74, 46 ), array( 4, 75, 47 ) ),
		27 => array( array( 22, 73, 45 ), array( 3, 74, 46 ) ),
		28 => array( array( 3, 73, 45 ), array( 23, 74, 46 ) ),
		29 => array( array( 21, 73, 45 ), array( 7, 74, 46 ) ),
		30 => array( array( 19, 75, 47 ), array( 10, 76, 48 ) ),
		31 => array( array( 2, 74, 46 ), array( 29, 75, 47 ) ),
		32 => array( array( 10, 74, 46 ), array( 23, 75, 47 ) ),
		33 => array( array( 14, 74, 46 ), array( 21, 75, 47 ) ),
		34 => array( array( 14, 74, 46 ), array( 23, 75, 47 ) ),
		35 => array( array( 12, 75, 47 ), array( 26, 76, 48 ) ),
		36 => array( array( 6, 75, 47 ), array( 34, 76, 48 ) ),
		37 => array( array( 29, 74, 46 ), array( 14, 75, 47 ) ),
		38 => array( array( 13, 74, 46 ), array( 32, 75, 47 ) ),
		39 => array( array( 40, 75, 47 ), array( 7, 76, 48 ) ),
		40 => array( array( 18, 75, 47 ), array( 31, 76, 48 ) ),
	);

	/**
	 * Render a QR payload to PNG bytes.
	 *
	 * @param string $text        EMVCo QR payload.
	 * @param int    $scale       Pixels per QR module.
	 * @param int    $quiet_zone  White modules around the QR symbol.
	 * @return string
	 * @throws Exception When the payload exceeds QR capacity or zlib is absent.
	 */
	public static function png( $text, $scale = 4, $quiet_zone = 4 ) {
		if ( ! function_exists( 'gzcompress' ) ) {
			throw new Exception( 'Máy chủ cần extension zlib để dựng ảnh QR.' );
		}

		$matrix     = self::matrix( $text );
		$scale      = max( 2, min( 10, (int) $scale ) );
		$quiet_zone = max( 4, (int) $quiet_zone );
		$modules    = count( $matrix );
		$width      = ( $modules + ( 2 * $quiet_zone ) ) * $scale;
		$white_row  = str_repeat( "\xff", $width );
		$raw        = '';

		for ( $row = 0; $row < $quiet_zone * $scale; $row++ ) {
			$raw .= "\x00" . $white_row;
		}

		foreach ( $matrix as $matrix_row ) {
			$pixel_row = str_repeat( "\xff", $quiet_zone * $scale );
			foreach ( $matrix_row as $dark ) {
				$pixel_row .= str_repeat( $dark ? "\x00" : "\xff", $scale );
			}
			$pixel_row .= str_repeat( "\xff", $quiet_zone * $scale );

			for ( $repeat = 0; $repeat < $scale; $repeat++ ) {
				$raw .= "\x00" . $pixel_row;
			}
		}

		for ( $row = 0; $row < $quiet_zone * $scale; $row++ ) {
			$raw .= "\x00" . $white_row;
		}

		$header = pack( 'NNCCCCC', $width, $width, 8, 0, 0, 0, 0 );
		return "\x89PNG\r\n\x1a\n"
			. self::png_chunk( 'IHDR', $header )
			. self::png_chunk( 'IDAT', gzcompress( $raw, 9 ) )
			. self::png_chunk( 'IEND', '' );
	}

	/**
	 * Build the boolean module matrix for a byte-mode QR code.
	 *
	 * @param string $text Payload.
	 * @return array
	 * @throws Exception When the payload exceeds QR version 40 capacity.
	 */
	public static function matrix( $text ) {
		$bytes   = array_values( unpack( 'C*', (string) $text ) ?: array() );
		$version = self::choose_version( count( $bytes ) );
		$data    = self::create_codewords( $bytes, $version );
		$size    = 17 + ( 4 * $version );
		$matrix  = array_fill( 0, $size, array_fill( 0, $size, null ) );

		self::draw_finder( $matrix, 0, 0 );
		self::draw_finder( $matrix, $size - 7, 0 );
		self::draw_finder( $matrix, 0, $size - 7 );
		self::draw_alignment_patterns( $matrix, $version );
		self::draw_timing_patterns( $matrix );
		self::draw_version_information( $matrix, $version );
		self::draw_format_information( $matrix, 0 );
		self::map_data( $matrix, $data, 0 );

		return $matrix;
	}

	/**
	 * Choose the smallest version whose level-M data area fits the byte payload.
	 *
	 * @param int $length Byte length.
	 * @return int
	 * @throws Exception When no QR version can hold the payload.
	 */
	private static function choose_version( $length ) {
		for ( $version = 1; $version <= 40; $version++ ) {
			$capacity_bits = self::data_capacity( $version ) * 8;
			$length_bits   = $version < 10 ? 8 : 16;
			if ( 4 + $length_bits + ( $length * 8 ) <= $capacity_bits ) {
				return $version;
			}
		}

		throw new Exception( 'Chuỗi QR vượt quá dung lượng QR Code phiên bản 40.' );
	}

	/**
	 * Build padded data bytes, Reed-Solomon ECC, and interleave all blocks.
	 *
	 * @param array $bytes   Payload bytes.
	 * @param int   $version QR version.
	 * @return array
	 */
	private static function create_codewords( $bytes, $version ) {
		$capacity = self::data_capacity( $version );
		$bits     = array( 0, 1, 0, 0 ); // Byte mode.
		self::append_bits( $bits, count( $bytes ), $version < 10 ? 8 : 16 );

		foreach ( $bytes as $byte ) {
			self::append_bits( $bits, $byte, 8 );
		}

		$remaining = ( $capacity * 8 ) - count( $bits );
		for ( $i = 0; $i < min( 4, $remaining ); $i++ ) {
			$bits[] = 0;
		}
		while ( 0 !== count( $bits ) % 8 ) {
			$bits[] = 0;
		}

		$data_bytes = array();
		for ( $offset = 0; $offset < count( $bits ); $offset += 8 ) {
			$value = 0;
			for ( $bit = 0; $bit < 8; $bit++ ) {
				$value = ( $value << 1 ) | $bits[ $offset + $bit ];
			}
			$data_bytes[] = $value;
		}

		$pad = 0;
		while ( count( $data_bytes ) < $capacity ) {
			$data_bytes[] = 0 === $pad % 2 ? 0xec : 0x11;
			$pad++;
		}

		$blocks      = array();
		$data_offset = 0;
		foreach ( self::expanded_blocks( $version ) as $block ) {
			$data_count  = $block[1];
			$total_count = $block[0];
			$block_data  = array_slice( $data_bytes, $data_offset, $data_count );
			$data_offset += $data_count;
			$blocks[] = array(
				'data' => $block_data,
				'ecc'  => self::reed_solomon_remainder( $block_data, $total_count - $data_count ),
			);
		}

		$result   = array();
		$max_data = 0;
		$max_ecc  = 0;
		foreach ( $blocks as $block ) {
			$max_data = max( $max_data, count( $block['data'] ) );
			$max_ecc  = max( $max_ecc, count( $block['ecc'] ) );
		}

		for ( $i = 0; $i < $max_data; $i++ ) {
			foreach ( $blocks as $block ) {
				if ( isset( $block['data'][ $i ] ) ) {
					$result[] = $block['data'][ $i ];
				}
			}
		}
		for ( $i = 0; $i < $max_ecc; $i++ ) {
			foreach ( $blocks as $block ) {
				if ( isset( $block['ecc'][ $i ] ) ) {
					$result[] = $block['ecc'][ $i ];
				}
			}
		}

		return $result;
	}

	/**
	 * Return expanded (total,data) block definitions for a version.
	 *
	 * @param int $version QR version.
	 * @return array
	 */
	private static function expanded_blocks( $version ) {
		$result = array();
		foreach ( self::RS_BLOCKS[ $version ] as $group ) {
			for ( $i = 0; $i < $group[0]; $i++ ) {
				$result[] = array( $group[1], $group[2] );
			}
		}
		return $result;
	}

	/**
	 * Total number of data codewords for a level-M version.
	 *
	 * @param int $version QR version.
	 * @return int
	 */
	private static function data_capacity( $version ) {
		$total = 0;
		foreach ( self::RS_BLOCKS[ $version ] as $group ) {
			$total += $group[0] * $group[2];
		}
		return $total;
	}

	/**
	 * Append an unsigned integer to a bit array, most-significant bit first.
	 *
	 * @param array $bits   Bit array (by reference).
	 * @param int   $value  Value.
	 * @param int   $length Bit count.
	 */
	private static function append_bits( &$bits, $value, $length ) {
		for ( $i = $length - 1; $i >= 0; $i-- ) {
			$bits[] = ( $value >> $i ) & 1;
		}
	}

	/**
	 * Compute a Reed-Solomon error-correction remainder in GF(2^8).
	 *
	 * @param array $data   Data codewords.
	 * @param int   $degree ECC codeword count.
	 * @return array
	 */
	private static function reed_solomon_remainder( $data, $degree ) {
		$divisor              = array_fill( 0, $degree, 0 );
		$divisor[ $degree - 1 ] = 1;
		$root                 = 1;

		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$divisor[ $j ] = self::gf_multiply( $divisor[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$divisor[ $j ] ^= $divisor[ $j + 1 ];
				}
			}
			$root = self::gf_multiply( $root, 0x02 );
		}

		$result = array_fill( 0, $degree, 0 );
		foreach ( $data as $byte ) {
			$factor = $byte ^ $result[0];
			array_shift( $result );
			$result[] = 0;
			for ( $i = 0; $i < $degree; $i++ ) {
				$result[ $i ] ^= self::gf_multiply( $divisor[ $i ], $factor );
			}
		}
		return $result;
	}

	/**
	 * Multiply two bytes in the QR Code Galois field.
	 *
	 * @param int $x First byte.
	 * @param int $y Second byte.
	 * @return int
	 */
	private static function gf_multiply( $x, $y ) {
		$result = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$result = ( $result << 1 ) ^ ( ( $result >> 7 ) * 0x11d );
			$result ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $result;
	}

	/** Draw a finder pattern and its white separator. */
	private static function draw_finder( &$matrix, $left, $top ) {
		$size = count( $matrix );
		for ( $dy = -1; $dy <= 7; $dy++ ) {
			for ( $dx = -1; $dx <= 7; $dx++ ) {
				$row = $top + $dy;
				$col = $left + $dx;
				if ( $row < 0 || $row >= $size || $col < 0 || $col >= $size ) {
					continue;
				}

				$inside = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
				$dark   = $inside && ( 0 === $dx || 6 === $dx || 0 === $dy || 6 === $dy || ( $dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4 ) );
				$matrix[ $row ][ $col ] = $dark;
			}
		}
	}

	/** Draw all non-overlapping alignment patterns. */
	private static function draw_alignment_patterns( &$matrix, $version ) {
		$positions = self::alignment_positions( $version );
		foreach ( $positions as $row ) {
			foreach ( $positions as $col ) {
				if ( null !== $matrix[ $row ][ $col ] ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$matrix[ $row + $dy ][ $col + $dx ] = 2 === max( abs( $dx ), abs( $dy ) ) || ( 0 === $dx && 0 === $dy );
					}
				}
			}
		}
	}

	/** Return alignment pattern center coordinates for a version. */
	private static function alignment_positions( $version ) {
		if ( 1 === $version ) {
			return array();
		}
		$count = intdiv( $version, 7 ) + 2;
		$step  = 32 === $version ? 26 : intdiv( ( 4 * $version ) + ( 2 * $count ) + 1, ( 2 * $count ) - 2 ) * 2;
		$size  = 17 + ( 4 * $version );
		$result = array( 6 );
		for ( $i = 1; $i < $count; $i++ ) {
			$result[] = $size - 7 - ( ( $count - 1 - $i ) * $step );
		}
		return $result;
	}

	/** Draw horizontal and vertical timing patterns. */
	private static function draw_timing_patterns( &$matrix ) {
		$size = count( $matrix );
		for ( $i = 8; $i < $size - 8; $i++ ) {
			if ( null === $matrix[6][ $i ] ) {
				$matrix[6][ $i ] = 0 === $i % 2;
			}
			if ( null === $matrix[ $i ][6] ) {
				$matrix[ $i ][6] = 0 === $i % 2;
			}
		}
	}

	/** Draw the 15 format bits for error correction M and the selected mask. */
	private static function draw_format_information( &$matrix, $mask ) {
		$size = count( $matrix );
		$bits = self::bch_format( $mask ); // M has format level value 0.
		for ( $i = 0; $i < 15; $i++ ) {
			$dark = 1 === ( ( $bits >> $i ) & 1 );
			$row  = $i < 6 ? $i : ( $i < 8 ? $i + 1 : $size - 15 + $i );
			$matrix[ $row ][8] = $dark;

			if ( $i < 8 ) {
				$col = $size - $i - 1;
			} elseif ( $i < 9 ) {
				$col = 7;
			} else {
				$col = 15 - $i - 1;
			}
			$matrix[8][ $col ] = $dark;
		}
		$matrix[ $size - 8 ][8] = true;
	}

	/** Draw version information for versions 7 and above. */
	private static function draw_version_information( &$matrix, $version ) {
		if ( $version < 7 ) {
			return;
		}
		$size = count( $matrix );
		$bits = self::bch_version( $version );
		for ( $i = 0; $i < 18; $i++ ) {
			$dark = 1 === ( ( $bits >> $i ) & 1 );
			$matrix[ intdiv( $i, 3 ) ][ ( $i % 3 ) + $size - 11 ] = $dark;
			$matrix[ ( $i % 3 ) + $size - 11 ][ intdiv( $i, 3 ) ] = $dark;
		}
	}

	/** Place masked data bits in the QR zig-zag traversal. */
	private static function map_data( &$matrix, $bytes, $mask ) {
		$size       = count( $matrix );
		$row        = $size - 1;
		$direction  = -1;
		$byte_index = 0;
		$bit_index  = 7;

		for ( $col = $size - 1; $col > 0; $col -= 2 ) {
			if ( 6 === $col ) {
				$col--;
			}

			while ( true ) {
				for ( $offset = 0; $offset < 2; $offset++ ) {
					$current_col = $col - $offset;
					if ( null !== $matrix[ $row ][ $current_col ] ) {
						continue;
					}

					$dark = false;
					if ( $byte_index < count( $bytes ) ) {
						$dark = 1 === ( ( $bytes[ $byte_index ] >> $bit_index ) & 1 );
					}
					if ( self::mask_bit( $mask, $row, $current_col ) ) {
						$dark = ! $dark;
					}
					$matrix[ $row ][ $current_col ] = $dark;

					$bit_index--;
					if ( -1 === $bit_index ) {
						$byte_index++;
						$bit_index = 7;
					}
				}

				$row += $direction;
				if ( $row < 0 || $row >= $size ) {
					$row       -= $direction;
					$direction = -$direction;
					break;
				}
			}
		}
	}

	/** Return whether a module is inverted by the selected QR mask. */
	private static function mask_bit( $mask, $row, $col ) {
		switch ( $mask ) {
			case 0:
				return 0 === ( $row + $col ) % 2;
			case 1:
				return 0 === $row % 2;
			case 2:
				return 0 === $col % 3;
			case 3:
				return 0 === ( $row + $col ) % 3;
			case 4:
				return 0 === ( intdiv( $row, 2 ) + intdiv( $col, 3 ) ) % 2;
			case 5:
				return 0 === ( ( $row * $col ) % 2 ) + ( ( $row * $col ) % 3 );
			case 6:
				return 0 === ( ( ( $row * $col ) % 2 ) + ( ( $row * $col ) % 3 ) ) % 2;
			default:
				return 0 === ( ( ( $row + $col ) % 2 ) + ( ( $row * $col ) % 3 ) ) % 2;
		}
	}

	/** Calculate BCH-protected format information. */
	private static function bch_format( $data ) {
		$value = $data << 10;
		while ( self::bit_length( $value ) - self::bit_length( 0x537 ) >= 0 ) {
			$value ^= 0x537 << ( self::bit_length( $value ) - self::bit_length( 0x537 ) );
		}
		return ( ( $data << 10 ) | $value ) ^ 0x5412;
	}

	/** Calculate BCH-protected version information. */
	private static function bch_version( $version ) {
		$value = $version << 12;
		while ( self::bit_length( $value ) - self::bit_length( 0x1f25 ) >= 0 ) {
			$value ^= 0x1f25 << ( self::bit_length( $value ) - self::bit_length( 0x1f25 ) );
		}
		return ( $version << 12 ) | $value;
	}

	/** Return the number of significant bits in a positive integer. */
	private static function bit_length( $value ) {
		$length = 0;
		while ( 0 !== $value ) {
			$length++;
			$value >>= 1;
		}
		return $length;
	}

	/** Create a binary PNG chunk. */
	private static function png_chunk( $type, $data ) {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}
}
