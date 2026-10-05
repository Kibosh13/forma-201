document.querySelectorAll('[data-attributes-editor]').forEach((editor) => {
    const rows = editor.querySelector('[data-attribute-rows]');
    const addButton = editor.querySelector('[data-add-attribute]');

    const addRow = () => {
        const row = document.createElement('div');
        row.className = 'attribute-row';
        row.innerHTML = '<input name="attribute_name[]" placeholder="Например: Длина"><input name="attribute_value[]" placeholder="Например: 3000 мм"><button class="attribute-remove" type="button" data-remove-attribute aria-label="Удалить характеристику">Удалить</button>';
        rows.appendChild(row);
        row.querySelector('input').focus();
    };

    addButton?.addEventListener('click', addRow);
    rows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-attribute]');
        if (!button) return;
        const row = button.closest('.attribute-row');
        if (rows.querySelectorAll('.attribute-row').length === 1) {
            row.querySelectorAll('input').forEach((input) => { input.value = ''; });
            row.querySelector('input').focus();
            return;
        }
        row.remove();
    });
});

document.querySelectorAll('[data-rich-editor]').forEach((wrapper) => {
    const editor = wrapper.querySelector('[data-rich-content]');
    const source = wrapper.querySelector('[data-rich-source]');
    editor.innerHTML = source.value;

    const sync = () => { source.value = editor.innerHTML; };
    editor.addEventListener('input', sync);
    wrapper.closest('form')?.addEventListener('submit', sync);

    wrapper.querySelectorAll('[data-rich-command]').forEach((button) => {
        button.addEventListener('click', () => {
            editor.focus();
            document.execCommand(button.dataset.richCommand, false, null);
            sync();
        });
    });
    wrapper.querySelector('[data-rich-block]')?.addEventListener('change', (event) => {
        editor.focus();
        document.execCommand('formatBlock', false, event.target.value);
        sync();
    });
    wrapper.querySelector('[data-rich-link]')?.addEventListener('click', () => {
        const url = window.prompt('Введите адрес ссылки', 'https://');
        if (!url) return;
        editor.focus();
        document.execCommand('createLink', false, url);
        sync();
    });
});

document.querySelectorAll('[data-reviews-editor]').forEach((editor) => {
    const rows = editor.querySelector('[data-review-rows]');
    const addButton = editor.querySelector('[data-add-review]');
    let counter = 0;

    addButton?.addEventListener('click', () => {
        counter += 1;
        const key = `new${Date.now()}_${counter}`;
        const row = document.createElement('section');
        row.className = 'review-editor-row';
        row.dataset.reviewRow = '';
        row.innerHTML = `
            <div class="review-editor-row__head"><strong>Новый отзыв</strong><label class="review-delete"><input type="checkbox" name="review_delete[${key}]" value="1"> Удалить отзыв</label></div>
            <div class="review-editor-grid">
                <div class="field"><label>Автор</label><input name="review_author[${key}]" placeholder="Имя автора"></div>
                <div class="field"><label>Оценка</label><select name="review_rating[${key}]"><option value="5">5 из 5</option><option value="4">4 из 5</option><option value="3">3 из 5</option><option value="2">2 из 5</option><option value="1">1 из 5</option></select></div>
                <div class="field full"><label>Текст</label><textarea name="review_text[${key}]" placeholder="Текст отзыва"></textarea></div>
                <div class="field full"><label>Фотографии отзыва</label><input type="file" name="review_upload_${key}[]" accept="image/*" multiple><span class="help">Можно добавить несколько фотографий.</span></div>
            </div>`;
        rows.appendChild(row);
        row.querySelector('input')?.focus();
    });
});
