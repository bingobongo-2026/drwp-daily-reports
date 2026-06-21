/**
 * drwp-combo: native <select> を検索可能なコンボボックスに変えるごく
 * 小さなプログレッシブ拡張。
 *
 * 使い方:
 *   <div class="drwp-combo" data-drwp-combo>
 *     <select name="project_id" required>
 *       <option value="">選択してください</option>
 *       <optgroup label="最近使った">
 *         <option value="123">中村邸 洗面所リフォーム</option>
 *       </optgroup>
 *       <optgroup label="案件">
 *         <option value="456">井上邸 ...</option>
 *       </optgroup>
 *     </select>
 *   </div>
 *
 * JS は select を視覚的に隠し、その横に「テキスト入力 + 候補リスト」
 * を生成する。利用者が候補を選ぶと隠した select.value をセットし、
 * change イベントを発火する (フォーム送信側のコードはそのまま使える)。
 *
 * 設計メモ:
 * - 1 関数完結。グローバルに 1 シンボルだけ ( window.DRWP_Combo )。
 * - キー操作: ↑↓ で候補を選択、Enter で確定、Esc で閉じる。
 * - フィルタは「全部分一致 (小文字)」。日本語の濁点ゆれ等は対応しない。
 * - 候補に optgroup ラベルを薄く付けて「最近使った」ピン留め群を視認
 *   できるようにする。
 * - <select> が JS 後に DOM に追加された場合のために
 *   DRWP_Combo.enhance(root) を公開。再呼び出しは冪等。
 */
