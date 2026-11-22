"""Generate a DEMO legacy .xls inventory file (fictional data). Dev dependency: xlwt.

Usage:  python scripts/generate_sample_xls.py [output_path]
"""
import random
import sys
from pathlib import Path

import xlwt

HEADERS = ["Barcode", "Item Name", "Category", "Karat", "Weight", "Making Charge",
           "Price", "Status", "Branch", "Notes"]

ITEMS = [
    ("خاتم", "خواتم", ["خاتم ذهب سوليتير", "خاتم ذهب نسائي مزخرف", "خاتم ذهب رجالي فص عقيق",
                       "دبلة زواج ذهب", "خاتم ذهب تركي ناعم", "خاتم ذهب إيطالي مفرغ"]),
    ("عقد", "عقود", ["عقد ذهب لؤلؤ", "عقد ذهب بحريني", "طقم عقد ذهب مرصع", "عقد ذهب فراشة",
                     "عقد ذهب هندي ثقيل"]),
    ("أسورة", "أساور", ["أسورة ذهب مبرومة", "غوايش ذهب سادة", "أسورة ذهب كارتير",
                        "أسورة ذهب سلسلة ناعمة", "غويشة ذهب محفورة"]),
    ("حلق", "أقراط", ["حلق ذهب دائري", "حلق ذهب طويل متدلي", "حلق ذهب أطفال", "حلق ذهب زركون",
                      "حلق ذهب قلب"]),
    ("سلسلة", "سلاسل", ["سلسلة ذهب كوبي", "سلسلة ذهب ناعمة", "سلسلة ذهب رجالي حبل",
                        "سلسلة ذهب فينيسي", "سلسلة ذهب مضلعة"]),
    ("تعليقة", "تعاليق", ["تعليقة ذهب آية الكرسي", "تعليقة ذهب اسم", "تعليقة ذهب خريطة السعودية",
                          "تعليقة ذهب نجمة", "تعليقة ذهب هلال"]),
]
STATUSES = ["متوفر", "متوفر", "متوفر", "محجوز", "مباع"]
BRANCHES = ["عرعر - الفرع الرئيسي", "عرعر - حي المساعدية"]


def build_rows(seed: int = 7):
    rnd = random.Random(seed)
    rows = []
    n = 1001
    for prefix, cat, names in ITEMS:
        for name in names:
            karat = rnd.choice([18, 21, 21, 22, 24])
            weight = round(rnd.uniform(1.5, 45.0), 2)
            making = rnd.choice([25, 35, 40, 50, 60, 80])
            price = round(weight * (180 + karat * 6) + weight * making, 2)
            rows.append([f"62800{n:05d}", name, cat, karat, weight, making, price,
                         rnd.choice(STATUSES), rnd.choice(BRANCHES), "بيانات تجريبية - أسعار وهمية"])
            n += 1
    # Special barcodes for acceptance tests
    rows.append(["00123456", "خاتم ذهب بفص ياقوت", "خواتم", 21, 4.35, 45, 1920.5, "متوفر",
                 BRANCHES[0], "باركود يبدأ بأصفار (نص) - تجربة"])
    rows.append(["GR-2024-A7", "طقم ذهب عروس", "أطقم", 21, 62.8, 70, 29850, "متوفر",
                 BRANCHES[0], "باركود أبجدي رقمي - تجربة"])
    rows.append([123456789, "سلسلة ذهب Gold Chain", "سلاسل", 18, 8.1, 30, 2650, "متوفر",
                 BRANCHES[1], "باركود مخزن كرقم - تجربة"])
    rows.append(["DUP-5555", "حلق ذهب (نسخة 1)", "أقراط", 21, 3.2, 40, 1450, "متوفر",
                 BRANCHES[0], "باركود مكرر عمداً لاختبار التكرار"])
    rows.append(["DUP-5555", "حلق ذهب (نسخة 2)", "أقراط", 21, 3.4, 40, 1530, "متوفر",
                 BRANCHES[1], "باركود مكرر عمداً لاختبار التكرار"])
    return rows


def write_xls(path: Path, rows=None, sheet="Inventory"):
    rows = build_rows() if rows is None else rows
    wb = xlwt.Workbook(encoding="utf-8")
    ws = wb.add_sheet(sheet)
    ws.cols_right_to_left = True
    hstyle = xlwt.easyxf("font: bold on; pattern: pattern solid, fore_colour gold;")
    for c, h in enumerate(HEADERS):
        ws.write(0, c, h, hstyle)
        ws.col(c).width = 256 * (28 if c in (1, 8, 9) else 14)
    for r, row in enumerate(rows, start=1):
        for c, v in enumerate(row):
            ws.write(r, c, v)
    path.parent.mkdir(parents=True, exist_ok=True)
    wb.save(str(path))
    return len(rows)


if __name__ == "__main__":
    out = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parent.parent / "data" / "inventory.xls"
    n = write_xls(out)
    print(f"Wrote {n} demo records to {out}")
