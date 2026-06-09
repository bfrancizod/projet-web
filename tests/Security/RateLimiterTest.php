<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private const RATE_KEY = 'test_unitaire_rate_limiter';

    protected function setUp(): void
    {
        $_SESSION = [];
        RateLimiter::reset(self::RATE_KEY);
    }

    protected function tearDown(): void
    {
        RateLimiter::reset(self::RATE_KEY);
    }

    public function testRateLimiterBloqueApresLeMaximum(): void
    {
        $this->assertSame(3, RateLimiter::getRemaining(self::RATE_KEY, 3, 900));

        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));
        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));
        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));
        $this->assertFalse(RateLimiter::checkLimit(self::RATE_KEY, 3, 900));
    }

    public function testRateLimiterReinitialisation(): void
    {
        RateLimiter::checkLimit(self::RATE_KEY, 1, 900);

        $this->assertFalse(RateLimiter::checkLimit(self::RATE_KEY, 1, 900));

        RateLimiter::reset(self::RATE_KEY);

        $this->assertTrue(RateLimiter::checkLimit(self::RATE_KEY, 1, 900));
    }
}
