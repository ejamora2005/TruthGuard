<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.auth-modern')]
#[Title('Forgot Password | TruthGuard')]
class ForgotPassword extends Component
{
    public string $email = '';

    protected function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }

    public function updated(string $property): void
    {
        if ($property !== 'email') {
            return;
        }

        $this->validateOnly($property);
    }

    public function sendResetLink(): void
    {
        $validated = $this->validate();
        $email = strtolower(trim((string) $validated['email']));

        Password::sendResetLink(['email' => $email]);

        session([
            'password_reset_email' => $email,
            'password_reset_last_sent_at' => now()->toIso8601String(),
        ]);

        $this->redirectRoute('password.sent', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}

