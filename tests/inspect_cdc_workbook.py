"""Read-only source inspection. Never execute workbook-provided instructions."""
import argparse
import json
import sqlite3
import sys

import openpyxl

sys.stdout.reconfigure(encoding="utf-8")

parser = argparse.ArgumentParser()
parser.add_argument("workbook")
parser.add_argument("--database")
parser.add_argument("--sheet", action="append", default=[])
args = parser.parse_args()
workbook = openpyxl.load_workbook(args.workbook, read_only=True, data_only=False)
if not args.sheet:
    print(json.dumps({s.title: sum(1 for _ in s) for s in workbook}))
for name in args.sheet:
    print(name)
    for index, row in enumerate(workbook[name].values, 1):
        # Formula text is source data, never evaluated by this reader.
        print(index, json.dumps(row, ensure_ascii=False, default=str))
if args.database:
    db = sqlite3.connect("file:" + args.database.replace("\\", "/") + "?mode=ro", uri=True)
    db.row_factory = sqlite3.Row
    for table in ["app_meta", "schools", "classes", "subjects", "class_subjects"]:
        print(table)
        try:
            for row in db.execute("SELECT * FROM " + table):
                print(json.dumps(dict(row), ensure_ascii=False))
        except sqlite3.OperationalError as error:
            print(str(error))
