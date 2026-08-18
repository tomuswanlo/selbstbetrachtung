<?php
declare(strict_types=1);
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Termin online buchen – Selbstbetrachtung</title>
<meta name="description" content="Vereinbaren Sie online einen Termin für ein Erstgespräch oder einen Folgetermin bei Gabriele Küppers – Selbstbetrachtung, Psychologische Beratung/Coaching, Mönchengladbach.">
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
  body{
    margin:0; background:var(--cream); color:var(--ink);
    font-family:var(--font-body); line-height:1.55;
  }
  a{color:var(--gold-dark);}
  .site-header{
    display:flex; align-items:center; justify-content:space-between;
    padding:1rem 1.5rem; background:var(--cream-light); border-bottom:1px solid var(--cream-dark);
  }
  .site-header .back-link{ color:var(--ink-mute); text-decoration:none; font-size:.95rem; }
  .site-header .back-link:hover{ color:var(--ink); }
  .site-header .brand{ font-family:var(--font-head); font-weight:600; letter-spacing:.03em; color:var(--ink); }

  main{ max-width:640px; margin:0 auto; padding:2rem 1.25rem 4rem; }
  h1{ font-family:var(--font-head); font-weight:600; font-size:1.8rem; margin:.2rem 0 .5rem; }
  h2{ font-family:var(--font-head); font-weight:600; font-size:1.25rem; margin:0 0 1rem; }
  .intro{ color:var(--ink-mute); margin-bottom:1.75rem; }

  .type-selector{ display:flex; gap:.75rem; margin-bottom:1.75rem; flex-wrap:wrap; }
  .type-selector label{
    flex:1 1 200px; display:flex; align-items:center; gap:.5rem;
    background:var(--cream-light); border:1.5px solid var(--cream-dark); border-radius:12px;
    padding:.85rem 1rem; cursor:pointer; font-weight:600; transition:border-color .15s, background .15s;
  }
  .type-selector label:has(input:checked){ border-color:var(--gold); background:#fff; }
  .type-selector .duration{ font-weight:400; color:var(--ink-mute); font-size:.9rem; }

  .calendar-card, .slots-card, .booking-form, .success-card{
    background:var(--cream-light); border:1px solid var(--cream-dark); border-radius:16px;
    padding:1.5rem; margin-bottom:1.5rem;
  }

  .calendar-nav{ display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; }
  .calendar-nav button{
    background:none; border:1px solid var(--cream-dark); border-radius:8px; width:2.2rem; height:2.2rem;
    font-size:1.1rem; cursor:pointer; color:var(--ink);
  }
  .calendar-nav button:disabled{ opacity:.35; cursor:not-allowed; }
  .calendar-nav span{ font-weight:600; font-family:var(--font-head); }

  .calendar-grid{ display:grid; grid-template-columns:repeat(7,1fr); gap:.35rem; }
  .calendar-grid .wd{ text-align:center; font-size:.75rem; color:var(--ink-mute); padding-bottom:.35rem; }
  .calendar-grid button{
    aspect-ratio:1; border:1px solid transparent; background:var(--cream); border-radius:8px;
    cursor:pointer; font-size:.9rem; color:var(--ink);
  }
  .calendar-grid button:hover:not(:disabled){ border-color:var(--gold); }
  .calendar-grid button.selected{ background:var(--gold); color:#fff; font-weight:700; }
  .calendar-grid button:disabled{ background:none; color:var(--ink-mute); opacity:.35; cursor:default; }
  .calendar-grid .empty{ visibility:hidden; }

  .slots-grid{ display:flex; flex-wrap:wrap; gap:.6rem; }
  .slots-grid button{
    border:1.5px solid var(--cream-dark); background:#fff; border-radius:10px; padding:.55rem 1rem;
    font-weight:600; cursor:pointer; color:var(--ink);
  }
  .slots-grid button:hover{ border-color:var(--gold); }
  .slots-grid button.selected{ background:var(--gold); border-color:var(--gold); color:#fff; }
  .slots-empty{ color:var(--ink-mute); }
  .slots-hint{ color:var(--ink-mute); font-size:.9rem; font-weight:600; margin:0 0 1rem; }

  .booking-form label{ display:block; margin-bottom:1rem; font-weight:600; font-size:.92rem; }
  .booking-form input[type=text], .booking-form input[type=email], .booking-form input[type=tel], .booking-form textarea{
    display:block; width:100%; margin-top:.4rem; padding:.65rem .8rem; font-family:inherit; font-size:1rem;
    border:1.5px solid var(--cream-dark); border-radius:10px; background:#fff; color:var(--ink); font-weight:400;
  }
  .booking-form textarea{ min-height:5rem; resize:vertical; }
  .booking-form .consent{ display:flex; gap:.5rem; align-items:flex-start; font-weight:400; font-size:.85rem; color:var(--ink-mute); }
  .booking-form .consent input{ margin-top:.2rem; }
  .slot-summary{ background:var(--cream-dark); border-radius:10px; padding:.7rem 1rem; font-weight:600; margin:-.25rem 0 1.25rem; }
  .hp{ position:absolute; left:-9999px; width:1px; height:1px; opacity:0; }

  .btn{
    display:inline-block; border:none; border-radius:999px; padding:.85rem 1.75rem; font-weight:700;
    font-size:1rem; cursor:pointer; font-family:inherit;
  }
  .btn--primary{ background:var(--gold); color:#fff; }
  .btn--primary:hover{ background:var(--gold-dark); }
  .btn:disabled{ opacity:.6; cursor:wait; }

  .form-error, .month-error{ color:var(--danger); font-weight:600; margin-top:1rem; }
  .success-card{ border-color:var(--green); }
  .success-card h2{ color:#2f7a53; }
  .success-card .hint{ color:var(--ink-mute); font-size:.9rem; }

  footer{ text-align:center; color:var(--ink-mute); font-size:.85rem; padding:2rem 1rem; }
</style>
</head>
<body>
  <header class="site-header">
    <a class="back-link" href="/">← Zur Startseite</a>
    <span class="brand">Selbstbetrachtung</span>
  </header>

  <main>
    <h1>Termin online buchen</h1>
    <p class="intro">Wählen Sie unten eine Terminart und einen freien Termin. Nach der Buchung erhalten Sie eine Bestätigung per E-Mail mit einem Link, über den Sie den Termin bei Bedarf wieder absagen können.</p>

    <div class="type-selector" id="typeSelector">
      <label>
        <input type="radio" name="type" value="erstgespraech" checked>
        Erstgespräch <span class="duration">(15 Min.)</span>
      </label>
      <label>
        <input type="radio" name="type" value="folgetermin">
        Folgetermin <span class="duration">(60 Min.)</span>
      </label>
    </div>

    <div class="calendar-card">
      <div class="calendar-nav">
        <button type="button" id="prevMonth" aria-label="Vorheriger Monat">‹</button>
        <span id="monthLabel"></span>
        <button type="button" id="nextMonth" aria-label="Nächster Monat">›</button>
      </div>
      <div class="calendar-grid" id="calendarGrid"></div>
      <p class="month-error" id="monthError" hidden></p>
    </div>

    <div class="slots-card" id="slotsSection" hidden>
      <h2>Verfügbare Uhrzeiten am <span id="selectedDateLabel"></span></h2>
      <p class="slots-hint">Sollte kein Termin in Ihrem gewünschten Zeitfenster frei sein, kontaktieren Sie mich gerne direkt über das <a href="/#kontakt">Kontaktformular</a>.</p>
      <div class="slots-grid" id="slotsGrid"></div>
      <p class="slots-empty" id="slotsEmpty" hidden>An diesem Tag sind leider keine Termine mehr frei.</p>
    </div>

    <form class="booking-form" id="bookingForm" hidden>
      <h2>Ihre Daten</h2>
      <p class="slot-summary" id="slotSummary"></p>

      <label>Name*
        <input type="text" name="name" required autocomplete="name">
      </label>
      <label>E-Mail*
        <input type="email" name="email" required autocomplete="email">
      </label>
      <label>Telefon (optional)
        <input type="tel" name="phone" autocomplete="tel">
      </label>
      <label>Nachricht (optional)
        <textarea name="message" placeholder="Möchten Sie mir vorab etwas mitteilen?"></textarea>
      </label>
      <label class="consent">
        <input type="checkbox" name="consent" required>
        <span>Ich habe die <a href="/#datenschutz" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen und bin mit der Verarbeitung meiner Daten zur Terminvereinbarung einverstanden.*</span>
      </label>

      <input type="text" name="hp_confirm2" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <input type="hidden" name="ts" id="tsField">
      <input type="hidden" name="date" id="dateField">
      <input type="hidden" name="start_time" id="startTimeField">
      <input type="hidden" name="type" id="typeField">

      <div class="cf-turnstile" data-sitekey="0x4AAAAAAES154ac2rM_sCUb" data-theme="light" style="margin-bottom:1rem;"></div>

      <button type="submit" class="btn btn--primary" id="submitBtn">Termin verbindlich buchen</button>
      <p class="form-error" id="formError" hidden></p>
    </form>

    <div class="success-card" id="successSection" hidden>
      <h2>Termin bestätigt ✓</h2>
      <p id="successDetails"></p>
      <p class="hint">Eine Bestätigung wurde an Ihre E-Mail-Adresse gesendet – dort finden Sie auch den Link zum Absagen, falls Sie den Termin nicht wahrnehmen können.</p>
    </div>
  </main>

  <footer>Selbstbetrachtung – Gabriele Küppers · Dachsweg 27, 41189 Mönchengladbach</footer>

  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  <script>
  (function(){
    var MAX_ADVANCE_DAYS = 90;
    var WEEKDAY_LABELS = ['Mo','Di','Mi','Do','Fr','Sa','So'];

    var today = new Date();
    var view = { year: today.getFullYear(), month: today.getMonth() + 1 }; // 1-indexed month
    var selectedType = 'erstgespraech';
    var selectedDate = null;
    var selectedSlot = null;

    var typeSelector = document.getElementById('typeSelector');
    var monthLabel = document.getElementById('monthLabel');
    var calendarGrid = document.getElementById('calendarGrid');
    var monthError = document.getElementById('monthError');
    var prevBtn = document.getElementById('prevMonth');
    var nextBtn = document.getElementById('nextMonth');
    var slotsSection = document.getElementById('slotsSection');
    var slotsGrid = document.getElementById('slotsGrid');
    var slotsEmpty = document.getElementById('slotsEmpty');
    var selectedDateLabel = document.getElementById('selectedDateLabel');
    var bookingForm = document.getElementById('bookingForm');
    var slotSummary = document.getElementById('slotSummary');
    var successSection = document.getElementById('successSection');
    var successDetails = document.getElementById('successDetails');
    var formError = document.getElementById('formError');
    var submitBtn = document.getElementById('submitBtn');

    document.getElementById('tsField').value = String(Date.now());

    var MONTH_NAMES = ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];

    function pad(n){ return n < 10 ? '0' + n : String(n); }
    function fmtDate(y,m,d){ return y + '-' + pad(m) + '-' + pad(d); }

    typeSelector.addEventListener('change', function(e){
      selectedType = typeSelector.querySelector('input[name=type]:checked').value;
      resetSelection();
      loadMonth();
    });

    prevBtn.addEventListener('click', function(){
      view.month--; if (view.month < 1) { view.month = 12; view.year--; }
      resetSelection();
      loadMonth();
    });
    nextBtn.addEventListener('click', function(){
      view.month++; if (view.month > 12) { view.month = 1; view.year++; }
      resetSelection();
      loadMonth();
    });

    function resetSelection(){
      selectedDate = null; selectedSlot = null;
      slotsSection.hidden = true; bookingForm.hidden = true; successSection.hidden = true;
    }

    function loadMonth(){
      monthLabel.textContent = MONTH_NAMES[view.month - 1] + ' ' + view.year;
      monthError.hidden = true;
      calendarGrid.innerHTML = '<p style="color:var(--ink-mute)">Lade Termine…</p>';

      var maxDate = new Date(); maxDate.setDate(maxDate.getDate() + MAX_ADVANCE_DAYS);
      nextBtn.disabled = (view.year > maxDate.getFullYear()) ||
        (view.year === maxDate.getFullYear() && view.month > maxDate.getMonth() + 1);
      var firstOfView = new Date(view.year, view.month - 1, 1);
      var firstOfThisMonth = new Date(today.getFullYear(), today.getMonth(), 1);
      prevBtn.disabled = firstOfView <= firstOfThisMonth;

      fetch('/termin-api.php?action=month&year=' + view.year + '&month=' + view.month + '&type=' + encodeURIComponent(selectedType))
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (!data.ok) { throw new Error(data.error || 'Fehler'); }
          renderCalendar(data.dates || []);
        })
        .catch(function(){
          calendarGrid.innerHTML = '';
          monthError.hidden = false;
          monthError.textContent = 'Der Kalender konnte nicht geladen werden. Bitte laden Sie die Seite neu.';
        });
    }

    function renderCalendar(availableDates){
      var available = {};
      availableDates.forEach(function(d){ available[d] = true; });

      var firstDay = new Date(view.year, view.month - 1, 1);
      var daysInMonth = new Date(view.year, view.month, 0).getDate();
      var startOffset = (firstDay.getDay() + 6) % 7; // Montag = 0

      var html = WEEKDAY_LABELS.map(function(w){ return '<div class="wd">' + w + '</div>'; }).join('');
      for (var i = 0; i < startOffset; i++) { html += '<button class="empty" disabled></button>'; }
      for (var d = 1; d <= daysInMonth; d++) {
        var dateStr = fmtDate(view.year, view.month, d);
        var isAvailable = !!available[dateStr];
        html += '<button type="button" data-date="' + dateStr + '"' + (isAvailable ? '' : ' disabled') + '>' + d + '</button>';
      }
      calendarGrid.innerHTML = html;

      calendarGrid.querySelectorAll('button[data-date]').forEach(function(btn){
        btn.addEventListener('click', function(){
          calendarGrid.querySelectorAll('button.selected').forEach(function(b){ b.classList.remove('selected'); });
          btn.classList.add('selected');
          selectDate(btn.getAttribute('data-date'));
        });
      });
    }

    function selectDate(dateStr){
      selectedDate = dateStr;
      selectedSlot = null;
      bookingForm.hidden = true;
      var d = new Date(dateStr + 'T00:00:00');
      selectedDateLabel.textContent = d.toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
      slotsSection.hidden = false;
      slotsGrid.innerHTML = '<p style="color:var(--ink-mute)">Lade Uhrzeiten…</p>';
      slotsEmpty.hidden = true;

      fetch('/termin-api.php?action=slots&date=' + encodeURIComponent(dateStr) + '&type=' + encodeURIComponent(selectedType))
        .then(function(r){ return r.json(); })
        .then(function(data){
          if (!data.ok) { throw new Error(data.error || 'Fehler'); }
          renderSlots(data.slots || []);
        })
        .catch(function(){
          slotsGrid.innerHTML = '';
          slotsEmpty.hidden = false;
          slotsEmpty.textContent = 'Die Uhrzeiten konnten nicht geladen werden. Bitte versuchen Sie es erneut.';
        });

      slotsSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function renderSlots(slots){
      if (!slots.length) {
        slotsGrid.innerHTML = '';
        slotsEmpty.hidden = false;
        return;
      }
      slotsEmpty.hidden = true;
      slotsGrid.innerHTML = slots.map(function(s){
        return '<button type="button" data-slot="' + s + '">' + s + ' Uhr</button>';
      }).join('');
      slotsGrid.querySelectorAll('button[data-slot]').forEach(function(btn){
        btn.addEventListener('click', function(){
          slotsGrid.querySelectorAll('button.selected').forEach(function(b){ b.classList.remove('selected'); });
          btn.classList.add('selected');
          selectSlot(btn.getAttribute('data-slot'));
        });
      });
    }

    function selectSlot(slot){
      selectedSlot = slot;
      var typeLabel = typeSelector.querySelector('input[name=type]:checked').parentNode.textContent.trim();
      var d = new Date(selectedDate + 'T00:00:00');
      slotSummary.textContent = typeLabel + ' am ' + d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' um ' + slot + ' Uhr';
      document.getElementById('dateField').value = selectedDate;
      document.getElementById('startTimeField').value = selectedSlot;
      document.getElementById('typeField').value = selectedType;
      bookingForm.hidden = false;
      formError.hidden = true;
      bookingForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    bookingForm.addEventListener('submit', function(e){
      e.preventDefault();
      formError.hidden = true;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Wird gebucht…';

      var fd = new FormData(bookingForm);
      fd.append('action', 'book');

      fetch('/termin-api.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json().then(function(data){ return { status: r.status, data: data }; }); })
        .then(function(res){
          var data = res.data;
          if (data.ok) {
            bookingForm.hidden = true;
            slotsSection.hidden = true;
            successSection.hidden = false;
            successDetails.textContent = data.booking.type_label + ' am ' + data.booking.date_formatted + ' um ' + data.booking.start_time + ' Uhr';
            successSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
          } else {
            if (data.conflict) {
              formError.textContent = data.error || 'Dieser Termin ist leider inzwischen vergeben.';
              formError.hidden = false;
              bookingForm.hidden = true;
              if (selectedDate) { selectDate(selectedDate); }
            } else {
              formError.textContent = data.error || 'Die Buchung ist fehlgeschlagen. Bitte versuchen Sie es erneut.';
              formError.hidden = false;
            }
            if (window.turnstile) { window.turnstile.reset(); }
          }
        })
        .catch(function(){
          formError.textContent = 'Die Buchung ist fehlgeschlagen. Bitte versuchen Sie es erneut.';
          formError.hidden = false;
          if (window.turnstile) { window.turnstile.reset(); }
        })
        .finally(function(){
          submitBtn.disabled = false;
          submitBtn.textContent = 'Termin verbindlich buchen';
        });
    });

    loadMonth();
  })();
  </script>
</body>
</html>
