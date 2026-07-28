import './bootstrap';
import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

document.addEventListener('DOMContentLoaded', () => {
    const nav = document.querySelector('.site-header');

    const syncHeader = () => {
        nav?.classList.toggle('is-scrolled', window.scrollY > 10);
    };

    syncHeader();
    window.addEventListener('scroll', syncHeader, { passive: true });

    document.querySelectorAll('.dropdown-menu a').forEach((link) => {
        link.addEventListener('click', () => {
            const openDropdown = link.closest('.dropdown');
            const toggle = openDropdown?.querySelector('[data-bs-toggle="dropdown"]');
            if (toggle) {
                bootstrap.Dropdown.getOrCreateInstance(toggle).hide();
            }
        });
    });
});
