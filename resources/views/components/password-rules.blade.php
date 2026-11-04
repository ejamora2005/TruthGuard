@props(['passwordId' => 'password', 'confirmationId' => 'password_confirmation'])

<ul id="{{ $passwordId }}-rules" class="mt-2 space-y-1 text-xs text-slate-500 dark:text-slate-400"
    aria-label="Password requirements"
    x-data="{
        longEnough: false,
        matches: false,
        check() {
            const password = document.getElementById(@js($passwordId))?.value || '';
            const confirmation = document.getElementById(@js($confirmationId))?.value || '';
            this.longEnough = Array.from(password).length >= 8;
            this.matches = password.length > 0 && password === confirmation;
        }
    }"
    x-init="$nextTick(() => check())"
    @input.window="check()" @change.window="check()" @reset.window="$nextTick(() => check())"
>
    <li :class="longEnough ? 'text-emerald-700 dark:text-emerald-400' : ''"><span aria-hidden="true" x-text="longEnough ? '✓' : '○'">○</span> At least 8 characters</li>
    <li :class="matches ? 'text-emerald-700 dark:text-emerald-400' : ''"><span aria-hidden="true" x-text="matches ? '✓' : '○'">○</span> Passwords must match</li>
</ul>
