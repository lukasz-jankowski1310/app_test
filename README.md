# Szablon aplikacji Web dla Zapqio

Gotowy punkt startowy dla repozytorium typu **Web** hostowanego przez Zapqio.
Szablon: [zapqio/web_app_template](https://github.com/zapqio/web_app_template).

Przykład pokazuje odczyt/zapis SQLite, pliku, formularz PHP z sesją Zapq i CSRF,
wywołanie potoku przez API oraz statyczne HTML/JS bez Composera ani etapu budowania.
Cały katalog stanowi samodzielne repozytorium typu **Web**; `public/` musi znajdować
się w jego katalogu głównym. Platforma wdraża kod z commita do wspólnego hosta PHP.
Nie tworzysz obrazu Docker dla każdej aplikacji. Szablon nie wymaga Composera ani npm.

## Od kopii szablonu do działającej aplikacji

1. Na GitHubie wybierz **Use this template → Create a new repository**.
2. Sklonuj swoje nowe repo, uruchom testy opisane niżej, dostosuj kod i wypchnij commit.
   Możesz też skopiować wszystkie pliki do repo na GitLabie; zachowaj pliki ukryte
   oraz `public/` w katalogu głównym repozytorium.
3. W Zapq wybierz **Repozytoria → Dodaj**, podaj URL swojego repo, gałąź i typ **Web**.
   Token do prywatnego repo ustaw w platformie, poza plikami szablonu.
4. W **Aplikacje → Dodaj** wybierz repo, nadaj nazwę i opcjonalny opis.
   Nazwa i repozytorium zostają przypisane na stałe. Samo utworzenie nie wdraża kodu.
5. Otwórz **Wdrożenia**, sprawdź repo i wdróż HEAD albo pełny SHA commita. Oczekuj
   `Applied`. Adres to `https://app.zapq.io/{firma}/ui/{nazwa}/` (z końcowym `/`).
6. Nadaj użytkownikowi Open do aplikacji. Otwórz ją z menu **Twoje aplikacje**, zapisz
   notatkę i sprawdź `state.php`: `values`, `file`, `version` i `integrity: "ok"`.
7. W Zapq przygotuj testowy potok z metodą wdrożoną na runnerze i przypisz do niego
   niestandardową metodę API. Szablon wysyła JSON `{"source":"web-app"}`; potok musi
   akceptować to wejście. Dla innego kontraktu zmień wejście w `public/run.php`.
8. Wpisz nazwę metody API w formularzu albo ustaw ją jako domyślną w `lib/config.php`.
   To jawna konfiguracja wskazująca potok, nie poświadczenie. Uruchom potok i kliknij
   **Odśwież wynik**. Wynik JSON zawiera stan kroków, `lastOutput` i autora; linki
   prowadzą do logów kroków. HTTP 200 przy uruchomieniu oznacza przyjęcie, nie sukces potoku.

Używamy istniejącego `POST /api/exec-pipeline/{nazwa-metody}`. Powiązanie nazwy z ID
potoku ustawia się w Zapq; nie ma potrzeby dodawania osobnego API do szablonu.
Bez JavaScript formularz nadal uruchamia potok i zwraca JSON z `runId`; wynik odczytasz
pod `status.php?runId=<runId>`. Nie ponawiaj automatycznie nieudanego POST: potok mógł
już wystartować mimo utraty odpowiedzi. Szablon nie zapisuje historii wyników w SQLite.

| Uprawnienie | Potrzebne do |
|---|---|
| `Repositories.Manage` / `Repositories.View` | Dodania repo / sprawdzenia i pobrania wersji. |
| `Applications.Manage` | Utworzenia, opisu i usunięcia aplikacji. |
| `Deployments.Send` na `application` | Wdrożenia, rollbacku i wycofania; obejmuje podgląd. |
| `Applications.Open` na aplikacji | Otwierania PHP, statyk i pobierania kontekstu. |
| `Pipelines.Run` na potoku | Uruchamiania przez API jako zalogowany użytkownik. |
| `Logs.View` w zakresie potoku | Odczytu wyniku, stanu i logów. |

Open, zarządzanie aplikacją i uruchamianie potoku są osobnymi prawami. Odczyt wyniku
może dać 403 mimo poprawnego uruchomienia. Nie wymaga to dodania logowania w aplikacji.

### Minimalny potok demonstracyjny

Jeśli nie masz jeszcze potoku testowego, w osobnym repo typu **Python** dodaj plik
`methods/template-echo.py` (kod skryptów i kod Web pozostają w osobnych repozytoriach):

```python
import json
import zapqio

def run(data: str) -> str:
    payload = json.loads(data)
    zapqio.log.info("Web template pipeline completed")
    return json.dumps({"source": payload["source"], "ok": True})
```

Pobierz commit tego repo i wdróż go na runnerze wewnętrznym. W Zapq utwórz potok
z jednym krokiem `template-echo` na tym runnerze, następnie metodę API np.
`web-template-demo` przypisaną do ID tego potoku. Wpisz tę nazwę w formularzu aplikacji.
Oczekuj stanu `Ok`, wyniku `{"source":"web-app","ok":true}` i linii logu podanej powyżej.

## Pliki

| Plik | Rola |
|---|---|
| `public/index.php` | Formularz notatek, POST i przekierowanie względne; HTML z escapowaniem tekstu. |
| `public/state.php` | GET/HEAD/POST JSON do próby danych, wersji SQLite i integralności bazy. |
| `public/assets/style.css` | Statyczny arkusz stylów. |
| `lib/database.php` | PDO, zapytania parametryzowane, utworzenie tabeli i obsługa zapisu. |
| `lib/zapq.php` | Zaufany kontekst użytkownika i wywołanie API z PHP. |
| `public/run.php` | POST uruchamiający metodę API w imieniu użytkownika. |
| `public/status.php` | GET wyniku uruchomienia lub logów kroku, z prawami użytkownika. |
| `lib/config.php`, `lib/version.php` | Jawna nazwa metody API i oznaczenie wersji przykładu. |
| `tests/`, `.github/workflows/check.yml`, `.gitlab-ci.yml` | Lint, kontrakty klienta i integralność SQLite; bez artefaktu wdrożeniowego. |
| `docs/agent.md` | Dane wejściowe i scenariusz odbioru dla agenta w SR7. |
| `public/static.html`, `public/assets/zapq.js` | Przykład statyczny, pobieranie kontekstu, CSRF i klient API. |

Host ustawia `ZAPQIO_APPLICATION_DATA_DIRECTORY=/apps-data/<ApplicationId>`.
Tam powstają `state.sqlite` i `entries.txt`. Baza pozostaje poza repozytorium i poza `public/`;
jej trwałość zapewnia wolumen. Kod korzysta z `__DIR__`, a linki i przekierowanie są względne,
co obsługuje adres `https://app.zapq.io/{firma}/ui/{nazwa}/` oraz pusty `PathBase`.

SQLite używa domyślnego rollback journal, `busy_timeout=5000` i `synchronous=FULL`.
Schemat zakłada autor aplikacji; zmiany schematu i zgodność starszego kodu po rollbacku
również należą do autora. Nie narzucamy biblioteki migracji. Zapis do bazy i do pliku
to dwie osobne operacje demonstracyjne, bez wspólnej transakcji. Błąd jednej z nich zwraca
błąd żądania; nie oznacza automatycznego cofnięcia wcześniej zapisanego rekordu.
Zapis pliku sprawdza również liczbę zapisanych bajtów, aby częściowy zapis przy braku miejsca
nie wyglądał na sukces. Ostrzeżenie zajętości instancji nie blokuje zapisów; rzeczywisty
brak miejsca nadal jest błędem. Próbę ENOSPC zawiera test hosta `run.py --faults`.

## Wdrożenie przez API

Pełne kontrakty swojej instancji znajdziesz pod `<PathBase>/api` oraz
`<PathBase>/api/openapi-v1.json`. Każde poniższe żądanie poprzedź adresem instancji
z `PathBase`, np. `https://app.zapq.io/firma`. Uwierzytelnij API własnym JWT lub kluczem
API przekazanym poza repo i logami. Nie używaj klucza API jako sesji wejścia do PHP.

| Kolejność | Żądanie i treść JSON |
|---|---|
| 1 | `POST /api/repositories` — `{"name":"moj-web","url":"https://github.com/moja-organizacja/moj-web","branch":"main","kind":"Web"}` → `id`. |
| 2 | `POST /api/repositories/{id}/check` → sprawdź `lastError` oraz `remoteHeadCommit`. |
| 3 | `POST /api/repositories/{id}/fetch` — `{"commit":"<pełny SHA>"}` → migawka i walidacja struktury. |
| 4 | `POST /api/applications` — `{"name":"notatki","repositoryId":"<id repo>"}` → `id`, `revision`, `name`. |
| 5 | `POST /api/applications/{id}/deployments` — `{"repositoryId":"<id repo>","commit":"<pełny SHA>"}`. |
| 6 | `GET /api/applications/{id}/deployments` → bieżący commit w `current`, błąd hosta i historia. |

Wdrożenie może odpowiedzieć HTTP 200 z `status: "Failed"` i `reason`; sprawdzaj treść.
Commit musi być pełnym SHA (40 lub 64 znaki). Zmiana opisu/usunięcie wymaga aktualnej
`revision`; konflikt 409 wymaga ponownego odczytu i uzgodnienia zmian.

## Wersje A → B → A i trwałość

1. Wersja początkowa ma `lib/version.php` zwracający `A`. Zapisz SHA A i dodaj notatkę.
2. Skopiuj `tests/versions/B.php` do `lib/version.php`, utwórz i wypchnij nowy commit.
   W PowerShell: `Copy-Item tests/versions/B.php lib/version.php`; w sh:
   `cp tests/versions/B.php lib/version.php`. Wdróż SHA B. Ekran i `state.php` pokażą B.
3. Sprawdź wcześniejszą notatkę i plik, dopisz drugą notatkę.
4. W historii wdrożeń wybierz A lub wyślij jego SHA przez API. Oznaczenie wraca do A,
   obie notatki i plik pozostają. Nie zmieniamy schematu między tymi wersjami.
5. Wycofaj wdrożenie przez panel albo `POST .../deployments/withdraw` z
   `{"expectedDeploymentId":"<current.id>"}`. Nowe wejścia dają 404. Ponowne wdrożenie
   zachowuje dane. Usunięcie aplikacji także zachowuje dane starego ID; nowa aplikacja
   pod starą nazwą ma nowe ID i pusty katalog danych.

Dane przetrwają restart tylko z tym samym wolumenem. Rollback cofa kod, nie dane ani
schemat. Kopie zapasowe/import SQLite i twarde quota nie są częścią tego szablonu.

## Testy i CI

Wymagania lokalne: PHP 8.4 z PDO SQLite, mbstring i cURL oraz Node 24 do testu JS.
Z katalogu głównego własnego repo:

```sh
php tests/check.php
node --experimental-vm-modules --test tests/zapq.test.mjs
```

Bez lokalnego PHP możesz użyć tego samego obrazu co CI (PowerShell):

```powershell
docker run --rm --network none --read-only --tmpfs /tmp -v "${PWD}:/template:ro" -w /template php:8.4.26-fpm-alpine3.24@sha256:78cd8de9970a9cd6ff4d98860a94eb5bf37dd2d5776b4785562dbfad01c29d5a php tests/check.php
```

Testy zapisują dane wyłącznie w katalogu tymczasowym. Sprawdzają składnię wszystkich
plików PHP, strukturę samodzielnego repo, odrzucanie nieprawidłowych danych, SQLite po
ponownym otwarciu, klienta API, CSRF i odnowienie kontekstu dla obu wariantów PathBase.
GitHub Actions ([checkout v7.0.1](https://github.com/actions/checkout/releases/tag/v7.0.1))
i GitLab CI wykonują te same testy. Nie powstaje ZIP ani obraz wymagany do wdrożenia:
platforma pobiera migawkę commita. Wynik CI nie jest automatycznym wdrożeniem.

Repo `web_app_template` udostępnia **Use this template**, tak jak szablony
[.NET](https://github.com/zapqio/dotnet_runner_template),
[Node.js](https://github.com/zapqio/internal_runner_node_template) i
[Python](https://github.com/zapqio/internal_runner_python_template).
Odbiór własnej kopii wykonaj według szybkiego startu; scenariusz pracy agenta
i potrzebne dane opisuje [docs/agent.md](docs/agent.md).

Harness platformy wymaga także .NET 10, Pythona, Gita, Dockera Linux, Chrome i modułu
Node `playwright`. Jeśli moduł jest zainstalowany poza standardową ścieżką Node, ustaw
`ZAPQIO_TEST_PLAYWRIGHT_MODULE` na jego absolutny katalog. Artefakty pozostają w TEMP.

Testy jednostkowe nie symulują logowania. Pełny odbiór wymaga działającej instancji Zapq
z `ui-host`. W repo platformy uruchom:

```powershell
python -B Zapqio.Platform.Tests/Smoke/ui_host/api.py --template
python -B Zapqio.Platform.Tests/Smoke/ui_host/api.py --template --root-path
```

Harness używa własnego Compose, prawdziwego PHP, API, executora i lokalnej kopii Git;
nie publikuje repo ani nie zmienia działającej instancji. Pobranie z publicznego GitHub/GitLab
i zdalny przebieg CI wymagają wydania repo szablonu i są osobnym krokiem wydania.

## Kontrakt kontekstu PHP

Bramka Web usuwa nagłówki kontekstu od klienta i ustawia własne. PHP czyta je z `$_SERVER`;
funkcja `zapq_context()` w `lib/zapq.php` udostępnia gotową tablicę. Nazwy w tabeli są
nazwami nagłówków HTTP, np. `X-Zapq-User-Id` odpowiada `HTTP_X_ZAPQ_USER_ID`.

| Parametr | Znaczenie |
|---|---|
| `X-Zapq-Application-Id` | Stały GUID aplikacji. |
| `X-Zapq-Application-Url` | Publiczna ścieżka aplikacji z końcowym `/`, zawiera `PathBase`. |
| `X-Zapq-Api-Url` | Publiczna ścieżka `PathBase + /api/`. |
| `X-Zapq-Internal-Api-Url` | Skonfigurowany origin Weba oraz `PathBase + /api/`; używa go cURL. |
| `X-Zapq-User-Id`, `X-Zapq-User-Login` | ID użytkownika i login zakodowany percent-encoding; login odczytaj przez `rawurldecode`. |
| `X-Zapq-Access-Token` | JWT użytkownika ważny 15 minut, wyłącznie dla API Zapq. |
| `X-Zapq-Csrf-Token`, `X-Zapq-Csrf-Field`, `X-Zapq-Csrf-Header` | Token i nazwy pola/nagłówka ASP.NET Antiforgery. |
| env `ZAPQIO_APPLICATION_DATA_DIRECTORY` | Trwały katalog danych poza kodem i `public/`. |

Formularz umieszcza CSRF w ukrytym polu; fetch do aplikacji w nagłówku. Token wymaga
powiązanego cookie przeglądarki. Kontrolę wykonuje Web przed uruchomieniem PHP.
Nie przekazujemy do PHP cookie logowania, klucza API ani wejściowego `Authorization`.
Nie zapisuj JWT w logach, URL, bazie ani HTML; `run.php` używa go tylko po stronie PHP.
API zawsze ponownie rozstrzyga uprawnienia użytkownika. Autor aplikacji nie nadaje praw
przez nagłówki ani nie przełącza wywołania na konto systemowe.

## Statyczny HTML i JavaScript

`GET <adres-aplikacji>_zapq/context` zwraca `applicationId`, `applicationUrl`, `apiUrl`,
`user: { id, login }`, `token`, `expiresAt` oraz `csrf: { token, headerName, formFieldName }`.
Wymaga sesji cookie i `Open`, ma `Cache-Control: no-store`, nie zwraca refresh tokenu.
`assets/zapq.js` przechowuje wynik tylko w pamięci modułu i pobiera ponownie, gdy do końca
ważności zostaje mniej niż minuta. `applicationFetch()` dodaje CSRF, a `apiFetch()` bearer.
Nie zapisuj tokenu w localStorage/sessionStorage. Przy 401/403 wróć do logowania lub
sprawdź uprawnienia; ponowne pobranie kontekstu również wymaga aktywnej sesji i `Open`.

Wylogowanie i odebranie `Open` blokują dalsze wejścia do aplikacji oraz pobieranie kontekstu.
Już wydany JWT zachowuje dotychczasowe zasady API do wygaśnięcia, w tym ponowną kontrolę
uprawnień operacji. Nie jest to token z automatycznym unieważnianiem po zamknięciu strony.

Publiczny katalog `public/` jest jedynym katalogiem serwowanym przez host. `lib/`, testy,
README i dane nie są dostępne przez HTTP. Nie dodawaj sekretów ani baz do `public/`.
