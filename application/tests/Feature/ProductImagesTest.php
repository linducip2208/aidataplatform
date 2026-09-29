<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Product display metadata on the ABC table: known demo products render
 * their illustration and description, unknown products render an initial
 * fallback and never a wrong picture.
 */
class ProductImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/api/v1/analytics/abc' => Http::response(['success' => true, 'data' => [
                [
                    'product' => 'Minyak Goreng 1L',
                    'revenue' => 318000000,
                    'share_pct' => 25.4,
                    'cumulative_pct' => 25.4,
                    'grade' => 'A',
                    'description' => 'Minyak goreng sawit kemasan botol 1 liter untuk menumis dan menggoreng.',
                    'image_url' => '/images/demo-products/minyak-goreng-1l.svg',
                ],
                [
                    'product' => 'Barang Tanpa Gambar',
                    'revenue' => 1000000,
                    'share_pct' => 0.1,
                    'cumulative_pct' => 100.0,
                    'grade' => 'C',
                    'description' => null,
                    'image_url' => null,
                ],
            ]], 200),
            '*/api/v1/*' => Http::response(['success' => true, 'data' => []], 200),
        ]);
    }

    public function test_abc_rows_render_matching_images_and_descriptions(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('analytics.index'))
            ->assertOk()
            ->getContent();

        // The demo product shows the illustration that depicts it...
        $this->assertStringContainsString(
            '<img src="/images/demo-products/minyak-goreng-1l.svg"',
            $html,
        );
        $this->assertStringContainsString(
            'Minyak goreng sawit kemasan botol 1 liter untuk menumis dan menggoreng.',
            $html,
        );

        // ...while the unknown product gets the initial fallback, not an img.
        $this->assertStringContainsString('Barang Tanpa Gambar', $html);
        $this->assertSame(1, substr_count($html, '<img src="/images/demo-products/'));
    }

    public function test_demo_illustrations_exist_for_every_catalog_product(): void
    {
        $slugs = [
            'minyak-goreng-1l',
            'beras-premium-5kg',
            'gula-pasir-1kg',
            'kopi-bubuk-200g',
            'teh-celup-25',
            'mie-instan-goreng-85g',
            'susu-uht-1l',
            'telur-ayam-1kg',
            'sabun-mandi-80g',
            'shampo-170ml',
        ];

        foreach ($slugs as $slug) {
            $path = public_path('images/demo-products/'.$slug.'.svg');
            $this->assertFileExists($path, "missing illustration for {$slug}");
            $this->assertStringContainsString('<svg', (string) file_get_contents($path));
        }
    }
}
