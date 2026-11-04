# -*- coding: utf-8 -*-
"""Переставляет местами слайды 2 и 3 в презентации (имитация правки в Google Slides)."""
import sys

from pptx import Presentation
from pptx.oxml.ns import qn

path = sys.argv[1]
prs = Presentation(path)

sldIdLst = prs.slides._sldIdLst
ids = list(sldIdLst)
# было [1, 2, 3] -> станет [1, 3, 2]
sldIdLst.remove(ids[2])
sldIdLst.insert(1, ids[2])

prs.save(path)
print("слайды переставлены")
