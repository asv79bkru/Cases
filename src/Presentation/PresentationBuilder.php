<?php

declare(strict_types=1);

namespace CasesBot\Presentation;

/**
 * Обёртка над SlideCloner: добавляет титульный слайд, собирает и сохраняет финальный файл (§5.1.4, §5.1.5 ТЗ).
 */
class PresentationBuilder
{
    public function __construct(
        private SlideCloner $slideCloner,
        private string $pythonBin,
        private string $scriptPath,
        private string $outputDir,
    ) {
    }

    /**
     * Собирает подборку по $topic из $slides (уже найденных Matcher'ом) и сохраняет
     * в storage/output. Возвращает абсолютный путь к готовому файлу.
     *
     * @param array<int, array{source_path: string, slide_number: int}> $slides
     */
    public function build(string $topic, array $slides): string
    {
        if ($slides === []) {
            throw new \RuntimeException('PresentationBuilder: нет слайдов для сборки');
        }

        $outputPath = rtrim($this->outputDir, '/\\') . '/' . $this->generateFileName();

        $this->slideCloner->clone($slides, $outputPath);
        $this->addTitleSlide($outputPath, $topic, date('d.m.Y'));

        return $outputPath;
    }

    private function addTitleSlide(string $path, string $title, string $date): void
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [$this->pythonBin, $this->scriptPath, 'add-title-slide', $path],
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить slide_cloner.py (add-title-slide)');
        }

        fwrite($pipes[0], (string) json_encode(['title' => $title, 'date' => $date], JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException("slide_cloner.py (add-title-slide) завершился с ошибкой ({$exitCode}): {$stderr}");
        }

        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
            throw new \RuntimeException('add-title-slide: ' . ($decoded['error'] ?? "некорректный ответ: {$stdout}"));
        }
    }

    private function generateFileName(): string
    {
        return sprintf('cases_%s_%s.pptx', date('Ymd_His'), substr(md5(uniqid('', true)), 0, 6));
    }
}
