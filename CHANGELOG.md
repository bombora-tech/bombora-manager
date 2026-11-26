# Changelog

## [1.4.1] - 2026-11-27

### Opraveno
- Číslování objednávek: doplněny chybějící české překlady stránky nastavení.

## [1.4.0] - 2026-11-27

### Přidáno
- Vestavěný modul **Číslování objednávek** (*Bombora → Číslování objednávek*, karta i na stránce Moduly; výchozí vypnuto):
  - Formát se zástupnými znaky `{RR}` / `{RRRR}` (rok) a `{N}` (pořadí, počet číslic nastavitelný), doporučeno `{RR}` + číslice e-shopu + `{N}` (např. VO `{RR}9{N}` → 26900001).
  - Číslo se přidělí jednou při vytvoření objednávky (klasická i bloková pokladna, admin, REST, objednávky vytvořené pluginy); koncepty číslo nespotřebují. Pořadí se zvyšuje atomicky v databázi (bez duplicit při souběžných objednávkách), existující číslo se přeskočí.
  - Objednávky vytvořené před zapnutím si nechají své číslo (ID), i když se později upraví. Po vypnutí se zobrazuje opět ID, přidělená čísla zůstávají.
  - Volitelně pořadí každý rok od 1, ruční nastavení dalšího pořadového čísla, náhled příštího čísla.
  - Upozornění: nečíselný formát nebo víc než 10 číslic (nepoužitelné jako variabilní symbol), možný překryv se staršími ID, jiný aktivní plugin pro číslování.
  - Hledání objednávek v administraci podle nového čísla (HPOS i starší úložiště).

## [1.3.1] - 2026-11-25

### Přidáno
- Modul **Bombora B2B Agent** (repo `bombora-b2b-agent`, složka `bombora-b2b-agent`): velkoobchod vo.zgruzie.cz – ceny pro schválené partnery, registrace ARES/VIES, doručení lihovin a piva jen v ČR, platba na fakturu (proforma z Eurofaktury), více doručovacích adres (pobočky). Nastavení v *WooCommerce → Bombora B2B*.
- Převod do nové složky: `zg-b2b` → `bombora-b2b-agent` (data, nastavení a B2B účty zůstanou).

## [1.3.0] - 2026-11-25

### Přidáno
- Modul **Bombora Mergado WPML** (repo `mergado-wpml-support`, složka `bombora-mergado-wpml`): samostatný Mergado produktový feed pro každý jazyk WPML, nastavení v *Bombora → Mergado feedy*.

## [1.2.1] - 2026-11-25

### Opraveno
- Varianty balení: tagy `{bombora_bundle_*}` uvnitř smyčky (včetně podmínek zobrazení) vždy čtou položku smyčky – prázdný štítek „Ušetríte“ u jednotlivého kusu se už nezobrazí.
- `{bombora_bundle_variants_count}` vrací při 0 prázdný řetězec – podmínka sekce „není prázdné“ (hodnotu „0“ Bricks v podmínkách ignoruje).

## [1.2.0] - 2026-11-25

### Přidáno
- **Varianty balení pro Bricks** (součást Manageru, bez ACF a bez snippetů):
  - Query loop typ **„Bombora: Varianty balení“** – zdroj jsou nativní WooCommerce **Up-selly** produktu; když jsou prázdné, automaticky se najde rodina balení (jednotlivý kus + WPC bundly obsahující jen tento produkt). Řazení: 1 ks → 3× → 6× → 20×.
  - Dynamické tagy (skupina „Bombora“): `{bombora_bundle_saving}` (úspora balíčku), `{bombora_bundle_saving_pct}`, `{bombora_bundle_qty}`, `{bombora_bundle_unit_price}`, `{bombora_bundle_variants_count}` (pro podmínku zobrazení sekce).
  - WPML: ID se překládají na aktuální jazyk, automatická rodina se počítá ve výchozím jazyce; na frontendu žádné pevné texty (popisky jsou v Bricks).
  - Nápověda u pole Up-selly v editaci produktu.

## [1.1.1] - 2026-11-24

### Přidáno
- Modul **Bombora Infinite Scroll** (repo `bombora-infinite-scroll`): automatické donačítání produktů ve výpisech, nastavení v *Bombora → Infinite Scroll*.

## [1.1.0] - 2026-11-23

### Přidáno
- Modul **Bombora Read More Toggle** (repo `bombora-read-more-toogle`, tag `[read]…[/read]`).
- **Převod do nové složky**: Smart Search (`zg-smart-search` → `bombora-smart-search`), Wine Nutrition (`wine-nutrition-manager` → `bombora-wine-nutrition`) a Read More Toggle (`read-more-toggle` → `bombora-read-more-toggle`). Tlačítko „Převést a aktualizovat na X“ ve dvou krocích: ověřený balíček → instalace nové složky → tichá deaktivace staré → (nový požadavek) aktivace nové → smazání staré složky bez `uninstall.php`, takže data a nastavení zůstanou. Při chybě aktivace se vrátí stará verze. Nedokončený převod jde dokončit tlačítkem „Dokončit převod“.
- Pojistka proti dvojímu načtení Manageru (např. druhá kopie ve složce `bombora-manager-main`).

### Změněno
- Všechny moduly se jmenují „Bombora …“ a jejich složky začínají `bombora-` (v adresáři pluginů jsou pod sebou).
- Stará složka modulu se neaktualizuje standardní cestou WordPressu – jen převodem.

## [1.0.1] - 2026-11-23

### Opraveno
- Zjištění poslední verze bez tokenu už nepoužívá GitHub API (limit 60 dotazů/h na IP – weby na stejném serveru ho vyčerpaly a v Manageru se zobrazovalo „—“). Verze se čte z přesměrování `github.com/<repo>/releases/latest`, balíčky z `releases/download/vX.Y.Z/` – ověření SHA-256 a struktury ZIPu beze změny.
- S konstantou `BOMBORA_GITHUB_TOKEN` se dál používá API, při jeho chybě se přejde na webovou cestu.
- Chyba kontroly se zobrazí v kartě modulu; po chybě se znovu zkouší po 15 min (dříve 1 h).

## [1.0.0] - 2026-11-23

### Přidáno
- Menu **Bombora** s přehledem modulů (Pop-up košík, Smart Search, Wine Nutrition): stav, nainstalovaná a poslední verze.
- Instalace modulů z GitHub Releases (bombora-tech), aktivace/deaktivace, aktualizace jedním klikem.
- Integrace do standardních aktualizací WordPressu (Nástěnka → Aktualizace, automatické aktualizace, WP-CLI) pro všechny moduly i Manager.

### Bezpečnost
- Zdroje pevně v katalogu, jen HTTPS a povolení hostitelé GitHubu, přesměrování bez tokenu.
- Každý balíček ověřen SHA-256 a kontrolou struktury ZIPu (jen očekávaná složka, žádný path traversal).
- Instalace jen s `install_plugins` + nonce, aktualizace s `update_plugins`, respektuje `DISALLOW_FILE_MODS`.
