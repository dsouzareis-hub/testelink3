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

    // Marcar/desmarcar todos (atribuições)
    var all = document.querySelector('[data-check-all]');
    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('[data-check-item]').forEach(function (c) { c.checked = all.checked; });
        });
    }
})();
