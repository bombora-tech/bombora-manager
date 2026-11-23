# Changelog

## [1.0.0] - 2026-11-23

### Přidáno
- Menu **Bombora** s přehledem modulů (Pop-up košík, Smart Search, Wine Nutrition): stav, nainstalovaná a poslední verze.
- Instalace modulů z GitHub Releases (bombora-tech), aktivace/deaktivace, aktualizace jedním klikem.
- Integrace do standardních aktualizací WordPressu (Nástěnka → Aktualizace, automatické aktualizace, WP-CLI) pro všechny moduly i Manager.

### Bezpečnost
- Zdroje pevně v katalogu, jen HTTPS a povolení hostitelé GitHubu, přesměrování bez tokenu.
- Každý balíček ověřen SHA-256 a kontrolou struktury ZIPu (jen očekávaná složka, žádný path traversal).
- Instalace jen s `install_plugins` + nonce, aktualizace s `update_plugins`, respektuje `DISALLOW_FILE_MODS`.
