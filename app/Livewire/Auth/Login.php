<?php

namespace App\Livewire\Auth;

use App\Services\Auth\SessionTimeoutManager;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.auth-modern')]
#[Title('Login | TruthGuard')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    protected function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['email', 'password'], true)) {
            return;
        }

        $this->validateOnly($property);
    }

    public function login(): void
    {
        $this->validate();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], false)) {
            $this->addError('email', 'The provided credentials do not match our records.');

            return;
        }

        request()->session()->regenerate();
        app(SessionTimeoutManager::class)->touch(request());
        request()->session()->forget('url.intended');

        $target = Auth::user()?->isAdmin()
            ? route('admin.dashboard', absolute: false)
            : route('dashboard', absolute: false);

        $this->redirect($target, navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
