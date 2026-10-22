import axios from 'axios';

// Enregistre la langue de l'interface (null = langue par défaut du site)
// puis recharge la page : le dictionnaire est chargé au démarrage.
export async function setLocale(code) {
  await axios.post('/locale', { locale: code }, { headers: { Accept: 'application/json' } });
  window.location.reload();
}
