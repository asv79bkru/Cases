"""
SlideCloner (§6, §6.2 ТЗ).

Вызывается из PHP-класса CasesBot\\Presentation\\SlideCloner как подпроцесс.
OOXML-«хирургия»: копирует конкретные слайды из исходных .pptx в новый файл на
уровне сырых частей пакета (python-pptx opc/package API) — слайд, его layout,
master, theme и все связанные media копируются как есть (blob), с ремаппингом
id/media/layout-master, без чтения через объектную модель и пересборки (это и
теряет SmartArt/кастомные шрифты/анимации у PHPOffice-подобных библиотек).

Заметки докладчика и комментарии НЕ копируются: в них могут быть внутренние метки/теги
эксперта (#кейс#, хештеги) и переписка рецензентов Google Slides — не для показа клиенту.
Санация не затрагивает содержимое слайдов/layout/master/media: всё отсекается на уровне
связей (reltype) или удалением отдельных элементов из XML, остальное копируется байт-в-байт.

Режимы:
  python slide_cloner.py clone <output.pptx>
      stdin: {"slides": [{"source_path": "...", "slide_number": N}, ...]}
      Создаёт output.pptx из перечисленных слайдов в указанном порядке.

  python slide_cloner.py clone-all <output.pptx>
      stdin: {"source_path": "..."}
      Копирует ВСЕ слайды исходника, в исходном порядке — основа "парадных" копий
      (PublicCopyBuilder): та же презентация, но уже без заметок/комментариев.

  python slide_cloner.py add-title-slide <path.pptx>
      stdin: {"title": "...", "date": "..."}
      Добавляет титульный слайд первым в уже существующий path.pptx (§5.1 P0.5).
"""

import json
import posixpath
import re
import sys

from lxml import etree
from pptx import Presentation
from pptx.opc.constants import RELATIONSHIP_TYPE as RT
from pptx.opc.package import PartFactory
from pptx.opc.packuri import PackURI
from pptx.oxml.ns import qn

# Заметки и комментарии слайда не копируются — служебные пометки эксперта и переписка
# рецензентов не для клиента. RT.NOTES_SLIDE — заметки; RT.COMMENT — комментарии «старого»
# форматата (отдельные части comments/*.xml). Современные комментарии PowerPoint/Google
# (threadingComments/comex) ходят со своими reltype без констант в python-pptx, поэтому
# на подстраховку SKIP_RELTYPES дополняется is_skipped_rel() по подстроке 'comment' —
# других легитимных reltype с этим словом в OOXML нет.
SKIP_RELTYPES = {RT.NOTES_SLIDE, RT.COMMENTS}
P_NS = "http://schemas.openxmlformats.org/presentationml/2006/main"


def is_skipped_rel(reltype):
    return reltype in SKIP_RELTYPES or "comment" in reltype.lower()


def strip_inline_comments(element):
    """Удаляет inline-комментарии старого формата (p:cmLst прямо в XML слайда, pptx 2007–2010).
    Современный формат хранит комментарии отдельными частями и отсекается is_skipped_rel(),
    но старые pptx тоже не должны отдаваться клиенту с пометками. Возвращает число удалённых."""
    removed = 0
    for cm_lst in element.findall(f".//{{{P_NS}}}cmLst"):
        parent = cm_lst.getparent()
        if parent is not None:
            parent.remove(cm_lst)
            removed += 1
    return removed

# r:* атрибуты внутри XML части, которые нужно перенаправить на новые rId при
# копировании (встроенные картинки, гиперссылки). SmartArt/диаграммы (r:dm, r:lo,
# r:qs, встроенные OLE/xlsx) вне охвата v1 — редкость для кейс-слайдов с текстом+фото.
R_NS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
R_ATTRS = ("id", "embed", "link")


def partname_template(partname):
    """"/ppt/slides/slide12.xml" -> "/ppt/slides/slide%d.xml" (шаблон для next_partname)."""
    base = partname.baseURI
    filename = partname.filename
    name_no_ext, ext = posixpath.splitext(filename)
    prefix = re.match(r"[a-zA-Z]+", name_no_ext).group(0)
    return f"{base}/{prefix}%d{ext}"


