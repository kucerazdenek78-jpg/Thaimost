#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Vygeneruje PDF e-booky nabídky z HTML (CZ/EN/DE), vše z assets/js/data.js.
Po změně cen/textů v data.js spusť znovu:  python3 tools/nabidka_pdf.py
Potřebuje Google Chrome / Chromium.
"""
import http.server, socketserver, threading, subprocess, sys, os, shutil, time
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = 8099
PAGES = [
    ("nabidka-masazi.html",    "nabidka-masazi.pdf"),
    ("nabidka-masazi-en.html", "nabidka-masazi-en.pdf"),
    ("nabidka-masazi-de.html", "nabidka-masazi-de.pdf"),
]
def find_chrome():
    for c in ("google-chrome","google-chrome-stable","chromium","chromium-browser","chrome"):
        p = shutil.which(c)
        if p: return p
    return None
def main():
    chrome = find_chrome()
    if not chrome: sys.exit("Nenalezen Chrome/Chromium.")
    os.chdir(ROOT)
    httpd = socketserver.TCPServer(("127.0.0.1", PORT), type("H",(http.server.SimpleHTTPRequestHandler,),{"log_message":lambda *a:None}))
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    try:
        for page, out in PAGES:
            outp = os.path.join(ROOT, out)
            subprocess.run([chrome,"--headless=new","--no-sandbox","--disable-gpu","--no-pdf-header-footer",
                "--virtual-time-budget=15000","--print-to-pdf=%s"%outp,"http://127.0.0.1:%d/%s"%(PORT,page)],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            time.sleep(0.2)
            print(("OK  " if os.path.exists(outp) else "FAIL ")+out+(" (%dkB)"%(os.path.getsize(outp)//1024) if os.path.exists(outp) else ""))
    finally:
        httpd.shutdown()
if __name__=="__main__": main()
