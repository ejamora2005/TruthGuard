import '../css/dialog.css';

let pending = Promise.resolve();

function showDialog(message, options, confirmation) {
    const run = () => new Promise((resolve) => {
        const previousFocus = document.activeElement;
        const previousOverflow = document.documentElement.style.overflow;
        const dialog = document.createElement('dialog');
        dialog.className = 'tg-dialog';
        dialog.dataset.tone = options.tone === 'danger' ? 'danger' : 'warning';
        dialog.setAttribute('aria-labelledby', 'tg-dialog-title');
        dialog.setAttribute('aria-describedby', 'tg-dialog-message');
        dialog.innerHTML = `
            <div class="tg-dialog-content">
                <span class="tg-dialog-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 8v5m0 3h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke-linecap="round"/></svg>
                </span>
                <h2 id="tg-dialog-title"></h2>
                <p id="tg-dialog-message"></p>
            </div>
            <div class="tg-dialog-actions"></div>`;
        // File names, provider errors, and source names are always plain text.
        dialog.querySelector('h2').textContent = options.title || (confirmation ? 'Please confirm' : 'Please check');
        dialog.querySelector('p').textContent = String(message ?? '');
        const actions = dialog.querySelector('.tg-dialog-actions');
        const addButton = (label, primary, value) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = primary ? 'tg-dialog-primary' : 'tg-dialog-cancel';
            button.textContent = label;
            button.addEventListener('click', () => dialog.close(value));
            actions.append(button);
            return button;
        };
        const cancel = confirmation ? addButton(options.cancelLabel || 'Cancel', false, 'cancel') : null;
        const accept = addButton(options.confirmLabel || (confirmation ? 'Continue' : 'Got it'), true, 'accept');
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) return;
            const box = dialog.getBoundingClientRect();
            if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) {
                dialog.close('cancel');
            }
        });
        dialog.addEventListener('close', () => {
            const accepted = dialog.returnValue === 'accept';
            dialog.remove();
            document.documentElement.style.overflow = previousOverflow;
            if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true });
            resolve(accepted);
        }, { once: true });
        document.body.append(dialog);
        dialog.showModal();
        document.documentElement.style.overflow = 'hidden';
        (cancel || accept).focus({ preventScroll: true });
    });
    const result = pending.then(run);
    pending = result.catch(() => {});
    return result;
}

export function alertDialog(message, options = {}) {
    return showDialog(message, options, false);
}

export function confirmDialog(message, options = {}) {
    return showDialog(message, options, true);
}

window.TruthGuardDialog = { alert: alertDialog, confirm: confirmDialog };
