/**
 * jijipom: メインビジュアルの YouTube 背景を、YouTube IFrame API で
 * 制御する。目的は「開始時や終了時に YouTube のタイトル・関連動画
 * ("その他の動画")・ロゴなどが出るのを最小化する」こと。
 *
 *  - 読み込み後に確実に無音で自動再生する (端末によっては iframe の
 *    autoplay だけでは再生されないため保険)。
 *  - 動画終了直前に先頭へ seek し、終了画面 (関連動画グリッド) を
 *    出さずにシームレスにループさせる。
 *
 * iframe 自体を CSS で拡大トリミングして上下のチャームを画面外に
 * 追い出す処理は style.css 側 (.front-hero__yt iframe) で行う。
 */
(function () {
	'use strict';

	var iframe = document.getElementById('jijipom-hero-yt');
	if (!iframe) {
		return;
	}

	var player;
	var loopGuard;

	function startLoopGuard() {
		if (loopGuard) {
			return;
		}
		// 終了イベントを待つと一瞬だけ終了画面が見えるため、終了の
		// 少し手前で先頭に戻して「終わらせない」。
		loopGuard = window.setInterval(function () {
			if (!player || typeof player.getDuration !== 'function') {
				return;
			}
			try {
				var dur = player.getDuration();
				var cur = player.getCurrentTime();
				if (dur > 0 && cur >= dur - 0.4) {
					player.seekTo(0, true);
				}
			} catch (e) { /* noop */ }
		}, 250);
	}

	function createPlayer() {
		player = new window.YT.Player('jijipom-hero-yt', {
			events: {
				onReady: function (e) {
					try {
						e.target.mute();
						e.target.playVideo();
					} catch (err) { /* noop */ }
				},
				onStateChange: function (e) {
					var YT = window.YT;
					if (e.data === YT.PlayerState.PLAYING) {
						startLoopGuard();
					} else if (e.data === YT.PlayerState.ENDED) {
						// loop=1 でも保険として先頭へ。
						try {
							e.target.seekTo(0, true);
							e.target.playVideo();
						} catch (err) { /* noop */ }
					}
				}
			}
		});
	}

	function boot() {
		if (window.YT && window.YT.Player) {
			createPlayer();
			return;
		}
		// API 未ロードなら読み込む。既存の onYouTubeIframeAPIReady を壊さない。
		var prev = window.onYouTubeIframeAPIReady;
		window.onYouTubeIframeAPIReady = function () {
			if (typeof prev === 'function') {
				prev();
			}
			createPlayer();
		};
		if (!document.getElementById('jijipom-yt-api')) {
			var tag = document.createElement('script');
			tag.id = 'jijipom-yt-api';
			tag.src = 'https://www.youtube.com/iframe_api';
			document.head.appendChild(tag);
		}
	}

	boot();
})();
