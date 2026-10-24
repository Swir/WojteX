# Języki w WojteX — alpha.2

WojteX zawiera polski i angielski interfejs, instalator oraz komunikaty serwera. Administrator może dodać następny język przez plik JSON. Nie trzeba instalować wtyczki ani dopisywać kodu PHP.

## Nowe forum i wybór języka

Podczas pierwszej instalacji wybierz język w górnej części instalatora. Dostępne są **Polski** i **English**. Zatwierdzenie wyboru zmienia język formularza; język przesłany przy instalacji zostaje domyślnym językiem forum.

Tryb **Nowe forum / Fresh forum** pozwala rozpocząć od zera: powstaje konto administratora i cztery puste działy. Przenoszenie starej bazy nie jest wymagane. Przykładowa społeczność jest osobną, dobrowolną opcją. Jeżeli włączysz przykłady, ich treść zostanie utworzona w języku instalacji.

Tryb przygotowania migracji również tworzy oddzielną, nową instalację. Import starego forum wykonuje się później w panelu administracyjnym. Instalator nie odczytuje ani nie usuwa starej bazy. W trybie migracji przykładowa społeczność musi być wyłączona.

## Język odwiedzającego i język domyślny

Przełącznik języka w nagłówku działa także dla gości. Wybór jest zapamiętywany w sesji przeglądarki; interfejs zapisuje go również lokalnie w przeglądarce, gdy ta na to pozwala. Wybranie języka nie zmienia domyślnego języka całego forum.

Administrator ustawia język domyślny w **Panel administracyjny → Języki**. Ten język jest używany, gdy odwiedzający nie ma własnego, dostępnego wyboru. Forum z wcześniejszej alpha.1 bez ustawienia `default_locale` nadal korzysta z polskiego; do dodania tego ustawienia nie jest potrzebna nowa kolumna w bazie.

Zmiana języka obejmuje interfejs i komunikaty aplikacji. Nie tłumaczy postów, tytułów tematów, nazw działów, opisów użytkowników ani treści ustawionych przez administratora. Początkowe nazwy działów i powitanie są zapisywane w bazie w języku instalacji. Administrator może je potem edytować.

## Dodawanie kolejnego języka

1. Zaloguj się na konto administratora i otwórz **Panel administracyjny → Języki**.
2. Pobierz szablon angielski lub polski. Szablon zawiera komplet kluczy komunikatów aktualnej wersji forum.
3. Zmień `locale`, `name`, `native_name` i `version`. W `messages` tłumacz wartości po prawej stronie, pozostawiając klucze bez zmian.
4. Zapisz plik jako JSON w kodowaniu UTF-8, z rozszerzeniem `.json`. Maksymalny rozmiar paczki wynosi **1 MiB (1 048 576 bajtów)**.
5. Wybierz plik w formularzu dodawania języka i kliknij przycisk instalacji. Nowy język pojawi się w katalogu oraz przełączniku języka.
6. Jeżeli chcesz, wybierz go również jako domyślny język forum.

Minimalny przykład częściowego tłumaczenia niemieckiego:

```json
{
  "schema": 1,
  "locale": "de",
  "name": "German",
  "native_name": "Deutsch",
  "version": "1.0.0",
  "messages": {
    "ui.nav.latest": "Neueste Diskussionen",
    "ui.nav.rankings": "Community-Rangliste"
  }
}
```

Kod języka zapisuj małymi literami. Obsługiwane są dwie lub trzy litery języka, opcjonalnie czteroliterowy kod pisma i opcjonalnie dwuliterowy lub trzycyfrowy region, np. `de`, `pt-br`, `zh-hant-tw`, `es-419`. Używaj prawidłowego kodu odpowiadającego językowi; przeglądarka używa go również do formatowania liczb, czasu i form liczby mnogiej. Przypadkowe nazwy, ścieżki plików i nieprawidłowe segmenty, takie jak `de-12`, są odrzucane.

Pola `name` i `native_name` mają po maksymalnie 80 znaków. `name` to nazwa opisowa, np. `German`, a `native_name` to etykieta widoczna dla użytkownika, np. `Deutsch`. `version` jest tekstem do 40 znaków, rozpoczynającym się literą lub cyfrą; dalej może zawierać także `.`, `+`, `_` i `-`.

### Częściowe tłumaczenie i uzupełnianie z angielskiego

Paczka może zawierać tylko część komunikatów. Brakujące wartości są automatycznie pobierane z angielskiego katalogu dostarczonego z forum. Panel pokazuje odsetek dostępnych kluczy i liczbę brakujących komunikatów. Procent jest zaokrąglany w dół, więc bardzo mała paczka może pokazywać 0% mimo poprawnej instalacji.

Pokrycie opisuje obecność kluczy, a nie jakość tłumaczenia. Skopiowanie całego angielskiego szablonu i zmiana samego kodu języka pokaże pełne pokrycie, choć tekst pozostanie angielski. Pobranie szablonu dla częściowej paczki również uzupełnia jej brakujące klucze tekstem angielskim.

### Parametry i liczba mnoga

Nie usuwaj ani nie zmieniaj nazw parametrów, takich jak `:name`, `:count` czy `:percent`. Możesz przesunąć parametr w zdaniu. Paczka musi zachować zestaw parametrów obecny w angielskim odpowiedniku danego klucza.

```json
{
  "ui.languages.installed": "Sprache installiert: :name.",
  "ui.languages.coverage": "Übersetzt: :percent%"
}
```

