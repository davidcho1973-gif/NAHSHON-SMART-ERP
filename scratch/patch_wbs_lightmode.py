from pathlib import Path
import re

path = Path(r'c:\Users\handy\OneDrive\Desktop\NAHSHON-SMART-ERP\resources\views\smart-company\index.blade.php')
text = path.read_text(encoding='utf-8')

# Replace text only inside the WBS renderWbs functions
pattern = re.compile(r'(async function renderWbs\(\) \{.*?)(?=window\.changeWbsProject = function\(projectId\)|window\.openWbsManualFolder = function\(\))', re.S)

replacements = [
    ('color:white;margin-bottom:8px', 'color:var(--text-primary);margin-bottom:8px'),
    ('style="font-size:14px;color:white;font-weight:600"', 'style="font-size:14px;color:var(--text-primary);font-weight:600"'),
    ('style="color:white;font-weight:700;min-width:32px;text-align:right"', 'style="color:var(--text-primary);font-weight:700;min-width:32px;text-align:right"'),
    ('style="font-size:16px;font-weight:700;color:white"', 'style="font-size:16px;font-weight:700;color:var(--text-primary)"'),
    ('style="background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px 12px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer"', 'style="background:var(--bg-base);border:1px solid var(--border-default);color:var(--text-primary);padding:8px 12px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer"'),
    ('style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);color:white;padding:8px;border-radius:6px"', 'style="width:100%;background:var(--bg-base);border:1px solid var(--border-default);color:var(--text-primary);padding:8px;border-radius:6px"'),
    ('<strong style="color:white">WBS_MANUAL / 01_ì²˜ë¦¬ëŒ€ê¸°</strong>', '<strong style="color:var(--text-primary)">WBS_MANUAL / 01_ì²˜ë¦¬ëŒ€ê¸°</strong>'),
    ("var nameStyle = isDone ? 'text-decoration:line-through;color:#10b981;opacity:0.85' : 'color:white';", "var nameStyle = isDone ? 'text-decoration:line-through;color:#10b981;opacity:0.85' : 'color:var(--text-primary)';"),
]

new_text = text
changed = False
for match in pattern.finditer(text):
    segment = match.group(0)
    new_segment = segment
    for old, new in replacements:
        new_segment = new_segment.replace(old, new)
    if new_segment != segment:
        changed = True
        new_text = new_text.replace(segment, new_segment, 1)

if not changed:
    raise SystemExit('No WBS renderWbs text replaced; please verify patterns.')

path.write_text(new_text, encoding='utf-8')
print('patched')
