<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SecurityPwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_and_csp_on_every_kind_of_page(): void
    {
        $company = $this->makeCompany('Seg');
        $user = $this->makeUser($company);
        foreach (['/login', '/offline'] as $url) {
            $h = $this->get($url)->assertOk()->headers;
            $this->assertStringContainsString("default-src 'self'", $h->get('Content-Security-Policy'));
            $this->assertStringContainsString("object-src 'none'", $h->get('Content-Security-Policy'));
            $this->assertStringContainsString("frame-ancestors 'self'", $h->get('Content-Security-Policy'));
            $this->assertSame('nosniff', $h->get('X-Content-Type-Options'));
            $this->assertSame('SAMEORIGIN', $h->get('X-Frame-Options'));
        }
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertHeader('Content-Security-Policy');
    }

    public function test_csp_can_be_report_only_or_off(): void
    {
        config(['security.csp_mode' => 'report']);
        $h = $this->get('/login')->headers;
        $this->assertNotNull($h->get('Content-Security-Policy-Report-Only'));
        $this->assertNull($h->get('Content-Security-Policy'));
        config(['security.csp_mode' => 'off']);
        $this->assertNull($this->get('/login')->headers->get('Content-Security-Policy'));
    }

    /** La CSP no permite scripts en línea: ninguna vista puede traer <script> sin src ni manejadores on*="". */
    public function test_no_inline_scripts_or_event_handlers_in_views(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $src = $file->getContents();
            $this->assertSame(0, preg_match('/\son(click|submit|change|input|keyup|keydown|load|error|focus|blur)\s*=\s*"/i', $src), $file->getRelativePathname().' tiene un manejador en línea');
            $this->assertSame(0, preg_match('/<script(?![^>]*\ssrc=)[^>]*>/i', $src), $file->getRelativePathname().' tiene un <script> en línea');
        }
    }

    public function test_pwa_manifest_icons_and_service_worker_are_valid(): void
    {
        $m = json_decode(File::get(public_path('manifest.webmanifest')), true);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('/dashboard', $m['start_url']);
        $sizes = [];
        foreach ($m['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            $sizes[] = $icon['sizes'];
        }
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
        $this->assertContains('maskable', array_column($m['icons'], 'purpose'));
        $this->assertSame(512, getimagesize(public_path('icons/icon-512.png'))[0]);

        $this->get('/login')->assertSee('rel="manifest"', false)->assertSee('apple-touch-icon', false);
    }

    public function test_service_worker_never_caches_private_data(): void
    {
        $sw = File::get(public_path('sw.js'));
        $this->assertStringContainsString("'/offline'", $sw);
        $this->assertStringContainsString("startsWith('/build/')", $sw);
        $this->assertStringContainsString("req.method !== 'GET'", $sw);
        // solo estáticos públicos: nada de rutas de la aplicación ni de archivos privados en la lista de caché
        foreach (['/archivos', '/dashboard', '/almacen', '/reportes', '/api'] as $private) {
            $this->assertStringNotContainsString("'{$private}", $sw);
        }
    }

    public function test_offline_page_is_public_and_has_no_company_data(): void
    {
        $company = $this->makeCompany('Empresa Secreta SRL');
        $this->get('/offline')->assertOk()->assertSee('Sin conexión')->assertDontSee('Empresa Secreta SRL');
    }

    public function test_search_and_exports_are_throttled(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutesByName());
        $this->assertContains('throttle:120,1', $routes['search']->gatherMiddleware());
        $this->assertContains('throttle:60,1', $routes['reports.run']->gatherMiddleware());
        $this->assertContains('throttle:login', $routes['login.store']->gatherMiddleware());
    }

    public function test_lazy_loading_is_prevented_outside_production(): void
    {
        $this->assertTrue(\Illuminate\Database\Eloquent\Model::preventsLazyLoading());
    }
}
