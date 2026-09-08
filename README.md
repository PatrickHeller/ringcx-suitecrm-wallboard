# RingCX SuiteCRM Wallboard

Call-Wallboard für RingCX (Agentengruppen-Übersicht) als Custom Entry Point in SuiteCRM 8
(`crm.p-h-c.de`), Schwesterprojekt zum [RingEX SuiteCRM Wallboard](https://github.com/PatrickHeller/ringex-suitecrm-wallboard)
und Port des gleichnamigen WordPress-Boards (`ringcx-wordpress-wallboard`).

Registriert seit 2026-08-20 als Entry Point `RC_RingCX_Wallboard`.

> **Hinweis:** Dieses Projekt wurde beim Anlegen dieses Repos auf dem Server entdeckt, war aber
> zuvor nicht dokumentiert. Funktionsumfang wurde anhand des Codes rekonstruiert, aber nicht erneut
> live gegen die RingCX-API verifiziert — vor Weiterentwicklung Code gegenprüfen.

## Aufbau

- **Entry Point:** `custom/RC_RingCX/RC_RingCX_Wallboard_Entry.php` — analoge Struktur zum
  RingEX-Wallboard-Entry-Point (gleiche `format_duration()`-Hilfsfunktion etc.), URL:
  `https://crm.p-h-c.de/legacy/index.php?entryPoint=RC_RingCX_Wallboard`.
- **Registrierung:** `custom/Extension/application/Ext/EntryPointRegistry/RC_RingCX_Wallboard_Entry.php`.
- **Legacy-Modul-Wrapper:** `custom/modules/RC_RingCX_Wallboard/` (Controller + Sprachdatei),
  `custom/Extension/application/Ext/Include/RC_RingCX_Wallboard.php`.

## Konfiguration

Secrets liegen außerhalb dieses Repos unter `/etc/suitecrm/rc_ringcx_wallboard.php` (0640,
`www-data:www-data`). Vorlage: `config/rc_ringcx_wallboard.php.example`.

## Deploy

Nach `public/legacy/` der SuiteCRM-8-Installation kopieren, danach Quick Repair and Rebuild über
die Admin-UI ausführen.
