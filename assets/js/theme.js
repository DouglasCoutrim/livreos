// Caminho: /assets/js/theme.js
(function () {
    var root = document.documentElement;
    var currentTheme = null;

    try {
        currentTheme = localStorage.getItem('theme');
    } catch (e) {}

    if (!currentTheme) {
        currentTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    root.setAttribute('data-theme', currentTheme);

    window.addEventListener('DOMContentLoaded', function () {
        var btnToggle = document.getElementById('tg');
        if (btnToggle) {
            btnToggle.addEventListener('click', function () {
                currentTheme = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', currentTheme);
                try {
                    localStorage.setItem('theme', currentTheme);
                } catch (e) {}
                window.dispatchEvent(new CustomEvent('livreos:themeChanged', { detail: { theme: currentTheme } }));
            });
        }
    });
})();