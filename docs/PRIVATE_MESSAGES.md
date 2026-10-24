# Prywatne wiadomości — WojteX 0.1.0-beta.1

API, migracja oraz skrzynka PL/EN są objęte pełnym zestawem testów PHP/SQLite beta.1. Nie oznacza to kwalifikacji produkcyjnej ani testu fizycznej współbieżności MySQL. Zakres weryfikacji: [RELEASE_CHECKS.md](RELEASE_CHECKS.md).

## Zakres

- Zalogowany użytkownik: `#/messages`. Formularz wysyłki wyszukuje konta po fragmencie nazwy, następnie użytkownik jawnie wybiera konto po stabilnym ID, rozróżniając nawet identyczne nazwy. Wyszukiwanie (do 10 trafień, min. 2 znaki) nie udostępnia e-maili, pomija konta zablokowane i własne; filtruje dosłownie znaki `%`, `_` i `!`. To dwustopniowy wybór, nie autouzupełnianie na bieżąco. Błędny, zablokowany lub własny `recipient_id` zwraca 422. Dla zgodności ze starszymi klientami API można nadal podać dokładną nazwę `recipient`, ale duplikaty są odrzucane bez losowego wyboru.
- `GET /api/messages?page=1` (tylko `auth`): 30 ostatnich własnych wiadomości na stronę, razem z liczbą nieprzeczytanych odebranych wiadomości. Pokazuje tylko korespondencję, w której bieżące konto jest nadawcą albo odbiorcą. Administrator ani moderator nie uzyskują automatycznie wglądu w cudzą skrzynkę.
- `GET /api/messages/recipients?q=tekst`: wymaga sesji, ma limit 30 żądań/minutę, zwraca wyłącznie `{id,name}` dostępnych użytkowników.
- `POST /api/messages` `{recipient_id: 123, body: "tekst"}`: tekst 1–4000 znaków, limit 10 żądań/minutę, CSRF i sesja. Treść jest przechowywana jako zwykły tekst i **zawsze escapowana przy wyświetlaniu w SPA**, bez renderowania HTML/Markdown.
- `PATCH /api/messages/{id}/read`: tylko adresat może oznaczyć wiadomość jako przeczytaną; wielokrotne wywołanie jest idempotentne. Obce i nieistniejące ID zwracają jednakowe 404, bez informacji o istnieniu wiadomości.
- Zablokowane konta nie mogą odczytać ani wysłać wiadomości. Usunięcie konta usuwa jego korespondencję na mocy FK `cascadeOnDelete` (również u drugiego uczestnika).

Tabela `private_messages` (`sender_id`, `recipient_id`, `body`, `read_at`, timestamps, od dev.5: `sender_hidden_at`, `recipient_hidden_at`) ma indeksy po ID uczestników i rekordach. **Świeża instalacja** tworzy ją w zwykłej migracji; dla aktualizacji istniejącej instancji wykonaj kopię zapasową, następnie `php artisan migrate --force` (NIGDY `migrate:fresh`). Importer MyBB pozostaje oddzielny i nie importuje wiadomości prywatnych w tym wydaniu.

## Ograniczenia bezpieczeństwa i dalsze kroki

To nie jest szyfrowanie end-to-end ani szyfrowanie treści w bazie; administrator serwera bazy może je odczytać. Nie ma jeszcze powiadomień e-mail, przywracania ukrytych wiadomości/kosza, ustawień prywatności blokujących konkretne konta, antyspamu treści ani migracji PM z MyBB. Bieżący wybór odbiorcy to wyszukiwarka z ręcznym potwierdzeniem konta po ID; brakuje szybkiego autouzupełniania, listy kontaktów i rozmów w wątkach. Nie używać na aktywnej społeczności bez testów HTTP, kontroli bezpieczeństwa i odpowiedniej polityki retencji/prywatności.

**Weryfikacja w przygotowanym środowisku:** `php vendor/bin/phpunit --no-progress`. Uruchom także pełne testy regresji instalatora, uprawnień prywatnych działów, resetu hasła i dwujęzycznego UI. Na obecnym hoście wykonano jedynie test 13 asercji czystej polityki `php tests/Smoke/PrivateMessagePolicySmoke.php`, kontrolę składni, tras i tłumaczeń.

## Uzupełnienie dev.4 — ograniczenia weryfikacji

