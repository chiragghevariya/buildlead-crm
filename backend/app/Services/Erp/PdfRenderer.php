<?php

namespace App\Services\Erp;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Quote;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Branded A4 PDFs (dompdf, DejaVu Sans for € and umlauts).
 */
class PdfRenderer
{
    public function quote(Quote $quote): string
    {
        $quote->loadMissing(['items', 'lead', 'creator']);

        return $this->render('pdf.quote', ['quote' => $quote, 'lead' => $quote->lead]);
    }

    public function invoice(Invoice $invoice): string
    {
        $invoice->loadMissing(['project.lead', 'project.quote', 'project.invoices']);

        // Invoices issued before this one (creation order), excluding drafts.
        $earlier = $invoice->project->invoices
            ->filter(fn (Invoice $i) => $i->id < $invoice->id && $i->status !== InvoiceStatus::Draft)
            ->sortBy('id');

        return $this->render('pdf.invoice', [
            'invoice' => $invoice,
            'project' => $invoice->project,
            'lead' => $invoice->project->lead,
            'previouslyInvoiced' => (float) $earlier->sum('amount'),
            'earlierInvoices' => $earlier->values(),
        ]);
    }

    public function filename(Quote|Invoice $document): string
    {
        return $document->number.'.pdf';
    }

    private function render(string $view, array $data): string
    {
        $helpers = [
            'company' => config('company'),
            'money' => fn ($v) => Money::cents($v),
            'qty' => fn ($v) => Money::quantity($v),
            'date' => fn ($d) => $d ? $d->format('j M Y') : '—',
        ];

        $pdf = Pdf::loadView($view, $data + $helpers)
            ->setPaper('a4')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isFontSubsettingEnabled' => true, 'dpi' => 96]);

        // dompdf has no CSS total-page counter: stamp "Seite x / y" onto every page after layout.
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 84, $canvas->get_height() - 30, 'Seite {PAGE_NUM} / {PAGE_COUNT}', $font, 6, [0.42, 0.45, 0.5]);

        return $dompdf->output();
    }
}
