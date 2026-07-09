/**
 * メインビジュアルのスライドショー
 * 画像が2枚以上のときだけ、一定間隔で自動的に切り替えます。
 * prefers-reduced-motion 設定時は自動切り替えを行いません。
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
			return;
		}

		var interval = parseInt( hero.getAttribute( 'data-hero-interval' ), 10 ) || 5000;
		var current = 0;

		window.setInterval( function () {
			slides[ current ].classList.remove( 'is-active' );
			current = ( current + 1 ) % slides.length;
			slides[ current ].classList.add( 'is-active' );
		}, interval );
	} );
}() );
