<?php
// Weiterleitung zum WhatsApp-Kontakt.
// Zweck: Die eigentliche Telefonnummer taucht dadurch nicht im öffentlichen
// HTML-Quelltext auf (nur dieser Pfad wird verlinkt) und wird so für simple,
// automatisierte Nummern-Sammler unsichtbar, die nur Seiteninhalte auswerten
// und Weiterleitungen nicht verfolgen.
declare(strict_types=1);

header('Location: https://wa.me/4915141357281', true, 302);
exit;
