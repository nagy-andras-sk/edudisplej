# Fake KMS az installerben (`vc4-fkms-v3d`)

## Rövid válasz

Az installerben szereplő "fake KMS" **nem** Microsoft KMS vagy licencelési aktivátor.
Itt a "KMS" a Raspberry Pi grafikus stack része (**Kernel Mode Setting**), és a `vc4-fkms-v3d` egy videó driver overlay.

---

## Alapfogalmak

### 1) Mi az a KMS?

**KMS = Kernel Mode Setting**.
Ez azt jelenti, hogy a kijelző (felbontás, framebuffer, megjelenítés) kezelését a Linux kernel végzi.

### 2) Mi a "Full KMS" (`vc4-kms-v3d`)?

A Raspberry Pi modernebb, teljesebb grafikus driver módja.
Jellemzően újabb hardveren működik jól.

### 3) Mi a "Fake KMS" (`vc4-fkms-v3d`)?

Kompatibilitási mód: részben legacy videó stack-et használ, de megtartja a VC4/V3D gyorsítási útvonal egy részét.
Régebbi Raspberry Pi modelleken (különösen ARMv6 / Pi 1 / Pi Zero) stabilabb lehet.

### 4) Mi **nem** ez?

- Nem licence aktiválás
- Nem "kalóz" vagy crack eszköz
- Nem hálózati KMS szerver

---

## Mit csinál pontosan az installer?

Az `install.sh` ARMv6 platformon egy célzott boot-config javítást futtat (`fix_armv6_boot_config`):

1. Megkeresi a boot config fájlt:
   - `/boot/firmware/config.txt` vagy
   - `/boot/config.txt`
2. Biztonsági mentést készít (`.backup.YYYYMMDD_HHMMSS`).
3. Ha talál `dtoverlay=vc4-kms-v3d` sort, kikommenteli.
4. Ha nincs jelen `dtoverlay=vc4-fkms-v3d`, hozzáadja.
5. Idempotens: ha már van `# ARMv6 fix - Issue #47`, nem írja újra.

Ez a blokk csak akkor fut, ha az architektúra `armv6l`.

---

## Miért van erre szükség?

Az installer kommentje szerint (`Issue #47`) Pi Zero / Pi 1 eszközökön a full KMS (`vc4-kms-v3d`) fekete képernyőt okozhat,
miközben az Xorg folyamat valójában fut.

A projekt célja stabil kiosk megjelenítés régebbi hardveren is,
ezért ARMv6 esetén a kompatibilisebb `vc4-fkms-v3d` kerül beállításra.

Gyakorlati eredmény:

- kevesebb black screen eset,
- nagyobb első boot stabilitás,
- megbízhatóbb automatikus indulás a kijelzőn.

---

## Mikor érdemes visszaállni full KMS-re?

Újabb Raspberry Pi modelleken (nem ARMv6) és friss grafikus stack esetén a full KMS lehet jobb választás.
Ebben a projektben az ARMv6 fix célzott és konzervatív: csak az érintett platformra vonatkozik.

---

## Összefoglalás

A "fake KMS" itt egy **grafikus kompatibilitási beállítás**, nem licencelési trükk.
Azért van az installerben, hogy ARMv6 (Pi Zero/Pi 1) eszközökön elkerülje a fekete képernyőt és stabilabb legyen a kiosk mód.
