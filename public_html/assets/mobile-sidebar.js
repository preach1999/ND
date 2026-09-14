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

  function addAnnualReportAction() {
    if (window.location.pathname !== '/reports') return;
    var exportButton = Array.from(document.querySelectorAll('button')).find(function (button) {
      return button.textContent.indexOf('Export CSV') !== -1;
    });
    if (!exportButton || document.querySelector('.annual-report-button')) return;
    var button = exportButton.cloneNode(true);
    button.className += ' annual-report-button';
    button.textContent = 'Annual report';
    button.addEventListener('click', showAnnualReport);
    exportButton.parentNode.insertBefore(button, exportButton);
  }

  async function showAnnualReport() {
    var current = new Date().getFullYear();
    var yearMatch = document.body.innerText.match(/\b(20\d{2}) report\b/i);
    var year = yearMatch ? yearMatch[1] : String(current);
    var results = await Promise.all(Array.from({ length: 12 }, function (_, index) {
      var month = String(index + 1).padStart(2, '0');
      return fetch('/api/finance.php?period=' + year + '-' + month, { cache: 'no-store' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .catch(function () { return null; });
    }));
    var rows = results.filter(Boolean);
    var totals = rows.reduce(function (sum, item) {
      sum.sales += item.summary.salesCentavos || 0;
      sum.purchases += item.summary.purchasesCentavos || 0;
      sum.expenses += item.summary.expensesCentavos || 0;
      sum.net += item.summary.netChangeCentavos || 0;
      return sum;
    }, { sales: 0, purchases: 0, expenses: 0, net: 0 });
    var money = function (value) { return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value / 100); };
    var overlay = document.createElement('div');
    overlay.className = 'annual-report-overlay';
    overlay.innerHTML = '<div class="annual-report-dialog" role="dialog" aria-modal="true" aria-labelledby="annual-report-title"><button class="annual-report-close" aria-label="Close annual report">&times;</button><h2 id="annual-report-title">Annual report ' + year + '</h2><div class="annual-report-summary"><div><span>Sales</span><strong>' + money(totals.sales) + '</strong></div><div><span>Purchases</span><strong>' + money(totals.purchases) + '</strong></div><div><span>Expenses</span><strong>' + money(totals.expenses) + '</strong></div><div><span>Net income</span><strong>' + money(totals.net) + '</strong></div></div><table><thead><tr><th>Month</th><th>Sales</th><th>Purchases</th><th>Expenses</th><th>Net income</th></tr></thead><tbody>' + rows.map(function (item) { return '<tr><td>' + item.period.label + '</td><td>' + money(item.summary.salesCentavos) + '</td><td>' + money(item.summary.purchasesCentavos) + '</td><td>' + money(item.summary.expensesCentavos) + '</td><td>' + money(item.summary.netChangeCentavos) + '</td></tr>'; }).join('') + '</tbody></table></div>';
    document.body.appendChild(overlay);
    var close = function () { overlay.remove(); };
    overlay.querySelector('.annual-report-close').addEventListener('click', close);
    overlay.addEventListener('click', function (event) { if (event.target === overlay) close(); });
  }
  document.addEventListener('click', function (event) {
    if (window.innerWidth < 768 && event.target.closest('[data-sidebar="menu-button"]')) {
      window.setTimeout(triggerSidebar, 0);
    }
  });
  new MutationObserver(function () { ensureCloseButton(); addAnnualReportAction(); }).observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('resize', ensureCloseButton);
  ensureCloseButton();
  addAnnualReportAction();
}());
