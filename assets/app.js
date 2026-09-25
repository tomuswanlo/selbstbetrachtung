/* ============================================================
   SELBSTBETRACHTUNG — Interaktionen
============================================================ */
(function () {
  "use strict";

  /* --- Nav: Schatten beim Scrollen --- */
  var nav = document.getElementById("nav");
  function onScroll() {
    if (window.scrollY > 24) nav.classList.add("scrolled");
    else nav.classList.remove("scrolled");
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  /* --- Mobile Menü --- */
  var burger = document.getElementById("burger");
  var menu = document.getElementById("mobileMenu");
  function closeMenu() {
    burger.classList.remove("open");
    menu.classList.remove("open");
    burger.setAttribute("aria-expanded", "false");
    burger.setAttribute("aria-label", "Menü öffnen");
    document.body.style.overflow = "";
  }
  burger.addEventListener("click", function () {
    var open = menu.classList.toggle("open");
    burger.classList.toggle("open", open);
    burger.setAttribute("aria-expanded", open ? "true" : "false");
    burger.setAttribute("aria-label", open ? "Menü schließen" : "Menü öffnen");
    document.body.style.overflow = open ? "hidden" : "";
  });
  menu.querySelectorAll("a").forEach(function (a) {
    a.addEventListener("click", closeMenu);
  });

  /* --- Scroll-Reveal --- */
  var reveals = document.querySelectorAll(".reveal");
  if ("IntersectionObserver" in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add("in");
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.12, rootMargin: "0px 0px -8% 0px" });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add("in"); });
  }

  /* --- Aktiver Nav-Link --- */
  var sections = ["themen", "fuer-wen", "ueber-mich", "ablauf", "preise", "faq"];
  var navLinks = {};
  document.querySelectorAll(".nav__links a").forEach(function (a) {
    var id = a.getAttribute("href").slice(1);
    navLinks[id] = a;
  });
  if ("IntersectionObserver" in window) {
    var spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          Object.keys(navLinks).forEach(function (k) { navLinks[k].classList.remove("active"); });
          var link = navLinks[e.target.id];
          if (link) link.classList.add("active");
        }
      });
    }, { rootMargin: "-45% 0px -50% 0px" });
    sections.forEach(function (id) {
      var el = document.getElementById(id);
      if (el) spy.observe(el);
    });
  }

  /* --- Lightbox für Zertifikate --- */
  var lb = document.getElementById("lightbox");
  var lbImg = document.getElementById("lbImg");
  var lbClose = document.getElementById("lbClose");
  function openLb(src, alt) {
    lbImg.src = src;
    lbImg.alt = alt || "Dokument";
    lb.classList.add("open");
    document.body.style.overflow = "hidden";
  }
  function closeLb() {
    lb.classList.remove("open");
    document.body.style.overflow = "";
  }
  document.querySelectorAll(".cert").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var img = btn.querySelector("img");
      openLb(btn.getAttribute("data-src"), img ? img.alt : "");
    });
  });
  lbClose.addEventListener("click", closeLb);
  lb.addEventListener("click", function (e) { if (e.target === lb) closeLb(); });
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") { closeLb(); closeMenu(); }
  });

  /* --- Telefonnummer in Impressum/Datenschutz/AGB vor simplem Scraping schuetzen ---
     Ziffern liegen im HTML nur als versetzte Zeichencodes vor (data-t), nicht als
     lesbarer Text/Linkmuster. Echte Besucher (mit JS, wie hier ueberall auf der
     Seite vorausgesetzt) sehen die Nummer normal, selektierbar und vorlesbar. */
  document.querySelectorAll(".obf-tel").forEach(function (el) {
    var codes = el.getAttribute("data-t").split(",").map(function (n) {
      return parseInt(n, 10) - 7;
    });
    el.textContent = String.fromCharCode.apply(null, codes);
  });

  /* --- Jahr im Footer --- */
  var y = document.getElementById("year");
  if (y) y.textContent = new Date().getFullYear();

  /* --- Kontaktformular --- */
  var form = document.getElementById("contactForm");
  if (form) {
    var status = document.getElementById("cfStatus");
    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    var formRenderedAt = Date.now(); // für serverseitige Zeit-Prüfung gegen Bots
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      status.className = "cf__status";
      status.textContent = "";
      var name = form.elements["name"];
      var email = form.elements["email"];
      var msg = form.elements["message"];
      var consent = form.elements["consent"];
      [name, email, msg].forEach(function (el) { el.classList.remove("is-invalid"); });

      var firstError = null;
      if (!name.value.trim()) { name.classList.add("is-invalid"); firstError = firstError || name; }
      if (!EMAIL.test(email.value.trim())) { email.classList.add("is-invalid"); firstError = firstError || email; }
      if (!msg.value.trim()) { msg.classList.add("is-invalid"); firstError = firstError || msg; }
      if (!consent.checked) { firstError = firstError || consent; }

      if (firstError) {
        status.className = "cf__status err";
        status.textContent = consent.checked
          ? "Bitte füllen Sie die markierten Felder aus."
          : (firstError === consent ? "Bitte bestätigen Sie die Datenschutzerklärung." : "Bitte füllen Sie die markierten Felder aus.");
        if (firstError.focus) firstError.focus();
        return;
      }

      function mailtoFallback() {
        var subject = encodeURIComponent("Anfrage über die Website – " + name.value.trim());
        var body = encodeURIComponent(
          "Name: " + name.value.trim() + "\n" +
          "E-Mail: " + email.value.trim() + "\n\n" +
          "Nachricht:\n" + msg.value.trim()
        );
        window.location.href = "mailto:kontakt@selbstbetrachtung-online.de?subject=" + subject + "&body=" + body;
      }

      var submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;
      status.className = "cf__status";
      status.textContent = "Wird gesendet …";

      var fd = new FormData(form);
      fd.append("ts", String(formRenderedAt));

      fetch("/contact.php", { method: "POST", body: fd })
        .then(function (r) {
          return r.json().catch(function () { return {}; }).then(function (data) {
            return { ok: r.ok, data: data };
          });
        })
        .then(function (res) {
          if (res.ok && res.data && res.data.ok) {
            status.className = "cf__status ok";
            status.textContent = "Vielen Dank für Ihre Nachricht! Ich melde mich in der Regel innerhalb von 24–48 Stunden bei Ihnen.";
            form.reset();
          } else {
            throw new Error((res.data && res.data.error) || "Versand fehlgeschlagen");
          }
        })
        .catch(function () {
          mailtoFallback();
          status.className = "cf__status err";
          status.textContent = "Der Versand über die Website hat nicht geklappt – Ihr E-Mail-Programm öffnet sich als Alternative.";
        })
        .then(function () {
          if (submitBtn) submitBtn.disabled = false;
        });
    });
  }
})();
