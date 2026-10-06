// Runs before the first paint (loaded without defer), so a sidebar the user collapsed is drawn collapsed straight
// away instead of drawn open and then snapped shut. `js` lets the CSS show controls that only work with app.js.
// Storage can throw (private mode, blocked site data): fall back to open. A file rather than an inline script so
// pages with a strict Content-Security-Policy (the Password Manager, ADR-092) can run it.
document.documentElement.classList.add('js');
try { if (localStorage.getItem('sidebarCollapsed') === '1') { document.documentElement.classList.add('sidebar-collapsed'); } } catch (e) {}
