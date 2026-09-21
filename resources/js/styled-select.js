const mounted = new WeakSet();
let selectId = 0;

function enhanceSelect(select) {
    if (mounted.has(select)) return;
    mounted.add(select);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tg-select-trigger';
    button.setAttribute('aria-label', select.getAttribute('aria-label') || 'Choose an option');
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');
    const menu = document.createElement('div');
    menu.className = 'tg-select-menu';
    menu.id = `tg-select-${++selectId}`;
    menu.setAttribute('role', 'listbox');
    menu.setAttribute('aria-label', button.getAttribute('aria-label'));
    menu.tabIndex = -1;
    menu.hidden = true;
    button.setAttribute('aria-controls', menu.id);
    select.after(button);
    select.hidden = true;
    select.before(menu);
    let active = select.selectedIndex;
    let opened = false;
    let search = '';
    let searchTime = 0;
    const options = Array.from(select.options);
    const sync = () => {
        button.textContent = select.selectedOptions[0]?.textContent || 'Choose';
        button.disabled = select.disabled;
    };
    const position = () => {
        const rect = button.getBoundingClientRect();
        const width = Math.min(Math.max(rect.width, 208), window.innerWidth - 24);
        const below = window.innerHeight - rect.bottom - 12;
        const above = rect.top - 12;
        menu.style.width = `${width}px`;
        menu.style.left = `${Math.max(12, Math.min(rect.right - width, window.innerWidth - width - 12))}px`;
        menu.style.maxHeight = `${Math.min(280, Math.max(below, above))}px`;
        menu.style.top = below >= Math.min(menu.scrollHeight, 280) || below >= above ? `${rect.bottom + 6}px` : 'auto';
        menu.style.bottom = menu.style.top === 'auto' ? `${window.innerHeight - rect.top + 6}px` : 'auto';
    };
    const highlight = () => {
        Array.from(menu.children).forEach((item, index) => {
            item.classList.toggle('is-focused', index === active);
            item.setAttribute('aria-selected', String(index === select.selectedIndex));
        });
        menu.setAttribute('aria-activedescendant', `${menu.id}-${active}`);
        menu.children[active]?.scrollIntoView({ block: 'nearest' });
    };
    const close = (restore = false) => {
        opened = false;
        menu.hidden = true;
        button.setAttribute('aria-expanded', 'false');
        select.before(menu);
        if (restore) button.focus();
    };
    const choose = index => {
        if (options[index]?.disabled) return;
        const changed = index !== select.selectedIndex;
        select.selectedIndex = index;
        sync();
        close(true);
        if (changed) select.dispatchEvent(new Event('change', { bubbles: true }));
    };
    options.forEach((option, index) => {
        const item = document.createElement('div');
        item.id = `${menu.id}-${index}`;
        item.className = 'tg-select-option';
        item.setAttribute('role', 'option');
        item.setAttribute('aria-disabled', String(option.disabled));
        item.textContent = option.textContent;
        item.addEventListener('click', () => choose(index));
        menu.appendChild(item);
    });
    const open = () => {
        if (button.disabled) return;
        opened = true;
        active = select.selectedIndex;
        document.body.appendChild(menu);
        menu.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        position();
        menu.focus({ preventScroll: true });
        highlight();
    };
    button.addEventListener('click', () => opened ? close(true) : open());
    button.addEventListener('keydown', event => {
        if (['ArrowDown', 'ArrowUp'].includes(event.key)) { event.preventDefault(); open(); }
    });
    menu.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); close(true); return; }
        if (event.key === 'Tab') { close(true); return; }
        if (['Enter', ' '].includes(event.key)) { event.preventDefault(); choose(active); return; }
        const enabled = options.map((option, index) => option.disabled ? -1 : index).filter(index => index >= 0);
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            const current = enabled.indexOf(active);
            active = event.key === 'Home' ? enabled[0] : event.key === 'End' ? enabled.at(-1)
                : enabled[(current + (event.key === 'ArrowDown' ? 1 : -1) + enabled.length) % enabled.length];
        } else if (event.key.length === 1) {
            search = Date.now() - searchTime > 700 ? event.key : search + event.key;
            searchTime = Date.now();
            active = enabled.find(index => options[index].text.toLowerCase().startsWith(search.toLowerCase())) ?? active;
        }
        highlight();
    });
    document.addEventListener('pointerdown', event => {
        if (opened && !button.contains(event.target) && !menu.contains(event.target)) close();
    });
    window.addEventListener('resize', () => { if (opened) position(); });
    document.addEventListener('scroll', event => { if (opened && !menu.contains(event.target)) close(); }, true);
    document.addEventListener('livewire:navigating', () => { if (opened) close(); });
    select.addEventListener('change', sync);
    sync();
}

const init = () => document.querySelectorAll('select[data-tg-select]').forEach(enhanceSelect);
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();
document.addEventListener('livewire:navigated', init);
