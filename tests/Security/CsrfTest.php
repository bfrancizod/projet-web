<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Security\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testCsrfGenerationDuToken(): void
    {
        $token = Csrf::token();

        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, $_SESSION['_csrf_token']);
    }

    public function testCsrfValidation(): void
    {
        $token = Csrf::token();

        $this->assertTrue(Csrf::validate($token));
        $this->assertFalse(Csrf::validate('faux'));
        $this->assertFalse(Csrf::validate(null));
        $this->assertFalse(Csrf::validate(''));
    }

    public function testCsrfRotation(): void
    {
        $ancien = Csrf::token();

        Csrf::rotate();

        $this->assertNotSame($ancien, Csrf::token());
        $this->assertFalse(Csrf::validate($ancien));
    }
}
