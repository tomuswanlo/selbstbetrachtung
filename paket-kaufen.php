<?php
declare(strict_types=1);
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>5-Stunden-Paket online kaufen – Selbstbetrachtung</title>
<meta name="description" content="5-Stunden-Paket psychologische Beratung/Coaching bei Gabriele Küppers online buchen und sicher per Kreditkarte, SEPA-Lastschrift oder PayPal bezahlen.">
<meta name="robots" content="noindex, follow">
<style>
  :root{
    --cream:#F4EFE7; --cream-light:#FBF8F2; --cream-dark:#EDE5D8;
    --ink:#2E3439; --ink-2:#23282C; --ink-mute:#626B71;
    --gold:#D6A26A; --gold-light:#E6C396; --gold-dark:#B3813F;
    --blue:#7E909A; --green:#8FD0A8; --danger:#B84A3C;
    --font-head:"Lora","Iowan Old Style","Palatino Linotype",Palatino,"Book Antiqua",Georgia,serif;
    --font-body:"Mulish",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
  }
  *{box-sizing:border-box;}
  body{ margin:0; background:var(--cream); color:var(--ink); font-family:var(--font-body); line-height:1.55; }
  a{color:var(--gold-dark);}
  .site-header{ display:flex; align-items:center; justify-content:space-between; padding:1rem 1.5rem; background:var(--cream-light); border-bottom:1px solid var(--cream-dark); }
  .site-header .back-link{ color:var(--ink-mute); text-decoration:none; font-size:.95rem; }
  .site-header .back-link:hover{ color:var(--ink); }
  .site-header .brand{ font-family:var(--font-head); font-weight:600; letter-spacing:.03em; color:var(--ink); }

  main{ max-width:640px; margin:0 auto; padding:2rem 1.25rem 4rem; }
  h1{ font-family:var(--font-head); font-weight:600; font-size:1.8rem; margin:.2rem 0 .5rem; }
  h2{ font-family:var(--font-head); font-weight:600; font-size:1.2rem; margin:0 0 1rem; }
  .intro{ color:var(--ink-mute); margin-bottom:1.5rem; }

  .price-card{ background:var(--cream-light); border:1px solid var(--gold-light); border-radius:16px; padding:1.4rem 1.5rem; margin-bottom:1.75rem; }
  .price-card .price{ font-family:var(--font-head); font-size:2.1rem; font-weight:600; color:var(--ink); }
  .price-card .price small{ font-family:var(--font-body); font-size:1rem; font-weight:400; color:var(--ink-mute); }
  .price-card .save{ color:#2f7a53; font-weight:700; font-size:.9rem; }

  .booking-form{ background:var(--cream-light); border:1px solid var(--cream-dark); border-radius:16px; padding:1.5rem; margin-bottom:1.5rem; }
  .booking-form label{ display:block; margin-bottom:1rem; font-weight:600; font-size:.92rem; }
  .booking-form input[type=text], .booking-form input[type=email], .booking-form input[type=tel], .booking-form input[type=date]{
    display:block; width:100%; margin-top:.4rem; padding:.65rem .8rem; font-family:inherit; font-size:1rem;
    border:1.5px solid var(--cream-dark); border-radius:10px; background:#fff; color:var(--ink); font-weight:400;
  }
  .row2{ display:grid; grid-template-columns:1fr 1fr; gap:0 1rem; }
  @media (max-width:480px){ .row2{ grid-template-columns:1fr; } }

  .vertrag-box{ background:#fff; border:1px solid var(--cream-dark); border-radius:12px; padding:1rem 1.1rem; margin:.25rem 0 1.25rem; font-size:.88rem; color:var(--ink-mute); max-height:11rem; overflow-y:auto; }
  .vertrag-box p{ margin:.5rem 0; }
  .vertrag-box strong{ color:var(--ink); }

  .consent{ display:flex; gap:.5rem; align-items:flex-start; font-weight:400; font-size:.85rem; color:var(--ink-mute); margin-bottom:1rem; }
  .consent input{ margin-top:.2rem; }
  .hp{ position:absolute; left:-9999px; width:1px; height:1px; opacity:0; }

  .pay-options{ display:flex; gap:.75rem; flex-wrap:wrap; margin-top:1.25rem; }
  .btn{ display:inline-flex; align-items:center; justify-content:center; gap:.5rem; border:none; border-radius:999px; padding:.85rem 1.5rem; font-weight:700; font-size:1rem; cursor:pointer; font-family:inherit; flex:1 1 220px; }
  .btn--stripe{ background:var(--ink); color:#fff; }
  .btn--stripe:hover{ background:var(--ink-2); }
  .btn--paypal{ background:#FFC439; color:#003087; }
  .btn--paypal:hover{ background:#ffb400; }
  .btn:disabled{ opacity:.6; cursor:wait; }

  .form-error{ color:var(--danger); font-weight:600; margin-top:1rem; }
  footer{ text-align:center; color:var(--ink-mute); font-size:.85rem; padding:2rem 1rem; }
</style>
</head>
<body>
  <header class="site-header">
    <a class="back-link" href="/">← Zur Startseite</a>
    <span class="brand">Selbstbetrachtung</span>
  </header>

  <main>
    <h1>5-Stunden-Paket kaufen</h1>
    <p class="intro">Bündeln Sie fünf Beratungsstunden zum vergünstigten Paketpreis und lösen Sie die Stunden bequem bei künftigen Terminbuchungen ein.</p>

    <div class="price-card">
      <div class="price">325,00&nbsp;€ <small>statt 350,00&nbsp;€</small></div>
      <p class="save">Sie sparen 25,00&nbsp;€ – entspricht 65,00&nbsp;€/Stunde</p>
    </div>

    <form class="booking-form" id="kaufForm">
      <h2>Ihre Daten</h2>
      <label>Name, Vorname*
        <input type="text" name="name" required autocomplete="name">
      </label>
      <label>Straße, Hausnummer
        <input type="text" name="strasse" autocomplete="street-address">
      </label>
      <div class="row2">
        <label>PLZ
          <input type="text" name="plz" autocomplete="postal-code">
        </label>
        <label>Ort
          <input type="text" name="ort" autocomplete="address-level2">
        </label>
      </div>
      <div class="row2">
        <label>E-Mail*
          <input type="email" name="email" required autocomplete="email">
        </label>
        <label>Telefon
          <input type="tel" name="phone" autocomplete="tel">
        </label>
      </div>
      <label>Geburtsdatum
        <input type="date" name="geburtsdatum">
      </label>

      <h2>Beratervertrag</h2>
      <div class="vertrag-box">
        <p><strong>Selbstbetrachtung – Gabriele Küppers</strong>, Dachsweg 27, 41189 Mönchengladbach ("Beraterin") und die oben genannte Person ("Klient/in") vereinbaren ein 5-Stunden-Paket psychologische Beratung/Coaching zum Pauschalpreis von 325,00&nbsp;€ (statt 350,00&nbsp;€ für fünf Einzelstunden).</p>
        <p>Es gelten ergänzend die <a href="/#agb" target="_blank" rel="noopener">Allgemeinen Geschäftsbedingungen</a> von Selbstbetrachtung, insbesondere: Die Beratung stellt keine Psychotherapie dar. Ein vereinbarter Termin kann bis 24 Stunden vorher kostenlos storniert werden, danach wird die Sitzung fällig. Alle Angaben unterliegen der Verschwiegenheit gem. § 203 StGB. Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.</p>
        <p>Mit dem Absenden dieses Formulars und der Bezahlung erklären Sie Ihr rechtsverbindliches Einverständnis mit diesem Vertrag in Textform (§ 126b BGB).</p>
      </div>
      <label class="consent">
        <input type="checkbox" name="consent_vertrag" required>
        <span>Ich habe die Vertragsbedingungen oben gelesen und stimme ihnen zu.*</span>
      </label>
      <label class="consent">
        <input type="checkbox" name="consent_datenschutz" required>
        <span>Ich habe die <a href="/#datenschutz" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen und bin mit der Verarbeitung meiner Daten zur Vertragsabwicklung einverstanden.*</span>
      </label>

      <input type="text" name="hp_confirm2" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <input type="hidden" name="ts" id="tsField">

      <div class="cf-turnstile" data-sitekey="0x4AAAAAAES154ac2rM_sCUb" data-theme="light" style="margin-bottom:1rem;"></div>

      <div class="pay-options">
        <button type="submit" class="btn btn--stripe" data-provider="stripe" id="stripeBtn">Mit Karte/SEPA bezahlen</button>
        <button type="submit" class="btn btn--paypal" data-provider="paypal" id="paypalBtn">Mit PayPal bezahlen</button>
      </div>
      <p class="form-error" id="formError" hidden></p>
    </form>
  </main>

  <footer>Selbstbetrachtung – Gabriele Küppers · Dachsweg 27, 41189 Mönchengladbach</footer>

  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <script>
  (function(){
    var form = document.getElementById('kaufForm');
    var formError = document.getElementById('formError');
    var stripeBtn = document.getElementById('stripeBtn');
    var paypalBtn = document.getElementById('paypalBtn');

    document.getElementById('tsField').value = String(Date.now());

    var chosenProvider = null;
    [stripeBtn, paypalBtn].forEach(function(btn){
      btn.addEventListener('click', function(){ chosenProvider = btn.getAttribute('data-provider'); });
    });

    form.addEventListener('submit', function(e){
      e.preventDefault();
      formError.hidden = true;
      stripeBtn.disabled = true; paypalBtn.disabled = true;
      var clickedBtn = chosenProvider === 'paypal' ? paypalBtn : stripeBtn;
      var originalLabel = clickedBtn.textContent;
      clickedBtn.textContent = 'Wird vorbereitet…';

      var fd = new FormData(form);
      fd.append('provider', chosenProvider || 'stripe');

      fetch('/paket-api.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json().then(function(data){ return { status: r.status, data: data }; }); })
        .then(function(res){
          var data = res.data;
          if (data.ok && data.redirect_url) {
            window.location.href = data.redirect_url;
            return;
          }
          formError.textContent = data.error || 'Der Kauf konnte nicht gestartet werden. Bitte versuchen Sie es erneut.';
          formError.hidden = false;
          if (window.turnstile) { window.turnstile.reset(); }
        })
        .catch(function(){
          formError.textContent = 'Der Kauf konnte nicht gestartet werden. Bitte versuchen Sie es erneut.';
          formError.hidden = false;
          if (window.turnstile) { window.turnstile.reset(); }
        })
        .finally(function(){
          stripeBtn.disabled = false; paypalBtn.disabled = false;
          clickedBtn.textContent = originalLabel;
        });
    });
  })();
  </script>
</body>
</html>
