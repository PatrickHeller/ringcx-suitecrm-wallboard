# RingCX SuiteCRM Wallboard

**Live-Wallboard** für RingCX — Echtzeit-Queue-Performance (offered/answered/abandoned/talk-time/
wait-time je Gate) plus Echtzeit-Agentenstatus einer Agentengruppe — als Custom Entry Point in
SuiteCRM 8 (`crm.p-h-c.de`). Schwesterprojekt zum
[RingEX SuiteCRM Wallboard](https://github.com/PatrickHeller/ringex-suitecrm-wallboard) (dort:
historische Call-Statistik von heute, kein Live-Status) und Port des gleichnamigen WordPress-Boards
(`ringcx-wordpress-wallboard`).

Registriert seit 2026-08-20 als Entry Point `RC_RingCX_Wallboard`.

> **Hinweis:** Dieses Projekt wurde beim Anlegen dieses Repos auf dem Server entdeckt, war aber
> zuvor nicht dokumentiert. Funktionsumfang wurde anhand des Codes rekonstruiert, aber nicht erneut
> live gegen die RingCX-API verifiziert — vor Weiterentwicklung Code gegenprüfen.

## Aufbau

- **Entry Point:** `custom/RC_RingCX/RC_RingCX_Wallboard_Entry.php` — 1:1 portierte Logik aus dem
  WordPress-Wallboard (gleiche Funktionsnamen mit `rc_ringcx_`-Präfix), URL:
  `https://crm.p-h-c.de/legacy/index.php?entryPoint=RC_RingCX_Wallboard`.
- **Datenquellen** (RingCX Voice API, nicht das klassische RC-Call-Log wie beim RingEX-Board):
  - `GET {BASE_URL}/voice/api/v1/admin/accounts/{accountId}/realTimeData/inbound` — Queue/Gate-
    Performance in Echtzeit.
  - `GET {BASE_URL}/voice/api/v1/admin/accounts/{accountId}/realTimeData/agent` — Live-Agentenstatus.
  - `GET {BASE_URL}/voice/api/v1/admin/accounts/{accountId}/agentGroups/{AGENT_GROUP_ID}/agents` —
    Mitgliederliste der überwachten Agentengruppe (per `merge_agents_with_realtime()` mit dem
    Live-Status gemerged, offline/nicht angemeldete Agenten zeigen "NICHT ANGEMELDET").
- **Auth (zweistufig, wichtig anders als beim RingEX-Board):** JWT-Bearer-Login zunächst gegen
  `platform.ringcentral.com` (klassisches RingCentral-Konto), danach Token-Tausch gegen
  `{BASE_URL}/api/auth/login/rc/accesstoken` für einen RingCX-eigenen Access-Token. Bei
  abgelaufenem RingEX-Refresh-Token wird transparent neu per JWT eingeloggt.
- **Registrierung:** `custom/Extension/application/Ext/EntryPointRegistry/RC_RingCX_Wallboard_Entry.php`.
- **Legacy-Modul-Wrapper:** `custom/modules/RC_RingCX_Wallboard/` (Controller + Sprachdatei),
  `custom/Extension/application/Ext/Include/RC_RingCX_Wallboard.php`.

## Konfiguration

Secrets liegen außerhalb dieses Repos unter `/etc/suitecrm/rc_ringcx_wallboard.php` (0640,
`www-data:www-data`). Vorlage: `config/rc_ringcx_wallboard.php.example`. **Wichtig:** `BASE_URL`
ist die RingCX-API-Root **ohne** `/voice/api/v1`-Suffix (z.B. `https://ringcx.ringcentral.com`) —
der Code hängt `/voice/api/v1/...` bzw. `/api/auth/...` selbst an.

## Deploy

Nach `public/legacy/` der SuiteCRM-8-Installation kopieren, danach Quick Repair and Rebuild über
die Admin-UI ausführen.
