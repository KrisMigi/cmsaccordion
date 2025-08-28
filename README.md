# cmsaccordion

instrukcja obsługi (użytkowanie i diagnoza)
gdzie używać

W treści stron CMS, opisach produktów, modułach i innych miejscach, które generują HTML przechodzący przez standardowy rendering PS — moduł automatycznie zamieni shortcode na harmonijkę.

dwa sposoby wstawiania

Pełny tag

[{accordion id=ID title="Dowolny tytuł" open=0/1 group="#selector"}]


id – ID strony CMS (wymagane).

title – opcjonalny tytuł widoczny w belce (puste = tytuł CMS).

open – czy startowo rozwinięty (1, true, tak, on itp. rozpoznawane).

group – opcjonalny CSS selector grupy (np. #product-details). Sekcje z tym samym selektorem zachowują się jak „akordeon” – otwarcie jednej zamyka inne.

Alias (wygodniej bez pamiętania ID):

wejdź w Moduły → KM CMS Accordion → Konfiguruj, dodaj wpis: alias, ID CMS, opcjonalnie Tytuł, Otwarty, Grupa.

potem w treści użyj:

[{twoj_alias}]

styl i zachowanie

HTML: <details>/<summary> – działa nawet bez JS.

JS: dodaje ARIA i obsługuje „tylko jedna otwarta” w obrębie tej samej group (brak zewnętrznych zależności).

CSS: podstawowe obramowanie i ikonka „+ / –”.

szybka diagnostyka (opcjonalnie)

Włącz DIAG: w kmcmsaccordion.php ustaw:

private const DIAG = true;


Wyczyść cache (konsola w katalogu sklepu):

rm -rf var/cache/* var/modules/*


Sprawdź komentarz DIAG na froncie (początek HTML):

curl -sS https://twoj-sklep/ | head -n3
# <!-- KMCMS DIAG layer=...; replaced accordion=X, alias=Y; ts=... -->


Zobacz log:

tail -n 200 modules/kmcmsaccordion/kmcmsaccordion.log

typowe problemy

„nic się nie pokazuje”: upewnij się, że ID CMS istnieje i strona jest aktywna (wtedy [ { accordion id=... } ] zadziała).

alias nie działa: alias musi być unikalny, tylko [A-Za-z0-9_-].

nie zwija/rozwija: upewnij się, że w temacie jest hook header (standard w PS) — wtedy CSS/JS się załadują.