def next_available_partname(dest_package, template, reserved_partnames):
    """Как dest_package.next_partname(), но учитывает reserved_partnames — имена частей,
    созданных в рамках текущей сборки, но ещё не достижимых по связям от корня пакета
    (слайд привязывается к sldIdLst только после полного копирования). Без этого два разных
    исходника могли получить одинаковое dest-имя → в zip попадали два разных блоба под одним
    именем (PowerPoint читает «последний», картинка на слайде могла отобразиться не та)."""
    used = {str(part.partname) for part in dest_package.iter_parts()}
    n = 1
    while True:
        candidate = f"{template % n}"
        if candidate not in used and candidate not in reserved_partnames:
            reserved_partnames.add(candidate)
            return PackURI(candidate)
        n += 1


def remap_r_attrs(element, id_map):
    for attr in R_ATTRS:
        for el in element.xpath(f".//*[@r:{attr}]"):
            key = f"{{{R_NS}}}{attr}"
            old = el.get(key)
            if old in id_map:
                el.set(key, id_map[old])


def copy_part_and_deps(source_part, dest_package, cache, reserved_partnames):
    """Копирует source_part и всё, от чего он (рекурсивно) зависит, в dest_package.

    Возвращает соответствующую часть в dest_package. Повторные вызовы для уже
    скопированной части (например, общий layout/master у нескольких слайдов из
    одной презентации) отдают закэшированный результат, а не дублируют.
    reserved_partnames — общий на весь клон реестр имён, уже выданных в dest
    (см. next_available_partname: iter_parts() не видит ещё не привязанные к
    корню пакета части, поэтому next_partname() может выдать занятое имя).
    """
    cache_key = (id(source_part.package), str(source_part.partname))
    if cache_key in cache:
        return cache[cache_key]

    new_partname = next_available_partname(dest_package, partname_template(source_part.partname), reserved_partnames)
    new_part = PartFactory(new_partname, source_part.content_type, dest_package, source_part.blob)
    cache[cache_key] = new_part  # до рекурсии — на случай циклических ссылок

    id_map = {}
    for rId, rel in source_part.rels.items():
        if is_skipped_rel(rel.reltype):
            continue
        if rel.is_external:
            id_map[rId] = new_part.relate_to(rel.target_ref, rel.reltype, is_external=True)
        else:
            new_target = copy_part_and_deps(rel.target_part, dest_package, cache, reserved_partnames)
            id_map[rId] = new_part.relate_to(new_target, rel.reltype)

    if hasattr(new_part, "_element"):
        if id_map:
            remap_r_attrs(new_part._element, id_map)
        strip_inline_comments(new_part._element)

    return new_part


def next_master_id(dest_prs):
    """Аналог python-pptx'ного _next_id для sldId, но для sldMasterId: PowerPoint нумерует
    их начиная примерно с 2147483648; python-pptx сам это не делает (id на sldMasterId
    формально необязателен по схеме), но реальный PowerPoint всегда его пишет и без него
    может счесть файл повреждённым и «починить» — молча, без явной ошибки при чтении."""
    sldMasterIdLst = dest_prs._element.get_or_add_sldMasterIdLst()
    used = [int(el.get('id')) for el in sldMasterIdLst if el.get('id') is not None]
    return max([2147483647] + used) + 1


def register_master(dest_prs, dest_package, source_master_part, cache, registered_masters):
    """Гарантирует, что master (уже скопированный в cache через слайд/layout) числится
    в p:sldMasterIdLst презентации — иначе PowerPoint может считать файл повреждённым,
    даже если master фактически достижим через связи slide -> layout -> master."""
    key = (id(source_master_part.package), str(source_master_part.partname))
    if key in registered_masters:
        return
    registered_masters.add(key)

    dest_master_part = cache[key]
    rId = dest_prs.part.relate_to(dest_master_part, RT.SLIDE_MASTER)
    sldMasterIdLst = dest_prs._element.get_or_add_sldMasterIdLst()
    el = etree.SubElement(sldMasterIdLst, qn("p:sldMasterId"))
    el.set("id", str(next_master_id(dest_prs)))
    el.set(qn("r:id"), rId)


