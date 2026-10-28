<?php
use MAD_Oficina\Suppliers\Expenses;
use MAD_Oficina\Suppliers\Suppliers;

/** Searching expenses by name, text or amount. Invented data only. */
class ExpenseSearchTest extends MADOF_TestCase {

    public function test_finds_by_supplier_concept_number_and_amount_in_any_year_and_state(){
        $sup = Suppliers::save(['name' => 'Hosting Nube SL']);
        $a = Expenses::insert(['supplier_id' => $sup, 'supplier_name' => 'HOSTNUBE', 'concept' => 'Servidor VPS octubre', 'number' => 'HN-2024-77', 'issue_date' => '2024-10-02', 'total' => 49.9, 'status' => 'registered']);
        $b = Expenses::insert(['supplier_name' => 'Papelería Lápiz', 'concept' => 'Folios y tóner', 'issue_date' => '2026-03-10', 'total' => 12.5, 'status' => 'review', 'sender' => 'ventas@lapiz.example']);
        $ids = fn($q) => array_map(fn($e) => (int) $e['id'], Expenses::all(['q' => $q]));
        $this->assertSame([$a], $ids('hosting nube'), 'by the registered supplier name');
        $this->assertSame([$a], $ids('vps'));
        $this->assertSame([$a], $ids('HN-2024'));
        $this->assertSame([$a], $ids('49,90'), 'by amount');
        $this->assertSame([$b], $ids('lapiz.example'), 'by email, also while waiting for review');
        $this->assertSame([], $ids('vps tóner'), 'every word must match');
        $this->assertCount(2, Expenses::all(['q' => '']), 'empty: no filter');
    }
}
