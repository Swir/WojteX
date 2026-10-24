# Odzyskiwanie hasła — 0.1.0-beta.1

API i interfejs odzyskiwania hasła są objęte zestawem testów PHP/SQLite dla beta.1. Naprawiono kolizję właściwości języka powiadomienia z klasą Laravel. Testy używają atrap transportu poczty; rzeczywistego doręczenia przez zewnętrzne SMTP nie kwalifikowano. Szczegóły: [RELEASE_CHECKS.md](RELEASE_CHECKS.md).

## Przygotowanie poczty

Wszystkie rzeczywiste linki wychodzą wyłącznie przez skonfigurowaną pocztę. Domyślne `MAIL_MAILER=log` **nie działa jako transport odzyskiwania hasła**, żeby token resetujący nie trafiał do logów. `array` jest dopuszczony tylko w środowisku `testing`, przeznaczonym do automatycznych testów. Nie wolno dodawać mailera `log` jako fallbacku dla maili z tokenami.

W `.env` skonfiguruj poprawny adres forum, serwer pocztowy i nadawcę. Przykład pól (wartości są wyłącznie placeholderami):

```dotenv
APP_URL=https://forum.example.com
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=twoje-konto
MAIL_PASSWORD="haslo-serwera-poczty"
MAIL_FROM_ADDRESS=forum@example.com
MAIL_FROM_NAME="WojteX Forum"
```

Dostosuj `MAIL_SCHEME` i port do wymagań swojego dostawcy SMTP (np. jawny STARTTLS lub SMTPS); przykład **nie stanowi przetestowanego połączenia SMTP**. Po zmianie `.env` na hostingu z cache konfiguracji użyj `php artisan optimize:clear`. Zabezpiecz `.env`, nie pokazuj go przez WWW i nie dołączaj do paczek.

`APP_URL` musi być poprawnym adresem HTTPS, z wyjątkiem lokalnego HTTP na `localhost`, `127.0.0.1` lub `::1`. Link jest budowany z `APP_URL`, **nigdy z nagłówka Host wysyłającego żądanie**. Instalacja jest nadal przewidziana w katalogu głównym domeny. Upewnij się, że gotowy adres otwiera docelowe forum, a wysyłany mail nie wpada do spamu. Testowanie na prawdziwym SMTP należy wykonać osobno, na własnym koncie testowym; w tym wydaniu nie wysyłano wiadomości do ludzi.

## Przebieg i zabezpieczenia

1. Gość wybiera „Nie pamiętasz hasła?” pod formularzem logowania i wpisuje adres. `POST /api/auth/password/forgot` zwraca **identyczną odpowiedź** dla istniejącego, nieznanego, zablokowanego i czasowo ograniczonego konta. Przy niezdefiniowanym transporcie mailowym zwraca wszystkim kod 503 i jasną informację o konfiguracji.
2. Dla istniejącego, niezablokowanego konta Laravel tworzy losowy token, zapisuje w tabeli `password_reset_tokens` wyłącznie jego hash i nadaje wiadomość w języku bieżącego żądania (PL lub EN; inne języki wykorzystują fallback). Token wygasa po 60 minutach, a nowy token dla adresu nie jest generowany częściej niż co 60 sekund. Dodatkowo endpoint ma limit pięciu żądań na minutę na IP.
3. W mailu jest jednorazowy link `https://forum.example.com/#/reset-password?token=…&email=…`; token jest w **fragmencie URL** i nie trafia na serwer przy samym otwarciu strony. Formularz wysyła token i nowe hasło przez sesję z CSRF do `POST /api/auth/password/reset` (limit pięciu żądań/min/IP).
4. Zmiana wymaga zgodnego potwierdzenia hasła o długości co najmniej 12 znaków (maksymalnie 128). Udana zmiana usuwa token, wymienia `remember_token` i czyści stary hash/sól z ewentualnej migracji MyBB. Nie loguje użytkownika automatycznie. Nieznane, użyte wcześniej lub przeterminowane tokeny otrzymują ten sam błąd.

**Sesje po resecie (dodane w alpha.3-dev.1):** standardowy middleware Laravel `AuthenticateSession` porównuje przy każdym następnym żądaniu hasz aktualnego hasła z wartością zapamiętaną w sesji podczas logowania. Po zmianie hasła zwykłe stare sesje (również utworzone w alpha.2) zostają wylogowane przy kolejnym żądaniu HTTP. Nie jest to natychmiastowe fizyczne usuwanie wszystkich plików rekordów sesji na wszystkich urządzeniach. Test jednostkowo-integracyjny działania middleware bez bazy przeszedł, a test pełnego przepływu HTTP został dopisany do PHPUnit, lecz nadal wymaga środowiska z PDO SQLite. Nie wprowadzono weryfikacji e-mail ani 2FA. Nie wykazano testami na tym hoście działania SMTP, MySQL, hostingu produkcyjnego ani zabezpieczeń w zewnętrznej infrastrukturze proxy/CDN.

## Testy lokalne

Docelowe polecenie: `php vendor/bin/phpunit --testsuite Feature`. Rozszerzona klasa testowa sprawdza anonimowość odpowiedzi, blokadę kont, lokalizację maila, konfigurację poczty, walidację HTTPS, odporność na nagłówek Host, hash tokenu, jednorazowość, upływ 60 minut, per-adres i per-IP throttling, CSRF, walidację haseł i wyczyszczenie danych logowania z MyBB. Sprawdź tę klasę oraz wszystkie dotychczasowe testy przed użyciem paczki jako wersji produkcyjnej.
