/* TestLink 3.0 — JavaScript vanilla */
(function () {
    'use strict';

    // Menu lateral (tablet/mobile)
    var toggle = document.querySelector('[data-menu-toggle]');
    var sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () { sidebar.classList.toggle('open'); });
        document.addEventListener('click', function (ev) {
            if (sidebar.classList.contains('open') && !sidebar.contains(ev.target) && !toggle.contains(ev.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    /* ------------------------------------------------------------------
     * Diálogos estilizados (no lugar de alert/confirm nativos do navegador)
     *   TL.confirm({title, message, ok, cancel, type}) -> Promise<boolean>
     *   TL.alert({title, message, ok, type})            -> Promise<void>
     *   type: 'danger' | 'warning' | 'info' | 'success'
     * window.alert também passa a usar este diálogo.
     * ------------------------------------------------------------------ */
    var ICONS = {
        danger: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        warning: '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        info: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
        success: '<polyline points="20 6 9 17 4 12"/>'
    };
    var dlg = null;

    function buildDialog() {
        dlg = document.createElement('div');
        dlg.className = 'dialog-overlay';
        dlg.hidden = true;
        dlg.innerHTML =
            '<div class="dialog" role="alertdialog" aria-modal="true" aria-labelledby="dlg-title" aria-describedby="dlg-msg">' +
            '<div class="dialog-icon"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"></svg></div>' +
            '<h3 class="dialog-title" id="dlg-title"></h3>' +
            '<p class="dialog-msg" id="dlg-msg"></p>' +
            '<div class="dialog-actions"><button type="button" class="btn btn-ghost" data-dlg-cancel></button>' +
            '<button type="button" class="btn" data-dlg-ok></button></div></div>';
        document.body.appendChild(dlg);
    }

    function openDialog(opts, withCancel) {
        return new Promise(function (resolve) {
            if (!dlg) { buildDialog(); }
            var type = ICONS[opts.type] ? opts.type : (withCancel ? 'warning' : 'info');
            var box = dlg.querySelector('.dialog');
            var okBtn = dlg.querySelector('[data-dlg-ok]');
            var cancelBtn = dlg.querySelector('[data-dlg-cancel]');
            var previous = document.activeElement;

            box.className = 'dialog dialog-' + type;
            dlg.querySelector('svg').innerHTML = ICONS[type];
            dlg.querySelector('.dialog-title').textContent = opts.title || (withCancel ? 'Confirmar ação' : 'Aviso');
            dlg.querySelector('.dialog-msg').textContent = opts.message || '';
            okBtn.textContent = opts.ok || (withCancel ? 'Confirmar' : 'OK');
            okBtn.className = 'btn ' + (type === 'danger' ? 'btn-danger' : (type === 'success' ? 'btn-success' : ''));
            cancelBtn.textContent = opts.cancel || 'Cancelar';
            cancelBtn.hidden = !withCancel;
            dlg.hidden = false;
            document.body.classList.add('lb-open');
            // Em ação destrutiva o foco começa em "Cancelar" (mais seguro)
            (withCancel && type === 'danger' ? cancelBtn : okBtn).focus();

            function done(result) {
                dlg.hidden = true;
                document.body.classList.remove('lb-open');
                dlg.removeEventListener('click', onClick);
                document.removeEventListener('keydown', onKey, true);
                if (previous && previous.focus) { previous.focus(); }
                resolve(result);
            }
            function onClick(ev) {
                if (ev.target === dlg) { done(false); }                       // clique fora
                else if (ev.target.closest('[data-dlg-ok]')) { done(true); }
                else if (ev.target.closest('[data-dlg-cancel]')) { done(false); }
            }
            function onKey(ev) {
                if (ev.key === 'Escape') { ev.preventDefault(); done(false); }
                else if (ev.key === 'Tab') {                                  // mantém o foco dentro do diálogo
                    ev.preventDefault();
                    var btns = [cancelBtn, okBtn].filter(function (b) { return !b.hidden; });
                    var i = btns.indexOf(document.activeElement);
                    btns[(i + (ev.shiftKey ? btns.length - 1 : 1)) % btns.length].focus();
                }
            }
            dlg.addEventListener('click', onClick);
            document.addEventListener('keydown', onKey, true);
        });
    }

    window.TL = {
        confirm: function (opts) { return openDialog(typeof opts === 'string' ? { message: opts } : (opts || {}), true); },
        alert: function (opts) { return openDialog(typeof opts === 'string' ? { message: opts } : (opts || {}), false); }
    };
    window.alert = function (msg) { window.TL.alert({ message: String(msg) }); };   // alert() nativo passa a ser estilizado

    // Confirmação em ações destrutivas: <form data-confirm="mensagem"
    //   data-confirm-title="Título" data-confirm-ok="Texto do botão" data-confirm-type="danger|warning|info">
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        var msg = form.getAttribute && form.getAttribute('data-confirm');
        if (!msg || form.dataset.confirmed === '1') { return; }
        ev.preventDefault();
        var submitter = ev.submitter || null;
        window.TL.confirm({
            title: form.getAttribute('data-confirm-title') || 'Confirmar ação',
            message: msg,
            ok: form.getAttribute('data-confirm-ok') || 'Confirmar',
            type: form.getAttribute('data-confirm-type') || 'danger'
        }).then(function (yes) {
            if (!yes) { return; }
            form.dataset.confirmed = '1';
            if (form.requestSubmit) { form.requestSubmit(submitter); } else { form.submit(); }
        });
    });

    // Botão Voltar: usa a página anterior do histórico; se ela não serve, segue o link de reserva (href)
    document.querySelectorAll('[data-back]').forEach(function (link) {
        link.addEventListener('click', function (ev) {
            try {
                if (!document.referrer || history.length < 2) { return; }
                var ref = new URL(document.referrer);
                if (ref.origin !== location.origin) { return; }
                var refPage = ref.pathname.split('/').pop();
                var here = location.pathname.split('/').pop();
                // Não volta para a própria página (ex.: depois de salvar um formulário) nem para telas de ação
                var skip = ['', 'login.php', 'register.php', 'logout.php', 'execute.php', 'case_form.php', 'kanban_move.php', 'report_pdf.php'];
                if (refPage !== here && skip.indexOf(refPage) === -1) {
                    ev.preventDefault();
                    history.back();
                }
            } catch (e) { /* usa o href de reserva */ }
        });
    });

    // Botão imprimir
    document.querySelectorAll('[data-print]').forEach(function (b) {
        b.addEventListener('click', function () { window.print(); });
    });

    // Mensagens de sucesso somem sozinhas
    document.querySelectorAll('.alert-success').forEach(function (a) {
        setTimeout(function () { a.style.display = 'none'; }, 6000);
    });

    // Execução: o bloco "em qual passo falhou?" só aparece quando o resultado é Reprovado
    var result = document.querySelector('[data-result-select]');
    var failedBox = document.querySelector('[data-failed-steps]');
    if (result && failedBox) {
        var syncFailed = function () {
            var failed = result.value === 'failed';
            failedBox.hidden = !failed;
            failedBox.querySelectorAll('input').forEach(function (i) { i.disabled = !failed; });   // oculto = não envia
        };
        result.addEventListener('change', syncFailed);
        syncFailed();
    }

    // Imagens: escolher arquivos OU COLAR PRINT direto (Ctrl+V). Mostra miniaturas com opção de remover.
    var fileInput = document.querySelector('[data-preview-input]');
    var previewBox = document.querySelector('[data-preview-box]');
    if (fileInput) {
        var picked = [];                    // arquivos efetivamente anexados (escolhidos + colados)
        var MAX_IMAGES = 10;
        var urls = [];
        var pasteCount = 0;

        var syncInput = function () {
            // Reescreve a lista de arquivos do <input> para o que está em "picked" (é o que o formulário envia)
            try {
                var dt = new DataTransfer();
                picked.forEach(function (f) { dt.items.add(f); });
                fileInput.files = dt.files;
            } catch (e) { /* navegador sem suporte: mantém só a seleção nativa */ }
            renderPreview();
        };

        var renderPreview = function () {
            if (!previewBox) { return; }
            urls.forEach(function (u) { URL.revokeObjectURL(u); });
            urls = [];
            previewBox.innerHTML = '';
            picked.forEach(function (f, idx) {
                var wrap = document.createElement('span');
                wrap.className = 'thumb thumb-new';
                var img = document.createElement('img');
                var u = URL.createObjectURL(f);
                urls.push(u);
                img.src = u;
                img.alt = f.name;
                img.title = f.name;
                var x = document.createElement('button');
                x.type = 'button';
                x.className = 'thumb-x';
                x.setAttribute('aria-label', 'Remover imagem');
                x.textContent = '×';
                x.addEventListener('click', function () { picked.splice(idx, 1); syncInput(); });
                wrap.appendChild(img);
                wrap.appendChild(x);
                previewBox.appendChild(wrap);
            });
        };

        var addFiles = function (files) {
            var skipped = 0;
            Array.prototype.forEach.call(files, function (f) {
                if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { skipped++; return; }
                if (picked.length >= MAX_IMAGES) { skipped++; return; }
                picked.push(f);
            });
            syncInput();
            if (skipped) {
                window.TL.alert({ type: 'warning', title: 'Alguns arquivos não foram adicionados',
                    message: 'Só são aceitas imagens JPG, PNG ou WEBP, até ' + MAX_IMAGES + ' por envio.' });
            }
        };

        // Escolha pelo botão "Escolher arquivos": soma ao que já existe (inclusive prints colados)
        fileInput.addEventListener('change', function () {
            if (fileInput.dataset.syncing) { return; }
            addFiles(Array.prototype.slice.call(fileInput.files));
        });
        // Evita reprocessar a mudança feita por nós mesmos
        var origSync = syncInput;
        syncInput = function () { fileInput.dataset.syncing = '1'; origSync(); setTimeout(function () { delete fileInput.dataset.syncing; }, 0); };

        // COLAR (Ctrl+V): imagem na área de transferência (print) vira anexo, sem salvar arquivo
        document.addEventListener('paste', function (ev) {
            var items = ev.clipboardData && ev.clipboardData.items;
            if (!items) { return; }
            var imgs = [];
            var hasText = false;
            Array.prototype.forEach.call(items, function (it) {
                if (it.kind === 'file' && /^image\//.test(it.type)) { imgs.push(it.getAsFile()); }
                if (it.kind === 'string' && it.type === 'text/plain') { hasText = true; }
            });
            if (!imgs.length || (hasText && /^(INPUT|TEXTAREA)$/.test((ev.target || {}).tagName || ''))) { return; }   // texto normal segue o fluxo padrão
            ev.preventDefault();
            var stamp = new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
            var named = imgs.map(function (f, i) {
                var ext = (f.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                pasteCount++;                                   // número sequencial: nomes nunca se repetem
                return new File([f], 'print-' + stamp + '-' + pasteCount + '.' + ext, { type: f.type });
            });
            addFiles(named);
            fileInput.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        });
    }

    // Editor de passos em tópicos: digitar + Enter cria o próximo passo
    document.querySelectorAll('[data-steps-editor]').forEach(function (list) {
        var addBtn = document.querySelector('[data-step-add]');
        function rows() { return Array.prototype.slice.call(list.querySelectorAll('.step-row')); }
        function renumber() { rows().forEach(function (r, i) { r.querySelector('.step-no').textContent = (i + 1) + '.'; }); }
        function makeRow(text) {
            var li = document.createElement('li');
            li.className = 'step-row';
            li.innerHTML = '<span class="step-box" aria-hidden="true"></span><span class="step-no"></span>' +
                '<input type="text" name="steps[]" maxlength="500" autocomplete="off" placeholder="Descreva o passo e aperte Enter">' +
                '<button type="button" class="step-del" aria-label="Remover passo" title="Remover passo">×</button>';
            li.querySelector('input').value = text || '';
            return li;
        }
        function addAfter(row, text) {
            var li = makeRow(text);
            if (row && row.nextSibling) { list.insertBefore(li, row.nextSibling); } else { list.appendChild(li); }
            renumber();
            li.querySelector('input').focus();
            return li;
        }
        function removeRow(row) {
            var all = rows();
            if (all.length <= 1) { row.querySelector('input').value = ''; row.querySelector('input').focus(); return; }
            var prev = row.previousElementSibling || row.nextElementSibling;
            row.remove();
            renumber();
            if (prev) { prev.querySelector('input').focus(); }
        }

        list.addEventListener('keydown', function (ev) {
            var input = ev.target.closest('input');
            if (!input) { return; }
            var row = input.closest('.step-row');
            if (ev.key === 'Enter') {
                ev.preventDefault();                                  // não envia o formulário
                if (input.value.trim() !== '' || rows().length === 1) { addAfter(row); }
                else { input.focus(); }                               // linha vazia + Enter: não cria outra vazia
            } else if (ev.key === 'Backspace' && input.value === '' && rows().length > 1) {
                ev.preventDefault();
                removeRow(row);
            } else if (ev.key === 'ArrowDown' && row.nextElementSibling) {
                ev.preventDefault();
                row.nextElementSibling.querySelector('input').focus();
            } else if (ev.key === 'ArrowUp' && row.previousElementSibling) {
                ev.preventDefault();
                row.previousElementSibling.querySelector('input').focus();
            }
        });
        // Colar várias linhas de uma vez: cada linha vira um passo
        list.addEventListener('paste', function (ev) {
            var input = ev.target.closest('input');
            var text = ev.clipboardData && ev.clipboardData.getData('text');
            if (!input || !text || !/\r?\n/.test(text.trim())) { return; }
            ev.preventDefault();
            var lines = text.split(/\r?\n/).map(function (s) { return s.replace(/^\s*(\d+[.)-]\s*|[-*•]\s*)/, '').trim(); }).filter(Boolean);
            var row = input.closest('.step-row');
            if (input.value.trim() === '') { input.value = lines.shift() || ''; }
            lines.forEach(function (l) { row = addAfter(row, l); });
        });
        list.addEventListener('click', function (ev) {
            var del = ev.target.closest('.step-del');
            if (del) { removeRow(del.closest('.step-row')); }
        });
        if (addBtn) { addBtn.addEventListener('click', function () { addAfter(rows().slice(-1)[0]); }); }
    });

    // Select de suíte dependente do projeto (data-project em cada <option>)
    var projSel = document.querySelector('[data-project-select]');
    var suiteSel = document.querySelector('[data-suite-select]');
    if (projSel && suiteSel) {
        var filterSuites = function () {
            var pid = projSel.value;
            Array.prototype.forEach.call(suiteSel.options, function (o) {
                if (!o.value) { return; }
                var show = !pid || o.getAttribute('data-project') === pid;
                o.hidden = !show;
                o.disabled = !show;
            });
            var cur = suiteSel.options[suiteSel.selectedIndex];
            if (cur && cur.value && cur.disabled) { suiteSel.value = ''; }
        };
        projSel.addEventListener('change', filterSuites);
        filterSuites();
    }

    // Visualizador de imagens (modal): clicar numa miniatura abre a foto grande sobre a própria página.
    // Sem JavaScript o link continua abrindo a imagem normalmente.
    (function () {
        var overlay = null, img, caption, counter, prevBtn, nextBtn, closeBtn;
        var group = [], index = 0, lastFocus = null;

        function build() {
            overlay = document.createElement('div');
            overlay.className = 'lightbox';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-label', 'Visualização da imagem');
            overlay.hidden = true;
            overlay.innerHTML =
                '<button type="button" class="lb-btn lb-close" aria-label="Fechar">&times;</button>' +
                '<button type="button" class="lb-btn lb-nav lb-prev" aria-label="Foto anterior">&#10094;</button>' +
                '<figure class="lb-figure"><img class="lb-img" alt="">' +
                '<figcaption class="lb-caption"></figcaption></figure>' +
                '<button type="button" class="lb-btn lb-nav lb-next" aria-label="Próxima foto">&#10095;</button>';
            document.body.appendChild(overlay);
            img = overlay.querySelector('.lb-img');
            caption = overlay.querySelector('.lb-caption');
            prevBtn = overlay.querySelector('.lb-prev');
            nextBtn = overlay.querySelector('.lb-next');
            closeBtn = overlay.querySelector('.lb-close');

            closeBtn.addEventListener('click', close);
            prevBtn.addEventListener('click', function () { show(index - 1); });
            nextBtn.addEventListener('click', function () { show(index + 1); });
            // Clique no fundo (fora da imagem e dos botões) fecha
            overlay.addEventListener('click', function (ev) {
                if (ev.target === overlay || ev.target.classList.contains('lb-figure')) { close(); }
            });
            document.addEventListener('keydown', function (ev) {
                if (overlay.hidden) { return; }
                if (ev.key === 'Escape') { close(); }
                else if (ev.key === 'ArrowLeft') { show(index - 1); }
                else if (ev.key === 'ArrowRight') { show(index + 1); }
                else if (ev.key === 'Tab') {   // mantém o foco dentro do modal
                    ev.preventDefault();
                    var btns = [closeBtn, prevBtn, nextBtn].filter(function (b) { return !b.hidden; });
                    var i = btns.indexOf(document.activeElement);
                    btns[(i + (ev.shiftKey ? btns.length - 1 : 1)) % btns.length].focus();
                }
            });
        }

        function show(i) {
            if (!group.length) { return; }
            index = (i + group.length) % group.length;
            var a = group[index];
            img.src = a.href;
            img.alt = a.title || '';
            caption.textContent = (a.title || '') + (group.length > 1 ? '  ·  ' + (index + 1) + ' / ' + group.length : '');
            prevBtn.hidden = nextBtn.hidden = group.length < 2;
        }

        function open(link) {
            if (!overlay) { build(); }
            var gallery = link.closest('.gallery');
            group = Array.prototype.slice.call((gallery || document).querySelectorAll('a.thumb'));
            lastFocus = overlay.contains(document.activeElement) ? link : document.activeElement;
            overlay.hidden = false;
            document.body.classList.add('lb-open');
            show(group.indexOf(link));
            closeBtn.focus();
        }

        function close() {
            overlay.hidden = true;
            img.removeAttribute('src');
            document.body.classList.remove('lb-open');
            if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        }

        document.addEventListener('click', function (ev) {
            var link = ev.target.closest && ev.target.closest('a.thumb');
            if (!link || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button === 1) { return; }
            ev.preventDefault();
            open(link);
        });
    })();

    // Tomada de decisão: mostra só o seletor do destino escolhido (Desenvolvedor OU Tester)
    document.querySelectorAll('[data-decision]').forEach(function (form) {
        var panels = form.querySelectorAll('[data-target-panel]');
        function sync() {
            var chosen = form.querySelector('input[name="target"]:checked');
            var value = chosen ? chosen.value : '';
            panels.forEach(function (p) {
                var on = p.getAttribute('data-target-panel') === value;
                p.hidden = !on;
                p.querySelectorAll('select').forEach(function (s) { s.disabled = !on; });
            });
            form.querySelectorAll('.choice').forEach(function (c) {
                c.classList.toggle('on', c.querySelector('input').checked);
            });
        }
        form.addEventListener('change', sync);
        sync();
    });

    // Marcar/desmarcar todos (atribuições)
    var all = document.querySelector('[data-check-all]');
    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('[data-check-item]').forEach(function (c) { c.checked = all.checked; });
        });
    }
})();
