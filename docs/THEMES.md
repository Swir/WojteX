# Motywy w WojteX beta.1

## Ustawienia w panelu

Administrator ustala domyślny motyw: grafit, nocny granat, jasny lub zgodny z urządzeniem. Dostępnych jest sześć akcentów: niebieski, fioletowy, turkusowy, zielony, bursztynowy i różowy. Przycisk „Wygląd” na komputerze i telefonie otwiera osobisty wybór, podgląd na żywo, odstępy wygodne/zwarte oraz reset. Preferencje są zapisywane w tej przeglądarce pod `wojtex.appearance.v1`; „Jak na forum” używa ustawień administratora. Nie ma synchronizacji między urządzeniami.

## Własny motyw CSS

Interfejs ładuje `public/assets/forum.css`, reguły WojteX w `wojtex.css`, a następnie `custom.css`. Początkowo własny arkusz zawiera tylko komentarz. Własne reguły trafiają tutaj, więc nie trzeba edytować bazowego arkusza. Po zmianie odśwież cache przeglądarki lub zmień parametr wersji linku arkusza w `resources/views/forum.blade.php`.

Przykład jest gotowy w `themes/forest/custom.css`. Zrób kopię swojego dotychczasowego `custom.css`, a następnie skopiuj zawartość przykładu do `public/assets/custom.css`. Po odświeżeniu zmieni się kolorystyka obu wariantów i znak forum. Powrót polega na przywróceniu poprzedniej zawartości tego pliku.

Najważniejsze zmienne:

| Zmienna | Zastosowanie |
| --- | --- |
| `--bg`, `--sidebar` | Tło aplikacji i nawigacji. |
| `--panel`, `--panel-raised`, `--input` | Karty, podniesione powierzchnie, formularze. |
| `--text`, `--muted`, `--faint` | Hierarchia koloru tekstu. |
| `--accent`, `--accent-solid`, `--accent-soft` | Wyróżnienia, przyciski, delikatne tła. |
| `--accent-rgb` | Składowe RGB oddzielone przecinkami, używane z przezroczystością. |
| `--line`, `--line-strong` | Linie i ramki. |

Reguły dla całego motywu można zapisać tak:

```css
:root[data-theme="dark"][data-accent] {
  --accent: #86d9aa;
  --accent-rgb: 134, 217, 170;
  --accent-solid: #347850;
}
```

Oba atrybuty zapewniają odpowiednią szczegółowość w stosunku do wbudowanych wariantów. Sprawdź kontrast tekstu, hover, fokus klawiatury, formularze i szerokość telefonu. Samo podmienienie koloru przycisku nie weryfikuje dostępności całego motywu.

Beta.1 nie zawiera instalatora zewnętrznych motywów ZIP, manifestów ani edytora układu w panelu. Własny układ wymaga pracy nad widokiem Blade i JavaScriptem oraz sprawdzenia zgodności z API.
