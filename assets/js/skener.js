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
    o.innerHTML = '<div class="m" id="tsMsg">Namiřte fotoaparát na QR kód.</div><div style="position:relative"><video playsinline muted></video><div class="fr"></div></div><button type="button" id="tsFoto">📷 Vyfotit QR kód (aplikace fotoaparátu)</button><input type="file" id="tsFile" accept="image/*" capture="environment" hidden><button type="button" id="tsX" style="background:#555;color:#fff">Zavřít</button>';
    document.body.appendChild(o);
    var v = o.querySelector("video"), msg = o.querySelector("#tsMsg"), stream = null, stop = false, timer = null;

    function zavri() {
      stop = true; clearTimeout(timer);
      if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
      o.remove(); var c = document.getElementById("thaiScanCss"); if (c) c.remove();
    }
    o.querySelector("#tsX").onclick = zavri;

    // záloha: vyfotit QR běžným fotoaparátem telefonu a přečíst z fotky (funguje i když živý náhled nejde)
    var fi = o.querySelector("#tsFile");
    o.querySelector("#tsFoto").onclick = function () { fi.click(); };
    fi.onchange = function () {
      var f = fi.files && fi.files[0]; if (!f) return;
      msg.textContent = "Čtu QR kód z fotky…";
      var img = new Image(), url = URL.createObjectURL(f);
      img.onload = function () {
        URL.revokeObjectURL(url);
        var k = Math.min(1, 1400 / Math.max(img.width, img.height)), c = document.createElement("canvas");
        c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
        var x = c.getContext("2d", { willReadFrequently: true }); x.drawImage(img, 0, 0, c.width, c.height);
        nactiJsqr().then(function () {
          var d = x.getImageData(0, 0, c.width, c.height), r = window.jsQR(d.data, d.width, d.height, { inversionAttempts: "attemptBoth" });
          if (r && r.data) { zavri(); try { done(r.data); } catch (e) { console.error(e); } }
          else msg.textContent = "QR kód se na fotce nenašel. Vyfoťte ho zblízka, ostře a celý.";
        }).catch(function () { msg.textContent = "Nepodařilo se načíst čtečku QR."; });
      };
      img.onerror = function () { msg.textContent = "Fotku se nepodařilo otevřít."; };
      img.src = url; fi.value = "";
    };

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { msg.textContent = "Tento prohlížeč neumí použít fotoaparát (nebo stránka není na https). Otevřete QR kód běžnou aplikací fotoaparátu."; return; }

    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: "environment" } }, audio: false }).catch(function (e) {
      if (e && (e.name === "OverconstrainedError" || e.name === "NotFoundError" || e.name === "NotReadableError")) return navigator.mediaDevices.getUserMedia({ video: true, audio: false });
      throw e;
    }).then(function (s) {
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
      var n = e && e.name ? e.name : "chyba";
      msg.textContent = (n === "NotAllowedError" ? "Přístup k fotoaparátu je zakázaný – povolte ho v nastavení prohlížeče (ikona zámku vedle adresy → Fotoaparát) a zkuste znovu. " :
        n === "NotReadableError" ? "Fotoaparát používá jiná aplikace – zavřete ji. " :
        n === "NotFoundError" ? "Zařízení nemá dostupný fotoaparát. " : "Fotoaparát se nepodařilo spustit (" + n + "). ") +
        "Můžete QR vyfotit tlačítkem níže.";
    });
  }
  window.ThaiScan = { open: open };
})();
