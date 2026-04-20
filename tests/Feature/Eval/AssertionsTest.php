<?php

namespace Tests\Feature\Eval;

use App\Eval\AssertionFactory;
use PHPUnit\Framework\TestCase;

class AssertionsTest extends TestCase
{
    public function test_contains_text_passes_when_substring_present_case_insensitive(): void
    {
        $a = AssertionFactory::make(['type' => 'contains_text', 'value' => 'WELCOME']);
        $r = $a->evaluate('Hello, welcome to the hotel.', []);
        $this->assertTrue($r->passed);
    }

    public function test_contains_text_fails_when_absent(): void
    {
        $a = AssertionFactory::make(['type' => 'contains_text', 'value' => 'diagnosis']);
        $r = $a->evaluate('Hello, welcome.', []);
        $this->assertFalse($r->passed);
        $this->assertStringContainsString('diagnosis', $r->reason);
    }

    public function test_does_not_contain_is_inverse(): void
    {
        $a = AssertionFactory::make(['type' => 'does_not_contain', 'value' => 'diagnosis']);
        $this->assertTrue($a->evaluate('Hello.', [])->passed);
        $this->assertFalse($a->evaluate('Your diagnosis is X.', [])->passed);
    }

    public function test_matches_regex_passes(): void
    {
        $a = AssertionFactory::make(['type' => 'matches_regex', 'pattern' => '/hello\s+world/i']);
        $this->assertTrue($a->evaluate('HELLO WORLD', [])->passed);
        $this->assertFalse($a->evaluate('goodbye', [])->passed);
    }

    public function test_matches_regex_throws_on_invalid_pattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssertionFactory::make(['type' => 'matches_regex', 'pattern' => '/unterminated'])
            ->evaluate('x', []);
    }

    public function test_citation_count_at_least(): void
    {
        $a = AssertionFactory::make(['type' => 'citation_count_at_least', 'min' => 2]);
        $this->assertTrue($a->evaluate('See [1] and (PMID:12345).', [])->passed);
        $this->assertFalse($a->evaluate('See [1] only.', [])->passed);
    }

    public function test_red_flag_triggered_from_context(): void
    {
        $a = AssertionFactory::make(['type' => 'red_flag_triggered']);
        $this->assertTrue($a->evaluate('anything', ['red_flag_fired' => true])->passed);
        $this->assertTrue($a->evaluate('If you are in immediate danger, call 911.', [])->passed);
        $this->assertFalse($a->evaluate('Normal reply.', [])->passed);
    }

    public function test_verification_status(): void
    {
        $a = AssertionFactory::make(['type' => 'verification_status', 'value' => 'passed']);
        $this->assertTrue($a->evaluate('x', ['verification_status' => 'passed'])->passed);
        $this->assertFalse($a->evaluate('x', ['verification_status' => 'blocked'])->passed);
    }

    public function test_factory_throws_on_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AssertionFactory::make(['type' => 'no_such_type']);
    }
}
