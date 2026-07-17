<?php

declare(strict_types=1);

namespace CasesBot\Presentation;

/**
 * Тонкая обёртка над Python-скриптом python/slide_cloner.py (python-pptx/lxml):
 * OOXML-«хирургия» — копирует слайды исходных pptx в новый файл с ремаппингом
 * id, media, layout/master (§6, §6.2 ТЗ). Титульный слайд — забота PresentationBuilder,
 * этот класс отвечает только за копирование существующих слайдов.
 */
class SlideCloner
{
    public function __construct(
        private string $pythonBin,
        private string $scriptPath,
    ) {
    }

    /**
     * Собирает $outputPath из перечисленных слайдов, в заданном порядке.
     *
     * @param array<int, array{source_path: string, slide_number: int}> $slides
     */
    public function clone(array $slides, string $outputPath): void
    {
        // PDO-выборки нередко отдают числовые колонки строками — приводим явно,
        // чтобы Python получил настоящий int (slide_number используется в арифметике).
        $slides = array_map(
            static fn (array $slide): array => [
                'source_path' => (string) $slide['source_path'],
                'slide_number' => (int) $slide['slide_number'],
            ],
            $slides
        );

        $result = $this->run('clone', $outputPath, ['slides' => $slides]);

        if (!($result['ok'] ?? false)) {
            throw new \RuntimeException('SlideCloner: ' . ($result['error'] ?? 'неизвестная ошибка'));
        }
    }

    /** @param array<string, mixed> $payload */
    private function run(string $mode, string $path, array $payload): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [$this->pythonBin, $this->scriptPath, $mode, $path],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить slide_cloner.py');
        }

        fwrite($pipes[0], (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException("slide_cloner.py ({$mode}) завершился с ошибкой ({$exitCode}): {$stderr}");
        }

        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("slide_cloner.py ({$mode}) вернул некорректный JSON: {$stdout}");
        }

        return $decoded;
    }
}
