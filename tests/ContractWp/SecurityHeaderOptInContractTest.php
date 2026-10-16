<?php
namespace Peanut\Connect\Tests\ContractWp;

use WP_UnitTestCase;

class SecurityHeaderOptInContractTest extends WP_UnitTestCase {
    private $previous;
    private const CALLBACK = ['Peanut_Connect_Security', 'send_security_headers'];

    public function set_up(): void {
        parent::set_up();
        $this->previous = get_option('peanut_connect_security_headers', false);
        remove_action('send_headers', self::CALLBACK);
    }

    public function tear_down(): void {
        remove_action('send_headers', self::CALLBACK);
        if ($this->previous === false) {
            delete_option('peanut_connect_security_headers');
        } else {
            update_option('peanut_connect_security_headers', $this->previous);
        }
        \Peanut_Connect_Security::init();
        parent::tear_down();
    }

    public function test_real_wordpress_default_does_not_register_header_emission(): void {
        delete_option('peanut_connect_security_headers');
        \Peanut_Connect_Security::init();
        $this->assertFalse(has_action('send_headers', self::CALLBACK));
        $this->assertFalse(\Peanut_Connect_Security::get_settings()['security_headers']);
    }

    public function test_explicit_opt_in_registers_at_the_real_frontend_header_hook(): void {
        update_option('peanut_connect_security_headers', '1');
        \Peanut_Connect_Security::init();
        $this->assertSame(10, has_action('send_headers', self::CALLBACK));
        $this->assertTrue(\Peanut_Connect_Security::get_settings()['security_headers']);
    }
}
