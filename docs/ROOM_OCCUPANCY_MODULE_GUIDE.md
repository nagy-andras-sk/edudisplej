# Room Occupancy Module (Terem foglaltság)

## Cél
A `room-occupancy` modul intézményi termek foglaltságát jeleníti meg.

Támogatott adatforrások:
- **Kézi szerkesztés**: dashboard felületen (`room_occupancy_config.php`)
- **Külső szinkron**: tokenes API endpointok (`api/room_occupancy.php?action=external_sync` és társai)

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

## Külső rendszer integráció

Az API tokennel az alábbi műveletek érhetők el:

- `external_rooms_sync` - termek/helyiségek szinkronja
- `external_occupancy_sync` - időintervallumos foglaltság szinkronja
- `external_sync` - a kettő együtt
- `external_upsert` - kompatibilitási alias az occupancy sync-re

### Auth

Fejlécek:
- `Content-Type: application/json`
- `Authorization: Bearer <company_api_token>` vagy `X-API-Token: <company_api_token>`

Az admin oldalon a szerverkulcsot is párosítani kell a céghez. A request body-ban küldött `company_id` opcionális, de ha szerepel, egyeznie kell a tokenhez tartozó céggel.

### 1) Termek szinkronja

Endpoint:
`POST /api/room_occupancy.php?action=external_rooms_sync`

Body példa:
```json
{
  "server_key": "sis",
  "rooms": [
    {
      "room_key": "a101",
      "room_name": "A101 Informatika",
      "capacity": 30,
      "is_active": true
    },
    {
      "room_key": "b201",
      "room_name": "B201 Nyelvi labor",
      "capacity": 24,
      "is_active": true
    }
  ]
}
```

### 2) Foglaltság szinkronja

Endpoint:
`POST /api/room_occupancy.php?action=external_occupancy_sync`

Body példa:
```json
{
  "server_key": "sis",
  "occupancies": [
    {
      "external_ref": "sis-evt-2026-02-22-a101-0800",
      "room_key": "a101",
      "room_name": "A101 Informatika",
      "event_date": "2026-02-22",
      "start_time": "08:00",
      "end_time": "09:30",
      "title": "10.A Matematika",
      "comment": "Tanár: Kovács"
    }
  ]
}
```

`external_ref` ajánlott, de ha nem küldöd, a szerver a `server_key + room_key + dátum + időtartam` alapján automatikusan generál egy stabil azonosítót, így az ugyanazt az idősávot frissíti.

### 3) Teljes sync

Endpoint:
`POST /api/room_occupancy.php?action=external_sync`

Body-ban szerepelhet egyszerre `rooms` és `occupancies` is.

### cURL példa
```bash
curl -X POST "https://YOUR_DOMAIN/api/room_occupancy.php?action=external_sync" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_COMPANY_TOKEN" \
  -d '{
    "server_key": "sis",
    "rooms": [
      {
        "room_key": "a101",
        "room_name": "A101 Informatika",
        "capacity": 30,
        "is_active": true
      }
    ],
    "occupancies": [
      {
        "room_key": "a101",
        "room_name": "A101 Informatika",
        "event_date": "2026-02-22",
        "start_time": "08:00",
        "end_time": "09:30",
        "title": "10.A Matematika",
        "comment": "Tanár: Kovács"
      }
    ]
  }'
```

### Válasz

```json
{
  "success": true,
  "stored_rooms": 1,
  "stored_occupancies": 1
}
```

## Loop modul beállítások
`room-occupancy` támogatja:
- `roomId`
- `showOnlyCurrent`
- `showNextCount`
- `apiBaseUrl`
