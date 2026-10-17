<?php
namespace FFFL { class Plugin {} }
namespace FFFL\Database {
    class Database {
        public function get_submission($id) { return ['id'=>$id,'instance_id'=>5,'form_data'=>['email'=>'verification@example.invalid','confirmation_number'=>'IS-123'],'is_test'=>0,'status'=>'completed']; }
        public function get_instance($id) { return ['name'=>'Utility enrollment']; }
    }
}
namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
    if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
    $GLOBALS['bridge_hooks'] = [];
    function add_action($hook,$callback,$priority=10,$args=1) { $GLOBALS['bridge_hooks'][$hook]=$callback; }
    function add_shortcode($name,$callback) {}
    function home_url($path='') { return 'https://utility.example'.$path; }
    function current_time($type,$gmt=false) { return '2026-10-16 18:00:00'; }
    function wp_generate_uuid4() { return '00000000-0000-4000-a000-000000000000'; }
    function wp_json_encode($value) { return json_encode($value); }
    function get_option($key,$default=false) { return $GLOBALS['bridge_options'][$key] ?? $default; }
    function update_option($key,$value,$autoload=false) { $GLOBALS['bridge_options'][$key]=$value; }
    class Peanut_Connect_Database { public static function table($name) { return 'wp_peanut_connect_'.$name; } }
    class Peanut_Connect_Tracker { public static function get_visitor_id() { return ''; } }
    class BridgeWpdb {
        public string $prefix='wp_';
        public array $rows=[];
        public array $queries=[];
        public bool $fail=false;
        public ?array $sourceCompletion=null;
        public function query($sql) { $this->queries[]=$sql; return 0; }
        public function prepare($sql,...$args) { $this->queries[]=$sql; return $args[0] ?? ''; }
        public function get_var($uuid) { return isset($this->rows[$uuid]) ? 1 : null; }
        public function insert($table,$row) { if ($this->fail) return false; $this->rows[$row['submission_uuid']]=$row; return 1; }
        public function get_results($cursor,$mode) { if ($this->sourceCompletion===null) return $cursor < 42 ? [['id'=>42,'instance_id'=>5]] : []; $rows=[]; foreach ($this->sourceCompletion as $id=>$done) if ($done && $id>$cursor) $rows[]=['id'=>$id,'instance_id'=>5]; return array_slice($rows,0,100); }
    }
    require_once dirname(__DIR__, 2).'/includes/class-connect-forms.php';
    final class FormFlowLiteBridgeTest extends \PHPUnit\Framework\TestCase {
        public function testLiteCompletionQueuesDecryptedDataExactlyOnce(): void {
            $GLOBALS['wpdb']=new BridgeWpdb();
            Peanut_Connect_Forms::init();
            self::assertArrayHasKey('fffl_enrollment_completed',$GLOBALS['bridge_hooks']);
            self::assertTrue(Peanut_Connect_Forms::is_formflow_active());
            ($GLOBALS['bridge_hooks']['fffl_enrollment_completed'])(42,5);
            ($GLOBALS['bridge_hooks']['fffl_enrollment_completed'])(42,5);
            self::assertCount(1,$GLOBALS['wpdb']->rows);
            $row=array_values($GLOBALS['wpdb']->rows)[0];
            self::assertSame('verification@example.invalid',json_decode($row['data'],true)['email']);
            self::assertSame('IS-123',json_decode($row['metadata'],true)['confirmation_number']);
            self::assertFalse(json_decode($row['metadata'],true)['is_test']);
        }
        public function testWrongInstanceCannotQueueAnotherFormsData(): void {
            $GLOBALS['wpdb']=new BridgeWpdb();
            Peanut_Connect_Forms::handle_formflow_submission(42,99);
            self::assertSame([],$GLOBALS['wpdb']->rows);
        }
        public function testBackfillIsBoundedAndRetrySafe(): void {
            $GLOBALS['wpdb']=new BridgeWpdb();
            $GLOBALS['bridge_options']=[];
            Peanut_Connect_Forms::backfill_formflow_lite_submissions();
            self::assertSame(42,$GLOBALS['bridge_options']['peanut_connect_fffl_completed_cursor']);
            Peanut_Connect_Forms::backfill_formflow_lite_submissions();
            self::assertCount(1,$GLOBALS['wpdb']->rows);
            self::assertSame(0,$GLOBALS['bridge_options']['peanut_connect_fffl_completed_cursor']);
            self::assertStringContainsString("status = 'completed'",$GLOBALS['wpdb']->queries[0]);
            self::assertStringContainsString('LIMIT 100',$GLOBALS['wpdb']->queries[0]);
        }
        public function testMissedEarlierSubmissionRecoversAfterItCompletesLater(): void {
            $GLOBALS['wpdb']=new BridgeWpdb();
            $GLOBALS['wpdb']->sourceCompletion=[40=>false,42=>true];
            $GLOBALS['bridge_options']=[];
            Peanut_Connect_Forms::backfill_formflow_lite_submissions();
            $GLOBALS['wpdb']->sourceCompletion[40]=true;
            Peanut_Connect_Forms::backfill_formflow_lite_submissions();
            Peanut_Connect_Forms::backfill_formflow_lite_submissions();
            $ids=array_map(fn($row)=>json_decode($row['metadata'],true)['formflow_submission_id'],array_values($GLOBALS['wpdb']->rows));
            sort($ids);
            self::assertSame([40,42],$ids);
            self::assertCount(2,$GLOBALS['wpdb']->rows);
        }
        public function testFailedQueueWriteDoesNotAdvanceBackfillCursor(): void {
            $GLOBALS['wpdb']=new BridgeWpdb();
            $GLOBALS['wpdb']->fail=true;
            $GLOBALS['bridge_options']=[];
            try { Peanut_Connect_Forms::backfill_formflow_lite_submissions(); self::fail('Expected queue failure'); }
            catch (\RuntimeException $e) { self::assertArrayNotHasKey('peanut_connect_fffl_completed_cursor',$GLOBALS['bridge_options']); }
        }
    }
}
