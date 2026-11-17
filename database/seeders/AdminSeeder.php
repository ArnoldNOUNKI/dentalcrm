<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');

        if (User::where('email', $email)->exists()) {
            return;
        }

        $password = env('ADMIN_PASSWORD') ?: Str::password(16, symbols: false);

        User::create([
            'name' => env('ADMIN_NAME', 'Administrateur'),
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
            'receives_leads' => false,
        ]);

        if (! env('ADMIN_PASSWORD')) {
            $this->command?->warn("Compte admin créé : {$email} / {$password}  (changez-le à la première connexion)");
        }
    }
}
