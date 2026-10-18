"""Screenshot helper: python3 shoot.py <path> <name> [scrolls...]  (desktop 1366x800 + mobile 390x844)."""
import sys
from playwright.sync_api import sync_playwright
P = "/var/lib/freelancer/projects/40507143/ehmar-store/shots/"
path, name = sys.argv[1], sys.argv[2]
scrolls = [int(x) for x in sys.argv[3:]] or [0]
errs = []
with sync_playwright() as p:
    b = p.chromium.launch()
    for tag, vp in (("d", {"width": 1366, "height": 800}), ("m", {"width": 390, "height": 844})):
        pg = b.new_page(viewport=vp)
        pg.on("console", lambda m: errs.append(f"{tag} {m.text} {m.location.get('url','')}") if m.type == "error" else None)
        pg.on("pageerror", lambda e: errs.append(f"{tag} {e}"))
        pg.goto("http://localhost:8090" + path, wait_until="networkidle")
        for i, y in enumerate(scrolls):
            pg.evaluate(f"window.scrollTo(0,{y})"); pg.wait_for_timeout(350)
            pg.screenshot(path=f"{P}{tag}-{name}-{i}.png")
        print(tag, "scrollW", pg.evaluate("document.documentElement.scrollWidth"), "clientW", pg.evaluate("document.documentElement.clientWidth"), "H", pg.evaluate("document.body.scrollHeight"))
    b.close()
print("errors:", errs[:8])
