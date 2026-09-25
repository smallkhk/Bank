// Loaded synchronously in <head> so the saved theme applies before first paint (no white flash).
(function () {
  try {
    var t = localStorage.getItem('theme');
    if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
    if (localStorage.getItem('privacy') === '1') document.documentElement.classList.add('privacy-pending');
  } catch (e) {}
})();