Nowy endpoint i wybór odbiorcy dodano do kodu; `tests/Feature/PrivateMessagesTest.php` rozszerzono o wyszukiwanie, ukrywanie kont, dosłowne wildcardy i adresowanie po ID, ale pełnego zestawu PHPUnit/HTTP **nie uruchomiono** z powodu brakujących rozszerzeń PHP. Test PHP `tests/Smoke/RecipientSearchSmoke.php` i niezależny test wzorców SQL na Python SQLite `tests/Smoke/RecipientSearchSqliteSmoke.py` przeszły. Ten drugi **nie zastępuje** testu Laravel+PDO SQLite/MySQL.

## Uzupełnienie dev.5 — osobiste ukrywanie kopii wiadomości

- Nowy przycisk **Ukryj w mojej skrzynce / Hide from my mailbox** w obu językach wyświetla potwierdzenie. Akcja `DELETE /api/messages/{id}` ukrywa wpis tylko u wywołującego (nadawcy albo adresata) i wymaga zalogowania oraz CSRF. Obca wiadomość, powtórne ukrycie i nieznane ID dają 404.
- Nowa migracja `2026_10_22_000004_add_private_message_visibility.php` dodaje dwa pola nullable. Dotychczasowe wiadomości mają oba pola puste i pozostają widoczne. Aktualizacja istniejącego forum: po wykonaniu kopii zapasowej użyj `php artisan migrate --force` przed wejściem na forum.
- Lista, paginacja i licznik nieprzeczytanych ignorują wiadomości ukryte przez bieżącego użytkownika. Ukrycie nie zmienia `read_at` ani widoczności u drugiego uczestnika. Wiadomość pozostaje w bazie nawet gdy obie osoby ją ukryły; to funkcja skrzynki, **nie prawo do usunięcia danych, kosz ani okres retencji**.
- Przy wyszukiwaniu odbiorcy ciągi składające się wyłącznie z białych znaków albo mniej niż dwóch znaków po odcięciu są odrzucane kodem 422.
- Weryfikacja: czysta polityka PHP 16 asercji PASS, zachowanie odpowiednich filtrów zapytania Python SQLite 14 asercji PASS, kontrola składni i PL/EN. Test pełny `PrivateMessagesTest` rozszerzono, ale **nie wykonano go** (brak `pdo_sqlite`, `dom`, `mbstring`, `xmlwriter` w PHP).

## Uzupełnienie dev.6 — blokowanie kontaktów w prywatnych wiadomościach

- Użytkownik może blokować osobę po stabilnym ID z wyszukiwarki lub z dowolnej istniejącej wiadomości, sprawdzić własne blokady i je cofnąć. UI, potwierdzenia i komunikaty mają pełne PL/EN, a paczki trzecich języków bez nowych kluczy korzystają z angielskiego fallbacku.
- Zapis blokady: `POST /api/messages/blocks {"user_id":123}`; odczyt własnej listy `GET /api/messages/blocks`; odblokowanie `DELETE /api/messages/blocks/{id}`. Obie mutacje mają limity żądań oraz zwykłe `auth`/CSRF. Blokada i odblokowanie są idempotentne, bez dostępu do listy innej osoby. Nie można zablokować siebie ani konta, którego nie ma.
- Jeśli którakolwiek osoba zablokuje drugą, **żadna strona nie wyśle nowej wiadomości do drugiej** (także przez stare API po nazwie). Wyszukiwarka ukrywa niedostępne konta bez ujawniania przyczyny. Wysyłka sprawdza kontakt przed zapisem oraz ponownie w jednym atomowym `INSERT ... SELECT ... NOT EXISTS` w SQL; nie powinna korzystać wyłącznie ze starego wyniku wyszukiwania ani klienta JavaScript.
- Migracja `2026_10_22_000005_create_private_message_blocks.php` ma unikalną parę z FK i `cascadeOnDelete`; nie usuwa istniejącej korespondencji. Zablokowanie dotyczy tylko nowych PM, **nie** publicznego forum, istniejących wiadomości, żądań usunięcia danych ani weryfikacji kont. Usunięcie uczestnika czyści jego wpisy w liście blokad.
- W tej paczce wykonano PHP pure policy i niezależny Python SQLite SQL, kontrolę składni, tras i 917 kluczy PL/EN. Testy HTTP rozszerzono, lecz pełny PHPUnit, rzeczywista migracja przez PHP PDO, MySQL i rzeczywiste wyścigi transakcji nadal wymagają kompletnego runtime oraz osobnej kwalifikacji.

## Uzupełnienie dev.7 — zgłoszenia nadużyć otrzymanych wiadomości

