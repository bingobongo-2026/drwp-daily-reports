/**
 * drwp-mosaic: アップロード前 / 後の画像に対する「矩形をドラッグで
 * 囲んでモザイクを掛ける」シンプルな Canvas エディタ。
 *
 * 使い方:
 *   DRWP_Mosaic.open({
 *     imageUrl: 'https://example.com/photo.jpg',   // または File オブジェクト
 *     onApply: function (blob, dataUrl) {
 *       // blob: モザイク済み画像 (image/jpeg)
 *       // dataUrl: data: URL (プレビュー差し替え用)
 *     },
 *     onCancel: function () { ... }
 *   });
 *
 * 設計メモ:
 * - 依存ゼロ。document.body にダイアログを動的挿入する。
 * - 矩形は複数追加可能 (履歴で「↶取り消し」「すべて消す」)。確定するまで
 *   元画像は触らず、Apply 時にまとめて pixelate して新しい blob を作る。
 * - モバイル対応 (touchstart/move/end)。クロスオリジン画像は対応外
 *   (taint されて toBlob が失敗するため)。プラグイン内の写真は同一
 *   オリジンなので普段は問題ない。
 * - モザイクの粒度 (block size) はキャンバスの長辺に応じて自動算出
 *   + スライダーで微調整。
 */
