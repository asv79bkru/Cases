"""
SlideTextExtractor (§6, §6.2 ТЗ).

Вызывается из PHP-класса CasesBot\\Presentation\\SlideTextExtractor как подпроцесс.
Читает текст слайдов (заголовок, буллеты) через python-pptx — источник подсказок
для тегирования при индексации (Indexer). Печатает в stdout JSON-массив:
[{"slide_number": 1, "title": "...", "text": "...", "is_case": true}, ...]

Слайд считается кейсом, только если в заметках к слайду есть метка CASE_MARKER
(#кейс# по умолчанию) — так эксперт помечает слайды с кейсами прямо в PowerPoint:
метка видна только в заметках докладчика, не в самом слайде и не в режиме показа.

Использование: python slide_text_extractor.py <путь_к_pptx> [метка]
"""

import json
import sys

CASE_MARKER = "#кейс#"


def slide_title(slide, texts):
    title_shape = slide.shapes.title
    if title_shape is not None:
        title = title_shape.text_frame.text.strip()
        if title:
            return title

    return texts[0].splitlines()[0] if texts else ""


def notes_text(slide):
    if not slide.has_notes_slide:
        return ""
    return slide.notes_slide.notes_text_frame.text.strip()


def extract(path, case_marker):
    from pptx import Presentation

    presentation = Presentation(path)
    slides = []

    for index, slide in enumerate(presentation.slides, start=1):
        texts = []
        for shape in slide.shapes:
            if shape.has_text_frame:
                text = shape.text_frame.text.strip()
                if text:
                    texts.append(text)

        notes = notes_text(slide)

        slides.append({
            "slide_number": index,
            "title": slide_title(slide, texts),
            "text": "\n".join(texts),
            "is_case": case_marker.lower() in notes.lower(),
        })

    return slides


def main():
    if len(sys.argv) not in (2, 3):
        print("usage: slide_text_extractor.py <path.pptx> [case_marker]", file=sys.stderr)
        sys.exit(1)

    case_marker = sys.argv[2] if len(sys.argv) == 3 else CASE_MARKER
    slides = extract(sys.argv[1], case_marker)
    sys.stdout.reconfigure(encoding="utf-8")
    json.dump(slides, sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
