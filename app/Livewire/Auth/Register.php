<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.auth-modern')]
#[Title('Register | TruthGuard')]
class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:8'],
        ];
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['name', 'email', 'password', 'password_confirmation'], true)) {
            return;
        }

        $this->validateOnly($property);
    }

    public function register(): void
    {
        $validated = $this->validate();

        $userAttributes = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ];

        if (User::hasPasswordSetAtColumn()) {
            $userAttributes['password_set_at'] = now();
        }

        User::create($userAttributes);

        session()->flash('status', 'Registration successful. Please sign in to continue.');

        $this->redirect(route('login', absolute: false), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register');
    }
}
