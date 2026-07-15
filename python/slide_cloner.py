"""
SlideCloner (§6, §6.2 ТЗ).

Вызывается из PHP-класса CasesBot\\Presentation\\SlideCloner как подпроцесс.
Копирует конкретные слайды исходных pptx в новый файл на уровне сырого OOXML
(slideN.xml + rels + media), перенумеровывает id, обновляет presentation.xml,
presentation.xml.rels и [Content_Types].xml — слайд переносится «байт в байт».

Использование (контракт с PHP-стороной согласовать при реализации):
    python slide_cloner.py <исходные_pptx_и_номера_слайдов...> <путь_к_новому_pptx>
"""
