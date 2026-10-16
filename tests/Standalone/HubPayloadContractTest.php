<?php
if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
function get_option($key, $default = false) { return $GLOBALS['contract_options'][$key] ?? $default; }
require_once dirname(__DIR__, 2) . '/includes/class-connect-hub-sync.php';
require_once dirname(__DIR__, 2) . '/includes/class-connect-health.php';
final class HubPayloadContractTest extends \PHPUnit\Framework\TestCase {
    public function testCurrentValidationEnvelopeReportsFieldsWithoutCustomerValues(): void {
        $body = ['error'=>['message'=>'The given data was invalid.', 'validation'=>['form_submissions.0.data'=>['private customer input']]]];
        self::assertSame('The given data was invalid. Fields: form_submissions.0.data', Peanut_Connect_Hub_Sync::hub_error_message($body,422));
        self::assertSame('Old response', Peanut_Connect_Hub_Sync::hub_error_message(['message'=>'Old response'],400));
        self::assertSame('HTTP 502', Peanut_Connect_Hub_Sync::hub_error_message(null,502));
    }
    public function testBackupDetectionMatchesHubsBooleanContractWithAndWithoutPlugin(): void {
        $method = new \ReflectionMethod(Peanut_Connect_Health::class, 'get_backup_data');
        $GLOBALS['contract_options'] = ['active_plugins'=>['updraftplus/updraftplus.php']];
        $with = $method->invoke(null);
        self::assertTrue($with['plugin_detected']);
        self::assertSame('UpdraftPlus',$with['plugin_name']);
        $GLOBALS['contract_options'] = [];
        $without = $method->invoke(null);
        self::assertFalse($without['plugin_detected']);
        self::assertNull($without['plugin_name']);
    }
}
