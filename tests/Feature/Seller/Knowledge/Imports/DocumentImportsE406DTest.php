<?php

namespace Tests\Feature\Seller\Knowledge\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Feature\Tenancy\TenancyTestCase;

final class DocumentImportsE406DTest extends TenancyTestCase
{
    private function pdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            "<< /Length 40 >>\nstream\nBT /F1 12 Tf 20 200 Td (PDF fact) Tj ET\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }

    public function test_pdf_docx_and_document_list_are_scoped_and_validated(): void
    {
        [$owner, $org, $brand, $other] = $this->cabinet();
        foreach ([$brand, $other] as $item) {
            DB::table('brand_modules')->insert(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'brand_id' => $item->id, 'module' => 'seller', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->withSession(['brand_id' => $brand->id]);
        $pdf = $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('facts.pdf', $this->pdf())])->assertOk()->assertJsonPath('proposals.0', 'PDF fact');
        $pdfId = $pdf->json('document_id');
        $word = new PhpWord;
        $word->addSection()->addText('DOCX fact');
        $path = tempnam(sys_get_temp_dir(), 'docx');
        IOFactory::createWriter($word, 'Word2007')->save($path);
        try {
            $this->postJson('/seller/knowledge/imports/preview', ['file' => new UploadedFile($path, 'facts.docx', null, null, true)])->assertOk()->assertJsonPath('proposals.0', 'DOCX fact');
        } finally {
            @unlink($path);
        }
        $this->assertDatabaseHas('seller_knowledge_import_documents', ['id' => $pdfId, 'brand_id' => $brand->id, 'name' => 'facts.pdf', 'type' => 'pdf', 'pages' => 1, 'status' => 'previewed']);
        $this->assertDatabaseHas('seller_knowledge_import_documents', ['brand_id' => $brand->id, 'name' => 'facts.docx', 'type' => 'docx', 'pages' => null]);
        $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('bad.docx', 'bad')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('bad.xyz', 'bad')])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->get('/seller/knowledge/imports')->assertOk()->assertSee($pdfId);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'imported'])->assertRedirect();
        $this->post('/seller/knowledge/imports', ['version' => 2, 'group' => 'imported', 'facts' => ['PDF fact'], 'document_id' => $pdfId])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('seller_knowledge_import_documents', ['id' => $pdfId, 'status' => 'imported']);
        $this->post('/brand/switch', ['brand_id' => $other->id])->assertRedirect();
        $this->get('/seller/knowledge/imports')->assertOk()->assertDontSee($pdfId);
        $this->post('/seller/knowledge/draft')->assertRedirect();
        $this->post('/seller/knowledge/facts/group', ['version' => 1, 'key' => 'imported'])->assertRedirect();
        $this->post('/seller/knowledge/imports', ['version' => 2, 'group' => 'imported', 'facts' => ['fact'], 'document_id' => $pdfId])->assertNotFound();
        [$viewer] = $this->member($org, 'viewer', [$other->id]);
        $this->actingAs($viewer)->withSession(['brand_id' => $other->id]);
        $this->postJson('/seller/knowledge/imports/preview', ['file' => UploadedFile::fake()->createWithContent('facts.pdf', $this->pdf())])->assertForbidden();
    }
}
