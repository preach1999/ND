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
    button.addEventListener('click', renderAnnualReport);
    exportButton.parentNode.insertBefore(button, exportButton);
  }

  async function renderAnnualReport(requestedYear) {
    var main = document.querySelector('main');
    if (!main) return;
    var year = requestedYear || String(new Date().getFullYear());
    var yearMatch = document.body.innerText.match(/\b(20\d{2}) report\b/i);
    if (yearMatch && !requestedYear) year = yearMatch[1];
    var load = async function (selectedYear) {
      var rows = (await Promise.all(Array.from({ length: 12 }, function (_, index) {
        var month = String(index + 1).padStart(2, '0');
        return fetch('/api/finance.php?period=' + selectedYear + '-' + month, { cache: 'no-store' }).then(function (response) { return response.ok ? response.json() : null; }).catch(function () { return null; });
      }))).filter(Boolean);
      var totals = rows.reduce(function (sum, item) { sum.sales += item.summary.salesCentavos || 0; sum.purchases += item.summary.purchasesCentavos || 0; sum.expenses += item.summary.expensesCentavos || 0; sum.net += item.summary.netChangeCentavos || 0; return sum; }, { sales: 0, purchases: 0, expenses: 0, net: 0 });
      var money = function (value) { return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value / 100); };
      main.innerHTML = '<section class="annual-page"><div class="annual-page-header"><div><p class="annual-eyebrow">Financial report</p><h1>' + selectedYear + ' annual report</h1><p class="annual-description">Annual profitability summary from active transactions only.</p></div><div class="annual-controls"><label for="annual-year">Year</label><select id="annual-year"><option>' + selectedYear + '</option><option>' + (Number(selectedYear) - 1) + '</option><option>' + (Number(selectedYear) + 1) + '</option></select><button type="button" class="annual-back">Back to monthly report</button><button type="button" class="annual-export">Export CSV</button></div></div><div class="annual-report-grid"><section class="annual-profit-card"><h2>Profit summary</h2><div class="annual-summary-row"><span>Total Sales</span><strong class="annual-positive">+' + money(totals.sales) + '</strong></div><div class="annual-summary-row"><span>Purchases</span><strong class="annual-negative">' + money(totals.purchases) + '</strong></div><div class="annual-summary-row"><span>Operating expenses</span><strong class="annual-negative">' + money(totals.expenses) + '</strong></div><div class="annual-summary-total"><span>Net income</span><strong>' + money(totals.net) + '</strong></div></section></div><section class="annual-table-card"><h2>Monthly performance</h2><p>Annual totals by month</p><div class="annual-table-wrap"><table><thead><tr><th>Month</th><th>Sales</th><th>Purchases</th><th>Expenses</th><th>Net income</th></tr></thead><tbody>' + rows.map(function (item) { return '<tr><td>' + item.period.label + '</td><td>' + money(item.summary.salesCentavos) + '</td><td>' + money(item.summary.purchasesCentavos) + '</td><td>' + money(item.summary.expensesCentavos) + '</td><td>' + money(item.summary.netChangeCentavos) + '</td></tr>'; }).join('') + '</tbody></table></div></section></section>';
      main.querySelector('#annual-year').addEventListener('change', function (event) { renderAnnualReportYear(event.target.value); });
      main.querySelector('.annual-back').addEventListener('click', function () { window.location.reload(); });
      main.querySelector('.annual-export').addEventListener('click', function () { var csv = [['Month','Sales','Purchases','Operating expenses','Net income']].concat(rows.map(function (item) { return [item.period.label, money(item.summary.salesCentavos), money(item.summary.purchasesCentavos), money(item.summary.expensesCentavos), money(item.summary.netChangeCentavos)]; })).map(function (row) { return row.map(function (value) { return '"' + String(value).replaceAll('"', '""') + '"'; }).join(','); }).join('\n'); var link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); link.download = 'ND-Financial-Annual-' + selectedYear + '.csv'; link.click(); URL.revokeObjectURL(link.href); });
    };
    window.renderAnnualReportYear = load;
    await load(year);
  }

  async function renderAnnualReportYear(year) {
    await renderAnnualReport(year);
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
