# Changelog

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
