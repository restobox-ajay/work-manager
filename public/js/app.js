/*
 * Shell behaviour for the Maxeme Auto layout. Everything here is an enhancement: every link is a real link, and
 * the sidebar groups are <details> elements the browser opens and closes on its own.
 *
 * The collapsed state lives on <html> and is restored by the inline script in base.html.twig before the first
 * paint; this file only flips it on click and remembers the choice.
 */
(function () {
    'use strict';

    var COLLAPSED_KEY = 'sidebarCollapsed';
    var COLLAPSED_CLASS = 'sidebar-collapsed';
    var NAV_OPEN_CLASS = 'nav-open';
    var MENU_OPEN_CLASS = 'show';
    var root = document.documentElement;

    // Storage can throw (private mode, blocked site data); losing a remembered preference is never worth an error.
    function writePreference(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* preference only */ }
    }

    function initSidebarCollapse() {
        var toggle = document.querySelector('.sidebar-collapse-toggle');
        if (!toggle) { return; }

        function sync() {
            var isCollapsed = root.classList.contains(COLLAPSED_CLASS);
            toggle.setAttribute('aria-expanded', String(!isCollapsed));
            toggle.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
        }

        toggle.addEventListener('click', function () {
            writePreference(COLLAPSED_KEY, root.classList.toggle(COLLAPSED_CLASS) ? '1' : '0');
            sync();
        });

        // A collapsed sidebar shows icons only: choosing a group expands the sidebar so its links can be seen.
        document.querySelectorAll('.sidebar .nav-group > summary').forEach(function (summary) {
            summary.addEventListener('click', function (event) {
                if (!root.classList.contains(COLLAPSED_CLASS)) { return; }
                event.preventDefault();
                root.classList.remove(COLLAPSED_CLASS);
                writePreference(COLLAPSED_KEY, '0');
                summary.parentElement.open = true;
                sync();
            });
        });

        sync();
    }

    function initMobileNav() {
        var toggle = document.querySelector('.nav-toggle');
        if (!toggle) { return; }

        function setNavOpen(isOpen) {
            root.classList.toggle(NAV_OPEN_CLASS, isOpen);
            toggle.setAttribute('aria-expanded', String(isOpen));
        }

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            setNavOpen(!root.classList.contains(NAV_OPEN_CLASS));
        });

        document.addEventListener('click', function (event) {
            if (root.classList.contains(NAV_OPEN_CLASS) && !event.target.closest('.sidebar')) {
                setNavOpen(false);
            }
        });
    }

    function initUserMenus() {
        function closeAll(except) {
            document.querySelectorAll('.user-menu .dropdown.' + MENU_OPEN_CLASS).forEach(function (dropdown) {
                if (dropdown.parentElement === except) { return; }
                dropdown.classList.remove(MENU_OPEN_CLASS);
                var toggle = dropdown.parentElement.querySelector('.user-menu-toggle');
                if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
            });
        }

        document.querySelectorAll('.user-menu-toggle').forEach(function (toggle) {
            toggle.addEventListener('click', function (event) {
                event.stopPropagation();
                var menu = toggle.closest('.user-menu');
                var dropdown = menu.querySelector('.dropdown');
                closeAll(menu);
                toggle.setAttribute('aria-expanded', String(dropdown.classList.toggle(MENU_OPEN_CLASS)));
            });
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.user-menu')) { closeAll(null); }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { closeAll(null); }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initSidebarCollapse();
        initMobileNav();
        initUserMenus();
    });
})();
