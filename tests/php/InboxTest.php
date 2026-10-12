<?php
use MAD_Oficina\Core\Approvals;

/** The inbox: filters by kind and several requests at once. */
class InboxTest extends MADOF_TestCase {

    public $seen = [];

    public function set_up(){
        parent::set_up();
        add_filter('madoficina_approval_handler_test_ok', fn() => function($a, $input){ $this->seen[] = [$a['id'], $input]; return ['message' => 'ok']; });
        add_filter('madoficina_approval_default_input_test_ok', fn($in, $a) => ['choice' => 'proposed'], 10, 2);
        add_filter('madoficina_approval_handler_test_fail', fn() => fn($a, $input) => new WP_Error('x', 'Calendar caído'));
    }

    public function test_bulk_approval_uses_each_proposal_and_reports_failures(){
        $a = Approvals::create('accountant', 'test_ok', 'Uno', []);
        $b = Approvals::create('accountant', 'test_ok', 'Dos', []);
        $c = Approvals::create('accountant', 'test_fail', 'Tres', []);
        $d = Approvals::create('accountant', 'test_ok', 'Cuatro', []);
        $counts = Approvals::count_by_type(); ksort($counts);
        $this->assertSame(['test_fail' => 1, 'test_ok' => 3], $counts);
        $this->assertCount(1, Approvals::pending(50, 'test_fail'));

        $r = Approvals::resolve_many([$a, $b, $c, $a], 'approve', 1);
        $this->assertSame(2, $r['done']);
        $this->assertSame(['Tres: Calendar caído'], $r['errors']);
        $this->assertSame([[$a, ['choice' => 'proposed']], [$b, ['choice' => 'proposed']]], $this->seen, 'each one «tal cual», once');
        $this->assertSame(Approvals::PENDING, Approvals::get($c)['status'], 'a failure stays in the inbox');
        $this->assertSame('bulk', Approvals::get($a)['channel']);

        $this->assertSame(1, Approvals::resolve_many([$d], 'reject')['done']);
        $this->assertSame(Approvals::REJECTED, Approvals::get($d)['status']);
        $this->assertSame(['test_fail' => 1], Approvals::count_by_type());
    }
}
