<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ContactDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspace;

    protected User $admin;

    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->workspace = Workspace::query()->orderBy('created_at')->firstOrFail();

        $this->admin = User::create([
            'name' => 'Workspace Admin',
            'email' => 'directory-admin@test.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->firstOrFail());

        $this->agent = User::create([
            'name' => 'Workspace Agent',
            'email' => 'directory-agent@test.com',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->agent->roles()->attach(Role::where('name', 'agent')->firstOrFail());
    }

    public function test_workspace_admin_can_list_contacts_with_channels_and_variables(): void
    {
        $channel = Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Webchat de prueba',
            'type' => 'webchat',
            'is_active' => true,
            'configuration' => [],
        ]);
        $contact = Contact::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'display_name' => 'Ana Pérez',
            'external_identifier' => 'visitor-ana',
            'email' => 'ana@example.test',
            'metadata' => [
                'flow_variables' => ['$pedido' => 'A-100', '_internal' => 'omit'],
                'provider_profile' => ['name' => 'Ana', 'nested_token' => 'never-return'],
                'webhook_secret' => 'never-return',
            ],
        ]);
        Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'bot',
            'is_simulation' => false,
            'started_at' => now()->subMinute(),
            'last_message_at' => now(),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/contacts');

        $response->assertOk()
            ->assertJsonPath('data.0.display_name', 'Ana Pérez')
            ->assertJsonPath('data.0.channels.0.name', 'Webchat de prueba')
            ->assertJsonPath('data.0.variables.pedido', 'A-100')
            ->assertJsonPath('data.0.variable_count', 1)
            ->assertJsonMissing(['webhook_secret' => 'never-return'])
            ->assertJsonMissing(['nested_token' => 'never-return'])
            ->assertJsonPath('meta.total', 1);
    }

    public function test_simulation_only_contacts_are_not_exposed_and_workspace_scope_is_kept(): void
    {
        $channel = Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Canal real',
            'type' => 'webchat',
            'is_active' => true,
            'configuration' => [],
        ]);
        $realContact = Contact::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'display_name' => 'Contacto real',
            'external_identifier' => 'real-1',
        ]);
        $simulationContact = Contact::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'display_name' => 'Contacto de simulación',
            'external_identifier' => 'simulation-1',
        ]);
        Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'channel_id' => $channel->id,
            'contact_id' => $realContact->id,
            'status' => 'bot',
            'is_simulation' => false,
            'started_at' => now(),
        ]);
        Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'channel_id' => $channel->id,
            'contact_id' => $simulationContact->id,
            'status' => 'bot',
            'is_simulation' => true,
            'started_at' => now(),
        ]);

        $otherWorkspace = Workspace::create(['name' => 'Otro Workspace', 'slug' => 'otro-workspace']);
        $otherContact = Contact::withoutGlobalScopes()->create([
            'workspace_id' => $otherWorkspace->id,
            'display_name' => 'Otro tenant',
            'external_identifier' => 'other-1',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/contacts');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (string) $realContact->id)
            ->assertJsonMissing(['id' => (string) $simulationContact->id])
            ->assertJsonMissing(['id' => (string) $otherContact->id]);
    }

    public function test_agents_cannot_access_contact_directory(): void
    {
        $this->actingAs($this->agent, 'sanctum')
            ->getJson('/api/v1/contacts')
            ->assertForbidden();
    }

    public function test_admin_can_preview_map_import_and_export_an_arbitrary_csv(): void
    {
        $whatsappChannel = Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'WhatsApp',
            'type' => 'whatsapp',
            'is_active' => true,
            'configuration' => [],
        ]);
        $csv = implode("\n", [
            'cliente,correo,telefono,origen,campos',
            'bot-001,ana.importada@example.test,+50688887777,WhatsApp,"{""segmento"":""ventas"",""pedido"":""A-100"",""api_key"":""do-not-export"",""formula"":""=1+1""}"',
            'bot-002,beto.importado@example.test,+50688886666,Telegram,"{""segmento"":""soporte""}"',
        ]);
        $previewFile = UploadedFile::fake()->createWithContent('contactos.csv', $csv);

        $preview = $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/contacts/import/preview', ['file' => $previewFile]);

        $preview->assertOk()
            ->assertJsonPath('data.headers.0', 'cliente')
            ->assertJsonPath('data.suggested_mapping.external_identifier', 'cliente')
            ->assertJsonPath('data.suggested_mapping.email', 'correo')
            ->assertJsonPath('data.suggested_mapping.phone', 'telefono')
            ->assertJsonPath('data.available_channels.0.id', (string) $whatsappChannel->id)
            ->assertJsonPath('data.row_count', 2);

        $importFile = UploadedFile::fake()->createWithContent('contactos.csv', $csv);
        $import = $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/contacts/import', [
                'file' => $importFile,
                'mapping' => json_encode([
                    'external_identifier' => 'cliente',
                    'email' => 'correo',
                    'phone' => 'telefono',
                    'custom_fields' => 'campos',
                ]),
                'destination_channel_id' => (string) $whatsappChannel->id,
            ]);

        $import->assertOk()
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 0);

        $contact = Contact::query()->where('external_identifier', 'bot-001')->firstOrFail();
        $this->assertSame('ana.importada@example.test', $contact->email);
        $this->assertSame('ventas', data_get($contact->metadata, 'contact_import.extra_fields.segmento'));
        $this->assertSame('do-not-export', data_get($contact->metadata, 'contact_import.extra_fields.api_key'));
        $this->assertSame([(string) $whatsappChannel->id], data_get($contact->metadata, 'contact_import.linked_channel_ids'));
        $this->assertArrayNotHasKey('channels', data_get($contact->metadata, 'contact_import'));

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/contacts')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['id' => (string) $whatsappChannel->id, 'name' => 'WhatsApp', 'type' => 'whatsapp'])
            ->assertJsonMissing(['name' => 'Telegram']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/contacts/'.$contact->id)
            ->assertOk()
            ->assertJsonPath('data.attributes.contact_import.extra_fields.segmento', 'ventas');

        $export = $this->actingAs($this->admin, 'sanctum')->get('/api/v1/contacts/export');
        $export->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $exportContent = $export->streamedContent();
        $this->assertStringContainsString('ana.importada@example.test', $exportContent);
        $this->assertStringContainsString('WhatsApp', $exportContent);
        $this->assertStringNotContainsString('do-not-export', $exportContent);
        $this->assertStringContainsString("'+50688887777", $exportContent);
    }

    public function test_agents_cannot_import_or_export_contacts(): void
    {
        $file = UploadedFile::fake()->createWithContent('contactos.csv', "id,email\none,one@example.test\n");

        $this->actingAs($this->agent, 'sanctum')
            ->post('/api/v1/contacts/import/preview', ['file' => $file])
            ->assertForbidden();

        $this->actingAs($this->agent, 'sanctum')
            ->get('/api/v1/contacts/export')
            ->assertForbidden();
    }

    public function test_import_requires_a_destination_channel(): void
    {
        $file = UploadedFile::fake()->createWithContent('contactos.csv', "id,email\none,one@example.test\n");

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/contacts/import', [
                'file' => $file,
                'mapping' => json_encode(['external_identifier' => 'id', 'email' => 'email']),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Selecciona un canal destino antes de importar.');
    }

    public function test_import_rejects_an_inactive_destination_channel(): void
    {
        $inactiveChannel = Channel::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Canal inactivo',
            'type' => 'webchat',
            'is_active' => false,
            'configuration' => [],
        ]);
        $file = UploadedFile::fake()->createWithContent('contactos.csv', "id,email\none,one@example.test\n");

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/v1/contacts/import', [
                'file' => $file,
                'mapping' => json_encode(['external_identifier' => 'id', 'email' => 'email']),
                'destination_channel_id' => (string) $inactiveChannel->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El canal destino no pertenece al Workspace activo o está inactivo.');
    }

    public function test_simulation_contact_cannot_be_loaded_directly(): void
    {
        $simulationContact = Contact::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'display_name' => 'Solo simulación',
            'external_identifier' => 'simulation-only',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/contacts/'.$simulationContact->id)
            ->assertNotFound();
    }
}
