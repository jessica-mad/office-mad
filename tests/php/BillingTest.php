<?php
use MAD_Oficina\Billing\Invoices;
use MAD_Oficina\Billing\LegacyImport;
use MAD_Oficina\Billing\Money;
use MAD_Oficina\Billing\Profiles;
use MAD_Oficina\Core\Repo\Clients;

/**
 * Billing: amounts, the legacy-sheet import and numbering. Invented data only.
 */
class BillingTest extends MADOF_TestCase {

    const HEADER = 'fecha,n_factura,cliente,cif,localidad,domicilio,concepto_1,valor_1,concepto_2,valor_2,base,iva,irpf,total,email,Enviar?,pdf,año,mes,PAGO,deuda,Presupuesto';

    private function csv(array $rows){
        $out = [self::HEADER];
        foreach ( $rows as $r ) {
            $out[] = implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $r));
        }
        return implode("\n", $out);
    }

    private function row($date, $num, $client, $nif, $concept, $base, $vat, $irpf, $total, $sent = 'Enviado', $paid = 'TRUE'){
        return [$date, $num, $client, $nif, 'Madrid', 'Calle Falsa 1', $concept, $base, '', '', $base, $vat, $irpf, $total, 'jess@imad.pro', $sent, $sent ? 'https://drive.google.com/file/d/x/view' : '', '', '', $paid, 'FALSE', 'FALSE'];
    }

    private function sample(){
        return $this->csv([
            ['2025', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''], // separator row
            $this->row('15/01/2025', '25-001', 'Taller Azul SL', 'ES-B11111111', 'Gestión de marketing', '€1.000,00', '€210,00', '-€150,00', '€1.060,00'),
            $this->row('20/02/2025', '25-002', 'Taller Azul S.L.', 'B11111111', 'Gestión de marketing', '€500,00', '€105,00', '-€75,00', '€530,00', '', 'FALSE'),
            $this->row('28/02/2025', '', 'Estudio Pampa', '', 'Diseño', '€500,00', '€0,00', '€0,00', '€500,00'),
            $this->row('28/03/2025', '25-', 'Panadería Lúa', 'B22222222', 'Gestión', '€0,00', '€0,00', '€0,00', '€0,00', ''),
            $this->row('30/09/2026', '26-019', 'Panadería Lúa', 'B22222222', 'Gestión', '€350,00', '€73,50', '-€52,50', '€371,00'),
            $this->row('30/09/2026', '26-020', 'Taller Azul SL', 'B11111111', 'Web fase 2', '€731,79', '€153,68', '-€109,77', '€775,70'),
            $this->row('30/10/2026', '26-019', 'Panadería Lúa', 'B22222222', 'Gestión', '€350,00', '€73,50', '-€52,50', '€371,00', ''),
            $this->row('30/10/2026', '26-022', 'Museo Mini', 'B22222222', 'Setup', '€600,00', '€126,00', '-€90,00', '€636,00', ''),
            $this->row('15/06/2026', 'R-26-01 RECTIFICATIVA', 'Taller Azul SL', 'B11111111', 'Rectifica 25-002', '-€500,00', '-€105,00', '€75,00', '-€530,00'),
        ]);
    }

    public function test_money_parsing(){
        $this->assertSame(1500.0, Money::parse('€1.500,00'));
        $this->assertSame(-22.5, Money::parse('-€22,50'));
        $this->assertSame(300.0, Money::parse('300 €'));
        $this->assertSame(731.79, Money::parse('731.79'));
        $this->assertNull(Money::parse(''));
        $this->assertSame('1.234,50 €', Money::format(1234.5));
    }

    public function test_analysis_spots_real_problems_only(){
        $a = LegacyImport::analyze($this->sample());
        $this->assertSame(9, $a['stats']['total'], 'separator row ignored');
        $issues = implode(' ', $a['issues']);
        $this->assertStringContainsString('B22222222', $issues, 'same NIF for Panadería Lúa and Museo Mini');
        $this->assertStringNotContainsString('B11111111', $issues, '«Taller Azul SL» and «Taller Azul S.L.» are the same client');
        $this->assertArrayHasKey('Estudio Pampa', $a['ar_candidates']);
        $this->assertTrue($a['ar_candidates']['Estudio Pampa']['suggested']);
        $this->assertArrayNotHasKey('Panadería Lúa', $a['ar_candidates'], 'empty rows are not candidates');
    }

    public function test_import_keeps_history_and_fixes_october(){
        $a = LegacyImport::analyze($this->sample());
        $count = LegacyImport::apply($a, ['Estudio Pampa']);
        $this->assertSame(8, $count['imported']);
        $this->assertSame(1, $count['skipped_empty']);
        $this->assertSame(1, $count['argentina']);
        $this->assertSame(2, $count['renumbered'], 'October drafts lose the numbers they borrowed');

        $f = fn($n) => Invoices::all(['q' => $n])[0] ?? null;
        $first = $f('25-001');
        $this->assertSame('sent', $first['status']);
        $this->assertTrue($first['paid']);
        $this->assertSame(21.0, $first['vat_rate']);
        $this->assertSame(15.0, $first['irpf_rate']);
        $this->assertSame('issued', $f('25-002')['status'], 'numbered and earlier than the last sent one: an issued invoice');
        $this->assertFalse($f('25-002')['paid']);
        $this->assertSame('R', $f('R-26-01')['series']);

        $ar = Invoices::all(['series' => 'AR']);
        $this->assertCount(1, $ar);
        $this->assertNull($ar[0]['number']);

        $drafts = Invoices::all(['status' => 'draft', 'year' => 2026]);
        $this->assertCount(2, $drafts);
        foreach ( $drafts as $d ) {
            $this->assertNull($d['number']);
            $this->assertNotEmpty($d['notes']);
        }
        $museo = array_values(array_filter($drafts, fn($d) => $d['client_name'] === 'Museo Mini'))[0];
        $this->assertNull($museo['client_tax_id'], 'the copied NIF is removed from the draft');

        // Same Taller Azul profile despite the different spellings.
        $this->assertSame($f('25-001')['profile_id'], $f('25-002')['profile_id']);

        // Idempotent.
        $again = LegacyImport::apply(LegacyImport::analyze($this->sample()), ['Estudio Pampa']);
        $this->assertSame(0, $again['imported']);
        $this->assertSame(8, $again['skipped_existing']);
    }

    public function test_drafts_get_the_next_free_number(){
        LegacyImport::apply(LegacyImport::analyze($this->sample()), ['Estudio Pampa']);
        $draft = Invoices::all(['status' => 'draft', 'year' => 2026])[0];
        $issued = Invoices::assign_number($draft['id'], '2026-10-31');
        $this->assertSame('26-021', $issued['number'], 'after 26-020 (26-022 was only a draft)');
        $this->assertSame('issued', $issued['status']);
        $other = Invoices::all(['status' => 'draft', 'year' => 2026])[0];
        $this->assertSame('26-022', Invoices::assign_number($other['id'], '2026-10-31')['number']);
        $this->assertSame('26-021', Invoices::assign_number($draft['id'])['number'], 'numbering twice changes nothing');

        $ar = Invoices::all(['series' => 'AR'])[0];
        $this->assertWPError(Invoices::assign_number($ar['id']));
    }

    public function test_summary_only_counts_spanish_books(){
        LegacyImport::apply(LegacyImport::analyze($this->sample()), ['Estudio Pampa']);
        $m = Invoices::monthly(2025);
        $this->assertSame(1000.0, $m[1]['base']);
        $this->assertSame(500.0, $m[2]['base']);
        $this->assertSame(0.0, $m[3]['base'], 'Argentina series and drafts excluded');
        $this->assertSame(500.0, (float) Invoices::external_total(2025)['total']);
    }

    public function test_totals_and_number_format(){
        $t = Invoices::totals([['amount' => 731.79]], 21, 15);
        $this->assertSame(['base' => 731.79, 'vat' => 153.68, 'irpf' => 109.77, 'total' => 775.7], $t);
        $this->assertSame('26-007', Invoices::format_number('F', 2026, 7));
        $this->assertSame('R-26-02', Invoices::format_number('R', 2026, 2));
        $this->assertSame('B21507181', Profiles::norm_tax_id('ES-B21507181'));
        $this->assertSame('15456125X', Profiles::norm_tax_id('15456125-X'));
    }

    public function test_erasing_a_client_keeps_its_invoices(){
        $client = $this->make_client('TAZ', 'Taller Azul');
        LegacyImport::apply(LegacyImport::analyze($this->sample()), []);
        global $wpdb;
        $wpdb->query($wpdb->prepare('UPDATE ' . Invoices::table() . ' SET client_id = %d', $client));
        Clients::delete($client);
        $this->assertNotEmpty(Invoices::all(['q' => '25-001']));
        $this->assertNull(Invoices::all(['q' => '25-001'])[0]['client_id']);
    }
}
