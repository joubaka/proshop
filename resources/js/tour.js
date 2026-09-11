const { driver } = require('driver.js');

// Preserve the existing first-party guided-tour steps using the MIT driver.
window.Tour = class {
    constructor(options) { this.steps = options.steps || []; this.current = null; }
    addSteps(steps) { this.steps.push(...steps); }
    init() { return this; }
    restart() {
        if (this.current) this.current.destroy();
        this.current = driver({
            showProgress: true,
            nextBtnText: window.LANG?.next || 'Next',
            prevBtnText: window.LANG?.prev || 'Previous',
            doneBtnText: window.LANG?.end_tour || 'Done',
            steps: this.steps.map(step => ({ element: step.element,
                popover: { title: window.ProshopSanitize(step.title || ''), description: window.ProshopSanitize(step.content || '') },
                onHighlightStarted: () => { if (step.onShow) step.onShow(this); }
            }))
        });
        this.current.drive();
    }
};
