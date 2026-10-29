<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\Invoice;
use Dompdf\{Dompdf, Options};
use Twig\Environment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class InvoicePdf
{
    public function __construct(private Environment $twig, #[Autowire('%invoice.archive_dir%')] private string $archiveDir) {}
    public function html(Invoice $invoice): string
    {
        $snapshot = $invoice->getSnapshot();
        return $this->twig->render('invoice/document_v'.(int) $snapshot['schema'].'.html.twig', ['invoice'=>$invoice, 'document'=>$snapshot]);
    }
    public function generate(Invoice $invoice): string
    {
        if (!is_dir($this->archiveDir) && !mkdir($this->archiveDir, 0700, true) && !is_dir($this->archiveDir)) throw new \RuntimeException('Archivage PDF indisponible.');
        if (!is_file($this->archiveDir.'/.htaccess') && file_put_contents($this->archiveDir.'/.htaccess', "Require all denied\n") === false) throw new \RuntimeException('Protection de l’archive indisponible.');
        $fingerprint = hash('sha256', $invoice->getNumber().$invoice->getIssuedAt()->format(DATE_ATOM).json_encode($invoice->getSnapshot(), JSON_THROW_ON_ERROR));
        $path = $this->archiveDir.'/'.$invoice->getNumber().'-'.$fingerprint.'.pdf';
        $lock = fopen($this->archiveDir.'/'.$invoice->getNumber().'.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Archivage PDF temporairement indisponible.');
        try {
            if (is_file($path)) return file_get_contents($path);
            $bytes = $this->render($invoice, $fingerprint);
            $temporary = tempnam($this->archiveDir, 'invoice-');
            if (!$temporary) throw new \RuntimeException('Archivage PDF indisponible.');
            try {
                if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $path)) throw new \RuntimeException('Archivage PDF impossible.');
            } finally { if (is_file($temporary)) unlink($temporary); }
            return $bytes;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private function render(Invoice $invoice, string $fingerprint): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false); $options->set('isPhpEnabled', false); $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans'); $options->set('isFontSubsettingEnabled', true);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->html($invoice), 'UTF-8'); $pdf->setPaper('A4', 'portrait'); $pdf->render();
        $canvas = $pdf->getCanvas();
        if ($canvas instanceof \Dompdf\Adapter\CPDF) {
            $canvas->add_info('CreationDate', 'D:'.$invoice->getIssuedAt()->format('YmdHisO'));
            $canvas->add_info('ModDate', 'D:'.$invoice->getIssuedAt()->format('YmdHisO'));
            $canvas->get_cpdf()->fileIdentifier = substr($fingerprint, 0, 32);
        }
        return $pdf->output();
    }
}
