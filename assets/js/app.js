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

    // Confirmação em ações destrutivas: <form data-confirm="mensagem">
    document.addEventListener('submit', function (ev) {
        var msg = ev.target.getAttribute && ev.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) { ev.preventDefault(); }
    });

    // Botão imprimir
    document.querySelectorAll('[data-print]').forEach(function (b) {
        b.addEventListener('click', function () { window.print(); });
    });

    // Mensagens de sucesso somem sozinhas
    document.querySelectorAll('.alert-success').forEach(function (a) {
        setTimeout(function () { a.style.display = 'none'; }, 6000);
    });

    // Bloco "Encaminhar ao desenvolvedor" só aparece quando o resultado é Reprovado
    var result = document.querySelector('[data-result-select]');
    var box = document.getElementById('forward-box');
    var forwardChk = document.querySelector('[data-forward-check]');
    if (result && box) {
        var syncForward = function () {
            var failed = result.value === 'failed';
            if (forwardChk) {
                forwardChk.closest('.forward-toggle').hidden = !failed;
                box.hidden = !(failed && forwardChk.checked);
            } else {
                box.hidden = !failed;
            }
        };
        result.addEventListener('change', syncForward);
        if (forwardChk) { forwardChk.addEventListener('change', syncForward); }
        syncForward();
    }

    // Pré-visualização das imagens selecionadas
    var fileInput = document.querySelector('[data-preview-input]');
    var previewBox = document.querySelector('[data-preview-box]');
    if (fileInput && previewBox) {
        fileInput.addEventListener('change', function () {
            previewBox.innerHTML = '';
            Array.prototype.forEach.call(fileInput.files, function (f) {
                if (!/^image\/(jpeg|png|webp)$/.test(f.type)) { return; }
                var a = document.createElement('span');
                a.className = 'thumb';
                var img = document.createElement('img');
                img.alt = f.name;
                img.src = URL.createObjectURL(f);
                a.appendChild(img);
                previewBox.appendChild(a);
            });
        });
    }

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
