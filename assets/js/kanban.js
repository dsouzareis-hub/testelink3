/* Kanban "Meus Testes": arrastar e soltar + seletor "Mover para". A regra de conclusão é validada no servidor. */
(function () {
    'use strict';

    var board = document.querySelector('[data-kanban]');
    if (!board) { return; }
    var toast = document.getElementById('kb-toast');
    var toastTimer = null;

    function showToast(msg, ok) {
        if (!msg) { return; }
        toast.textContent = msg;
        toast.className = 'kb-toast ' + (ok ? 'ok' : 'err');
        toast.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.hidden = true; }, ok ? 3500 : 9000);
    }

    function refresh() {
        board.querySelectorAll('.kb-col').forEach(function (col) {
            var n = col.querySelectorAll('.kb-card').length;
            col.querySelector('[data-count]').textContent = n;
            col.querySelector('[data-empty]').hidden = n > 0;
        });
    }

    // Insere o cartão na coluna mantendo a ordem por criticidade (mais crítico no topo)
    function place(card, list) {
        var rank = +card.dataset.rank;
        var before = null;
        list.querySelectorAll('.kb-card').forEach(function (c) {
            if (!before && c !== card && +c.dataset.rank > rank) { before = c; }
        });
        if (before) { list.insertBefore(card, before); } else { list.insertBefore(card, list.querySelector('[data-empty]')); }
    }

    function listOf(status) {
        return board.querySelector('.kb-col[data-status="' + status + '"] [data-list]');
    }

    // Trava o cartão (chamado concluído pelo dev): continua na coluna, mas sem arrastar nem mover
    function lock(card) {
        card.classList.add('locked');
        card.setAttribute('draggable', 'false');
        var form = card.querySelector('.move-form');
        if (form) { form.remove(); }
        if (!card.querySelector('[data-lock]')) {
            var p = document.createElement('p');
            p.className = 'kb-lock';
            p.setAttribute('data-lock', '');
            p.textContent = 'Concluído · somente leitura';
            var meta = card.querySelector('.kb-meta');
            if (meta) { meta.parentNode.insertBefore(p, meta.nextSibling); }
        }
        var open = card.querySelector('.kb-actions .kb-btn');
        if (open) { open.textContent = 'Ver chamado'; open.classList.add('kb-btn-ghost', 'kb-comment'); }
    }

    // Move visualmente, grava no servidor e desfaz se o servidor recusar
    function move(card, to) {
        var from = card.dataset.status;
        if (from === to || card.classList.contains('locked')) { return; }
        var originList = card.parentNode;
        var originNext = card.nextSibling;
        place(card, listOf(to));
        card.dataset.status = to;
        card.classList.add('saving');
        refresh();

        var fd = new FormData();
        fd.append('csrf', board.dataset.csrf);
        fd.append('id', card.dataset.id);
        fd.append('status', to);
        fetch('kanban_move.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                card.classList.remove('saving');
                var sel = card.querySelector('select[name="status"]');
                if (res.ok) {
                    if (sel) { sel.value = to; }
                    // Chamado concluído pelo dev: permanece em Concluído, porém travado (somente leitura)
                    if (res.locked) { lock(card); }
                    showToast(res.message, true);
                } else if (res.locked) {
                    // O servidor informa que o chamado já está concluído: garante que ele fique travado em Concluído
                    place(card, listOf('fixed'));
                    card.dataset.status = 'fixed';
                    lock(card);
                    refresh();
                    showToast(res.message, false);
                } else if (res.removed) {
                    // O chamado já não está com este usuário: tira do quadro
                    card.remove();
                    refresh();
                    showToast(res.message, false);
                } else {
                    originList.insertBefore(card, originNext);
                    card.dataset.status = from;
                    if (sel) { sel.value = from; }
                    card.classList.add('shake');
                    setTimeout(function () { card.classList.remove('shake'); }, 600);
                    showToast(res.message || 'Não foi possível mover o cartão.', false);
                    refresh();
                }
            })
            .catch(function () {
                card.classList.remove('saving');
                originList.insertBefore(card, originNext);
                card.dataset.status = from;
                showToast('Falha de conexão. O cartão voltou à coluna anterior.', false);
                refresh();
            });
    }

    // ----- Arrastar e soltar -----
    var dragged = null;
    board.addEventListener('dragstart', function (ev) {
        var card = ev.target.closest && ev.target.closest('.kb-card');
        if (!card) { return; }
        if (card.classList.contains('locked')) { ev.preventDefault(); return; }
        dragged = card;
        card.classList.add('dragging');
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', card.dataset.id);
    });
    board.addEventListener('dragend', function () {
        if (dragged) { dragged.classList.remove('dragging'); }
        dragged = null;
        board.querySelectorAll('.kb-col').forEach(function (c) { c.classList.remove('over'); });
    });
    board.querySelectorAll('.kb-col').forEach(function (col) {
        col.addEventListener('dragover', function (ev) {
            if (!dragged) { return; }
            ev.preventDefault();
            ev.dataTransfer.dropEffect = 'move';
            col.classList.add('over');
        });
        col.addEventListener('dragleave', function (ev) {
            if (!col.contains(ev.relatedTarget)) { col.classList.remove('over'); }
        });
        col.addEventListener('drop', function (ev) {
            ev.preventDefault();
            col.classList.remove('over');
            if (dragged) { move(dragged, col.dataset.status); }
        });
    });

    // ----- Seletor "Mover para" (teclado, toque e quem não arrasta) -----
    board.addEventListener('submit', function (ev) {
        var form = ev.target.closest('.move-form');
        if (!form) { return; }
        ev.preventDefault();
        move(form.closest('.kb-card'), form.querySelector('select').value);
    });
    board.addEventListener('change', function (ev) {
        var sel = ev.target.closest('.move-form select');
        if (sel) { move(sel.closest('.kb-card'), sel.value); }
    });
    // Com JavaScript o seletor age ao escolher; o botão "Mover" só serve de reserva sem JS
    board.classList.add('js');

    refresh();
})();
