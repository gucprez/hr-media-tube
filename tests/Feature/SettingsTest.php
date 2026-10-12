<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DocumentSequence;
use App\Services\DocumentNumbering;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_profile_and_logo_update_with_audit(): void
    {
        Storage::fake('public');
        $company = $this->makeCompany('Mi Empresa');
        $admin = $this->makeUser($company);

        $this->actingAs($admin)->put('/administracion/configuracion', [
            'name' => 'Carmen Cargo Express', 'rnc' => '123', 'primary_color' => '#112233', 'secondary_color' => '#0f2a52',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'settings' => ['ticket_footer' => 'Gracias', 'critical_ratio' => 0.4],
        ])->assertSessionHasNoErrors();

        $company->refresh();
        $this->assertSame('Carmen Cargo Express', $company->name);
        $this->assertSame('#112233', $company->primary_color);
        Storage::disk('public')->assertExists($company->logo_path);
        $this->assertSame(0.4, $company->setting('critical_ratio'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'updated', 'company_id' => $company->id]);
    }

    public function test_invalid_logo_and_colors_are_rejected(): void
    {
        $admin = $this->makeUser($this->makeCompany());
        $this->actingAs($admin)->put('/administracion/configuracion', [
            'name' => 'X', 'primary_color' => 'red', 'secondary_color' => '#0f2a52',
            'logo' => UploadedFile::fake()->create('virus.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors(['primary_color', 'logo']);
    }

    public function test_branches_and_warehouses_crud_with_single_default(): void
    {
        $company = $this->makeCompany();
        $admin = $this->makeUser($company);

        $this->actingAs($admin)->post('/administracion/configuracion/sucursales', ['name' => 'Santiago', 'code' => 'STI'])->assertSessionHasNoErrors();
        $branch = Branch::where('code', 'STI')->firstOrFail();
        $this->post('/administracion/configuracion/almacenes', ['name' => 'Almacén Santiago', 'code' => 'AS', 'branch_id' => $branch->id, 'is_default' => 1])->assertSessionHasNoErrors();

        $this->assertSame(1, \App\Models\Warehouse::where('is_default', true)->count());
        $this->assertDatabaseHas('warehouses', ['code' => 'AS', 'is_default' => true]);
        // duplicado de código en la misma empresa
        $this->post('/administracion/configuracion/sucursales', ['name' => 'Otra', 'code' => 'STI'])->assertSessionHasErrors('code');
    }

    public function test_document_numbers_are_sequential_per_company(): void
    {
        $a = $this->makeCompany('A');
        $b = $this->makeCompany('B');
        $svc = app(DocumentNumbering::class);

        $this->assertSame('0001', $svc->next('ticket_materials', $a->id));
        $this->assertSame('0002', $svc->next('ticket_materials', $a->id));
        $this->assertSame('0001', $svc->next('ticket_materials', $b->id));

        DocumentSequence::withoutGlobalScopes()->where('company_id', $a->id)->where('key', 'container')->update(['prefix' => 'F-', 'padding' => 4, 'next_number' => 111]);
        $this->assertSame('F-0111', $svc->next('container', $a->id));
    }

    public function test_numbering_cannot_go_backwards(): void
    {
        $admin = $this->makeUser($this->makeCompany());
        $this->actingAs($admin)->get('/administracion/configuracion/numeracion');
        $seq = DocumentSequence::where('key', 'container')->first();
        $seq->update(['next_number' => 50]);

        $this->put('/administracion/configuracion/numeracion', ['seq' => [$seq->id => ['prefix' => '', 'padding' => 4, 'next_number' => 10]]])
            ->assertSessionHas('error');
        $this->assertSame(50, $seq->fresh()->next_number);
    }
}
