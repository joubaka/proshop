/* Bootstrap 5 behavior with the existing AdminLTE/Bootstrap 3 presentation. */
(function ($, bs) {
    'use strict';
    const attributes = ['toggle', 'dismiss', 'target', 'backdrop', 'keyboard', 'placement', 'html', 'content', 'container', 'trigger', 'parent'];
    function translate(element) {
        if (!element || !element.getAttribute) return;
        attributes.forEach(name => {
            const value = element.getAttribute('data-' + name);
            if (value !== null) element.setAttribute('data-bs-' + name, value);
        });
        if (['tab', 'pill'].includes(element.getAttribute('data-toggle'))) {
            // Bootstrap 3 marks the enclosing li active; Bootstrap 5 marks its link.
            // Translate the whole group for AJAX-inserted tabs before the data API runs.
            element.closest('.nav, [role="tablist"]')?.querySelectorAll('[data-toggle="tab"], [data-toggle="pill"]').forEach(tab => {
                tab.setAttribute('data-bs-toggle', tab.getAttribute('data-toggle'));
                const item = tab.closest('li');
                if (item) tab.classList.toggle('active', item.classList.contains('active'));
            });
        }
    }
    ['click', 'mouseover', 'focusin'].forEach(event => document.addEventListener(event, e => {
        translate(e.target.closest?.('[data-toggle], [data-dismiss], [data-target]'));
    }, true));

    function install() {
        document.querySelectorAll('[data-toggle], [data-dismiss], [data-target]').forEach(translate);
        const components = { modal: bs.Modal, dropdown: bs.Dropdown, collapse: bs.Collapse, tab: bs.Tab, tooltip: bs.Tooltip, popover: bs.Popover, alert: bs.Alert };
        Object.entries(components).forEach(([name, Component]) => {
            const plugin = function (option) {
                return this.each(function () {
                    translate(this);
                    const config = typeof option === 'object' && option ? { ...option } : {};
                    if (name === 'collapse') {
                        if (this.classList.contains('in')) this.classList.add('show');
                        config.toggle = typeof option !== 'string';
                    }
                    if (name === 'tooltip' || name === 'popover') {
                        config.sanitize = true;
                        config.sanitizeFn = window.ProshopSanitize;
                        const placement = config.placement || this.getAttribute('data-placement');
                        if (typeof placement === 'string') config.placement = placement.replace(/^auto\s*/, '') || 'top';
                        config.template = name === 'tooltip'
                            ? '<div class="tooltip" role="tooltip"><div class="tooltip-arrow"></div><div class="tooltip-inner"></div></div>'
                            : '<div class="popover" role="tooltip"><div class="popover-arrow"></div><h3 class="popover-header"></h3><div class="popover-body"></div></div>';
                    }
                    const instance = Component.getOrCreateInstance(this, config);
                    $(this).data('bs.' + name, instance);
                    if (typeof option === 'string') {
                        const method = option === 'destroy' ? 'dispose' : option;
                        if (typeof instance[method] !== 'function') throw new Error('Unsupported Bootstrap method: ' + name + '.' + method);
                        instance[method]();
                    } else if (name === 'modal' && config.show !== false) instance.show();
                });
            };
            plugin.Constructor = Component;
            $.fn[name] = plugin;
        });
        $.fn.button = function (action) {
            return this.each(function () {
                const button = $(this);
                if (action === 'loading') {
                    if (!button.data('original-label')) button.data('original-label', button.html());
                    button.prop('disabled', true).text(button.data('loading-text') || 'Loading…');
                } else if (action === 'reset') {
                    button.prop('disabled', false);
                    if (button.data('original-label')) button.html(button.data('original-label'));
                } else bs.Button.getOrCreateInstance(this).toggle();
            });
        };
    }
    $(document).on('show.bs.modal show.bs.collapse', '.modal, .collapse', function () { $(this).addClass('in'); });
    $(document).on('hidden.bs.modal hidden.bs.collapse', '.modal, .collapse', function () { $(this).removeClass('in'); });
    $(document).on('shown.bs.dropdown', '[data-bs-toggle="dropdown"]', function () { $(this).parent().addClass('open'); });
    $(document).on('hidden.bs.dropdown', '[data-bs-toggle="dropdown"]', function () { $(this).parent().removeClass('open'); });
    $(document).on('shown.bs.tab', '[data-bs-toggle="tab"], [data-bs-toggle="pill"]', function () {
        $(this).closest('ul').find('li').removeClass('active');
        $(this).closest('li').addClass('active');
        $('.tab-pane.active').addClass('in');
    });
    install();
    document.addEventListener('DOMContentLoaded', install);
})(window.jQuery, window.bootstrap);
