#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Vygeneruje nabidka-masazi.pdf z nabidka-masazi.html (A4, vícestránkový e-book).
Bere ceny a texty z assets/js/data.js, takže po změně ceníku stačí spustit znovu:
    python3 tools/nabidka_pdf.py
Potřebuje Google Chrome / Chromium v systému.
"""
import http.server, socketserver, threading, subprocess, sys, os, shutil, time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = 8099
OUT  = os.path.join(ROOT, "nabidka-masazi.pdf")
PAGE = "nabidka-masazi.html"

def find_chrome():
    for c in ("google-chrome","google-chrome-stable","chromium","chromium-browser","chrome"):
        p = shutil.which(c)
        if p: return p
    return None

class Handler(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *a): pass

def main():
    chrome = find_chrome()
    if not chrome:
        sys.exit("Nenalezen Chrome/Chromium – nainstalujte jej a spusťte znovu.")
    os.chdir(ROOT)
    httpd = socketserver.TCPServer(("127.0.0.1", PORT), Handler)
    t = threading.Thread(target=httpd.serve_forever, daemon=True); t.start()
    try:
        cmd = [chrome, "--headless=new", "--no-sandbox", "--disable-gpu",
               "--no-pdf-header-footer", "--print-to-pdf-no-header",
               "--virtual-time-budget=15000",
               "--print-to-pdf=%s" % OUT,
               "http://127.0.0.1:%d/%s" % (PORT, PAGE)]
        r = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        time.sleep(0.3)
    finally:
        httpd.shutdown()
    if os.path.exists(OUT):
        print("Hotovo:", OUT, "(%d kB)" % (os.path.getsize(OUT)//1024))
    else:
        sys.stdout.write(r.stdout.decode("utf-8","replace"))
        sys.exit("PDF se nevytvořilo.")

if __name__ == "__main__":
    main()
