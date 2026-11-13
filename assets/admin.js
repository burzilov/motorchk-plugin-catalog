(function () {
  if (window.__catalogAdminBound) return;
  window.__catalogAdminBound = true;

  function csrf(el) {
    return el.getAttribute('data-csrf') || '';
  }

  function postForm(url, data, token) {
    var body = new FormData();
    Object.keys(data).forEach(function (k) {
      body.append(k, data[k]);
    });
    body.append('_csrf', token);
    return fetch(url, {
      method: 'POST',
      body: body,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': token },
    }).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok || j.ok === false) {
          throw new Error(j.error || r.statusText || 'Ошибка');
        }
        return j;
      });
    });
  }

  function renderGallery(root, images) {
    var list = root.querySelector('[data-catalog-list]');
    if (!list) return;
    list.innerHTML = '';
    (images || []).forEach(function (item, i) {
      var li = document.createElement('li');
      li.className = 'cat-gallery__item' + (i === 0 ? ' is-primary' : '');
      li.draggable = true;
      li.setAttribute('data-path', item.path);
      li.innerHTML =
        '<img src="' +
        item.thumb_url +
        '" alt="" width="96" height="96" loading="lazy">' +
        '<div class="cat-gallery__actions">' +
        '<button type="button" class="mc-btn mc-btn--quiet" data-catalog-primary title="Сделать главным">★</button>' +
        '<button type="button" class="mc-btn mc-btn--quiet mc-btn--danger-text" data-catalog-delete title="Убрать">×</button>' +
        '</div>';
      list.appendChild(li);
    });
    var empty = root.querySelector('[data-catalog-empty]');
    if (empty) empty.hidden = (images || []).length > 0;
  }

  function galleryOrder(root) {
    return Array.prototype.map.call(root.querySelectorAll('[data-catalog-list] [data-path]'), function (el) {
      return el.getAttribute('data-path');
    });
  }

  function bindGallery(root) {
    if (root.__bound) return;
    root.__bound = true;
    var status = root.querySelector('[data-catalog-status]');
    var setStatus = function (t) {
      if (status) status.textContent = t || '';
    };

    root.addEventListener('change', function (ev) {
      var input = ev.target.closest('[data-catalog-upload]');
      if (!input || !input.files || !input.files.length) return;
      var files = Array.prototype.slice.call(input.files);
      input.value = '';
      var chain = Promise.resolve();
      files.forEach(function (file) {
        chain = chain.then(function () {
          setStatus('Загрузка…');
          var body = new FormData();
          body.append('file', file);
          body.append('_csrf', csrf(root));
          return fetch(root.getAttribute('data-upload-url'), {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-Token': csrf(root) },
          })
            .then(function (r) {
              return r.json().then(function (j) {
                if (!r.ok || j.ok === false) throw new Error(j.error || 'Ошибка загрузки');
                renderGallery(root, j.images);
              });
            });
        });
      });
      chain.then(function () { setStatus('Готово'); }).catch(function (err) {
        setStatus(err.message || 'Ошибка');
      });
    });

    root.addEventListener('click', function (ev) {
      var primary = ev.target.closest('[data-catalog-primary]');
      var del = ev.target.closest('[data-catalog-delete]');
      var item = ev.target.closest('[data-path]');
      if (!item) return;
      var path = item.getAttribute('data-path');
      if (primary) {
        setStatus('Сохранение…');
        postForm(root.getAttribute('data-primary-url'), { path: path }, csrf(root))
          .then(function (j) {
            renderGallery(root, j.images);
            setStatus('Главный кадр обновлён');
          })
          .catch(function (err) { setStatus(err.message); });
      }
      if (del) {
        if (!window.confirm('Убрать изображение из галереи? Файлы на диске не удаляются без отдельного подтверждения.')) {
          return;
        }
        setStatus('Удаление…');
        postForm(root.getAttribute('data-delete-url'), { path: path, delete_files: '0' }, csrf(root))
          .then(function (j) {
            renderGallery(root, j.images);
            setStatus('Убрано из галереи');
          })
          .catch(function (err) { setStatus(err.message); });
      }
    });

    var dragEl = null;
    root.addEventListener('dragstart', function (ev) {
      var item = ev.target.closest('.cat-gallery__item');
      if (!item) return;
      dragEl = item;
      item.classList.add('is-dragging');
      ev.dataTransfer.effectAllowed = 'move';
    });
    root.addEventListener('dragend', function () {
      if (dragEl) dragEl.classList.remove('is-dragging');
      dragEl = null;
    });
    root.addEventListener('dragover', function (ev) {
      ev.preventDefault();
      var item = ev.target.closest('.cat-gallery__item');
      var list = root.querySelector('[data-catalog-list]');
      if (!dragEl || !list) return;
      if (item && item !== dragEl) {
        var rect = item.getBoundingClientRect();
        var before = ev.clientX < rect.left + rect.width / 2;
        list.insertBefore(dragEl, before ? item : item.nextSibling);
      }
    });
    root.addEventListener('drop', function (ev) {
      ev.preventDefault();
      if (!dragEl) return;
      setStatus('Сохранение порядка…');
      postForm(
        root.getAttribute('data-reorder-url'),
        { order: JSON.stringify(galleryOrder(root)) },
        csrf(root)
      )
        .then(function (j) {
          renderGallery(root, j.images);
          setStatus('Порядок сохранён');
        })
        .catch(function (err) { setStatus(err.message); });
    });
  }

  function collectLayout(board) {
    var rows = [];
    board.querySelectorAll('.cat-board__row').forEach(function (row) {
      var slugs = Array.prototype.map.call(row.querySelectorAll('[data-slug]'), function (el) {
        return el.getAttribute('data-slug');
      });
      if (row.getAttribute('data-group') === 'new' && slugs.length === 0) return;
      rows.push(slugs);
    });
    return rows;
  }

  function bindBoard(board) {
    if (board.__bound) return;
    board.__bound = true;
    var dragCard = null;

    board.addEventListener('dragstart', function (ev) {
      var card = ev.target.closest('.cat-board__card');
      if (!card) return;
      dragCard = card;
      card.classList.add('is-dragging');
      ev.dataTransfer.effectAllowed = 'move';
    });
    board.addEventListener('dragend', function () {
      if (dragCard) dragCard.classList.remove('is-dragging');
      dragCard = null;
    });
    board.addEventListener('dragover', function (ev) {
      ev.preventDefault();
      if (!dragCard) return;
      var list = ev.target.closest('.cat-board__list');
      var card = ev.target.closest('.cat-board__card');
      if (!list) return;
      if (card && card !== dragCard) {
        var rect = card.getBoundingClientRect();
        var before = ev.clientX < rect.left + rect.width / 2;
        list.insertBefore(dragCard, before ? card : card.nextSibling);
      } else if (!card) {
        list.appendChild(dragCard);
      }
    });
    board.addEventListener('drop', function (ev) {
      ev.preventDefault();
      if (!dragCard) return;
      postForm(
        board.getAttribute('data-layout-url'),
        { layout: JSON.stringify(collectLayout(board)) },
        csrf(board)
      ).catch(function (err) {
        window.alert(err.message || 'Не удалось сохранить порядок');
      });
    });

    board.addEventListener('change', function (ev) {
      var box = ev.target.closest('[data-catalog-publish]');
      if (!box) return;
      var card = box.closest('[data-slug]');
      if (!card) return;
      postForm(
        board.getAttribute('data-publish-url'),
        { page: card.getAttribute('data-slug'), published: box.checked ? '1' : '' },
        csrf(board)
      ).catch(function (err) {
        box.checked = !box.checked;
        window.alert(err.message || 'Не удалось изменить публикацию');
      });
    });
  }

  function boot() {
    document.querySelectorAll('[data-catalog-gallery]').forEach(bindGallery);
    document.querySelectorAll('[data-catalog-board]').forEach(bindBoard);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
  document.body && document.body.addEventListener('htmx:afterSwap', boot);
})();
