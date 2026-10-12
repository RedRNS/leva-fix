<?php

namespace App\Services;

use Exception;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Process;

class PdfTextExtractor
{
    public function extract(string $path): string
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($path);
            return trim($pdf->getText());
        } catch (Exception $e) {
            throw new \RuntimeException('Failed to extract PDF text: ' . $e->getMessage());
        }
    }

    /**
     * Teks hasil ekstraksi dianggap tidak layak jika hampir kosong, misalnya PDF hasil scan
     * atau PDF yang rumus/soalnya berupa gambar.
     */
    public function isUsable(string $text): bool
    {
        $meaningful = preg_replace('/[^\p{L}\p{N}]/u', '', $text) ?? '';

        return mb_strlen($meaningful) >= 60;
    }

    /**
     * Render halaman PDF menjadi gambar (data URI) agar bisa dibaca model yang mendukung gambar.
     *
     * @return list<string>
     */
    public function renderPages(string $path, int $maxPages = 3, int $dpi = 100): array
    {
        $binary = config('services.pdf.binary_path');
        $pdftoppm = $binary ? dirname($binary) . '/pdftoppm' : 'pdftoppm';

        $prefix = tempnam(sys_get_temp_dir(), 'leva_pdf_');
        @unlink($prefix);

        $process = new Process([$pdftoppm, '-jpeg', '-r', (string) $dpi, '-l', (string) $maxPages, $path, $prefix]);
        $process->setTimeout(60);
        $process->run();

        $files = glob($prefix . '-*.jpg') ?: [];
        sort($files);

        $images = [];
        foreach ($files as $file) {
            $images[] = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($file));
            @unlink($file);
        }

        if ($images === [] && !$process->isSuccessful()) {
            throw new \RuntimeException('Failed to render PDF pages: ' . trim($process->getErrorOutput()));
        }

        return $images;
    }
}
