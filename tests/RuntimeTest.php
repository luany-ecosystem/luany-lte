<?php

namespace Luany\Lte\Tests;

use Luany\Lte\Engine;
use Luany\Lte\Runtime;
use PHPUnit\Framework\TestCase;

class RuntimeTest extends TestCase
{
    private string $views;
    private string $cache;

    protected function setUp(): void
    {
        $base        = sys_get_temp_dir() . '/lte_runtime_' . bin2hex(random_bytes(4));
        $this->views = $base . '/views';
        $this->cache = $base . '/cache';
        mkdir($this->views, 0777, true);
        Runtime::reset();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        Runtime::reset();
        $_SESSION = [];
    }

    private function render(string $tpl, array $data = []): string
    {
        $name = 'v' . bin2hex(random_bytes(4));
        file_put_contents("{$this->views}/{$name}.lte", $tpl);
        return (new Engine($this->views, $this->cache, true))->render($name, $data);
    }

    public function test_dump_is_noop_when_debug_disabled(): void
    {
        Runtime::setDebug(false);
        $this->assertSame('[]', $this->render('[@dump($x)]', ['x' => 'secret']));
    }

    public function test_dump_outputs_when_debug_enabled(): void
    {
        $this->assertStringContainsString('secret', $this->render('@dump($x)', ['x' => 'secret']));
    }

    public function test_auth_uses_custom_resolver(): void
    {
        Runtime::setAuthResolver(fn () => true);
        $this->assertSame('in', trim($this->render('@auth in@endauth@guest out@endguest')));

        Runtime::setAuthResolver(fn () => false);
        $this->assertSame('out', trim($this->render('@auth in@endauth@guest out@endguest')));
    }

    public function test_auth_default_uses_session_user_id(): void
    {
        $_SESSION['user_id'] = 1;
        $this->assertSame('in', trim($this->render('@auth in@endauth')));
    }
}
