#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Tiskové PDF nabídky pro tiskárnu: A4 na šířku + 3 mm spad (bleed) + ořezové
značky, TrimBox/BleedBox. Titulní a zadní strana jdou na spad (barva do kraje).
Generuje z jazykových stránek (CZ/EN/DE), tj. z data.js. Spusť po změně cen:
    python3 tools/nabidka_tisk.py
Potřebuje Google Chrome/Chromium, pypdf, reportlab.
"""
import http.server, socketserver, threading, subprocess, os, shutil, time, io, tempfile, sys
from reportlab.pdfgen import canvas
from reportlab.lib.units import mm
from pypdf import PdfReader, PdfWriter

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PORT = 8091
PAGES = [("nabidka-masazi.html","nabidka-masazi-tisk.pdf"),
         ("nabidka-masazi-en.html","nabidka-masazi-en-tisk.pdf"),
         ("nabidka-masazi-de.html","nabidka-masazi-de-tisk.pdf")]
OVERRIDE = """
@media print{
  @page{size:303mm 216mm;margin:13mm 16mm}
  @page bleed{margin:0}
  .cover,.back{page:bleed}
  .paper{width:auto!important;padding:0!important;margin:0!important;box-shadow:none!important}
  .cover{min-height:216mm!important;border-radius:0!important;margin:0!important;padding:26mm 32mm!important}
  .cover-fr{border:none!important}
  .back{min-height:216mm!important;border-radius:0!important;margin:0!important;padding:24mm 30mm!important}
}
"""
def chrome():
    for c in ("google-chrome","google-chrome-stable","chromium","chromium-browser"):
        p=shutil.which(c)
        if p: return p
    sys.exit("Chrome nenalezen")

def add_marks(raw, out):
    MW,MH=303*mm,216*mm; B=3*mm
    tl,tr,tb,tt=B,MW-B,B,MH-B
    r=PdfReader(raw); n=len(r.pages)
    buf=io.BytesIO(); c=canvas.Canvas(buf,pagesize=(MW,MH)); c.setLineWidth(0.3); c.setStrokeColorRGB(0,0,0)
    for _ in range(n):
        c.line(tl,0,tl,tb); c.line(0,tb,tl,tb)
        c.line(tr,0,tr,tb); c.line(tr,tb,MW,tb)
        c.line(tl,tt,tl,MH); c.line(0,tt,tl,tt)
        c.line(tr,tt,tr,MH); c.line(tr,tt,MW,tt)
        c.showPage()
    c.save(); buf.seek(0); ov=PdfReader(buf); w=PdfWriter()
    for i,p in enumerate(r.pages):
        p.merge_page(ov.pages[i])
        p.trimbox.lower_left=(B,B); p.trimbox.upper_right=(MW-B,MH-B)
        p.bleedbox.lower_left=(0,0); p.bleedbox.upper_right=(MW,MH)
        w.add_page(p)
    with open(out,"wb") as f: w.write(f)

def main():
    ch=chrome(); os.chdir(ROOT)
    httpd=socketserver.TCPServer(("127.0.0.1",PORT),type("H",(http.server.SimpleHTTPRequestHandler,),{"log_message":lambda *a:None}))
    threading.Thread(target=httpd.serve_forever,daemon=True).start()
    try:
        for src,out in PAGES:
            html=open(os.path.join(ROOT,src),encoding="utf-8").read().replace("</style>",OVERRIDE+"</style>")
            tmp=os.path.join(ROOT,"_tisktmp.html"); open(tmp,"w",encoding="utf-8").write(html)
            raw=os.path.join(ROOT,"_raw.pdf")
            subprocess.run([ch,"--headless=new","--no-sandbox","--disable-gpu","--no-pdf-header-footer","--virtual-time-budget=15000","--print-to-pdf="+raw,"http://127.0.0.1:%d/_tisktmp.html"%PORT],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            time.sleep(0.2); add_marks(raw,os.path.join(ROOT,out))
            os.remove(tmp); os.remove(raw)
            print("OK "+out)
    finally:
        httpd.shutdown()

if __name__=="__main__": main()
