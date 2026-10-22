// Caminho: /assets/js/app.js
document.addEventListener('DOMContentLoaded', function () {
    // 1. Recolher / expandir a sidebar (desktop) — estado salvo no navegador
    var btnCollapse = document.getElementById('sbCollapse');
    if (btnCollapse) {
        btnCollapse.addEventListener('click', function () {
            var collapsed = document.documentElement.classList.toggle('sb-collapsed');
            try { localStorage.setItem('sbCollapsed', collapsed ? '1' : '0'); } catch (e) {}
        });
    }

    // 2. Mantém a aba ativa visível na barra de abas (útil no celular)
    var abaAtiva = document.querySelector('.mod-tab.on');
    if (abaAtiva && abaAtiva.scrollIntoView) {
        abaAtiva.scrollIntoView({ inline: 'center', block: 'nearest' });
    }

    // 3. Menu Mobile (botão "Menu" na barra inferior)
    var sidebar = document.getElementById('appSidebar');
    var backdrop = document.getElementById('sbBackdrop');
    var btnMobileMenu = document.getElementById('btnMobileMenu');

    function toggleMobileMenu() {
        if (!sidebar) return;
        sidebar.classList.toggle('mobile-open');
        if (backdrop) backdrop.classList.toggle('mobile-open');
    }

    if (btnMobileMenu) btnMobileMenu.addEventListener('click', toggleMobileMenu);
    if (backdrop) backdrop.addEventListener('click', toggleMobileMenu);

    // 4. Auto-fechar Toasts após 4 segundos
    var toast = document.getElementById('appToast');
    if (toast) {
        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity .3s';
            setTimeout(function () { toast.remove(); }, 300);
        }, 4000);
    }

    // 5. Máscaras de Formulário (CPF/CNPJ, Telefone, CEP, Moeda)
    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el.dataset || !el.dataset.mask) return;
        var v = el.value.replace(/\D/g, '');

        if (el.dataset.mask === 'cpfcnpj') {
            if (v.length <= 11) {
                v = v.replace(/(\d{3})(\d)/, '$1.$2')
                     .replace(/(\d{3})(\d)/, '$1.$2')
                     .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            } else {
                v = v.substring(0, 14)
                     .replace(/^(\d{2})(\d)/, '$1.$2')
                     .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
                     .replace(/\.(\d{3})(\d)/, '.$1/$2')
                     .replace(/(\d{4})(\d)/, '$1-$2');
            }
            el.value = v;
        } else if (el.dataset.mask === 'phone') {
            v = v.substring(0, 11);
            if (v.length > 10) {
                v = v.replace(/^(\d{2})(\d{5})(\d{4}).*/, '($1) $2-$3');
            } else {
                v = v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
            }
            el.value = v;
        } else if (el.dataset.mask === 'cep') {
            v = v.substring(0, 8).replace(/^(\d{5})(\d)/, '$1-$2');
            el.value = v;
        } else if (el.dataset.mask === 'money') {
            if (v === '') { el.value = '0,00'; return; }
            var num = (parseInt(v, 10) / 100).toFixed(2);
            el.value = num.replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
    });

    // 6. Registro do Service Worker (PWA)
    if ('serviceWorker' in navigator && window.LIVREOS_BASE_URL) {
        navigator.serviceWorker.register(window.LIVREOS_BASE_URL + '/service-worker.js').catch(function () {});
    }
});