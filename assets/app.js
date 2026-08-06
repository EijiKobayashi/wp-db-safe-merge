const isFileDrag = (event) => Array.from(event.dataTransfer?.types || []).includes('Files');

// Prevent the browser from navigating to a dropped SQL file anywhere in the app.
['dragover', 'drop'].forEach((eventName) => {
  window.addEventListener(eventName, (event) => {
    if (isFileDrag(event)) event.preventDefault();
  });
});

document.querySelectorAll('[data-dropzone]').forEach((zone) => {
  const input = zone.querySelector('input[type=file]');
  const copy = zone.querySelector('[data-file-copy]');
  const showFile = (file) => {
    if (!file) return;
    copy.textContent = file.name;
    zone.classList.add('selected');
    zone.classList.remove('dragover');
  };

  input.addEventListener('change', () => showFile(input.files[0]));
  zone.addEventListener('dragenter', (event) => {
    if (!isFileDrag(event)) return;
    event.preventDefault();
    zone.classList.add('dragover');
  });
  zone.addEventListener('dragover', (event) => {
    if (!isFileDrag(event)) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = 'copy';
  });
  zone.addEventListener('dragleave', (event) => {
    if (!zone.contains(event.relatedTarget)) zone.classList.remove('dragover');
  });
  zone.addEventListener('drop', (event) => {
    event.preventDefault();
    event.stopPropagation();
    const file = event.dataTransfer?.files?.[0];
    if (!file) return;
    if (!file.name.toLowerCase().endsWith('.sql')) {
      copy.textContent = 'SQLファイル（.sql）を選択してください';
      zone.classList.remove('selected', 'dragover');
      return;
    }
    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
    showFile(file);
  });
});

document.querySelectorAll('[data-toggle]').forEach((button) => {
  button.addEventListener('click', () => {
    const target = document.getElementById(button.dataset.toggle);
    target.hidden = !target.hidden;
    button.textContent = target.hidden ? '詳細を比較' : '詳細を閉じる';
  });
});

document.querySelectorAll('[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});

document.querySelectorAll('[data-upload-form]').forEach((form) => {
  form.addEventListener('submit', () => {
    const button = form.querySelector('[data-submit]');
    button.disabled = true;
    button.textContent = 'SQLを解析しています…';
  });
});

document.querySelectorAll('[data-progress-page]').forEach((page) => {
  const message = page.querySelector('[data-progress-message]');
  const bar = page.querySelector('[data-progress-bar]');
  const track = page.querySelector('[role=progressbar]');
  const value = page.querySelector('[data-progress-value]');
  const error = page.querySelector('[data-progress-error]');
  let timer;

  const poll = async () => {
    try {
      const response = await fetch(page.dataset.statusUrl, { cache: 'no-store', credentials: 'same-origin' });
      if (!response.ok) throw new Error('進捗を取得できませんでした。');
      const state = await response.json();
      const percent = Math.max(0, Math.min(100, Number(state.progress) || 0));
      message.textContent = state.message;
      bar.style.width = `${percent}%`;
      value.textContent = `${percent}%`;
      track.setAttribute('aria-valuenow', String(percent));
      if (state.status === page.dataset.completeStatus) {
        window.location.assign(page.dataset.completeUrl);
        return;
      }
      if (state.status === 'failed') {
        error.hidden = false;
        error.querySelector('p').textContent = state.message;
        return;
      }
      timer = window.setTimeout(poll, 2000);
    } catch (exception) {
      message.textContent = '接続を再確認しています…';
      timer = window.setTimeout(poll, 4000);
    }
  };
  poll();
  window.addEventListener('pagehide', () => window.clearTimeout(timer), { once: true });
});

document.querySelectorAll('#bulk-form').forEach((form) => {
  const checkboxes = Array.from(document.querySelectorAll('[data-bulk-checkbox]'));
  const selectAll = form.querySelector('[data-select-all]');
  const count = form.querySelector('[data-selection-count]');
  const refresh = () => {
    const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
    count.textContent = `${selected}件選択`;
    selectAll.textContent = selected === checkboxes.length && checkboxes.length > 0 ? '選択をすべて解除' : 'このページをすべて選択';
  };
  checkboxes.forEach((checkbox) => checkbox.addEventListener('change', refresh));
  selectAll.addEventListener('click', () => {
    const shouldSelect = !checkboxes.every((checkbox) => checkbox.checked);
    checkboxes.forEach((checkbox) => { checkbox.checked = shouldSelect; });
    refresh();
  });
  form.addEventListener('submit', (event) => {
    if (!checkboxes.some((checkbox) => checkbox.checked)) {
      event.preventDefault();
      window.alert('一括変更する項目を選択してください。');
    }
  });
  refresh();
});

document.querySelectorAll('[data-auto-submit]').forEach((select) => {
  select.addEventListener('change', () => select.form.requestSubmit());
});
