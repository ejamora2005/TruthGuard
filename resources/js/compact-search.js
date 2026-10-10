// Progressive enhancement: forms remain visible when JavaScript is unavailable.
const mounted = new WeakMap();
const mobile = window.matchMedia('(max-width: 767px)');
let sequence = 0;

function mount(form) {
    if (mounted.has(form)) return;
    const input = form.querySelector('input[type="search"]');
    if (!input) return;
    form.id ||= `truthguard-search-${++sequence}`;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'truthguard-compact-search-toggle';
    button.setAttribute('aria-controls', form.id);
    const label = form.dataset.compactSearch || 'Search and filters';
    button.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"></circle><path d="m16 16 4.5 4.5"></path></svg>';
    let expanded = !!input.value.trim() || form.dataset.searchActive === 'true';
    const sync = () => {
        button.hidden = !mobile.matches;
        form.hidden = mobile.matches && !expanded;
        button.setAttribute('aria-expanded', String(!form.hidden));
        const action = form.hidden ? label : `Hide ${label.toLowerCase()}`;
        button.setAttribute('aria-label', action);
        button.title = action;
    };
    button.addEventListener('click', () => {
        expanded = !expanded;
        sync();
        if (expanded) input.focus({ preventScroll: true });
    });
    form.addEventListener('keydown', event => {
        if (event.defaultPrevented || event.key !== 'Escape' || !mobile.matches) return;
        // Do not submit/reset the form or discard the user's query.
        event.preventDefault();
        expanded = false;
        sync();
        button.focus();
    });
    const target = form.dataset.compactSearchTarget && document.getElementById(form.dataset.compactSearchTarget);
    if (target) target.append(button);
    else form.before(button);
    mounted.set(form, sync);
    sync();
}

const initialize = () => document.querySelectorAll('form[data-compact-search]').forEach(mount);
mobile.addEventListener('change', () => document.querySelectorAll('form[data-compact-search]').forEach(form => mounted.get(form)?.()));
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
else initialize();
document.addEventListener('livewire:navigated', initialize);
new MutationObserver(records => {
    if (records.some(record => [...record.addedNodes].some(node => node.nodeType === 1
        && (node.matches('form[data-compact-search]') || node.querySelector('form[data-compact-search]'))))) initialize();
}).observe(document.documentElement, { subtree: true, childList: true });
