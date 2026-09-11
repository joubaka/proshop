const { Jodit } = require('jodit');
require('jodit/esm/plugins/clean-html/clean-html.js');
require('jodit/esm/plugins/paste/paste.js');
require('jodit/esm/plugins/paste-from-word/paste-from-word.js');
require('jodit/esm/plugins/indent/indent.js');
require('jodit/esm/plugins/justify/justify.js');
require('jodit/esm/plugins/source/source.js');
require('jodit/esm/plugins/fullsize/fullsize.js');
require('jodit/esm/plugins/preview/preview.js');
require('jodit/esm/plugins/print/print.js');
require('jodit/esm/plugins/mobile/mobile.js');
require('jodit/esm/plugins/focus/focus.js');
require('jodit/esm/plugins/hr/hr.js');
require('jodit/esm/plugins/select-cells/select-cells.js');
require('jodit/esm/plugins/resize-cells/resize-cells.js');
const DOMPurify = require('dompurify');
const instances = new Map();
let defaultHeight = 300;
const sanitize = html => DOMPurify.sanitize(html, { USE_PROFILES: { html: true } });
window.ProshopSanitize = sanitize;

// Keep existing textarea names, HTML and AJAX callers; no TinyMCE code is loaded.
const editors = {
    activeEditor: null,
    overrideDefaults(options) { defaultHeight = options.height || defaultHeight; },
    init(options) {
        const result = [];
        document.querySelectorAll(options.selector).forEach(textarea => {
            if (instances.has(textarea)) { result.push(instances.get(textarea)); return; }
            textarea.value = sanitize(textarea.value);
            const editor = Jodit.make(textarea, {
                height: options.height || defaultHeight,
                toolbarAdaptive: true,
                buttons: ['undo', 'redo', '|', 'bold', 'italic', 'underline', 'brush', 'paragraph', '|',
                    'ul', 'ol', 'outdent', 'indent', 'align', '|', 'link', 'image', 'table', 'hr', '|', 'source', 'fullsize', 'preview', 'print'],
                uploader: { insertImageAsBase64URI: true },
                filebrowser: { ajax: { url: '' } },
                useSearch: false,
                sourceEditor: 'area',
                beautifyHTML: false,
                sourceEditorCDNUrlsJS: [],
                beautifyHTMLCDNUrlsJS: [],
                spellcheck: true,
                events: {
                    beforeSetValueToEditor(value) { return sanitize(value); },
                    change(value) { textarea.value = sanitize(value); },
                    focus() { editors.activeEditor = editor; }
                }
            });
            instances.set(textarea, editor);
            editors.activeEditor = editor;
            result.push(editor);
        });
        return Promise.resolve(result);
    },
    triggerSave() {
        for (const [textarea, editor] of instances) {
            if (textarea.isConnected) textarea.value = sanitize(editor.value);
        }
    },
    remove(selector) {
        for (const [textarea, editor] of instances) {
            if (!selector || textarea.matches(selector)) {
                textarea.value = sanitize(editor.value);
                editor.destruct();
                instances.delete(textarea);
                if (editors.activeEditor === editor) editors.activeEditor = null;
            }
        }
    },
    get(id) { return instances.get(document.getElementById(id)) || null; }
};
document.addEventListener('submit', () => editors.triggerSave(), true);
window.tinymce = window.tinyMCE = window.ProshopRichText = editors;
