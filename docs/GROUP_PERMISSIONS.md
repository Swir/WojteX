# Grupy WojteX — prywatne działy (0.1.0-beta.1)

**Status: beta do testów.** Testy aplikacji z PHP/PDO SQLite są częścią sprawdzonego zestawu beta.1. Zachowanie współbieżnych żądań i blokad MySQL wymaga dalszej kwalifikacji. Nie używaj na żywym forum bez kopii danych i prób na osobnej instalacji. Szczegóły: [RELEASE_CHECKS.md](RELEASE_CHECKS.md).

## Instalacja / aktualizacja

Nowa instalacja korzysta z dotychczasowego kreatora (bez importu, demo wyłączone). Kreator uruchamia wszystkie migracje, w tym `2026_10_22_000002_create_group_permissions.php`. Na **istniejącej** instalacji wykonaj kopię bazy i prywatnych danych, potem zaktualizuj kod, uruchom `php artisan migrate --force` i sprawdź `php artisan migrate:status`. Migracja dodaje tabele `forum_groups`, `user_group_members` i `category_group_permissions`. **Nie używaj** `migrate:fresh` na istniejącym forum — usuwa dane. Nie nadpisuj `.env`, bazy, sesji ani katalogu prywatnych załączników przy aktualizacji.

## Co potrafi ten etap

Administrator otwiera **Panel administracji → Grupy**. Tworzy lub zmienia nazwę grupy, dodaje lub usuwa jej członków oraz zaznacza osobno **Podgląd / Tworzenie tematów / Odpowiedzi** dla konkretnego prywatnego działu. Odznaczenie wszystkiego usuwa wpis nadania praw. W dev.9 domyślna kontrolka pokazuje **100 najnowszych kont**, ale administrator ma dodatkowo wyszukiwarkę **wszystkich kont** po fragmencie nazwy albo dokładnym `#ID`. Każda odpowiedź zawiera maksymalnie 20 wyników z jawnym ID, statusem bana i grupami znalezionego konta (bez e-maili); różne konta o tej samej nazwie pozostają rozróżnialne. Wyszukiwarka jest admin-only, limitowana do 30 żądań na minutę i traktuje `%`, `_` oraz `!` dosłownie. Licznik członków grupy obejmuje **wszystkich członków**, choć podgląd nazw nadal pokazuje tylko najnowszych; nowe konta można znajdować bez zmiany ich uprawnień do prywatnych działów. Usunięcie grupy natychmiast cofa jej nadania i członkostwa; nie usuwa kont ani postów. Operacje administratorskie trafiają do dziennika audytu.

Uprawnienia grup są **sumowane**: użytkownik mający kilka grup może korzystać z sumy ich pozytywnych nadań, nie ma jeszcze reguł *deny*. Dla prywatnego działu `Podgląd` jest obowiązkowy; `Tworzenie tematów` lub `Odpowiedzi` bez `Podglądu` jest odrzucane na serwerze. Gdy prywatny dział jest potomkiem innego prywatnego działu, **każdy prywatny przodek także wymaga Podglądu**. Nieistniejący przodek lub cykl blokują dostęp. Publiczne działy zachowują dotychczasowe reguły: widoczne dla gości, pisanie wymaga logowania. Moderatorzy i administratorzy zachowują dostęp niezależnie od członkostwa grup. Grupy **nie** nadają im uprawnień moderatorskich ani administracyjnych.

Dostępy są sprawdzane po stronie serwera dla list tematów, wyszukiwarki, pojedynczych tematów, pobierania załączników, tworzenia tematów, odpowiedzi, edytowania/usuwania postów, reakcji i wgrywania załączników. Zakładki własnych zapisanych tematów nie ujawniają już niewidocznych wątków. Interfejs ukrywa pola publikacji w prywatnych pokojach tylko do odczytu; sama kontrola interfejsu **nie zastępuje** kontroli endpointów. Własne nazwy grup, działów i treści postów nie są tłumaczone; komunikaty i panel są w PL/EN, a dodatkowe paczki JSON otrzymują angielski fallback.

## Granice etapu

To pierwsze **addytywne uprawnienia dla prywatnych kategorii**, nie pełna macierz wszystkich funkcji forum. Brak reguł odmawiających dostępu do kategorii publicznych, dziedziczenia pozostałych uprawnień, wyszukiwarki całej listy członków, automatycznego mapowania grup MyBB ani zaawansowanych ról moderatorów. Importer MyBB nadal działa niezależnie i **nie importuje jeszcze macierzy grup**. Przed użyciem na migracji prawdziwej społeczności trzeba przygotować odpowiednie mapowanie i testy bezpieczeństwa.

Bez SQLite w środowisku wykonano tylko `tests/Smoke/CategoryAccessSmoke.php` (19 asercji silnika zasad), `php -l`, kontrolę JS i symetrię tłumaczeń. Napisane `tests/Feature/GroupPermissionsTest.php` czekają na pełne środowisko testowe; nie należy traktować ich jako zaliczonych.

## Uzupełnienie dev.9 — konta poza listą ostatnich 100

Nowy `GET /api/admin/groups/users?q=...` jest dostępny wyłącznie administratorowi. Zapytanie po nazwie (2–80 znaków po trim) używa bezpiecznej, dosłownej składni LIKE z ograniczeniem do 20 wyników; `#123` oznacza dokładne ID. Można dzięki temu nadać lub cofnąć członkostwo starszego konta bez zgadywania jego ID. `GET /api/admin/groups` zwraca teraz także prawdziwe `member_count`, a starszy `member_ids` pozostaje ograniczony do ostatnich 100 kont dla kompatybilności. Bez nowych migracji. Nowy HTTP PHPUnit jest dopisany, ale nie uruchomiony na niepełnym PHP; **10 asercji** skompilowanego SQL Laravel przeszło w Python sqlite3, nie w PHP PDO/HTTP.
