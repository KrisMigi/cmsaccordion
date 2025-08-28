/* KM CMS Accordion – JS (opcjonalne ulepszenia: aria + jednoczesna jedna otwarta sekcja w grupie) */
(function(){
  function setAria(details){
    var summary = details.querySelector('.km-acc-header');
    if(summary){
      summary.setAttribute('aria-expanded', details.hasAttribute('open') ? 'true' : 'false');
    }
  }
  function closeOthers(details){
    var group = details.getAttribute('data-group');
    if(!group){ return; }
    // Zamknij inne <details> w tej samej grupie
    document.querySelectorAll('details.km-acc[data-group="'+group+'"][open]').forEach(function(el){
      if(el !== details){ el.removeAttribute('open'); setAria(el); }
    });
  }
  // Kliknięcie w summary – przeglądarka sama przełącza open; my tylko poprawiamy aria i grupę
  document.addEventListener('click', function(e){
    var summary = e.target.closest('summary.km-acc-header');
    if(!summary){ return; }
    var details = summary.closest('details.km-acc');
    if(!details){ return; }
    // Przełączenie atrybutu `open` następuje po kliknięciu – odczytaj stan "po"
    setTimeout(function(){
      if(details.hasAttribute('open')){ closeOthers(details); }
      setAria(details);
    }, 0);
  });
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('details.km-acc').forEach(setAria);
  });
})();
