<?php

declare(strict_types=1);

namespace CasesBot\Presentation;

/**
 * Тонкая обёртка над Python-скриптом python/slide_text_extractor.py (python-pptx):
 * читает текст слайда (заголовок, буллеты, полный текст) для подсказок при тегировании (§6, §6.2 ТЗ),
 * определяет, помечен ли слайд как кейс (метка в заметках докладчика, см. $caseMarker),
 * и для слайдов-кейсов сохраняет картинки в $imagesDir.
 */
class SlideTextExtractor
{
    public function __construct(
        private string $pythonBin,
        private string $scriptPath,
        private string $caseMarker = '#кейс#',
        private ?string $imagesDir = null,
    ) {
    }

    /**
     * @return array<int, array{slide_number: int, title: string, text: string, notes: string, is_case: bool, images: string[]}>
     */
    public function extract(string $pptxPath): array
    {
        $command = [$this->pythonBin, $this->scriptPath, $pptxPath, $this->caseMarker];
        if ($this->imagesDir !== null) {
            $command[] = $this->imagesDir;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить slide_text_extractor.py');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException("slide_text_extractor.py завершился с ошибкой ({$exitCode}): {$stderr}");
        }

        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("slide_text_extractor.py вернул некорректный JSON: {$stdout}");
        }

        return $decoded;
    }
}
