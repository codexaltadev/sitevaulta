<?php

namespace Tests\Feature;

use App\Enums\DatabaseDriver;
use App\Enums\FileProtocol;
use App\Filament\App\Resources\ServerConnections\Pages\EditServerConnection;
use App\Models\ServerConnection;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoWorkspaceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class ServerConnectionEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_connection_edit_page_populates_credentials(): void
    {
        $this->seed();

        $user = User::where('email', 'demo@sitevaulta.com')->first();
        $workspace = Workspace::where('slug', 'demo-agency')->first();
        app(DemoWorkspaceSeeder::class)->seedDemoForWorkspace($workspace);

        $conn = ServerConnection::where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($conn);

        // Verify that file_credentials and db_credentials exist
        $this->assertNotEmpty($conn->file_credentials);
        $this->assertNotEmpty($conn->db_credentials);
        $this->assertEquals('198.51.100.25', $conn->file_credentials['host']);
        $this->assertEquals('deploy', $conn->file_credentials['username']);
        $this->assertEquals('acme_prod_db', $conn->db_credentials['database']);

        // Request Edit page
        $response = $this->actingAs($user)
            ->get('/app/'.$workspace->slug.'/server-connections/'.$conn->id.'/edit');

        file_put_contents('/tmp/edit_page.html', $response->getContent());

        $response->assertStatus(200);
        $response->assertSee('198.51.100.25');
        $response->assertSee('deploy');
        $response->assertSee('acme-commerce');
        $response->assertSee('acme_prod_db');
        $response->assertSee('acme_user');
    }

    public function test_saving_server_connection_preserves_nested_credentials(): void
    {
        $this->seed();

        $user = User::where('email', 'demo@sitevaulta.com')->first();
        $workspace = Workspace::where('slug', 'demo-agency')->first();
        $website = $workspace->websites()->first();

        $conn = ServerConnection::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'name' => 'Demo Faculty Server',
            'file_protocol' => FileProtocol::SshSftp,
            'file_credentials' => [
                'host' => 'demofaculty.daffodilvarsity.edu.bd',
                'port' => 22,
                'username' => 'faculty_admin',
                'auth_type' => 'password',
                'password' => 'FacultyPass#2026',
                'root_path' => '/home/faculty/public_html',
            ],
            'db_driver' => DatabaseDriver::MySql,
            'db_use_ssh_tunnel' => true,
            'db_credentials' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'faculty_portal_db',
                'username' => 'faculty_dbuser',
                'password' => 'DbPass#2026',
            ],
        ]);

        $this->assertNotNull($conn->id);

        // Edit page must show all saved credentials
        $response = $this->actingAs($user)
            ->get('/app/'.$workspace->slug.'/server-connections/'.$conn->id.'/edit');

        $response->assertStatus(200);
        $response->assertSee('demofaculty.daffodilvarsity.edu.bd');
        $response->assertSee('faculty_admin');
        $response->assertSee('faculty_portal_db');
        $response->assertSee('faculty_dbuser');
    }

    public function test_editing_server_connection_via_livewire_form(): void
    {
        $this->seed();

        $user = User::where('email', 'demo@sitevaulta.com')->first();
        $workspace = Workspace::where('slug', 'demo-agency')->first();
        $website = $workspace->websites()->first();

        $conn = ServerConnection::create([
            'workspace_id' => $workspace->id,
            'website_id' => $website->id,
            'name' => 'Demo Faculty Server',
            'file_protocol' => FileProtocol::SshSftp,
            'file_credentials' => [
                'host' => 'demofaculty.daffodilvarsity.edu.bd',
                'port' => 22,
                'username' => 'faculty_admin',
                'auth_type' => 'password',
                'password' => 'FacultyPass#2026',
                'root_path' => '/home/faculty/public_html',
            ],
            'db_driver' => DatabaseDriver::MySql,
            'db_use_ssh_tunnel' => true,
            'db_credentials' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'faculty_portal_db',
                'username' => 'faculty_dbuser',
                'password' => 'DbPass#2026',
            ],
        ]);

        Auth::login($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($workspace);

        Livewire::actingAs($user)
            ->test(EditServerConnection::class, [
                'record' => $conn->id,
            ])
            ->assertSchemaStateSet([
                'name' => 'Demo Faculty Server',
                'file_credentials.host' => 'demofaculty.daffodilvarsity.edu.bd',
                'file_credentials.username' => 'faculty_admin',
                'file_credentials.root_path' => '/home/faculty/public_html',
                'db_credentials.database' => 'faculty_portal_db',
                'db_credentials.username' => 'faculty_dbuser',
            ])
            ->fillForm([
                'name' => 'Updated Faculty Production Server',
                'file_credentials.host' => 'updated.daffodilvarsity.edu.bd',
                'db_credentials.database' => 'updated_portal_db',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $updated = $conn->fresh();
        $this->assertEquals('Updated Faculty Production Server', $updated->name);
        $this->assertEquals('updated.daffodilvarsity.edu.bd', $updated->file_credentials['host']);
        $this->assertEquals('updated_portal_db', $updated->db_credentials['database']);
        // Verify unchanged nested fields are also preserved!
        $this->assertEquals('faculty_admin', $updated->file_credentials['username']);
        $this->assertEquals('/home/faculty/public_html', $updated->file_credentials['root_path']);
        $this->assertEquals('faculty_dbuser', $updated->db_credentials['username']);
    }
}
