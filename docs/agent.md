# Scenariusz przejęcia aplikacji przez agenta (SR7)

Ten dokument jest częścią szablonu. Nie stanowi dowodu wykonanego odbioru SR7.

## Dane przekazywane agentowi przez programistę

- URL nowego repozytorium Web, gałąź i dostęp do zapisu kodu.
- Adres instancji Zapq z `PathBase`, ID repozytorium oraz ID/nazwa aplikacji, jeśli już istnieją.
- Dostęp do API platformy przekazany poza repozytorium i logami.
- Nazwa metody API przypisanej do testowego potoku, ID potoku i oczekiwany wynik.
- Konto przeglądarkowe z Open oraz prawa API do zarządzania/wdrożeń, Run i Logs.View.

## Odbiór

1. Przeczytaj README. Uruchom `php tests/check.php` i testy JS.
2. Ustaw `lib/config.php`, przygotuj commit A i zapisz jego pełny SHA.
3. Przez API pobierz commit, utwórz aplikację, jeśli jej brak, i wdróż A. Sprawdź
   `status: Applied`, bieżący commit i niezmienny URL, uwzględniający PathBase.
4. Otwórz aplikację przez sesję Zapq. Zapisz unikalną notatkę, sprawdź SQLite i plik
   przez `state.php`. Uruchom potok i odczytaj wynik oraz logi przez `status.php`.
   Autor uruchomienia musi odpowiadać użytkownikowi aplikacji.
5. Skopiuj `tests/versions/B.php` do `lib/version.php`, utwórz commit B, uruchom CI,
   wypchnij kod i wdróż pełny SHA B przez API. Sprawdź wersję B i dotychczasowe dane.
6. Dodaj drugą notatkę, wdróż ponownie SHA A. Obie notatki oraz plik muszą pozostać.
7. Sprawdź odmowę Run oraz odczytu logów dla kont bez odpowiednich praw. Open samo
   w sobie nie nadaje tych uprawnień. Nie obchodź odmowy przez konto administratora.
8. Wycofaj wdrożenie z aktualnym `expectedDeploymentId`, sprawdź 404, przywróć A
   i sprawdź dane. Raport zawiera SHA, ID aplikacji/uruchomień i wyniki, bez tokenów.

Pełny SR7 dokłada zmianę i wdrożenie skryptu na runnerze. Ten szablon wywołuje istniejący
potok; nie instaluje runnera, nie tworzy automatycznie kont i nie zatwierdza wydania
zewnętrznego runnera. Zgoda na publikację repozytorium/wydanie wynika z bieżącego zadania.
