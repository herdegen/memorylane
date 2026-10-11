/**
 * Type d'appareil, pour adapter les explications (tuto d'envoi de photos…).
 * Les iPad récents se présentent comme des Mac : on les repère au tactile.
 *
 * @returns {'ios'|'android'|'desktop'}
 */
export function detectDevice() {
  if (typeof navigator === 'undefined') return 'desktop';
  const ua = navigator.userAgent || '';
  if (/iPhone|iPad|iPod/i.test(ua) || (/Macintosh/i.test(ua) && navigator.maxTouchPoints > 1)) return 'ios';
  if (/Android/i.test(ua)) return 'android';
  return 'desktop';
}
