(function () {
    var root = document.querySelector('.dstk-redirects');

    if (!root) {
        return;
    }

    var list = root.querySelector('.dstk-redirects__list');
    var addButton = root.querySelector('.dstk-redirects__add');
    var template = document.getElementById('dstk-redirect-template');
    var form = root.closest('form');
    var max = parseInt(root.getAttribute('data-max') || '100', 10);

    if (!list) {
        return;
    }

    function rows() {
        return Array.prototype.slice.call(list.querySelectorAll('.dstk-redirect-row'));
    }

    function reindex() {
        rows().forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/\[rows\]\[\d+\]/, '[rows][' + index + ']');
            });
        });

        var count = root.querySelector('.dstk-redirects__count');

        if (count) {
            count.textContent = String(rows().length);
        }

        if (addButton) {
            addButton.disabled = rows().length >= max;
        }
    }

    function bind(row) {
        var handle = row.querySelector('.dstk-redirect-row__handle');
        var remove = row.querySelector('.dstk-redirect-row__remove');

        if (handle) {
            handle.addEventListener('mousedown', function () {
                row.setAttribute('draggable', 'true');
            });

            handle.addEventListener('mouseup', function () {
                row.setAttribute('draggable', 'false');
            });

            handle.addEventListener('keydown', function (event) {
                var sibling;

                if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
                    return;
                }

                event.preventDefault();
                sibling = event.key === 'ArrowUp' ? row.previousElementSibling : row.nextElementSibling;

                if (!sibling || !sibling.classList.contains('dstk-redirect-row')) {
                    return;
                }

                if (event.key === 'ArrowUp') {
                    list.insertBefore(row, sibling);
                } else {
                    list.insertBefore(sibling, row);
                }

                reindex();
                handle.focus();
            });
        }

        row.addEventListener('dragstart', function (event) {
            row.classList.add('is-dragging');

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', 'row');
            }
        });

        row.addEventListener('dragend', function () {
            row.classList.remove('is-dragging');
            row.setAttribute('draggable', 'false');
            reindex();
        });

        row.addEventListener('dragover', function (event) {
            var dragging = list.querySelector('.is-dragging');
            var rect;

            event.preventDefault();

            if (!dragging || dragging === row) {
                return;
            }

            rect = row.getBoundingClientRect();
            list.insertBefore(dragging, event.clientY > rect.top + rect.height / 2 ? row.nextSibling : row);
        });

        if (remove) {
            remove.addEventListener('click', function () {
                row.remove();

                if (!rows().length) {
                    insertRow(false);
                }

                reindex();
            });
        }
    }

    function insertRow(focus) {
        var row;
        var input;

        if (!template || !template.content || rows().length >= max) {
            return;
        }

        row = template.content.cloneNode(true).querySelector('.dstk-redirect-row');

        if (!row) {
            return;
        }

        list.appendChild(row);
        bind(row);
        reindex();

        if (!focus) {
            return;
        }

        input = row.querySelector('input[type="text"]');

        if (input) {
            input.focus();
        }
    }

    rows().forEach(bind);

    if (addButton) {
        addButton.addEventListener('click', function () {
            insertRow(true);
        });
    }

    if (form) {
        form.addEventListener('submit', reindex);
    }

    reindex();
}());
