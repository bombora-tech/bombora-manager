# Bombora Manager

Správa všech pluginů Bombora group s.r.o. na jednom místě (podobně jako WPify).

| Modul | Složka pluginu | Repozitář |
|---|---|---|
| Bombora Manager | `bombora-manager` | `bombora-tech/bombora-manager` |
| Pop-up košík | `bombora-cart-popup` | `bombora-tech/bombora-cart-popup` |
| Smart Search | `zg-smart-search` | `bombora-tech/bombora-smart-search` |
| Wine Nutrition | `wine-nutrition-manager` | `bombora-tech/bombora-wine-nutrition` |
| Mergado WPML | `bombora-mergado-wpml` | `bombora-tech/mergado-wpml-support` |

## Varianty balení (Bricks)
- Bricks → Query loop → typ **Bombora: Varianty balení**. Zdroj: Up-selly produktu, jinak automaticky bundly téhož produktu (3×, 6× …).
- Tagy: `{bombora_bundle_saving}`, `{bombora_bundle_saving_pct}`, `{bombora_bundle_qty}`, `{bombora_bundle_unit_price}`, `{bombora_bundle_variants_count}`.
- Vypnutí automatiky: filtr `bombora_bundle_variants_auto` → `false`.

## Jak to funguje
- **Bombora → Moduly**: instalace, aktivace, nastavení a aktualizace modulů.
- Aktualizace se objeví i v běžném *Nástěnka → Aktualizace* (kontrola každých 6 h, ručně tlačítkem „Zkontrolovat aktualizace“).
- Na webech se nic nekonfiguruje – repozitáře jsou veřejné, zdroje jsou pevně v kódu.

## Vydání nové verze (libovolného modulu)
1. Zvýšit verzi v hlavičce pluginu (a v konstantě verze).
2. Doplnit `CHANGELOG.md`.
3. Push do `main` → GitHub Action sama vytvoří Release `vX.Y.Z` s `<slozka>.zip` + `.sha256` (jen pokud ta verze ještě nebyla vydána).

Release musí obsahovat asset pojmenovaný přesně podle tabulky (`<slozka>.zip` a `<slozka>.zip.sha256`) a ZIP musí obsahovat jedinou složku pluginu – jinak ho Manager odmítne.

## Přidání nového modulu
Doplnit položku do `includes/class-catalog.php` (název, soubor, repo, asset, stránka nastavení) a vydat novou verzi Manageru.

## Bezpečnost
- Zdroje instalace pevně v kódu (žádné URL z administrace/DB).
- HTTPS + povolení hostitelé GitHubu; přesměrování na CDN bez autorizační hlavičky.
- SHA-256 (`hash_equals`) + kontrola struktury ZIPu před instalací; limit 20 MB.
- `install_plugins` / `update_plugins` / `activate_plugins` + nonce; respektuje `DISALLOW_FILE_MODS`.
- Volitelně `BOMBORA_GITHUB_TOKEN` (wp-config) – jen pro privátní repa nebo vyšší limit API.
