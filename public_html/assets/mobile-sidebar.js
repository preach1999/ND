(function () {
  'use strict';
  function triggerSidebar() {
    var trigger = document.querySelector('[data-slot="sidebar-trigger"]');
    if (trigger) trigger.click();
  }
  function ensureCloseButton() {
    if (window.innerWidth >= 768) return;
    var sidebar = document.querySelector('[data-sidebar="sidebar"][data-mobile="true"]');
    if (!sidebar) return;
    var header = sidebar.querySelector('[data-slot="sidebar-header"]');
    if (!header || header.querySelector('.mobile-sidebar-close')) return;
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'mobile-sidebar-close';
    button.setAttribute('aria-label', 'Close menu');
    button.innerHTML = '<span aria-hidden="true">&times;</span>';
    button.addEventListener('click', triggerSidebar);
    header.appendChild(button);
  }
  document.addEventListener('click', function (event) {
    if (window.innerWidth < 768 && event.target.closest('[data-sidebar="menu-button"]')) {
      window.setTimeout(triggerSidebar, 0);
    }
  });
  new MutationObserver(ensureCloseButton).observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('resize', ensureCloseButton);
  ensureCloseButton();
}());
