/**
 * メインビジュアルのスライドショー
 * 画像が2枚以上のときだけ、一定間隔で自動的に切り替えます。
 * prefers-reduced-motion 設定時は自動切り替えを行いません。
 *
 * 2枚目以降の画像は data-bg 属性で遅延指定されており、ここで
 * background-image に適用します (初期表示のLCPを1枚目に集中させるため)。
 *
 * @package jijipom
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var hero = document.querySelector( '.front-hero[data-hero-interval]' );
		if ( ! hero ) {
			return;
		}

		var slides = hero.querySelectorAll( '.front-hero__slide' );
		if ( slides.length < 2 ) {
			return;
		}

		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			// 自動切り替えしない = 2枚目以降は表示されないので読み込みも省く。
			return;
		}

		// 遅延指定された背景画像を適用 (1枚目の表示を妨げないよう
		// DOMContentLoaded 後に読み込み開始する)。
		slides.forEach( function ( slide ) {
			var bg = slide.getAttribute( 'data-bg' );
			if ( bg && ! slide.style.backgroundImage ) {
				slide.style.backgroundImage = 'url(' + bg + ')';
			}
		} );

		var interval = parseInt( hero.getAttribute( 'data-hero-interval' ), 10 ) || 5000;
		var current = 0;

		window.setInterval( function () {
			slides[ current ].classList.remove( 'is-active' );
			current = ( current + 1 ) % slides.length;
			slides[ current ].classList.add( 'is-active' );
		}, interval );
	} );
}() );
