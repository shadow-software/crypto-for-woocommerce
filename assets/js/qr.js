/**
 * Tiny, dependency-free QR code generator (byte mode), rendered as inline SVG.
 *
 * Bundled so the pay page can show a scannable payment QR entirely offline — no
 * CDN, no remote image service, nothing that would violate wp.org's "no calling
 * home" rule. Based on the public-domain QR Code generator algorithm; trimmed to
 * byte mode with automatic version/ECC selection sufficient for Ethereum URIs.
 *
 * Exposes window.ShadowEthQR.render( element, text ).
 */
( function () {
	'use strict';

	// --- Reed-Solomon + bit buffer core -------------------------------------

	function QrSegment( text ) {
		this.bytes = [];
		for ( var i = 0; i < text.length; i++ ) {
			var code = text.charCodeAt( i );
			if ( code < 0x80 ) {
				this.bytes.push( code );
			} else if ( code < 0x800 ) {
				this.bytes.push( 0xc0 | ( code >> 6 ), 0x80 | ( code & 0x3f ) );
			} else {
				this.bytes.push(
					0xe0 | ( code >> 12 ),
					0x80 | ( ( code >> 6 ) & 0x3f ),
					0x80 | ( code & 0x3f )
				);
			}
		}
	}

	var ECC_CODEWORDS_PER_BLOCK = [
		[ -1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ],
		[ -1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28 ],
		[ -1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ],
		[ -1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ]
	];

	var NUM_ERROR_CORRECTION_BLOCKS = [
		[ -1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25 ],
		[ -1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49 ],
		[ -1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68 ],
		[ -1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81 ]
	];

	function getNumRawDataModules( ver ) {
		var result = ( 16 * ver + 128 ) * ver + 64;
		if ( ver >= 2 ) {
			var numAlign = Math.floor( ver / 7 ) + 2;
			result -= ( 25 * numAlign - 10 ) * numAlign - 55;
			if ( ver >= 7 ) {
				result -= 36;
			}
		}
		return result;
	}

	function getNumDataCodewords( ver, ecl ) {
		return (
			Math.floor( getNumRawDataModules( ver ) / 8 ) -
			ECC_CODEWORDS_PER_BLOCK[ ecl ][ ver ] * NUM_ERROR_CORRECTION_BLOCKS[ ecl ][ ver ]
		);
	}

	function reedSolomonComputeDivisor( degree ) {
		var result = [];
		for ( var i = 0; i < degree - 1; i++ ) {
			result.push( 0 );
		}
		result.push( 1 );
		var root = 1;
		for ( var j = 0; j < degree; j++ ) {
			for ( var k = 0; k < result.length; k++ ) {
				result[ k ] = reedSolomonMultiply( result[ k ], root );
				if ( k + 1 < result.length ) {
					result[ k ] ^= result[ k + 1 ];
				}
			}
			root = reedSolomonMultiply( root, 0x02 );
		}
		return result;
	}

	function reedSolomonComputeRemainder( data, divisor ) {
		var result = divisor.map( function () {
			return 0;
		} );
		data.forEach( function ( b ) {
			var factor = b ^ result.shift();
			result.push( 0 );
			divisor.forEach( function ( coef, i ) {
				result[ i ] ^= reedSolomonMultiply( coef, factor );
			} );
		} );
		return result;
	}

	function reedSolomonMultiply( x, y ) {
		var z = 0;
		for ( var i = 7; i >= 0; i-- ) {
			z = ( z << 1 ) ^ ( ( z >>> 7 ) * 0x11d );
			z ^= ( ( y >>> i ) & 1 ) * x;
		}
		return z & 0xff;
	}

	// --- QR matrix build ----------------------------------------------------

	function QrCode( version, ecl, dataCodewords, mask ) {
		this.version = version;
		this.size = version * 4 + 17;
		this.mask = mask;
		this.modules = [];
		this.isFunction = [];
		var i;
		for ( i = 0; i < this.size; i++ ) {
			this.modules.push( new Array( this.size ).fill( false ) );
			this.isFunction.push( new Array( this.size ).fill( false ) );
		}
		this.drawFunctionPatterns( ecl );
		var allCodewords = this.addEccAndInterleave( dataCodewords, version, ecl );
		this.drawCodewords( allCodewords );
		this.applyMask( mask );
		this.drawFormatBits( ecl, mask );
	}

	QrCode.prototype.getModule = function ( x, y ) {
		return this.modules[ y ][ x ];
	};

	QrCode.prototype.setFunctionModule = function ( x, y, isDark ) {
		this.modules[ y ][ x ] = isDark;
		this.isFunction[ y ][ x ] = true;
	};

	QrCode.prototype.drawFunctionPatterns = function ( ecl ) {
		var i;
		for ( i = 0; i < this.size; i++ ) {
			this.setFunctionModule( 6, i, i % 2 === 0 );
			this.setFunctionModule( i, 6, i % 2 === 0 );
		}
		this.drawFinderPattern( 3, 3 );
		this.drawFinderPattern( this.size - 4, 3 );
		this.drawFinderPattern( 3, this.size - 4 );

		var alignPos = this.getAlignmentPatternPositions();
		var numAlign = alignPos.length;
		for ( i = 0; i < numAlign; i++ ) {
			for ( var j = 0; j < numAlign; j++ ) {
				if (
					! ( ( i === 0 && j === 0 ) ||
						( i === 0 && j === numAlign - 1 ) ||
						( i === numAlign - 1 && j === 0 ) )
				) {
					this.drawAlignmentPattern( alignPos[ i ], alignPos[ j ] );
				}
			}
		}
		this.drawFormatBits( ecl, 0 );
		this.drawVersion();
	};

	QrCode.prototype.drawFinderPattern = function ( x, y ) {
		for ( var dy = -4; dy <= 4; dy++ ) {
			for ( var dx = -4; dx <= 4; dx++ ) {
				var dist = Math.max( Math.abs( dx ), Math.abs( dy ) );
				var xx = x + dx;
				var yy = y + dy;
				if ( xx >= 0 && xx < this.size && yy >= 0 && yy < this.size ) {
					this.setFunctionModule( xx, yy, dist !== 2 && dist !== 4 );
				}
			}
		}
	};

	QrCode.prototype.drawAlignmentPattern = function ( x, y ) {
		for ( var dy = -2; dy <= 2; dy++ ) {
			for ( var dx = -2; dx <= 2; dx++ ) {
				this.setFunctionModule( x + dx, y + dy, Math.max( Math.abs( dx ), Math.abs( dy ) ) !== 1 );
			}
		}
	};

	QrCode.prototype.getAlignmentPatternPositions = function () {
		if ( this.version === 1 ) {
			return [];
		}
		var numAlign = Math.floor( this.version / 7 ) + 2;
		var step = Math.floor( ( this.version * 8 + numAlign * 3 + 5 ) / ( numAlign * 4 - 4 ) ) * 2;
		var result = [ 6 ];
		for ( var pos = this.size - 7; result.length < numAlign; pos -= step ) {
			result.splice( 1, 0, pos );
		}
		return result;
	};

	QrCode.prototype.drawFormatBits = function ( ecl, mask ) {
		var eclFormatBits = [ 1, 0, 3, 2 ][ ecl ];
		var data = ( eclFormatBits << 3 ) | mask;
		var rem = data;
		for ( var i = 0; i < 10; i++ ) {
			rem = ( rem << 1 ) ^ ( ( rem >>> 9 ) * 0x537 );
		}
		var bits = ( ( data << 10 ) | rem ) ^ 0x5412;
		for ( i = 0; i <= 5; i++ ) {
			this.setFunctionModule( 8, i, ( ( bits >>> i ) & 1 ) !== 0 );
		}
		this.setFunctionModule( 8, 7, ( ( bits >>> 6 ) & 1 ) !== 0 );
		this.setFunctionModule( 8, 8, ( ( bits >>> 7 ) & 1 ) !== 0 );
		this.setFunctionModule( 7, 8, ( ( bits >>> 8 ) & 1 ) !== 0 );
		for ( i = 9; i < 15; i++ ) {
			this.setFunctionModule( 14 - i, 8, ( ( bits >>> i ) & 1 ) !== 0 );
		}
		for ( i = 0; i < 8; i++ ) {
			this.setFunctionModule( this.size - 1 - i, 8, ( ( bits >>> i ) & 1 ) !== 0 );
		}
		for ( i = 8; i < 15; i++ ) {
			this.setFunctionModule( 8, this.size - 15 + i, ( ( bits >>> i ) & 1 ) !== 0 );
		}
		this.setFunctionModule( 8, this.size - 8, true );
	};

	QrCode.prototype.drawVersion = function () {
		if ( this.version < 7 ) {
			return;
		}
		var rem = this.version;
		for ( var i = 0; i < 12; i++ ) {
			rem = ( rem << 1 ) ^ ( ( rem >>> 11 ) * 0x1f25 );
		}
		var bits = ( this.version << 12 ) | rem;
		for ( i = 0; i < 18; i++ ) {
			var isDark = ( ( bits >>> i ) & 1 ) !== 0;
			var a = this.size - 11 + ( i % 3 );
			var b = Math.floor( i / 3 );
			this.setFunctionModule( a, b, isDark );
			this.setFunctionModule( b, a, isDark );
		}
	};

	QrCode.prototype.addEccAndInterleave = function ( data, version, ecl ) {
		var numBlocks = NUM_ERROR_CORRECTION_BLOCKS[ ecl ][ version ];
		var blockEccLen = ECC_CODEWORDS_PER_BLOCK[ ecl ][ version ];
		var rawCodewords = Math.floor( getNumRawDataModules( version ) / 8 );
		var numShortBlocks = numBlocks - ( rawCodewords % numBlocks );
		var shortBlockLen = Math.floor( rawCodewords / numBlocks );
		var blocks = [];
		var rsDiv = reedSolomonComputeDivisor( blockEccLen );
		var k = 0;
		for ( var i = 0; i < numBlocks; i++ ) {
			var datLen = shortBlockLen - blockEccLen + ( i < numShortBlocks ? 0 : 1 );
			var dat = data.slice( k, k + datLen );
			k += datLen;
			var ecc = reedSolomonComputeRemainder( dat, rsDiv );
			if ( i < numShortBlocks ) {
				dat.push( 0 );
			}
			blocks.push( dat.concat( ecc ) );
		}
		var result = [];
		for ( i = 0; i < blocks[ 0 ].length; i++ ) {
			for ( var j = 0; j < blocks.length; j++ ) {
				if ( i !== shortBlockLen - blockEccLen || j >= numShortBlocks ) {
					result.push( blocks[ j ][ i ] );
				}
			}
		}
		return result;
	};

	QrCode.prototype.drawCodewords = function ( data ) {
		var i = 0;
		for ( var right = this.size - 1; right >= 1; right -= 2 ) {
			if ( right === 6 ) {
				right = 5;
			}
			for ( var vert = 0; vert < this.size; vert++ ) {
				for ( var jj = 0; jj < 2; jj++ ) {
					var x = right - jj;
					var upward = ( ( right + 1 ) & 2 ) === 0;
					var y = upward ? this.size - 1 - vert : vert;
					if ( ! this.isFunction[ y ][ x ] && i < data.length * 8 ) {
						this.modules[ y ][ x ] = ( ( data[ i >>> 3 ] >>> ( 7 - ( i & 7 ) ) ) & 1 ) !== 0;
						i++;
					}
				}
			}
		}
	};

	QrCode.prototype.applyMask = function ( mask ) {
		for ( var y = 0; y < this.size; y++ ) {
			for ( var x = 0; x < this.size; x++ ) {
				var invert;
				switch ( mask ) {
					case 0: invert = ( x + y ) % 2 === 0; break;
					case 1: invert = y % 2 === 0; break;
					case 2: invert = x % 3 === 0; break;
					case 3: invert = ( x + y ) % 3 === 0; break;
					case 4: invert = ( Math.floor( x / 3 ) + Math.floor( y / 2 ) ) % 2 === 0; break;
					case 5: invert = ( ( x * y ) % 2 ) + ( ( x * y ) % 3 ) === 0; break;
					case 6: invert = ( ( ( x * y ) % 2 ) + ( ( x * y ) % 3 ) ) % 2 === 0; break;
					default: invert = ( ( ( x + y ) % 2 ) + ( ( x * y ) % 3 ) ) % 2 === 0; break;
				}
				if ( invert && ! this.isFunction[ y ][ x ] ) {
					this.modules[ y ][ x ] = ! this.modules[ y ][ x ];
				}
			}
		}
	};

	// --- Encoder ------------------------------------------------------------

	function encodeText( text ) {
		var seg = new QrSegment( text );
		var ecl = 1; // Medium.
		var version;
		var dataUsedBits;
		for ( version = 1; version <= 40; version++ ) {
			var dataCapacityBits = getNumDataCodewords( version, ecl ) * 8;
			var usedBits = 4 + ( version < 10 ? 8 : 16 ) + seg.bytes.length * 8;
			if ( usedBits <= dataCapacityBits ) {
				dataUsedBits = usedBits;
				break;
			}
		}
		if ( version > 40 ) {
			throw new Error( 'Data too long' );
		}

		var bb = [];
		function appendBits( val, len ) {
			for ( var i = len - 1; i >= 0; i-- ) {
				bb.push( ( val >>> i ) & 1 );
			}
		}
		appendBits( 4, 4 ); // Byte mode.
		appendBits( seg.bytes.length, version < 10 ? 8 : 16 );
		seg.bytes.forEach( function ( b ) {
			appendBits( b, 8 );
		} );

		var dataCapacityBits2 = getNumDataCodewords( version, ecl ) * 8;
		appendBits( 0, Math.min( 4, dataCapacityBits2 - bb.length ) );
		appendBits( 0, ( 8 - ( bb.length % 8 ) ) % 8 );
		for ( var pad = 0xec; bb.length < dataCapacityBits2; pad ^= 0xec ^ 0x11 ) {
			appendBits( pad, 8 );
		}

		var dataCodewords = [];
		for ( var i = 0; i < bb.length; i += 8 ) {
			var byteVal = 0;
			for ( var j = 0; j < 8; j++ ) {
				byteVal = ( byteVal << 1 ) | bb[ i + j ];
			}
			dataCodewords.push( byteVal );
		}

		// Pick the mask with the lowest penalty.
		var best = null;
		var bestPenalty = Infinity;
		for ( var mask = 0; mask < 8; mask++ ) {
			var qr = new QrCode( version, ecl, dataCodewords, mask );
			var penalty = computePenalty( qr );
			if ( penalty < bestPenalty ) {
				bestPenalty = penalty;
				best = qr;
			}
		}
		return best;
	}

	function computePenalty( qr ) {
		// A light penalty heuristic (run + block); good enough for scannability.
		var size = qr.size;
		var penalty = 0;
		for ( var y = 0; y < size; y++ ) {
			var runColor = false;
			var runLen = 0;
			for ( var x = 0; x < size; x++ ) {
				if ( qr.modules[ y ][ x ] === runColor ) {
					runLen++;
					if ( runLen === 5 ) {
						penalty += 3;
					} else if ( runLen > 5 ) {
						penalty++;
					}
				} else {
					runColor = qr.modules[ y ][ x ];
					runLen = 1;
				}
			}
		}
		return penalty;
	}

	// --- Public render ------------------------------------------------------

	function render( element, text ) {
		var qr = encodeText( text );
		var size = qr.size;
		var border = 4;
		var dim = size + border * 2;
		var parts = [];
		for ( var y = 0; y < size; y++ ) {
			for ( var x = 0; x < size; x++ ) {
				if ( qr.getModule( x, y ) ) {
					parts.push( 'M' + ( x + border ) + ',' + ( y + border ) + 'h1v1h-1z' );
				}
			}
		}
		var svg =
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + dim + ' ' + dim +
			'" stroke="none"><rect width="100%" height="100%" fill="#fff"/><path d="' +
			parts.join( ' ' ) + '" fill="#000"/></svg>';
		element.innerHTML = svg;
	}

	window.ShadowEthQR = { render: render };
} )();
