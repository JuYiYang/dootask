<?php

namespace Tests\Feature;

use App\Module\Base;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SystemBrandingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_system_setting_exposes_login_branding(): void
    {
        Base::setting('system', [
            'system_alias' => 'Example Team',
            'login_logo' => 'uploads/user/picture/1/202609/example.png',
            'login_background' => 'uploads/user/picture/1/202609/background.png',
            'login_text_color' => '#fefefe',
            'login_icon_color' => '#123abc',
            'font_size' => 16,
        ], true);

        $response = $this->getJson('/api/system/setting')
            ->assertOk()
            ->assertJsonPath('ret', 1)
            ->assertJsonPath('data.system_alias', 'Example Team')
            ->assertJsonPath('data.login_text_color', '#fefefe')
            ->assertJsonPath('data.login_icon_color', '#123abc')
            ->assertJsonPath('data.font_size', 16);

        $this->assertStringEndsWith(
            '/uploads/user/picture/1/202609/example.png',
            $response->json('data.login_logo')
        );
        $this->assertStringEndsWith(
            '/uploads/user/picture/1/202609/background.png',
            $response->json('data.login_background')
        );

        $page = $this->get('/login')
            ->assertOk()
            ->assertSee('Example Team', false);

        $this->assertStringContainsString(
            '/uploads/user/picture/1/202609/example.png',
            str_replace('\/', '/', $page->getContent())
        );
        $this->assertStringContainsString(
            '/uploads/user/picture/1/202609/background.png',
            str_replace('\/', '/', $page->getContent())
        );
        $page->assertSee('loginTextColor: "#fefefe"', false)
            ->assertSee('loginIconColor: "#123abc"', false)
            ->assertSee('fontSize: 16', false);
    }

    public function test_global_font_size_defaults_to_fourteen_for_missing_or_invalid_settings(): void
    {
        foreach ([null, 21] as $value) {
            Base::setting('system', ['font_size' => $value], true);
            $this->getJson('/api/system/setting')
                ->assertOk()
                ->assertJsonPath('data.font_size', 14);
            $this->get('/login')->assertOk()->assertSee('fontSize: 14', false);
        }
    }

}
