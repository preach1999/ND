(function () {
  'use strict';
  var annualRequest = 0;

  function triggerSidebar() {
    var trigger = document.querySelector('[data-slot="sidebar-trigger"]');
    if (trigger) trigger.click();
  }

  function ensureCloseButton() {
    if (window.innerWidth >= 768) return;
    var sidebar = document.querySelector('[data-sidebar="sidebar"][data-mobile="true"]');
    var header = sidebar && sidebar.querySelector('[data-slot="sidebar-header"]');
    if (!header || header.querySelector('.mobile-sidebar-close')) return;
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'mobile-sidebar-close';
    button.setAttribute('aria-label', 'Close menu');
    button.innerHTML = '<span aria-hidden="true">&times;</span>';
    button.addEventListener('click', triggerSidebar);
    header.appendChild(button);
  }

  function reportRoot() {
    return document.querySelector('main[data-slot="sidebar-inset"] > main') || document.querySelector('main.mx-auto');
  }

  function selectedReportYear() {
    var root = reportRoot();
    var heading = root && root.querySelector('h1');
    var match = heading && heading.textContent.match(/\b(20\d{2})\b/);
    return match ? Number(match[1]) : new Date().getFullYear();
  }

  function addAnnualReportAction() {
    if (window.location.pathname.replace(/\/+$/, '') !== '/reports') return;
    var root = reportRoot();
    if (!root || root.querySelector('.annual-page') || root.querySelector('.annual-report-button')) return;
    var exportButton = Array.from(root.querySelectorAll('button')).find(function (button) {
      return button.textContent.trim().indexOf('Export CSV') !== -1;
    });
    if (!exportButton) return;
    var button = document.createElement('button');
    button.type = 'button';
    button.className = exportButton.className + ' annual-report-button';
    button.textContent = 'Annual report';
    button.addEventListener('click', function () { renderAnnualReport(selectedReportYear()); });
    exportButton.parentNode.insertBefore(button, exportButton);
  }

  function emptyMonth(year, monthIndex) {
    var key = year + '-' + String(monthIndex + 1).padStart(2, '0');
    return {
      period: { period_key: key, label: new Date(year, monthIndex, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' }) },
      summary: { salesCentavos: 0, purchasesCentavos: 0, expensesCentavos: 0, netChangeCentavos: 0 }
    };
  }

  async function loadYear(year) {
    return Promise.all(Array.from({ length: 12 }, async function (_, monthIndex) {
      var fallback = emptyMonth(year, monthIndex);
      try {
        var response = await fetch('/api/finance.php?period=' + fallback.period.period_key, { cache: 'no-store' });
        if (!response.ok) return fallback;
        var data = await response.json();
        return data && data.period && data.period.period_key === fallback.period.period_key ? data : fallback;
      } catch (_) {
        return fallback;
      }
    }));
  }

  function money(centavos) {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2 }).format((Number(centavos) || 0) / 100);
  }

  function csvValue(value) {
    return '"' + String(value).replaceAll('"', '""') + '"';
  }

  function exportAnnualCsv(year, rows) {
    var lines = [['Month', 'Sales', 'Purchases', 'Operating expenses', 'Net income']];
    rows.forEach(function (item) {
      lines.push([item.period.label, ((item.summary.salesCentavos || 0) / 100).toFixed(2), ((item.summary.purchasesCentavos || 0) / 100).toFixed(2), ((item.summary.expensesCentavos || 0) / 100).toFixed(2), ((item.summary.netChangeCentavos || 0) / 100).toFixed(2)]);
    });
    var csv = lines.map(function (row) { return row.map(csvValue).join(','); }).join('\r\n');
    var url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    var link = document.createElement('a');
    link.href = url;
    link.download = 'ND-Financial-Annual-' + year + '.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(function () { URL.revokeObjectURL(url); }, 0);
  }

  function yearOptions(selectedYear) {
    var current = new Date().getFullYear();
    var first = Math.min(selectedYear - 2, current - 2);
    var last = Math.max(selectedYear + 1, current + 1);
    var options = '';
    for (var year = last; year >= first; year -= 1) {
      options += '<option value="' + year + '"' + (year === selectedYear ? ' selected' : '') + '>' + year + '</option>';
    }
    return options;
  }

  function annualMarkup(year, rows) {
    var totals = rows.reduce(function (sum, item) {
      sum.sales += item.summary.salesCentavos || 0;
      sum.purchases += item.summary.purchasesCentavos || 0;
      sum.expenses += item.summary.expensesCentavos || 0;
      sum.net += item.summary.netChangeCentavos || 0;
      return sum;
    }, { sales: 0, purchases: 0, expenses: 0, net: 0 });
    var tableRows = rows.map(function (item) {
      return '<tr><td>' + item.period.label + '</td><td>' + money(item.summary.salesCentavos) + '</td><td>' + money(item.summary.purchasesCentavos) + '</td><td>' + money(item.summary.expensesCentavos) + '</td><td>' + money(item.summary.netChangeCentavos) + '</td></tr>';
    }).join('');
    return '<section class="annual-page"><div class="annual-page-header"><div><p class="annual-eyebrow">Financial report</p><h1>' + year + ' annual report</h1><p class="annual-description">Annual profitability and monthly performance from active transactions.</p></div><div class="annual-controls"><label for="annual-year">Year</label><select id="annual-year">' + yearOptions(year) + '</select><button type="button" class="annual-print">Print</button><button type="button" class="annual-back">Monthly report</button><button type="button" class="annual-export">Export CSV</button></div></div><div class="annual-report-grid"><section class="annual-profit-card"><h2>Profit summary</h2><div class="annual-summary-row"><span>Total sales</span><strong class="annual-positive">' + money(totals.sales) + '</strong></div><div class="annual-summary-row"><span>Purchases</span><strong class="annual-negative">' + money(totals.purchases) + '</strong></div><div class="annual-summary-row"><span>Operating expenses</span><strong class="annual-negative">' + money(totals.expenses) + '</strong></div><div class="annual-summary-total"><span>Net income</span><strong>' + money(totals.net) + '</strong></div></section></div><section class="annual-table-card"><h2>Monthly performance</h2><p>Annual totals by month</p><div class="annual-table-wrap"><table><thead><tr><th>Month</th><th>Sales</th><th>Purchases</th><th>Expenses</th><th>Net income</th></tr></thead><tbody>' + tableRows + '</tbody></table></div></section></section>';
  }

  async function renderAnnualReport(year) {
    var root = reportRoot();
    if (!root) return;
    var request = ++annualRequest;
    root.innerHTML = '<div class="annual-loading" role="status">Loading ' + year + ' annual report...</div>';
    var rows = await loadYear(year);
    if (request !== annualRequest) return;
    root.innerHTML = annualMarkup(year, rows);
    root.querySelector('#annual-year').addEventListener('change', function (event) { renderAnnualReport(Number(event.target.value)); });
    root.querySelector('.annual-print').addEventListener('click', function () { window.print(); });
    root.querySelector('.annual-back').addEventListener('click', function () { window.location.reload(); });
    root.querySelector('.annual-export').addEventListener('click', function () { exportAnnualCsv(year, rows); });
  }

  document.addEventListener('click', function (event) {
    if (window.innerWidth < 768 && event.target.closest('[data-sidebar="menu-button"]')) window.setTimeout(triggerSidebar, 0);
  });
  new MutationObserver(function () { ensureCloseButton(); addAnnualReportAction(); }).observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('resize', ensureCloseButton);
  ensureCloseButton();
  addAnnualReportAction();
}());
