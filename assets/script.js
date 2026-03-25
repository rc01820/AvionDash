// AvionDash — Client Scripts

// ---- Live Clock ----
function updateClock() {
  const el = document.getElementById('liveClock');
  if (!el) return;
  const now = new Date();
  const pad = n => String(n).padStart(2, '0');
  el.textContent =
    `${now.getUTCFullYear()}-${pad(now.getUTCMonth()+1)}-${pad(now.getUTCDate())}` +
    ` ${pad(now.getUTCHours())}:${pad(now.getUTCMinutes())}:${pad(now.getUTCSeconds())} UTC`;
}
updateClock();
setInterval(updateClock, 1000);

// ---- Sidebar Toggle ----
function toggleSidebar() {
  document.getElementById('sidebar')?.classList.toggle('open');
}

// ---- Auto-refresh dashboard every 60s ----
if (window.location.pathname.endsWith('dashboard.php')) {
  setTimeout(() => window.location.reload(), 60000);
}

// ---- SQL Editor: Tab key support ----
const sqlEd = document.getElementById('sqlEditor');
if (sqlEd) {
  sqlEd.addEventListener('keydown', e => {
    if (e.key === 'Tab') {
      e.preventDefault();
      const s = sqlEd.selectionStart;
      const v = sqlEd.value;
      sqlEd.value = v.slice(0, s) + '  ' + v.slice(sqlEd.selectionEnd);
      sqlEd.selectionStart = sqlEd.selectionEnd = s + 2;
    }
    // Ctrl+Enter to submit form
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      sqlEd.closest('form')?.submit();
    }
  });
}