(function (window, document) {
    'use strict';

    var INIT_FLAG = '__drwpComboInit';

    function enhance(root) {
        root = root || document;
        var nodes = root.querySelectorAll('[data-drwp-combo]');
        nodes.forEach(function (wrap) {
            if (wrap[INIT_FLAG]) return;
            var native = wrap.querySelector('select');
            if (!native) return;
            wrap[INIT_FLAG] = true;
            buildCombo(wrap, native);
        });
    }

    function buildCombo(wrap, native) {
        // <select> はキーボード/フォーム互換のため DOM に残す。
        // 視覚的にだけ隠す。display:none された required 要素を
        // ブラウザが「フォーカスできないのに必須」と扱って submit を
        // 拒否することがあるので、required は剥がす(検証は呼び出し側の
        // JS submit ハンドラと PHP サーバ側に任せる)。
        native.classList.add('drwp-combo-native');
        if (native.hasAttribute('required')) {
            native.dataset.drwpComboRequired = '1';
            native.removeAttribute('required');
        }

        // 候補リストを option/optgroup から構築。
        var items = [];
        Array.prototype.forEach.call(native.children, function (child) {
            if (child.tagName === 'OPTGROUP') {
                var label = child.getAttribute('label') || '';
                Array.prototype.forEach.call(child.children, function (opt) {
                    if (opt.tagName === 'OPTION' && opt.value !== '') {
                        items.push({
                            value: opt.value,
                            label: opt.textContent.trim(),
                            group: label,
                        });
                    }
                });
            } else if (child.tagName === 'OPTION' && child.value !== '') {
                items.push({
                    value: child.value,
                    label: child.textContent.trim(),
                    group: '',
                });
            }
        });

        // プレースホルダ (空 option) を吸い上げる
        var placeholder = '';
        var first = native.querySelector('option');
        if (first && first.value === '') placeholder = first.textContent.trim();
        if (!placeholder) placeholder = '案件を選択';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'drwp-combo-input';
        input.placeholder = placeholder;
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');

        var list = document.createElement('ul');
        list.className = 'drwp-combo-list';
        list.setAttribute('role', 'listbox');
        list.hidden = true;

        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'drwp-combo-clear';
        clearBtn.setAttribute('aria-label', 'クリア');
        clearBtn.innerHTML = '×';
        clearBtn.hidden = true;

        wrap.appendChild(input);
        wrap.appendChild(clearBtn);
        wrap.appendChild(list);

        var filtered = items.slice();
        var activeIndex = -1;

        function selectByValue(val) {
            if (val == null) val = '';
            // ネイティブ select も同期
            native.value = String(val);
            var match = items.filter(function (it) { return String(it.value) === String(val); })[0];
            input.value = match ? match.label : '';
            clearBtn.hidden = !match;
            native.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function renderList() {
            list.innerHTML = '';
            if (filtered.length === 0) {
                var empty = document.createElement('li');
                empty.className = 'drwp-combo-empty';
                empty.textContent = '一致する案件がありません';
                list.appendChild(empty);
                return;
            }
            var lastGroup = null;
            filtered.forEach(function (it, i) {
                if (it.group && it.group !== lastGroup) {
                    var head = document.createElement('li');
                    head.className = 'drwp-combo-group';
                    head.textContent = it.group;
                    list.appendChild(head);
                    lastGroup = it.group;
                }
                if (!it.group) lastGroup = null;
                var li = document.createElement('li');
                li.className = 'drwp-combo-item';
                li.setAttribute('role', 'option');
                li.setAttribute('data-value', it.value);
                li.setAttribute('data-index', String(i));
                li.textContent = it.label;
                if (i === activeIndex) li.classList.add('is-active');
                if (String(native.value) === String(it.value)) li.classList.add('is-selected');
                list.appendChild(li);
            });
        }

        function applyFilter(q) {
            q = (q || '').trim().toLowerCase();
            if (q === '') {
                filtered = items.slice();
            } else {
                filtered = items.filter(function (it) {
                    return it.label.toLowerCase().indexOf(q) !== -1;
                });
            }
            activeIndex = filtered.length > 0 ? 0 : -1;
            renderList();
        }

        function open() {
            applyFilter(input.value === currentLabel() ? '' : input.value);
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            wrap.classList.add('is-open');
        }
        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            wrap.classList.remove('is-open');
            // テキストを選択ラベルに戻す (中途半端な検索文字を残さない)
            input.value = currentLabel();
        }
        function currentLabel() {
            var match = items.filter(function (it) { return String(it.value) === String(native.value); })[0];
            return match ? match.label : '';
        }

        function moveActive(delta) {
            if (filtered.length === 0) return;
            activeIndex = (activeIndex + delta + filtered.length) % filtered.length;
            renderList();
            // スクロール追従
            var el = list.querySelector('.is-active');
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        }

        input.addEventListener('focus', open);
        input.addEventListener('click', open);
        input.addEventListener('input', function () {
            applyFilter(input.value);
            if (list.hidden) open();
        });
        input.addEventListener('keydown', function (e) {
            if (list.hidden && (e.key === 'ArrowDown' || e.key === 'Enter')) {
                open();
                e.preventDefault();
                return;
            }
            if (e.key === 'ArrowDown') { moveActive(1);  e.preventDefault(); }
            else if (e.key === 'ArrowUp') { moveActive(-1); e.preventDefault(); }
            else if (e.key === 'Enter') {
                if (activeIndex >= 0 && filtered[activeIndex]) {
                    selectByValue(filtered[activeIndex].value);
                    close();
                }
                e.preventDefault();
            } else if (e.key === 'Escape') {
                close();
            }
        });

        list.addEventListener('mousedown', function (e) {
            // mousedown は blur より先に飛ぶので、ここで確定すると
            // 「クリックしたのに閉じてしまった」事故が起きない
            var li = e.target.closest('.drwp-combo-item');
            if (!li) return;
            e.preventDefault();
            selectByValue(li.getAttribute('data-value'));
            close();
        });

        clearBtn.addEventListener('click', function (e) {
            e.preventDefault();
            selectByValue('');
            input.focus();
        });

        // wrap の外でクリック → 閉じる
        document.addEventListener('mousedown', function (e) {
            if (!wrap.contains(e.target)) close();
        });

        // 初期値の反映 (PHP 側で <option selected> が付いている時)
        selectByValue(native.value);
        // selectByValue は change を発火するので select 自体の初期値が
        // 空の場合 (新規フォーム) でも誤動作はしない。

        // 外部から native.value を更新された場合 (例えば JS で再代入)
        // にも input を同期するため、change を観測しておく
        native.addEventListener('change', function () {
            input.value = currentLabel();
            clearBtn.hidden = !native.value;
        });
    }

    window.DRWP_Combo = { enhance: enhance };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { enhance(document); });
    } else {
        enhance(document);
    }
})(window, document);
