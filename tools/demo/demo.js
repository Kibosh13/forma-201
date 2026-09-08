(() => {
  const message = 'Это демонстрация: отправка заявок, поиск и серверная фильтрация отключены.';
  function notify() {
    let notice = document.getElementById('demo-message');
    if (!notice) {
      notice = document.createElement('div');
      notice.id = 'demo-message';
      notice.setAttribute('role', 'status');
      document.body.appendChild(notice);
    }
    notice.textContent = message;
    notice.hidden = false;
    clearTimeout(notice.timer);
    notice.timer = setTimeout(() => { notice.hidden = true; }, 6000);
  }
  document.addEventListener('submit', event => {
    event.preventDefault();
    event.stopImmediatePropagation();
    notify();
  }, true);
  document.addEventListener('click', event => {
    const control = event.target.closest('button, input[type="submit"], .catalog-cart-add');
    if (control && (control.matches('.catalog-cart-add, input[type="submit"]') || (control.form && control.type === 'submit'))) {
      event.preventDefault();
      event.stopImmediatePropagation();
      notify();
    }
  }, true);
  document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('banner_cookies')?.remove();
    document.querySelectorAll('form.smartfilter input, form.smartfilter select, form.smartfilter button, .bx-filter input, .bx-filter select').forEach(control => {
      control.disabled = true;
      control.title = message;
    });
    // Search suggestions in the original Bitrix widget require its backend.
    document.querySelectorAll('input[name="q"]').forEach(input => {
      input.readOnly = true;
      input.addEventListener('click', notify);
      input.placeholder = 'Поиск недоступен в демоверсии';
    });
  });
})();
