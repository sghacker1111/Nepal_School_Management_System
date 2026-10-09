"""Read-only record fingerprints for checking a real import without exposing records."""
import hashlib
import json
import sqlite3
import sys
from pathlib import Path

db = sqlite3.connect("file:" + sys.argv[1].replace("\\", "/") + "?mode=ro", uri=True)
baseline = sqlite3.connect("file:" + sys.argv[2].replace("\\", "/") + "?mode=ro", uri=True) if len(sys.argv) > 2 else None
summary = {}
for table in ["students", "marks", "users", "exams", "exam_subjects", "attendances"]:
    columns = [r[1] for r in (baseline or db).execute("PRAGMA table_info(" + table + ")")]
    query = "SELECT " + ",".join('"' + c + '"' for c in columns) + " FROM " + table + " ORDER BY id"
    rows = db.execute(query).fetchall()
    if baseline:
        assert rows == baseline.execute(query).fetchall(), table + " records changed"
    summary[table] = {
        "count": len(rows),
        "sha256": hashlib.sha256(json.dumps(rows, ensure_ascii=True, separators=(",", ":")).encode()).hexdigest(),
    }
print(json.dumps(summary))
if baseline:
    assert db.execute("PRAGMA integrity_check").fetchone()[0] == "ok"
    duplicates = db.execute("SELECT school_id,LOWER(TRIM(name)),COUNT(*) FROM classes GROUP BY school_id,LOWER(TRIM(name)) HAVING COUNT(*)>1").fetchall()
    assert not duplicates, "Duplicate classes detected"
    print("Record values unchanged; database integrity and no duplicate classes verified.")
    package = json.loads((Path(__file__).resolve().parents[1] / "config/data/cdc_2083.json").read_text(encoding="utf-8"))
    db.row_factory = sqlite3.Row
    supported = 0
    for source in package["CLASS_SUBJECTS"]:
        key = str(source["class_grade"]) + ":" + str(source["subject_order"])
        mappings = db.execute("SELECT cs.*,s.code FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id WHERE cs.curriculum_key=?", (key,)).fetchall()
        assert mappings, "Missing workbook mapping " + key
        if source["import_flag"] not in ["YES", "REVIEW_LOCAL"]:
            continue
        fields = {"credit_hours": "total_credit_hours", "th_credit_hours": "th_credit_hours", "in_credit_hours": "in_credit_hours", "th_full_marks": "th_marks_max", "in_full_marks": "in_marks_max", "assessment_method": "method_id", "code": "source_subject_code"}
        for mapping in mappings:
            for field, source_field in fields.items():
                assert mapping[field] == source[source_field], key + " mismatch " + field
            supported += 1
    print(str(supported) + " supported workbook mappings exactly match the source values.")
