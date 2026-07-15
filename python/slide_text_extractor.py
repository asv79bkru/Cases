"""
SlideTextExtractor (§6, §6.2 ТЗ).

Вызывается из PHP-класса CasesBot\\Presentation\\SlideTextExtractor как подпроцесс.
Читает текст слайда (заголовок, буллеты) через python-pptx — источник подсказок
для тегирования при индексации (Indexer).

Использование (контракт с PHP-стороной согласовать при реализации):
    python slide_text_extractor.py <путь_к_pptx> <номер_слайда>
"""
