<?php

declare(strict_types=1);

namespace LapFund\Service;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * HTML to PDF with dompdf (architecture 10.1). Nothing is loaded from outside:
 * remote resources, PHP and JavaScript are off; files only from the uploads folder.
 */
final class Pdf
{
    public function __construct(private readonly string $root)
    {
    }

    public function render(string $html, string $title = '', bool $pageNumbers = true, bool $landscape = false): string
    {
        $cache = $this->root . '/storage/cache/dompdf';
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }
        $options = new Options([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'isFontSubsettingEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
            'defaultPaperSize' => 'a4',
            'chroot' => [$this->root . '/storage/uploads', $this->root . '/vendor/dompdf/dompdf'],
            'fontCache' => is_writable($cache) ? $cache : sys_get_temp_dir(),
            'tempDir' => is_writable($cache) ? $cache : sys_get_temp_dir(),
        ]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $landscape ? 'landscape' : 'portrait');
        $dompdf->render();
        if ($pageNumbers) {
            $canvas = $dompdf->getCanvas();
            $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
            // bottom right, A4 is 595 × 842 pt
            [$x, $y] = $landscape ? [768, 565] : [520, 810];
            $canvas->page_text($x, $y, '{PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.35, 0.4, 0.46]);
        }
        if ($title !== '') {
            $dompdf->addInfo('Title', $title);
        }

        return (string) $dompdf->output();
    }
}
