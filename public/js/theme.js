/*
 * Sidebar and account-menu behaviour for the wholesale-b2b theme (the same interactions as wholesale's app.js,
 * without jQuery). Everything here is an enhancement: every link is a real link, the group of the current page
 * is rendered open server-side, and a <noscript> rule opens every group when this script never runs.
 */
(function () {
    'use strict';

    var SIDEBAR_COLLAPSED_KEY = 'adminSidebarCollapsed';
    var COLLAPSED_CLASS = 'admin-sidebar-collapsed';
    var NAV_OPEN_CLASS = 'nav-open';
    var OPEN_CLASS = 'is-open';

    // Storage can throw (private mode, blocked site data); losing a remembered preference is never worth an error.
    function readPreference(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }

    function writePreference(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* preference only */ }
    }

    function setGroupOpen(group, isOpen) {
        group.classList.toggle(OPEN_CLASS, isOpen);
        var title = group.querySelector('.nav-group-title');
        if (title) { title.setAttribute('aria-expanded', String(isOpen)); }
    }

    function closeOtherGroups(except) {
        document.querySelectorAll('.nav-group.' + OPEN_CLASS).forEach(function (group) {
            if (group !== except) { setGroupOpen(group, false); }
        });
    }

    function initNavGroups() {
        document.querySelectorAll('.nav-group-title').forEach(function (title) {
            title.addEventListener('click', function () {
                var group = title.closest('.nav-group');
                var isOpen = !group.classList.contains(OPEN_CLASS);
                if (isOpen) { closeOtherGroups(group); }
                setGroupOpen(group, isOpen);
            });
        });
    }

    function initSidebarCollapse() {
        var toggle = document.querySelector('.sidebar-collapse-toggle');
        if (!toggle) { return; }

        if (readPreference(SIDEBAR_COLLAPSED_KEY) === '1') {
            document.body.classList.add(COLLAPSED_CLASS);
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function () {
            var isCollapsed = document.body.classList.toggle(COLLAPSED_CLASS);
            toggle.setAttribute('aria-expanded', String(!isCollapsed));
            writePreference(SIDEBAR_COLLAPSED_KEY, isCollapsed ? '1' : '0');
            if (isCollapsed) { closeOtherGroups(null); }
        });
    }

    function initMobileNav() {
        var toggle = document.querySelector('.nav-toggle');
        if (!toggle) { return; }

        function setNavOpen(isOpen) {
            document.body.classList.toggle(NAV_OPEN_CLASS, isOpen);
            toggle.setAttribute('aria-expanded', String(isOpen));
        }

        toggle.addEventListener('click', function () {
            setNavOpen(!document.body.classList.contains(NAV_OPEN_CLASS));
        });

        document.addEventListener('click', function (event) {
            if (document.body.classList.contains(NAV_OPEN_CLASS) && !event.target.closest('.topbar')) {
                setNavOpen(false);
            }
        });
    }

    function initUserMenus() {
        function closeAll(except) {
            document.querySelectorAll('.user-menu.' + OPEN_CLASS).forEach(function (menu) {
                if (menu === except) { return; }
                menu.classList.remove(OPEN_CLASS);
                var toggle = menu.querySelector('.user-menu-toggle');
                if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
            });
        }

        document.querySelectorAll('.user-menu-toggle').forEach(function (toggle) {
            toggle.addEventListener('click', function (event) {
                event.stopPropagation();
                var menu = toggle.closest('.user-menu');
                var isOpen = !menu.classList.contains(OPEN_CLASS);
                closeAll(menu);
                menu.classList.toggle(OPEN_CLASS, isOpen);
                toggle.setAttribute('aria-expanded', String(isOpen));
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
        initNavGroups();
        initSidebarCollapse();
        initMobileNav();
        initUserMenus();
    });
})();