- Przy widocznej **odebranej** wiadomości znajduje się przycisk **Zgłoś / Report**. Odbiorca wybiera powód (spam/nękanie/groźba/inny) i opcjonalny opis do 500 znaków. Formularz jawnie informuje, że zgłoszenie udostępni administratorowi **tę jedną** wiadomość i wyjaśnienie. Ukrytych kopii nie można zgłaszać; przy wcześniejszym ukryciu należy zgłosić nadużycie poza tym modułem. To świadome ograniczenie MVP, nie system przywracania wiadomości.
- `POST /api/messages/{id}/report` (limit 5/min na IP) zapisuje jeden wpis `(message_id,reporter_id)` dzięki bazowej unikalności; powtórka nie nadpisuje oryginalnych dowodów ani statusu. Wstępne i warunkowe sprawdzenie w SQL wymagają dokładnie odbiorcy z widoczną kopią. Obca wiadomość, wychodząca i nieznane ID zwracają takie samo 404. Zbanowane konto otrzymuje 403.
- Administrator widzi w panelu kolejkę **Zgłoszenia PW** ze stronicowaniem i trzema stanami: otwarte/rozpatrzone/odrzucone. Może zmieniać status; to **nie** kasuje wiadomości i **nie** blokuje automatycznie nadawcy. Ani administrator, ani moderator nie mogą przez to API czytać całej cudzej skrzynki. Administrator ma wgląd tylko w zgłoszoną wiadomość, nazwy uczestników i treść zgłoszenia. Wszelkie treści pokazywane są jako escapowany tekst.
- Migracja `2026_10_22_000006_create_private_message_reports.php` tworzy tabelę z FK, kaskadą po usunięciu wiadomości/konta, unikalnym raportem od odbiorcy i metadanymi statusu. Usunięcie konta może również usunąć zgłoszenie; **brak prawnej archiwizacji dowodów lub okresów retencji**. Raportów nie wolno traktować jako automatycznej decyzji moderacyjnej.
- Test polityki PHP (18 asercji) i rzeczywiste SQL wygenerowane przez Laravel, wykonane przez Python SQLite (10 asercji), przeprowadzono bez PHP PDO. Dodano `tests/Feature/PrivateMessageReportsTest.php`, ale **PHPUnit/HTTP i migracja PDO nie zostały wykonane**. Przed publicznym uruchomieniem wymagane jest sprawdzenie ról, CSRF, uprawnień, paginacji i kopii bazy w pełnym PHP 8.3+.

## Uzupełnienie dev.8 — rezygnacja z przyjmowania nowych PW

- Edycja profilu `PATCH /api/profile` przyjmuje opcjonalne `allow_private_messages:boolean`. Brak tego pola z dawnego klienta **nie włącza ponownie** odbioru; tylko jawnie podana wartość zmienia preferencję. Własny `selfUser` zwraca flagę, publiczny `publicUser` nie zwraca jej. Interfejs udostępnia checkbox i opis PL/EN.
- Domyślnie **true** po migracji `2026_10_22_000007_add_private_message_opt_out.php`, więc istniejąca społeczność zachowuje stary sposób działania. Wyłączenie zatrzymuje nowe wiadomości przychodzące i ukrywa konto z wyszukiwarki odbiorców; nie ukrywa starych wiadomości, nie blokuje wychodzących ani nie rozstrzyga relacji między kontami. Starsze API wysyłania po nazwie również podlega ustawieniu.
- SQL `INSERT ... SELECT` rewaliduje zgodę odbiorcy, jego ban oraz brak bana nadawcy **przy samym zapisie**, obok wcześniejszej kontroli dwustronnych blokad PM. Nie polega na wynikach wcześniejszego wyszukiwania ani na przestarzałych preflightach PHP. Mechanizm nie daje gwarancji izolacji ponad semantykę pojedynczej instrukcji SQL danego silnika.
- Wykonany RED-before/GREEN-after test `tests/Smoke/PrivateMessagePreferencesSqliteSmoke.py`: 14 sprawdzeń faktycznego SQL skompilowanego przez Laravel i wykonanego przez Python sqlite3 (nie PHP PDO). Funkcjonalny `tests/Feature/PrivateMessagePreferencesTest.php` został napisany, lecz **nie wykonany przez PHPUnit/HTTP**; tę kwalifikację i rzeczywiste migracje na SQLite/MySQL należy przeprowadzić przed produkcją.
