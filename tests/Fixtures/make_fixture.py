# -*- coding: utf-8 -*-
"""Генерирует тестовую презентацию для смоук-проверки индексации:
слайд 1 — обложка, слайд 2 и 3 — кейсы (метки и хэштеги в заметках, на слайде 2 — картинка)."""
import base64
import io
import sys

from pptx import Presentation
from pptx.util import Inches

# 1x1 пиксель PNG — для проверки извлечения картинок экстрактором.
PNG_1PX = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="
)

path = sys.argv[1]
prs = Presentation()

# Обложка
s = prs.slides.add_slide(prs.slide_layouts[0])
s.shapes.title.text = "Экспертиза 1С"
s.notes_slide.notes_text_frame.text = "Вводный слайд"

# Кейс 1
s = prs.slides.add_slide(prs.slide_layouts[0])
s.shapes.title.text = "Кейс: автокомпоненты"
tf = s.shapes[1].text_frame
tf.text = "Внедрение 1С на производстве автокомпонентов. Автопром, выпуск комплектующих."
s.shapes.add_picture(io.BytesIO(PNG_1PX), Inches(1), Inches(1), Inches(2), Inches(2))
s.notes_slide.notes_text_frame.text = "#кейс#\n#автопром# #1с# #производство#"

# Кейс 2
s = prs.slides.add_slide(prs.slide_layouts[0])
s.shapes.title.text = "Кейс: ритейл"
tf = s.shapes[1].text_frame
tf.text = "Автоматизация розничной сети. CRM и торговля."
s.notes_slide.notes_text_frame.text = "#кейс#\n#ритейл# #crm#"

prs.save(path)
print("создано:", path)