(function (window, document) {
    'use strict';

    function open(opts) {
        opts = opts || {};
        var source = opts.imageUrl;        // String URL or File or Blob
        if (!source) throw new Error('DRWP_Mosaic.open: imageUrl required');

        // ----- DOM 構築 -----
        var overlay = document.createElement('div');
        overlay.className = 'drwp-mosaic-overlay';
        overlay.innerHTML = ''
          + '<div class="drwp-mosaic-dialog" role="dialog" aria-modal="true" aria-label="モザイク編集">'
          +   '<div class="drwp-mosaic-head">'
          +     '<h3 class="drwp-mosaic-title">画像のモザイク編集</h3>'
          +     '<button type="button" class="drwp-mosaic-close" data-act="cancel" aria-label="閉じる">×</button>'
          +   '</div>'
          +   '<div class="drwp-mosaic-body">'
          +     '<div class="drwp-mosaic-canvas-wrap" data-role="wrap">'
          +       '<canvas class="drwp-mosaic-canvas" data-role="canvas"></canvas>'
          +       '<canvas class="drwp-mosaic-overlay-canvas" data-role="overlay"></canvas>'
          +     '</div>'
          +     '<p class="drwp-mosaic-hint">'
          +       'ぼかしたい部分をドラッグで囲んでください。複数の範囲を追加できます。'
          +     '</p>'
          +     '<div class="drwp-mosaic-controls">'
          +       '<label class="drwp-mosaic-strength">'
          +         '<span>粒度</span>'
          +         '<input type="range" min="6" max="40" value="14" data-role="strength" />'
          +         '<span class="drwp-mosaic-strength-val" data-role="strength-val">14</span>'
          +       '</label>'
          +       '<button type="button" class="drwp-mosaic-btn" data-act="undo">↶ 1つ取り消し</button>'
          +       '<button type="button" class="drwp-mosaic-btn" data-act="clear">すべて消す</button>'
          +     '</div>'
          +   '</div>'
          +   '<div class="drwp-mosaic-foot">'
          +     '<button type="button" class="drwp-mosaic-btn drwp-mosaic-btn-ghost" data-act="cancel">キャンセル</button>'
          +     '<button type="button" class="drwp-mosaic-btn drwp-mosaic-btn-primary" data-act="apply" disabled>適用</button>'
          +   '</div>'
          + '</div>';

        document.body.appendChild(overlay);

        var wrap     = overlay.querySelector('[data-role=wrap]');
        var base     = overlay.querySelector('[data-role=canvas]');
        var overlayC = overlay.querySelector('[data-role=overlay]');
        var strengthEl = overlay.querySelector('[data-role=strength]');
        var strengthVal = overlay.querySelector('[data-role=strength-val]');
        var applyBtn = overlay.querySelector('[data-act=apply]');

        var baseCtx = base.getContext('2d');
        var overCtx = overlayC.getContext('2d');

        var rects = [];   // {x,y,w,h} (画像座標系)
        var drawing = null;  // ドラッグ中の矩形
        var scale = 1;       // 表示倍率 (画像 px → 表示 px)
        var image = null;    // 読み込まれた Image
        var blockSize = 14;  // モザイクブロックの 1 辺 (画像座標 px)

        function done(blob, url, action) {
            cleanup();
            if (action === 'apply' && typeof opts.onApply === 'function') opts.onApply(blob, url);
            if (action === 'cancel' && typeof opts.onCancel === 'function') opts.onCancel();
        }
        function cleanup() {
            overlay.parentNode && overlay.parentNode.removeChild(overlay);
            document.removeEventListener('keydown', onKeyDown);
        }
        function onKeyDown(e) {
            if (e.key === 'Escape') done(null, null, 'cancel');
        }
        document.addEventListener('keydown', onKeyDown);

        // ----- 画像読み込み -----
        var img = new Image();
        img.crossOrigin = 'anonymous';   // CORS が許可されている場合のみ taint 回避
        img.onload = function () {
            image = img;
            fitCanvas();
            redrawOverlay();
        };
        img.onerror = function () {
            alert('画像を読み込めませんでした。');
            done(null, null, 'cancel');
        };
        if (typeof source === 'string') {
            img.src = source;
        } else if (source instanceof Blob) {
            img.src = URL.createObjectURL(source);
        } else {
            alert('画像ソースが不正です。');
            done(null, null, 'cancel');
            return;
        }

        function fitCanvas() {
            // 画像本来のサイズに合わせて canvas のピクセル幅 / 高さを決める。
            // 表示サイズ (CSS) は wrap 幅にフィットさせて scale を求める。
            base.width  = image.naturalWidth;
            base.height = image.naturalHeight;
            overlayC.width  = image.naturalWidth;
            overlayC.height = image.naturalHeight;

            baseCtx.drawImage(image, 0, 0);

            var maxW = wrap.clientWidth || 600;
            var maxH = Math.min(window.innerHeight * 0.55, 600);
            scale = Math.min(maxW / image.naturalWidth, maxH / image.naturalHeight, 1);
            var dispW = image.naturalWidth * scale;
            var dispH = image.naturalHeight * scale;
            base.style.width = dispW + 'px';
            base.style.height = dispH + 'px';
            overlayC.style.width = dispW + 'px';
            overlayC.style.height = dispH + 'px';

            // 既定の粒度は長辺の 1.5% くらい (扱いやすい値)
            var auto = Math.max(6, Math.round(Math.max(image.naturalWidth, image.naturalHeight) * 0.015));
            blockSize = Math.min(40, auto);
            strengthEl.value = String(blockSize);
            strengthVal.textContent = String(blockSize);
        }

        // ----- 矩形ドラッグ -----
        function toImageCoords(ev) {
            var rect = overlayC.getBoundingClientRect();
            var clientX, clientY;
            if (ev.touches && ev.touches[0]) {
                clientX = ev.touches[0].clientX;
                clientY = ev.touches[0].clientY;
            } else {
                clientX = ev.clientX;
                clientY = ev.clientY;
            }
            var x = (clientX - rect.left) / scale;
            var y = (clientY - rect.top)  / scale;
            x = Math.max(0, Math.min(image.naturalWidth, x));
            y = Math.max(0, Math.min(image.naturalHeight, y));
            return { x: x, y: y };
        }
        function startDraw(ev) {
            if (!image) return;
            ev.preventDefault();
            var p = toImageCoords(ev);
            drawing = { x: p.x, y: p.y, w: 0, h: 0, sx: p.x, sy: p.y };
        }
        function moveDraw(ev) {
            if (!drawing) return;
            ev.preventDefault();
            var p = toImageCoords(ev);
            drawing.x = Math.min(p.x, drawing.sx);
            drawing.y = Math.min(p.y, drawing.sy);
            drawing.w = Math.abs(p.x - drawing.sx);
            drawing.h = Math.abs(p.y - drawing.sy);
            redrawOverlay();
        }
        function endDraw() {
            if (!drawing) return;
            if (drawing.w > 4 && drawing.h > 4) {
                rects.push({ x: drawing.x, y: drawing.y, w: drawing.w, h: drawing.h });
                applyBtn.disabled = false;
            }
            drawing = null;
            redrawOverlay();
        }
        overlayC.addEventListener('mousedown',  startDraw);
        overlayC.addEventListener('mousemove',  moveDraw);
        window.addEventListener('mouseup',     endDraw);
        overlayC.addEventListener('touchstart', startDraw, { passive: false });
        overlayC.addEventListener('touchmove',  moveDraw,  { passive: false });
        overlayC.addEventListener('touchend',   endDraw);

        function redrawOverlay() {
            overCtx.clearRect(0, 0, overlayC.width, overlayC.height);
            overCtx.lineWidth = Math.max(2, 2 / scale);
            overCtx.strokeStyle = '#dc2626';
            overCtx.fillStyle = 'rgba(220, 38, 38, 0.18)';
            rects.forEach(function (r) {
                overCtx.fillRect(r.x, r.y, r.w, r.h);
                overCtx.strokeRect(r.x, r.y, r.w, r.h);
            });
            if (drawing) {
                overCtx.fillRect(drawing.x, drawing.y, drawing.w, drawing.h);
                overCtx.strokeRect(drawing.x, drawing.y, drawing.w, drawing.h);
            }
        }

        // ----- ピクセレート処理 -----
        function pixelateRegion(ctx, rect, block) {
            // 1) 領域を抜き出して block 倍縮小 → 拡大、で「ブロック平均色」を得る
            var sx = Math.max(0, Math.floor(rect.x));
            var sy = Math.max(0, Math.floor(rect.y));
            var sw = Math.min(image.naturalWidth  - sx, Math.ceil(rect.w));
            var sh = Math.min(image.naturalHeight - sy, Math.ceil(rect.h));
            if (sw < 2 || sh < 2) return;
            var tw = Math.max(1, Math.floor(sw / block));
            var th = Math.max(1, Math.floor(sh / block));
            // オフスクリーンに縮小コピー → そのまま元領域に拡大描画
            var off = document.createElement('canvas');
            off.width = tw; off.height = th;
            var offCtx = off.getContext('2d');
            offCtx.imageSmoothingEnabled = false;
            offCtx.drawImage(ctx.canvas, sx, sy, sw, sh, 0, 0, tw, th);
            ctx.imageSmoothingEnabled = false;
            ctx.drawImage(off, 0, 0, tw, th, sx, sy, sw, sh);
            ctx.imageSmoothingEnabled = true;
        }

        function applyAll() {
            if (!rects.length) return;
            rects.forEach(function (r) { pixelateRegion(baseCtx, r, blockSize); });
            base.toBlob(function (blob) {
                if (!blob) { alert('画像の書き出しに失敗しました。'); return; }
                done(blob, base.toDataURL('image/jpeg', 0.9), 'apply');
            }, 'image/jpeg', 0.9);
        }

        // ----- ボタン -----
        overlay.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.dataset || !t.dataset.act) return;
            if (t.dataset.act === 'cancel') done(null, null, 'cancel');
            else if (t.dataset.act === 'apply') applyAll();
            else if (t.dataset.act === 'undo')  { rects.pop(); applyBtn.disabled = !rects.length; redrawOverlay(); }
            else if (t.dataset.act === 'clear') { rects = []; applyBtn.disabled = true; redrawOverlay(); }
        });
        strengthEl.addEventListener('input', function () {
            blockSize = Math.max(2, parseInt(strengthEl.value, 10) || 14);
            strengthVal.textContent = String(blockSize);
        });
        // バックドロップクリックで閉じる
        overlay.addEventListener('mousedown', function (e) {
            if (e.target === overlay) done(null, null, 'cancel');
        });
    }

    window.DRWP_Mosaic = { open: open };
})(window, document);
