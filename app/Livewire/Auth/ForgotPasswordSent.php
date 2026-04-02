<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.auth-modern')]
#[Title('Check Your Email | TruthGuard')]
class ForgotPasswordSent extends Component
{
    public string $email = '';

    public int $cooldownSeconds = 0;

    public string $statusMessage = '';

    private int $cooldownDuration = 45;

    public function mount(): void
    {
        $this->email = (string) session('password_reset_email', '');

        if ($this->email === '') {
            $this->redirectRoute('password.request', navigate: true);

            return;
        }

        $this->cooldownSeconds = $this->remainingCooldownSeconds();
    }

    public function resendEmail(): void
    {
        if ($this->email === '') {
            $this->redirectRoute('password.request', navigate: true);

            return;
        }

        $remaining = $this->remainingCooldownSeconds();

        if ($remaining > 0) {
            $this->cooldownSeconds = $remaining;
            $this->addError('resend', "Please wait {$remaining} seconds before resending.");

            return;
        }

        Password::sendResetLink(['email' => $this->email]);

        session([
            'password_reset_last_sent_at' => now()->toIso8601String(),
        ]);

        $this->cooldownSeconds = $this->cooldownDuration;
        $this->statusMessage = 'A new reset link has been sent if that account exists.';
        $this->resetErrorBag('resend');
    }

    public function render()
    {
        return view('livewire.auth.forgot-password-sent');
    }

    private function remainingCooldownSeconds(): int
    {
        $lastSentAt = session('password_reset_last_sent_at');

        if (! is_string($lastSentAt) || $lastSentAt === '') {
            return 0;
        }

        try {
            $elapsed = Carbon::parse($lastSentAt)->diffInSeconds(now());
        } catch (\Throwable) {
            return 0;
        }

        return max(0, $this->cooldownDuration - $elapsed);
    }
}

