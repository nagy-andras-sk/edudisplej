# Room Occupancy Module (Terem foglaltság)

## Cél
A `room-occupancy` modul intézményi termek foglaltságát jeleníti meg.

Támogatott adatforrások:
- **Kézi szerkesztés**: dashboard felületen (`room_occupancy_config.php`)
- **Külső szinkron**: tokenes API endpoint (`api/room_occupancy.php?action=external_upsert`)

## Dashboard kezelő
- Oldal: `dashboard/room_occupancy_config.php`
- Funkciók:
  - termek létrehozása/szerkesztése (`room_key`, név, kapacitás, aktív)
  - napi foglaltsági idősávok kézi kezelése
  - külső API példa megjelenítése

## Publikus olvasó endpointok

### 1) Termek listája
`GET /api/room_occupancy.php?action=rooms&company_id=123`

Válasz:
```json
{
  "success": true,
  "items": [
    { "id": 10, "room_key": "a101", "room_name": "A101 Informatika", "capacity": 30 }
  ]
}
```

### 2) Napi foglaltság
`GET /api/room_occupancy.php?action=schedule&company_id=123&room_id=10&date=2026-02-22`

Válasz:
```json
{
  "success": true,
  "data": {
    "room_name": "A101 Informatika",
    "event_date": "2026-02-22",
    "events": [
      {
        "id": 1,
        "start_time": "08:00",
        "end_time": "09:30",
        "event_title": "10.A Matematika",
        "event_note": "Tanár: Kovács",
        "source_type": "manual",
        "updated_at": "2026-02-22 07:45:10"
      }
    ],
    "current_event": null,
    "is_occupied_now": false
  }
}
```

## Külső rendszer integráció (példa)

Endpoint:
`POST /api/room_occupancy.php?action=external_upsert`

Fejlécek:
- `Content-Type: application/json`
- `X-API-Token: <company_api_token>`

Body példa:
```json
{
  "company_id": 123,
  "events": [
    {
      "external_ref": "sis-evt-2026-02-22-a101-0800",
      "room_key": "a101",
      "room_name": "A101 Informatika",
      "event_date": "2026-02-22",
      "start_time": "08:00",
      "end_time": "09:30",
      "event_title": "10.A Matematika",
      "event_note": "Tanár: Kovács"
    }
  ]
}
```

`external_ref` kötelező minden külső eseménynél; ez alapján történik az idempotens frissítés.

### cURL példa
```bash
curl -X POST "https://YOUR_DOMAIN/api/room_occupancy.php?action=external_upsert" \
  -H "Content-Type: application/json" \
  -H "X-API-Token: YOUR_COMPANY_TOKEN" \
  -d '{
    "company_id": 123,
    "events": [
      {
        "external_ref": "sis-evt-2026-02-22-a101-0800",
        "room_key": "a101",
        "room_name": "A101 Informatika",
        "event_date": "2026-02-22",
        "start_time": "08:00",
        "end_time": "09:30",
        "event_title": "10.A Matematika",
        "event_note": "Tanár: Kovács"
      }
    ]
  }'
```

## Loop modul beállítások
`room-occupancy` támogatja:
- `roomId`
- `showOnlyCurrent`
- `showNextCount`
- `apiBaseUrl`
