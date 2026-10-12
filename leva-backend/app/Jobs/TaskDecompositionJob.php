<?php

namespace App\Jobs;

use App\Models\AtomicSubTask;
use App\Models\ScrapedTool;
use App\Models\TaskMaster;
use App\Services\OpenAIService;
use App\Services\PdfTextExtractor;
use App\Services\QdrantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class TaskDecompositionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public string $taskId
    ) {
    }

    public function handle(OpenAIService $openAIService, PdfTextExtractor $pdfTextExtractor, QdrantService $qdrantService): void
    {
        $task = TaskMaster::query()->with('user.profile')->find($this->taskId);

        if (!$task) {
            return;
        }

        try {
            [$text, $images] = $this->resolveSource($task, $pdfTextExtractor);
            $preparedText = Str::limit(trim($text), 8000, '');

            $result = $openAIService->decomposeTask($preparedText, [
                'major' => $task->user?->profile?->major,
                'semester' => $task->user?->profile?->semester,
                'language_preference' => $task->user?->profile?->language_preference,
            ], $images);

            DB::transaction(function () use ($task, $result, $qdrantService) {
                $task->subTasks()->delete();

                foreach ($result['sub_tasks'] as $index => $subTask) {
                    $recommendedToolIds = collect($qdrantService->searchTools(
                        trim(($subTask['judul_tugas'] ?? '').' '.($subTask['kategori_alat_ai_yang_rekomendasi'] ?? '')),
                        $subTask['kategori_alat_ai_yang_rekomendasi'] ?? null,
                        3,
                        0.7
                    ))
                        ->pluck('tool_mysql_id')
                        ->filter()
                        ->values()
                        ->all();

                    if ($recommendedToolIds === []) {
                        $recommendedToolIds = ScrapedTool::query()
                            ->where('category', $subTask['kategori_alat_ai_yang_rekomendasi'] ?? null)
                            ->orderByDesc('rating')
                            ->limit(3)
                            ->pluck('id')
                            ->all();
                    }

                    AtomicSubTask::query()->create([
                        'sub_task_id' => (string) Str::uuid(),
                        'parent_task_id' => $task->task_id,
                        'actionable_title' => $subTask['judul_tugas'] ?? 'Untitled step',
                        'description' => $subTask['deskripsi'] ?? null,
                        'tips' => $subTask['tips'] ?? null,
                        'status' => 'next',
                        'category' => $subTask['kategori_alat_ai_yang_rekomendasi'] ?? null,
                        'estimated_duration' => $subTask['estimasi_waktu'] ?? null,
                        'recommended_tool_ids' => $recommendedToolIds,
                        'order' => $index + 1,
                    ]);
                }

                $task->update([
                    'title' => $result['title'] ?? $task->title,
                    'status' => 'completed',
                    'error_message' => null,
                    'source_text' => $task->source_text ?: null,
                    'source_file_path' => null,
                ]);
            });
        } catch (Throwable $exception) {
            Log::error('TaskDecompositionJob failed', [
                'task_id' => $this->taskId,
                'error' => $exception->getMessage(),
            ]);

            $task->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
        } finally {
            $this->cleanupSourceFile($task);
        }
    }

    /**
     * @return array{0: string, 1: list<string>} teks sumber dan (jika perlu) gambar halaman PDF
     */
    private function resolveSource(TaskMaster $task, PdfTextExtractor $pdfTextExtractor): array
    {
        if ($task->source_type === 'text') {
            return [trim(implode("\n\n", array_filter([
                $task->source_text,
                $task->source_description,
            ]))), []];
        }

        if (!$task->source_file_path) {
            throw new \RuntimeException('PDF source file is missing.');
        }

        $path = Storage::disk('local')->path($task->source_file_path);
        $pdfText = $pdfTextExtractor->extract($path);
        $images = [];

        // PDF scan atau PDF dengan rumus berupa gambar tidak punya teks yang bisa dibaca,
        // jadi halamannya dikirim sebagai gambar agar tidak ditebak-tebak oleh AI.
        if (!$pdfTextExtractor->isUsable($pdfText)) {
            $images = $pdfTextExtractor->renderPages($path);

            if ($images === [] && trim($pdfText) === '') {
                throw new \RuntimeException('Isi PDF tidak bisa dibaca. Coba upload PDF lain atau tempel teksnya langsung.');
            }
        }

        return [trim(implode("\n\n", array_filter([
            $task->source_description,
            $pdfText,
        ]))), $images];
    }

    private function cleanupSourceFile(TaskMaster $task): void
    {
        if ($task->source_file_path && Storage::disk('local')->exists($task->source_file_path)) {
            Storage::disk('local')->delete($task->source_file_path);
        }
    }
}
