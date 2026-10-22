/* Skener QR kódů fotoaparátem (v prohlížeči telefonu / tabletu / notebooku).
   ThaiScan.open(function (text) { ... }) – otevře náhled kamery, po přečtení QR zavolá callback s textem.
   Použije vestavěný BarcodeDetector, jinak knihovnu jsQR (jsqr.js, načte se jen když je potřeba). Funguje jen na https. */
(function () {
  var base = (function () { var s = document.currentScript; return s ? s.src.replace(/[^\/]*$/, "") : ""; })();
  var css = "#thaiScan{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.92);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:16px;font:16px/1.4 system-ui,sans-serif;color:#fff;text-align:center}" +
    "#thaiScan video{width:min(92vw,520px);max-height:62vh;border-radius:14px;background:#000;object-fit:cover}" +
    "#thaiScan .fr{position:absolute;width:min(60vw,300px);aspect-ratio:1;border:3px solid #c69c5d;border-radius:18px;pointer-events:none;top:50%;left:50%;transform:translate(-50%,-62%)}" +
    "#thaiScan button{padding:12px 22px;border-radius:10px;border:0;background:#c69c5d;color:#2b2118;font:inherit;font-weight:700;cursor:pointer}#thaiScan .m{max-width:460px;opacity:.85}";

  function nactiJsqr() {
    return new Promise(function (ok, ko) {
      if (window.jsQR) return ok();
      var s = document.createElement("script"); s.src = base + "jsqr.js"; s.onload = ok; s.onerror = function () { ko(new Error("jsqr")); };
      document.head.appendChild(s);
    });
  }

  function open(done) {
    if (document.getElementById("thaiScan")) return;
    var st = document.createElement("style"); st.id = "thaiScanCss"; st.textContent = css; document.head.appendChild(st);
    var o = document.createElement("div"); o.id = "thaiScan";
    o.innerHTML = '<div class="m" id="tsMsg">Namiřte fotoaparát na QR kód.</div><div style="position:relative"><video playsinline muted></video><div class="fr"></div></div><button type="button">Zavřít</button>';
    document.body.appendChild(o);
    var v = o.querySelector("video"), msg = o.querySelector("#tsMsg"), stream = null, stop = false, timer = null;

    function zavri() {
      stop = true; clearTimeout(timer);
      if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
      o.remove(); var c = document.getElementById("thaiScanCss"); if (c) c.remove();
    }
    o.querySelector("button").onclick = zavri;

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { msg.textContent = "Tento prohlížeč neumí použít fotoaparát (nebo stránka není na https). Otevřete QR kód běžnou aplikací fotoaparátu."; return; }

    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" } }, audio: false }).then(function (s) {
      stream = s; v.srcObject = s; return v.play();
    }).then(function () {
      var det = null, cv = document.createElement("canvas"), cx = cv.getContext("2d", { willReadFrequently: true });
      var pripravit = ("BarcodeDetector" in window) ? Promise.resolve().then(function () { det = new BarcodeDetector({ formats: ["qr_code"] }); }).catch(function () { det = null; return nactiJsqr(); }) : nactiJsqr();
      return pripravit.then(function () {
        (function smycka() {
          if (stop) return;
          var p;
          if (det) p = det.detect(v).then(function (r) { return r && r[0] ? r[0].rawValue : null; }).catch(function () { return null; });
          else {
            var w = v.videoWidth, h = v.videoHeight;
            if (w) { var k = Math.min(1, 640 / w); cv.width = Math.round(w * k); cv.height = Math.round(h * k); cx.drawImage(v, 0, 0, cv.width, cv.height);
              var im = cx.getImageData(0, 0, cv.width, cv.height), r = window.jsQR(im.data, im.width, im.height, { inversionAttempts: "dontInvert" }); p = Promise.resolve(r ? r.data : null); }
            else p = Promise.resolve(null);
          }
          p.then(function (t) {
            if (stop) return;
            if (t) { zavri(); try { done(t); } catch (e) { console.error(e); } return; }
            timer = setTimeout(smycka, 150);
          });
        })();
      });
    }).catch(function (e) {
      msg.textContent = (e && e.name === "NotAllowedError") ? "Přístup k fotoaparátu je zakázaný. Povolte ho v nastavení prohlížeče pro tuto stránku." : "Fotoaparát se nepodařilo spustit.";
    });
  }
  window.ThaiScan = { open: open };
})();
