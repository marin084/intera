<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class InitAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'conexa:init-admin
                            {--email= : Admin email address}
                            {--name= : Admin name}
                            {--password= : Admin password (min 12 characters). WARNING: CLI flags may expose credentials in process lists/history. Prefer interactive input}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize the first Super Admin user safely without default passwords';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== Inicialización del Super Administrador de Intera ===');

        $email = $this->option('email') ?: (getenv('INITIAL_ADMIN_EMAIL') ?: env('INITIAL_ADMIN_EMAIL'));
        $name = $this->option('name') ?: (getenv('INITIAL_ADMIN_NAME') ?: env('INITIAL_ADMIN_NAME'));
        $password = $this->option('password') ?: (getenv('INITIAL_ADMIN_PASSWORD') ?: env('INITIAL_ADMIN_PASSWORD'));

        if (! $email) {
            $email = $this->ask('Ingrese el correo electrónico del administrador:');
        }

        if (! $name) {
            $name = $this->ask('Ingrese el nombre del administrador:', 'Administrador Principal');
        }

        if (! $password) {
            $password = $this->secret('Password:');
            $confirmPassword = $this->secret('Confirm password:');

            if ($password !== $confirmPassword) {
                $this->error('Error: Las contraseñas no coinciden.');

                return self::FAILURE;
            }
        }

        $validator = Validator::make([
            'email' => $email,
            'name' => $name,
            'password' => $password,
        ], [
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
        ], [
            'password.min' => 'La contraseña debe tener al menos 12 caracteres de longitud por seguridad.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error("Error: {$error}");
            }

            return self::FAILURE;
        }

        $superAdminRole = Role::where('name', 'super_admin')->first();
        if (! $superAdminRole) {
            $this->error("Error: El rol 'super_admin' no existe. Ejecute primero 'php artisan db:seed'.");

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            if (! $this->confirm("El usuario {$email} ya existe. ¿Desea actualizar su contraseña y asegurar rol super_admin?")) {
                $this->info('Operación cancelada.');

                return self::SUCCESS;
            }

            $user->update([
                'name' => $name,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);
        } else {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);
        }

        // Attach super_admin role if not already attached
        if (! $user->roles()->where('name', 'super_admin')->exists()) {
            $user->roles()->attach($superAdminRole->id);
        }

        AuditLogger::log(
            action: 'auth.init_admin',
            entityType: 'User',
            entityId: (string) $user->id,
            metadata: ['email' => $user->email],
            userId: $user->id
        );

        $this->info("¡Super Administrador [{$user->email}] configurado correctamente con éxito!");

        return self::SUCCESS;
    }
}
