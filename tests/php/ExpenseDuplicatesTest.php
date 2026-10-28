<?php
use MAD_Oficina\Meetings\Orders;
use MAD_Oficina\Suppliers\Duplicates;
use MAD_Oficina\Suppliers\Expenses;
use MAD_Oficina\Suppliers\Intake;
use MAD_Oficina\Suppliers\Suppliers;

/** The same supplier invoice received twice. Invented data only. */
class ExpenseDuplicatesTest extends MADOF_TestCase {

    private function exp(array $over){
        return Expenses::insert($over + ['supplier_name' => 'Hosting Nube SL', 'issue_date' => '2026-03-01', 'total' => 49.9, 'base' => 41.24, 'number' => 'HN-77', 'status' => 'registered', 'source' => 'gmail']);
    }

    private function moves(){
        return array_values(array_filter(Orders::pending(), fn($o) => in_array($o['type'], ['expense_move', 'expense_archive'], true)));
    }

    public function test_the_same_document_twice_is_discarded_and_two_documents_go_together_for_her_to_choose(){
        $sup = Suppliers::save(['name' => 'Hosting Nube SL']);
        $a = $this->exp(['supplier_id' => $sup, 'drive_file_id' => 'fileA', 'gmail_message_id' => 'm1', 'filename' => 'f.pdf']);
        $same = $this->exp(['supplier_id' => $sup, 'gmail_message_id' => 'm1', 'filename' => 'f.pdf']);
        $other = $this->exp(['supplier_id' => $sup, 'drive_file_id' => 'fileB', 'source' => 'drive', 'number' => 'HN 77']);
        $notdup = $this->exp(['supplier_id' => $sup, 'number' => 'HN-78']);

        $r = (new Duplicates('root'))->scan();
        $this->assertSame(['identical' => 1, 'grouped' => 1], $r);
        $this->assertSame(['registered', 'ignored', 'ignored', 'registered'], array_map(fn($id) => Expenses::get($id)['status'], [$a, $same, $other, $notdup]), 'only one counts');
        $open = Duplicates::open();
        $this->assertCount(1, $open);
        $g = reset($open); $key = key($open);
        $this->assertSame('2026-03-01 Hosting Nube SL 49,90 Nº HN-77', $g['folder']);
        $folders = array_map(fn($o) => $o['payload']['folder'], $this->moves());
        $this->assertSame([['Duplicados', $g['folder']], ['Duplicados', $g['folder']]], $folders, 'both documents side by side');

        // Again: nothing new.
        $this->assertSame(['identical' => 0, 'grouped' => 0], (new Duplicates('root'))->scan());

        // She keeps the second one: it counts and goes back to its quarter.
        $intake = new Intake([]);
        (new Duplicates('root'))->keep($key, $other, $intake);
        $this->assertSame(['ignored', 'registered'], [Expenses::get($a)['status'], Expenses::get($other)['status']]);
        $moves = $this->moves(); $last = end($moves);
        $this->assertSame(['2026', '2026-T1'], $last['payload']['folder']);
        $this->assertCount(0, Duplicates::open());
    }

    public function test_not_duplicates_both_count_and_are_not_asked_again(){
        $a = $this->exp(['drive_file_id' => 'x1']);
        $b = $this->exp(['drive_file_id' => 'x2']);
        (new Duplicates())->scan();
        $key = key(Duplicates::open());
        (new Duplicates())->dismiss($key, new Intake([]));
        $this->assertSame(['registered', 'registered'], [Expenses::get($a)['status'], Expenses::get($b)['status']]);
        $this->assertSame(['identical' => 0, 'grouped' => 0], (new Duplicates())->scan());
    }
}
