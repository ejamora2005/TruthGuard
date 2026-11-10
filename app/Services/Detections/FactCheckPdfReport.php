<?php

namespace App\Services\Detections;

use App\Models\Detection;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

final class FactCheckPdfReport
{
    public function render(Detection $detection): string
    {
        $directory = storage_path('app/private/pdf-renderer');
        File::ensureDirectoryExists($directory);
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('tempDir', $directory);
        $options->set('fontCache', $directory);
        $options->set('chroot', public_path());
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml(view('detections.download-report', ['selectedDetection' => $detection,
            'transparentReport' => FactCheckReportPresenter::build($detection)])->render(), 'UTF-8');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $canvas->page_text(42, 812, 'TruthGuard | Page {PAGE_NUM} of {PAGE_COUNT}',
            $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [.39, .45, .55]);

        return $pdf->output();
    }
}
