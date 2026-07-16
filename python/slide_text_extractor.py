"""
SlideTextExtractor (§6, §6.2 ТЗ).

Вызывается из PHP-класса CasesBot\\Presentation\\SlideTextExtractor как подпроцесс.
Читает текст слайдов (заголовок, буллеты) через python-pptx — источник подсказок
для тегирования при индексации (Indexer). Печатает в stdout JSON-массив:
[{"slide_number": 1, "title": "...", "text": "..."}, ...]

Использование: python slide_text_extractor.py <путь_к_pptx>
"""

import json
import sys


def slide_title(slide, texts):
    title_shape = slide.shapes.title
    if title_shape is not None:
        title = title_shape.text_frame.text.strip()
        if title:
            return title

    return texts[0].splitlines()[0] if texts else ""


def extract(path):
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

        slides.append({
            "slide_number": index,
            "title": slide_title(slide, texts),
            "text": "\n".join(texts),
        })

    return slides


def main():
    if len(sys.argv) != 2:
        print("usage: slide_text_extractor.py <path.pptx>", file=sys.stderr)
        sys.exit(1)

    slides = extract(sys.argv[1])
    sys.stdout.reconfigure(encoding="utf-8")
    json.dump(slides, sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
