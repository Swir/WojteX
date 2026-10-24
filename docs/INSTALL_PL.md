# Instalacja WojteX 0.1.0-beta.1

Wybierz pusty katalog i osobną bazę danych. Nie podłączaj instalatora do bazy działającego forum.

## Hosting

1. Pobierz `WojteX-0.1.0-beta.1-install.zip` z Releases i rozpakuj cały katalog `wojtex-forum`. Zależności są dołączone; PHP nie jest dołączone.
2. Ustaw PHP 8.3 lub nowsze z rozszerzeniami opisanymi w README. Jeśli masz terminal, wykonaj `php tools/check.php`.
3. Umieść aplikację poza publicznym katalogiem hostingu i skieruj domenę na jej **`public/`**. Przykład: aplikacja `/home/konto/wojtex`, katalog domeny `/home/konto/wojtex/public`. Apache wymaga obsługi reguł `.htaccess` i `mod_rewrite`. W Nginx skonfiguruj przekazywanie nieistniejących ścieżek do `public/index.php`.
4. Uruchom `php tools/prepare.php`. Powstają prywatna `.env`, losowy klucz aplikacji i kod instalacyjny. Bez terminala przygotowanie uruchomi się przy pierwszym wejściu; kod odczytaj menedżerem plików z `storage/install-token`. Nie publikuj kodu ani pliku `.env`.
5. W `.env` ustaw `APP_URL=https://twoja-domena.example`, pozostaw `APP_ENV=production` i `APP_DEBUG=false`. Dla HTTPS dodaj `SESSION_SECURE_COOKIE=true`.
6. Domyślnie używana jest SQLite. Dla MySQL/MariaDB utwórz **nową pustą bazę i osobnego użytkownika**, a przed instalacją ustaw:

```dotenv
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=nazwa_nowej_bazy
DB_USERNAME=uzytkownik_nowej_bazy
DB_PASSWORD="wlasne_haslo"
```

7. PHP musi mieć prawo zapisu do `storage/`, `bootstrap/cache/` i — dla SQLite — `database/`. Przy pierwszym przygotowaniu również do katalogu głównego aplikacji, aby utworzyć `.env`. Nie ustawiaj publicznego zapisu `777`.
8. Otwórz `https://twoja-domena.example/install`, wybierz język i **Nowe forum**, wpisz kod instalacyjny, nazwę forum i własne dane administratora. Zostaw dane przykładowe wyłączone, jeśli chcesz pustą instalację.
9. Instalator tworzy konto i cztery puste działy, usuwa kod właściciela oraz blokuje ponowną instalację. Wejdź do panelu administratora i ustaw rejestrację, działy oraz domyślny wygląd.

Jeśli hosting nie pozwala zmienić katalogu domeny, umieść **tylko zawartość `public/`** w katalogu strony. Dostosuj ścieżki w jego `index.php`, aby wskazywały prywatną aplikację. Zachowaj publiczne pliki statyczne i reguły przekazywania adresów. Jest to konfiguracja zależna od hostingu — przed użyciem sprawdź, że `/.env`, `/storage/` i `/vendor/` nie udostępniają prywatnych plików.

## Lokalnie

```sh
php tools/check.php
php tools/prepare.php
php -S 127.0.0.1:8080 -t public public/router.php
```

Otwórz `http://127.0.0.1:8080/install`. Wbudowany serwer PHP jest przeznaczony wyłącznie do lokalnych testów.

## Poczta i wygląd

Paczka nie zawiera konta SMTP. Domyślny `MAIL_MAILER=log` nie umożliwia odzyskiwania hasła przez e-mail. Skonfiguruj własne SMTP zgodnie z [PASSWORD_RECOVERY.md](PASSWORD_RECOVERY.md).

Przycisk **Wygląd** działa również dla gości i na telefonie. Wybór zapisuje się tylko w używanej przeglądarce. Administrator ustala wartości domyślne; „Jak na forum” przywraca ich używanie.

Przed testowaniem ważnych danych zachowaj kopię bazy, `.env` z niezmienionym `APP_KEY` oraz prywatnego storage. Nie przesyłaj haseł, kopii baz ani treści prywatnych wiadomości do publicznych zgłoszeń GitHub.
