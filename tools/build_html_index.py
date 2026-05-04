import pathlib

root = pathlib.Path(__file__).resolve().parents[1]
body_path = root / "html" / "en" / "body_fragment.txt"
if not body_path.exists():
    body_path = root / "html" / "body_fragment.txt"
body = body_path.read_text(encoding="utf-8")
head = """<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="apidir" content="../../php/"/>
<title>Bukid Connect — Farm-to-Buyer Marketplace</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&amp;family=DM+Sans:wght@300;400;500;600&amp;display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../../css/main.css"/>
</head>
<body>
"""
scripts = """
<script src="../../js/config.js"></script>
<script src="../../js/shared/utils.js"></script>
<script src="../../js/farmer/farmer-pages.js"></script>
<script src="../../js/buyer/buyer-pages.js"></script>
<script src="../../js/admin/admin-pages.js"></script>
<script src="../../js/shared/navigation.js"></script>
<script src="../../js/shared/app.js"></script>
</body>
</html>
"""
out = root / "html" / "en" / "index.html"
out.parent.mkdir(parents=True, exist_ok=True)
out.write_text(head + body + scripts, encoding="utf-8")
print("html/en/index.html OK")
