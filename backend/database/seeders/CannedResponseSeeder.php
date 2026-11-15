<?php

namespace Database\Seeders;

use App\Models\CannedResponse;
use App\Models\User;
use Illuminate\Database\Seeder;

class CannedResponseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $creator = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first()
            ?? User::first();

        $cannedResponses = [
            [
                'title' => 'Cuentas Banco de Costa Rica',
                'shortcut' => 'bcr',
                'content' => "Estimado/a, para transferencias en Costa Rica:\n• Colones IBAN: CR24015201001000002303\n• Dólares: 001-129651-5\nAgradecemos enviar comprobante por este medio.",
                'category' => 'Finanzas',
                'is_active' => true,
            ],
            [
                'title' => 'Datos Bancolombia / Nequi',
                'shortcut' => 'nequi',
                'content' => "Para pagos en Colombia mediante Bancolombia o Nequi:\n• Cuenta de Ahorros: 123-456789-01\n• NIT: 900.123.456-7\n• Convenio Nequi disponible al número de atención.",
                'category' => 'Finanzas',
                'is_active' => true,
            ],
            [
                'title' => 'Canal Oficial de YouTube',
                'shortcut' => 'youtube',
                'content' => "¡Te invitamos a suscribirte a nuestro canal oficial de YouTube para acceder a transmisiones en vivo y contenidos exclusivos!: https://youtube.com/@CanalOficial",
                'category' => 'Atención',
                'is_active' => true,
            ],
            [
                'title' => 'Espacio Seguro y Respeto',
                'shortcut' => 'cordialidad',
                'content' => "Recordamos que este canal es un espacio de respeto y cordialidad mutua. Estamos aquí para ayudarte con el mayor gusto.",
                'category' => 'Atención',
                'is_active' => true,
            ],
        ];

        foreach ($cannedResponses as $data) {
            CannedResponse::updateOrCreate(
                ['shortcut' => $data['shortcut']],
                array_merge($data, [
                    'created_by' => $creator?->id,
                ])
            );
        }
    }
}
