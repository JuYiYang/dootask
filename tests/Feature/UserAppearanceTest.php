<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\AppearanceController;
use App\Models\User;
use App\Module\Base;
use App\Module\UserAppearance;
use App\Services\RequestContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class UserAppearanceTest extends TestCase
{
    use DatabaseTransactions;

    private $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        RequestContext::clean();
        $this->dispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
    }

    protected function tearDown(): void
    {
        RequestContext::clean();
        Model::setEventDispatcher($this->dispatcher);
        parent::tearDown();
    }

    private function user(): User
    {
        $user = User::createInstance(['email' => uniqid('font') . '@example.com', 'nickname' => 'Font test', 'identity' => ',normal,']);
        $user->save();
        return $user;
    }

    public function test_appearance_endpoints_are_registered(): void
    {
        foreach (['settings', 'save'] as $method) {
            $route = app('router')->getRoutes()->match(Request::create('/api/appearance/' . $method, $method === 'save' ? 'POST' : 'GET'));
            $this->assertSame('api/appearance/{method}', $route->uri());
        }
    }

    public function test_fonts_are_persistent_and_isolated_per_account_and_can_follow_system(): void
    {
        $first = $this->user();
        $second = $this->user();
        Base::setting('system', ['font_size' => 18], true);
        $this->assertNull(UserAppearance::get($first)['font_size']);
        UserAppearance::save($first, 12);
        UserAppearance::save($second, 20);
        RequestContext::clean();
        $this->assertSame(12, UserAppearance::get($first->fresh())['font_size']);
        $this->assertSame(20, UserAppearance::get($second->fresh())['font_size']);
        UserAppearance::save($first, null);
        $this->assertNull(UserAppearance::get($first)['font_size']);
        $this->assertSame(20, UserAppearance::get($second)['font_size']);
        $this->assertSame(18, Base::settingFind('system', 'font_size'));
    }

    public function test_invalid_font_sizes_cannot_change_existing_preference(): void
    {
        $user = $this->user();
        UserAppearance::save($user, 16);
        foreach ([11, 21, 14.5, true, '16'] as $invalid) {
            try {
                UserAppearance::save($user, $invalid);
                $this->fail('Invalid size was accepted');
            } catch (ApiException $e) {
                $this->assertSame(16, UserAppearance::get($user)['font_size']);
            }
        }
    }

    public function test_controller_always_updates_authenticated_account_and_rejects_get_writes(): void
    {
        $first = $this->user();
        $second = $this->user();
        \Illuminate\Support\Facades\Request::swap(Request::create('/api/appearance/save', 'POST', ['font_size' => 17, 'userid' => $second->userid]));
        RequestContext::save('auth', $first);
        $controller = new AppearanceController();
        $this->assertSame(1, $controller->save()['ret']);
        $this->assertSame(17, UserAppearance::get($first)['font_size']);
        $this->assertNull(UserAppearance::get($second)['font_size']);
        \Illuminate\Support\Facades\Request::swap(Request::create('/api/appearance/save', 'GET', ['font_size' => 12]));
        RequestContext::save('auth', $first);
        $this->assertSame(0, $controller->save()['ret']);
        $this->assertSame(17, UserAppearance::get($first)['font_size']);
    }
}