Powyższy fragment pokazuje tylko zawartość `messages`, nie pełną paczkę. Tam, gdzie katalog udostępnia formy `.one`, `.few`, `.many` i `.other`, przetłumacz je zgodnie z gramatyką swojego języka. Interfejs wybiera formę według reguł liczby mnogiej dla aktywnego języka.

### Tekst, bez kodu i HTML

Wartości w `messages` muszą być niepustymi napisami, po maksymalnie 8000 znaków. Nie mogą być tablicami ani obiektami. Akceptowane są wyłącznie klucze obecne w bieżącym angielskim katalogu. Paczka może mieć najwyżej 5000 komunikatów.

Nie wstawiaj znaczników HTML, skryptów ani kodu PHP. Format służy do tłumaczenia tekstu; nie instaluje kodu wykonywalnego. Zapisuj znaki zgodnie ze składnią JSON, np. cudzysłów jako `\"`, a znak nowej linii jako `\n`. Nie dodawaj komentarzy ani przecinków po ostatnim elemencie.

## Aktualizacja istniejącej paczki

Zachowaj ten sam `locale`, popraw tłumaczenia i zwiększ `version`. W formularzu zaznacz **zastąpienie istniejącej paczki o tym samym kodzie**. Bez tej opcji istniejąca paczka nie zostanie nadpisana. Niepoprawna aktualizacja zostaje odrzucona przed zastąpieniem działającego pliku.

Wbudowane `pl` i `en` są chronione i nie można nadpisać ich przez upload, także z zaznaczoną opcją zastąpienia. W tej wersji panel pozwala dodawać i aktualizować dodatkowe języki; nie ma osobnej operacji usuwania paczki.

## Pliki, kopie zapasowe i aktualizacja forum

Wbudowane katalogi znajdują się w `lang/pl/` i `lang/en/`. Poszczególne pliki JSON obejmują interfejs, instalator, serwer i komunikaty migratora. Każdy język ma też `manifest.json` z metadanymi.

Dodatkowe paczki są przechowywane w prywatnym katalogu `storage/app/private/languages/`, poza publicznym katalogiem serwera. Uwzględnij ten katalog w kopiach zapasowych razem z bazą forum. Domyślny język jest ustawieniem w bazie; sama kopia plików językowych nie przenosi ustawień ani danych społeczności. W konfiguracji aplikacji ścieżkę paczek określa `veyra.language_path`.

Po aktualizacji forum pobierz aktualny szablon, porównaj klucze i uzupełnij tłumaczenie. Nowe brakujące klucze korzystają z angielskiego uzupełnienia. Jeśli nowa wersja usunie klucz albo zmieni parametry, starsza paczka może wymagać poprawienia przed ponowną instalacją. Uszkodzony lub niezgodny opcjonalny plik jest pomijany przy odczycie, dzięki czemu nie blokuje startu forum. Jeśli zapisany język domyślny lub język sesji nie jest już dostępny, aplikacja wraca do dostępnego języka domyślnego albo polskiego.

## API dla autorów integracji

Wszystkie poniższe ścieżki należą do aplikacji forum. Operacje zmieniające dane korzystają z sesji i ochrony CSRF.

| Operacja | Ścieżka | Dostęp |
| --- | --- | --- |
| Pobranie danych forum wraz z `i18n` | `GET /api/bootstrap` | Gość po instalacji |
| Zapis wyboru języka w sesji | `POST /api/locale` z polem `locale` | Gość po instalacji, CSRF |
| Katalog języków i język domyślny | `GET /api/admin/languages` | Administrator |
| Pobranie pełnej paczki | `GET /api/admin/languages/template?locale=en` | Administrator |
| Dodanie lub aktualizacja JSON | `POST /api/admin/languages`, multipart `file`, opcjonalnie `replace=1` | Administrator, CSRF |
| Zmiana języka domyślnego | `PATCH /api/admin/settings` z polem `default_locale` | Administrator, CSRF |

Odpowiedź `i18n` zawiera `locale`, `fallback_locale`, `languages` i mapę `messages`. Klient może wskazać dostępny język nagłówkiem `X-WojteX-Locale`; ma on pierwszeństwo przed wyborem sesji i ustawieniem domyślnym. Sam nagłówek nie zapisuje preferencji. Nieprawidłowe wskazówki językowe są ignorowane, a niepoprawne wartości w operacjach zapisu zwracają błąd walidacji 422.

## Zakres wersji alpha.2

Mechanizm obsługuje tłumaczenia dostarczonej aplikacji, dodawanie paczek i wybór języka. Nie zapewnia automatycznego tłumaczenia wypowiedzi, osobnych wersji językowych każdego tematu ani automatycznego tłumaczenia obcych wtyczek i motywów. Autor rozszerzenia powinien przygotować jego teksty zgodnie z obsługiwanym systemem tłumaczeń; uploader odrzuca nieznane klucze. Dodanie paczki nie jest deklaracją pełnej obsługi każdego pisma lub układu pisania.


## Nowe teksty w alpha.3-dev

Dodano klucze `ui.auth.forgot_*`, `ui.auth.reset_*`, `server.password_*` oraz `validation.attributes.token` do PL i EN. Własne paczki stworzone na podstawie alpha.2 pozostają obsługiwane: używany wcześniej klucz `ui.auth.recovery_unavailable` pozostaje w katalogach jako przestarzały, ale zgodny z poprzednimi paczkami. Nowe, nieprzetłumaczone komunikaty wyświetlają się po angielsku; administrator może pobrać aktualny szablon i uzupełnić tłumaczenia.