def clone_slides(slides_spec, output_path):
    dest_prs = Presentation()
    dest_package = dest_prs.part.package
    cache = {}
    reserved_partnames = set()
    registered_masters = set()
    source_cache = {}
    size_set = False

    for item in slides_spec:
        source_path = item["source_path"]
        slide_number = item["slide_number"]

        if source_path not in source_cache:
            source_cache[source_path] = Presentation(source_path)
        source_prs = source_cache[source_path]

        if not size_set:
            dest_prs.slide_width = source_prs.slide_width
            dest_prs.slide_height = source_prs.slide_height
            size_set = True

        source_slide = source_prs.slides[slide_number - 1]
        source_slide_part = source_slide.part
        source_layout_part = source_slide_part.part_related_by(RT.SLIDE_LAYOUT)
        source_master_part = source_layout_part.part_related_by(RT.SLIDE_MASTER)

        new_slide_part = copy_part_and_deps(source_slide_part, dest_package, cache, reserved_partnames)
        register_master(dest_prs, dest_package, source_master_part, cache, registered_masters)

        rId = dest_prs.part.relate_to(new_slide_part, RT.SLIDE)
        dest_prs._element.get_or_add_sldIdLst().add_sldId(rId)

    dest_prs.save(output_path)
    return len(slides_spec)


def clone_all_slides(source_path, output_path):
    """Клонирует все слайды исходника подряд — PublicCopyBuilder («парадные» копии).
    Слайды определяются по sldIdLst исходника, т.е. порядок сохраняется точно."""
    source_prs = Presentation(source_path)
    slides_spec = [
        {"source_path": source_path, "slide_number": number}
        for number in range(1, len(source_prs.slides._sldIdLst) + 1)
    ]

    return clone_slides(slides_spec, output_path)


def find_title_layout(prs):
    """Layout с настоящим плейсхолдером-заголовком (обычным или центрированным титульным).

    Не полагаемся на slide_masters[0].slide_layouts[0] — для пустого Presention() это и
    правда всегда "Title Slide", но после clone_slides() в файле уже есть master(ы),
    скопированные из исходных презентаций, и от их состава/порядка layouts мы не зависим.
    """
    from pptx.enum.shapes import PP_PLACEHOLDER

    title_types = {PP_PLACEHOLDER.TITLE, PP_PLACEHOLDER.CENTER_TITLE}
    for master in prs.slide_masters:
        for layout in master.slide_layouts:
            for placeholder in layout.placeholders:
                if placeholder.placeholder_format.type in title_types:
                    return layout

    return prs.slide_masters[0].slide_layouts[0]  # запасной вариант, если титульного не нашлось


def add_title_slide(path, title, date):
    prs = Presentation(path)
    layout = find_title_layout(prs)
    slide = prs.slides.add_slide(layout)
    slide.shapes.title.text = title
    if len(slide.placeholders) > 1:
        slide.placeholders[1].text = date

    # Новый слайд add_slide() добавляет последним в sldIdLst — переносим его на первое место.
    sldIdLst = prs._element.get_or_add_sldIdLst()
    new_sldId = sldIdLst[-1]
    sldIdLst.remove(new_sldId)
    sldIdLst.insert(0, new_sldId)

    prs.save(path)


def main():
    if len(sys.argv) < 3:
        print("usage: slide_cloner.py clone|add-title-slide <path> [...]", file=sys.stderr)
        sys.exit(1)

    mode, path = sys.argv[1], sys.argv[2]
    sys.stdin.reconfigure(encoding="utf-8")
    payload = json.load(sys.stdin)

    try:
        if mode == "clone":
            count = clone_slides(payload["slides"], path)
            result = {"ok": True, "slide_count": count}
        elif mode == "clone-all":
            count = clone_all_slides(payload["source_path"], path)
            result = {"ok": True, "slide_count": count}
        elif mode == "add-title-slide":
            add_title_slide(path, payload["title"], payload["date"])
            result = {"ok": True}
        else:
            result = {"ok": False, "error": f"неизвестный режим: {mode}"}
    except Exception as e:  # индексация/сборка не должна падать без объяснения причины
        result = {"ok": False, "error": f"{type(e).__name__}: {e}"}

    sys.stdout.reconfigure(encoding="utf-8")
    json.dump(result, sys.stdout, ensure_ascii=False)


if __name__ == "__main__":
    main()
